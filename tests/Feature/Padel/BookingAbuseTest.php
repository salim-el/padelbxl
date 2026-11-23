<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\BlockedIdentity;
use App\Models\Court;
use App\Models\FraudFlag;
use App\Models\User;
use App\Notifications\MatchCancelled;
use App\Services\AccountStatusService;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Réserver sans payer ou annuler en boucle : les règles sont les mêmes pour tous, et seule une personne décide d'une sanction. */
class BookingAbuseTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private User $lucas;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->service = app(BookingService::class);
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
    }

    private function court(int $n = 1): Court
    {
        return Court::where('name', "Terrain $n")->firstOrFail();
    }

    private function slot(string $time = '19:30', string $date = '2026-10-23'): CarbonImmutable
    {
        return Clock::slotStart($date, $time);
    }

    /** Bloque le créneau puis laisse passer les dix minutes sans payer. */
    private function abandon(User $user, ?array $walkIn = null): void
    {
        $this->service->hold($user, $this->court(), $this->slot(), $walkIn);
        $this->travel(11)->minutes();
    }

    public function test_after_three_abandoned_payments_the_slot_cannot_be_held_again_for_24_hours(): void
    {
        foreach (range(1, 3) as $i) {
            $this->abandon($this->lucas);
        }

        try {
            $this->service->hold($this->lucas, $this->court(), $this->slot());
            $this->fail('Un quatrième blocage a été accepté.');
        } catch (BookingException $e) {
            // 24 h après le premier abandon, celui de 10 h 00.
            $this->assertStringContainsString('le 21/10 à 10:00', $e->getMessage());
        }
        // Le créneau reste libre pour les autres joueurs, et Lucas peut en bloquer un autre : changer de créneau n'est pas un abandon.
        $this->assertSame('pending_payment', $this->service->hold(User::factory()->create(), $this->court(), $this->slot())->status);
        $this->assertSame('pending_payment', $this->service->hold($this->lucas, $this->court(2), $this->slot())->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:01', Clock::ZONE));
        $this->assertSame('pending_payment', $this->service->hold($this->lucas, $this->court(), $this->slot())->status);
    }

    public function test_a_hold_released_by_a_suspension_is_not_an_abandoned_payment(): void
    {
        $this->abandon($this->lucas);
        $this->abandon($this->lucas);
        $this->service->hold($this->lucas, $this->court(), $this->slot());
        // Le club suspend par erreur puis réactive : le blocage libéré par sa décision ne compte pas contre Lucas.
        $status = app(AccountStatusService::class);
        $status->suspend($this->lucas, $this->marc, 'Erreur de saisie.', 7);
        $status->reactivate($this->lucas->fresh(), $this->marc, 'Suspension levée après vérification.');

        $this->assertSame('pending_payment', $this->service->hold($this->lucas->fresh(), $this->court(), $this->slot())->status);
    }

    public function test_the_counter_of_the_club_is_not_limited(): void
    {
        $client = ['walk_in_name' => 'Client du comptoir', 'walk_in_contact' => 'client@example.test'];
        foreach (range(1, 4) as $i) {
            $this->abandon($this->marc, $client);
        }

        $this->assertSame('pending_payment', $this->service->hold($this->marc, $this->court(), $this->slot(), $client)->status);
    }

    public function test_more_than_three_cancellations_in_30_days_flag_the_account_once_without_blocking_it(): void
    {
        $cancel = fn (string $time) => $this->service->cancel($this->service->confirmPayment($this->service->hold($this->lucas, $this->court(), $this->slot($time)), $this->lucas), $this->lucas);
        foreach (['09:00', '10:30', '12:00'] as $time) {
            $cancel($time);
        }
        // Une annulation décidée par le club ne compte pas : elle ne dit rien du joueur.
        $this->service->cancelByClub($this->service->confirmPayment($this->service->hold($this->lucas, $this->court(), $this->slot('13:30')), $this->lucas), $this->marc, 'Terrain fermé pour travaux.');
        $this->assertSame(0, FraudFlag::count());

        $cancel('15:00');
        $flag = FraudFlag::sole();
        $this->assertSame(['repeated_cancellations', 'open', $this->lucas->id], [$flag->type, $flag->status, $flag->user_id]);
        $this->assertSame('4 annulations en 30 jours, dont 0 à moins de 24 h du match.', $flag->details);

        // Un seul signalement par période, et le compte réserve toujours : seule une personne peut le suspendre.
        $cancel('16:30');
        $this->assertSame(1, FraudFlag::count());
        $this->assertSame('active', $this->lucas->fresh()->status);
        $this->assertSame('pending_payment', $this->service->hold($this->lucas, $this->court(), $this->slot('18:00'))->status);
    }

    public function test_partners_who_paid_hear_of_a_cancellation_made_24_hours_before(): void
    {
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $booking = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(), $this->slot()), $this->lucas, [], 'card', [$ines->email]);
        $this->service->payShare($booking, $ines);

        $this->service->cancel($booking, $this->lucas);

        Notification::assertSentTo($ines, MatchCancelled::class, fn (MatchCancelled $n) => str_contains(implode(' ', $n->toMail($ines)->introLines), '24,00 €'));
        Notification::assertNotSentTo($this->lucas, MatchCancelled::class);
    }

    public function test_a_staff_member_cannot_cancel_for_the_club_a_match_he_plays(): void
    {
        $booking = $this->service->confirmPayment($this->service->hold($this->marc, $this->court(), $this->slot()), $this->marc);

        $this->actingAs($this->marc)->get('/admin?day=2026-10-23')->assertInertia(fn ($page) => $page->where('today.0.cancellable', false));
        $this->actingAs($this->marc)->post("/admin/bookings/{$booking->public_id}/cancel", ['reason' => 'Je ne peux plus venir.'])->assertSessionHasErrors('booking');

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_an_alias_of_a_used_address_cannot_open_a_second_account(): void
    {
        $this->assertSame('jeandupont@gmail.com', User::canonicalEmail(' Jean.Dupont+padel@GoogleMail.com '));
        $this->assertSame('lucas.martin@example.test', User::canonicalEmail('lucas.martin+bis@example.test'));
        User::factory()->create(['email' => 'jean.dupont@gmail.com']);

        // Seul le champ e-mail est regardé : les autres manquent exprès.
        $this->post('/register', ['email' => 'lucas.martin+bis@example.test'])->assertSessionHasErrors('email');
        $this->post('/register', ['email' => 'jeandupont+padel@googlemail.com'])->assertSessionHasErrors('email');
        $this->post('/register', ['email' => 'nouvelle.joueuse@example.test'])->assertSessionDoesntHaveErrors('email');
        // Changer l'adresse de son profil vers celle d'un autre compte est refusé de la même façon.
        $this->actingAs(User::factory()->create())->patch('/settings/profile', ['name' => 'Autre', 'email' => 'lucas.martin+x@example.test'])
            ->assertSessionHasErrors('email');

        // Une fois le compte anonymisé, l'adresse est libre.
        $this->lucas->anonymize();
        $this->assertSame("supprime-{$this->lucas->id}@padelbxl.invalid", $this->lucas->fresh()->email_canonical);
    }

    public function test_a_revoked_address_stays_refused_under_an_alias(): void
    {
        BlockedIdentity::create(['email_hash' => BlockedIdentity::hashEmail('fraude@gmail.com'), 'reason' => 'Fraude à la carte']);

        $this->post('/register', ['email' => 'f.raude+2@gmail.com'])
            ->assertSessionHasErrors(['email' => __('Cette adresse ne peut pas être utilisée pour créer un compte.')]);
    }

    public function test_registration_is_limited_per_address(): void
    {
        // Une autre limite par adresse (double authentification, une minute) ne raccourcit pas celle de l'inscription.
        $this->post('/two-factor-challenge', []);
        foreach (range(1, 10) as $i) {
            $this->post('/register', [])->assertSessionHasErrors();
        }

        $this->post('/register', [])->assertStatus(429);
        // Derrière le proxy de Render, chaque joueur a son compteur : son adresse vient de X-Forwarded-For.
        $this->post('/register', [], ['X-Forwarded-For' => '203.0.113.7'])->assertSessionHasErrors();
    }
}
