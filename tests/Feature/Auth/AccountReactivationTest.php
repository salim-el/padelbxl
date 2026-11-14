<?php

namespace Tests\Feature\Auth;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Notifications\ReactivateAccount;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountReactivationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'lucas.martin@example.test';

    /** Lucas a joué le 23/10 puis a désactivé son compte le 25/10 à 10 h. */
    private function deactivatedPlayer(): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $player = User::where('email', self::EMAIL)->first();
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($service->hold($player, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30')), $player);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));
        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD]);
        auth()->logout();

        return [$player, $booking];
    }

    public function test_the_page_is_reachable_from_the_login_screen(): void
    {
        $this->get('/reactivate-account')->assertOk();
    }

    public function test_a_deactivated_player_receives_a_link_and_gets_his_account_back_with_its_history(): void
    {
        Notification::fake();
        [$player, $booking] = $this->deactivatedPlayer();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', Clock::ZONE));

        $this->withoutDefer()->post('/reactivate-account', ['email' => 'Lucas.Martin@example.test'])->assertSessionHas('status');

        $link = null;
        Notification::assertSentTo(User::withTrashed()->find($player->id), ReactivateAccount::class, function ($n, $channels, $notifiable) use (&$link) {
            $link = $n->toMail($notifiable)->actionUrl;

            return true;
        });
        $this->get($link)->assertRedirect('/login');

        $back = User::find($player->id);
        $this->assertNotNull($back);
        $this->assertSame('active', $back->status);
        $this->assertSame($player->id, Booking::find($booking->id)->organizer_id);
        $this->post('/login', ['email' => self::EMAIL, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->assertAuthenticatedAs($back);
    }

    public function test_the_link_works_only_once(): void
    {
        [$player] = $this->deactivatedPlayer();
        $link = URL::temporarySignedRoute('account.reactivate', now()->addHour(), ['user' => $player->id]);

        $this->get($link)->assertRedirect('/login');
        $this->get($link)->assertNotFound();
    }

    public function test_a_forged_or_expired_link_is_refused(): void
    {
        [$player] = $this->deactivatedPlayer();
        $link = URL::temporarySignedRoute('account.reactivate', now()->addHour(), ['user' => $player->id]);

        $this->get("/reactivate-account/{$player->id}")->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($link)->assertForbidden();
        $this->assertSoftDeleted($player, deletedAtColumn: 'deactivated_at');
    }

    public function test_no_link_after_thirty_days_nor_for_an_active_or_unknown_account_and_the_answer_stays_the_same(): void
    {
        Notification::fake();
        [$player] = $this->deactivatedPlayer();

        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:01', Clock::ZONE));
        $answers = collect([self::EMAIL, 'ines.laurent@example.test', 'personne@example.test'])
            ->map(fn ($email) => $this->withoutDefer()->post('/reactivate-account', ['email' => $email])->getSession()->get('status'));

        Notification::assertNothingSent();
        $this->assertCount(1, $answers->unique());
        $this->assertNotNull($answers->first());

        // Trente jours passés : même un lien signé ne rend plus le compte.
        $link = URL::temporarySignedRoute('account.reactivate', now()->addHour(), ['user' => $player->id]);
        $this->get($link)->assertNotFound();
    }

    public function test_requests_are_rate_limited(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (range(1, 3) as $i) {
            $this->post('/reactivate-account', ['email' => self::EMAIL])->assertRedirect();
        }
        $this->post('/reactivate-account', ['email' => self::EMAIL])->assertStatus(429);
    }
}
