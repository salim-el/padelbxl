<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\User;
use App\Notifications\ShareInvitation;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $ines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
        $this->ines = User::where('email', 'ines.laurent@example.test')->first();
    }

    private function share(array $guests, array $extra = ['accept_guarantee' => '1'], string $day = '2026-10-23')
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart($day, '19:30'));
        $response = $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'guests' => $guests, ...$extra]);

        return [$booking->refresh(), $response];
    }

    public function test_the_organizer_pays_only_his_share_and_each_partner_is_invited(): void
    {
        [$booking] = $this->share(['Ines.Laurent@example.test', 'marc.dumont@example.test', 'nouveau@example.test']);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(1200, (int) DB::table('payments')->where('booking_id', $booking->id)->sum('amount_cents'));
        $players = $booking->players()->orderBy('id')->get();
        $this->assertSame([1200, 1200, 1200, 1200], $players->pluck('share_cents')->all());
        $this->assertSame(['paid', 'invited', 'invited', 'invited'], $players->pluck('status')->all());
        // L'adresse sans compte est gardée telle quelle, celle d'un membre est reliée à son compte.
        $this->assertSame($this->ines->id, $players[1]->user_id);
        $this->assertSame('nouveau@example.test', $players[3]->guest_email);
        Notification::assertSentTo($this->ines, ShareInvitation::class);
        Notification::assertSentOnDemand(ShareInvitation::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'nouveau@example.test');
    }

    public function test_the_cents_that_do_not_divide_stay_with_the_organizer(): void
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $booking->update(['price_cents' => 4801]);
        app(BookingService::class)->confirmPayment($booking, $this->lucas, guests: ['a@example.test', 'b@example.test', 'c@example.test']);

        $this->assertSame([1201, 1200, 1200, 1200], $booking->players()->orderBy('id')->pluck('share_cents')->all());
        $this->assertSame(4801, (int) $booking->players()->sum('share_cents'));
    }

    public function test_an_invited_member_pays_his_share_and_gets_his_own_invoice_and_qr_code(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test']);

        $this->actingAs($this->ines)->get("/bookings/{$booking->public_id}/share")->assertOk();
        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1'])->assertSessionHasNoErrors();

        $player = $booking->players()->where('user_id', $this->ines->id)->first();
        $this->assertSame('paid', $player->status);
        $this->assertNotNull($player->accessPass);
        $invoice = DB::table('invoices')->join('payments', 'payments.id', '=', 'invoices.payment_id')->where('payments.payer_id', $this->ines->id)->first();
        $this->assertSame(2400, $invoice->total_cents);
        $this->assertSame('Inès Laurent', $invoice->customer_name);

        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1'])->assertSessionHasErrors('booking');
        $this->assertSame(1, DB::table('payments')->where('payer_id', $this->ines->id)->count());
    }

    public function test_an_invitation_sent_before_the_account_exists_is_claimed_by_that_email(): void
    {
        [$booking] = $this->share(['nouveau@example.test']);
        $newcomer = User::factory()->create(['email' => 'nouveau@example.test']);

        $this->actingAs($newcomer)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($newcomer->id, $booking->players()->where('is_organizer', false)->value('user_id'));
        // L'organisateur revoit l'adresse qu'il a tapée, même si le compte change d'adresse ensuite.
        $newcomer->update(['email' => 'autre-adresse@example.test']);
        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}")->assertInertia(fn ($page) => $page->where('players.1.name', 'nouveau@example.test'));
    }

    public function test_a_player_who_is_not_invited_gets_a_404(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/bookings/{$booking->public_id}/share")->assertNotFound();
        $this->actingAs($stranger)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1'])->assertNotFound();
    }

    public function test_the_guarantee_charges_the_organizer_for_unpaid_shares_24_hours_before(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test', 'marc.dumont@example.test', 'nouveau@example.test']);
        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);

        $this->artisan('bookings:charge-unpaid-shares');
        $this->assertSame(2400, (int) DB::table('payments')->where('booking_id', $booking->id)->sum('amount_cents'), 'Trop tôt : personne ne paie à la place des invités.');

        $this->travelTo(CarbonImmutable::parse('2026-10-22 19:35', Clock::ZONE));
        $this->artisan('bookings:charge-unpaid-shares');
        $this->artisan('bookings:charge-unpaid-shares');

        $this->assertSame(4800, (int) DB::table('payments')->where('booking_id', $booking->id)->sum('amount_cents'));
        $this->assertSame(3600, (int) DB::table('payments')->where('booking_id', $booking->id)->where('payer_id', $this->lucas->id)->sum('amount_cents'));
        $this->assertSame(['paid', 'paid', 'covered', 'covered'], $booking->players()->orderBy('id')->pluck('status')->all());
        $this->assertSame(4, DB::table('access_passes')->whereIn('booking_player_id', $booking->players()->pluck('id'))->count());
    }

    public function test_sharing_is_refused_less_than_24_hours_before_and_nothing_is_charged(): void
    {
        [$booking, $response] = $this->share(['ines.laurent@example.test'], day: '2026-10-20');

        $response->assertSessionHasErrors('booking');
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame(0, DB::table('payments')->where('booking_id', $booking->id)->count());
        $this->assertSame(1, $booking->players()->count());
        Notification::assertNothingSent();
    }

    public function test_sharing_requires_the_guarantee_and_at_most_three_partners(): void
    {
        [$booking, $response] = $this->share(['ines.laurent@example.test'], extra: []);
        $response->assertSessionHasErrors('accept_guarantee');
        $this->assertSame('pending_payment', $booking->status);

        $response = $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test']]);
        $response->assertSessionHasErrors('guests');

        $response = $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => ['lucas.martin@example.test']]);
        $response->assertSessionHasErrors('booking');
    }

    public function test_cancelling_more_than_24_hours_before_refunds_every_share(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test', 'marc.dumont@example.test']);
        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);

        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/cancel");

        $this->assertSame('cancelled_refunded', $booking->refresh()->status);
        $this->assertSame(2, DB::table('refunds')->count());
        $this->assertSame(0, DB::table('access_passes')->whereIn('booking_player_id', $booking->players()->pluck('id'))->whereNull('revoked_at')->count());
        // Une partie annulée n'accepte plus de part, et la garantie ne la débite pas.
        $marc = User::where('email', 'marc.dumont@example.test')->first();
        $this->actingAs($marc)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1'])->assertSessionHasErrors('booking');
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', Clock::ZONE));
        $this->artisan('bookings:charge-unpaid-shares');
        $this->assertSame(2, DB::table('payments')->where('booking_id', $booking->id)->count());
    }

    public function test_an_organizer_cannot_send_more_than_twelve_invitations_a_day(): void
    {
        [$first] = $this->share(['a@example.test', 'b@example.test', 'c@example.test']);
        foreach (range(1, 9) as $i) {
            DB::table('booking_players')->insert(['booking_id' => $first->id, 'guest_email' => "extra$i@example.test", 'is_organizer' => false, 'share_cents' => 100, 'status' => 'invited']);
        }

        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 2')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => ['d@example.test']])
            ->assertSessionHasErrors('booking');
        $this->assertSame('pending_payment', $booking->refresh()->status);
    }

    public function test_an_unconfirmed_account_cannot_claim_an_invitation_sent_to_its_address(): void
    {
        [$booking] = $this->share(['nouveau@example.test']);
        $impostor = User::factory()->unverified()->create(['email' => 'nouveau@example.test']);

        $this->actingAs($impostor)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);

        $this->assertNull($booking->players()->where('is_organizer', false)->value('user_id'));
        $this->assertSame(0, DB::table('payments')->where('payer_id', $impostor->id)->count());
        $this->actingAs($impostor)->get('/bookings')->assertRedirect(route('verification.notice'));
    }

    public function test_an_invitation_is_not_linked_to_an_account_whose_email_is_not_confirmed(): void
    {
        $impostor = User::factory()->unverified()->create(['email' => 'cible@example.test']);
        [$booking] = $this->share(['cible@example.test']);

        $guest = $booking->players()->where('is_organizer', false)->first();
        $this->assertNull($guest->user_id);
        $this->assertSame('cible@example.test', $guest->guest_email);
        $this->actingAs($impostor)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);
        $this->assertSame(0, DB::table('payments')->where('payer_id', $impostor->id)->count());
    }

    public function test_the_organizer_sees_the_address_he_typed_not_the_partner_account_name(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test']);

        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}")->assertInertia(fn ($page) => $page
            ->where('players.0.name', 'Lucas Martin')->where('players.1.name', 'ines.laurent@example.test'));
    }

    public function test_markdown_in_the_organizer_name_does_not_reach_the_invitation_email(): void
    {
        $this->lucas->update(['name' => '[Gagnez](http://piege.test) **vite**']);
        $this->share(['ines.laurent@example.test']);

        Notification::assertSentTo($this->ines, ShareInvitation::class, function ($n) {
            $mail = $n->toMail($this->ines);
            $text = $mail->subject.' '.implode(' ', $mail->introLines);

            return ! str_contains($text, '](') && ! str_contains($text, '**') && str_contains($text, 'Gagnez');
        });
    }

    public function test_the_organizer_name_keeps_only_letters_in_the_invitation_email(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test']);
        $mail = (new ShareInvitation($booking, 'Banque : appelez le 0800 12 34 ou https://piege.test', 1200))->toMail($this->ines);

        $this->assertDoesNotMatchRegularExpression('/[0-9:\/.]/', $mail->subject);
        $this->assertStringStartsWith('Banque appelez le ou https piege test', $mail->subject);
        $this->assertSame('PadelBxl vous invite à une partie de padel', (new ShareInvitation($booking, '12345', 1200))->toMail($this->ines)->subject);
    }

    public function test_a_late_cancellation_charges_the_unpaid_shares_without_waiting_for_the_schedule(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test', 'autre@example.test']);
        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);

        // Moins de 24 h avant, mais la tâche planifiée n'est pas encore passée.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00', Clock::ZONE));
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/cancel");

        $this->assertSame('cancelled_unrefunded', $booking->refresh()->status);
        $this->assertSame(4800, (int) DB::table('payments')->where('booking_id', $booking->id)->sum('amount_cents'));
        $this->assertSame(['paid', 'paid', 'covered'], $booking->players()->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, DB::table('refunds')->count());
    }

    public function test_the_organizer_does_not_see_or_download_a_partner_invoice(): void
    {
        $this->ines->forceFill(['account_type' => 'company', 'company_name' => 'Laurent SRL', 'vat_number' => 'BE0123456749'])->save();
        [$booking] = $this->share(['ines.laurent@example.test']);
        $this->actingAs($this->ines)->post("/bookings/{$booking->public_id}/share", ['accept_terms' => '1']);
        $partnerInvoice = DB::table('invoices')->join('payments', 'payments.id', '=', 'invoices.payment_id')->where('payments.payer_id', $this->ines->id)->value('number');

        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}")->assertInertia(fn ($page) => $page->has('invoices', 1));
        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}/invoices/{$partnerInvoice}/ubl")->assertNotFound();
    }

    public function test_invited_games_are_listed_on_the_partner_bookings_page(): void
    {
        [$booking] = $this->share(['ines.laurent@example.test']);

        $this->actingAs($this->ines)->get('/bookings')->assertInertia(fn ($page) => $page
            ->has('invitations', 1)->where('invitations.0.id', $booking->public_id)->where('invitations.0.share_cents', 2400));
    }
}
