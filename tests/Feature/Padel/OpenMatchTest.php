<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\SpotAvailable;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OpenMatchTest extends TestCase
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

    /** Lucas paie le terrain 1 du 23/10 à 19 h 30 (48 €) en ouvrant des places. */
    private function open(int $spots, array $guests = [], array $extra = ['accept_guarantee' => '1']): Booking
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'guests' => $guests, 'open_spots' => $spots, ...$extra]);

        return $booking->refresh();
    }

    public function test_open_spots_share_the_price_and_a_member_takes_one_by_paying_his_share(): void
    {
        $booking = $this->open(2, ['marc.dumont@example.test']);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame([1200, 1200, 1200, 1200], $booking->players()->orderBy('id')->pluck('share_cents')->all());
        $this->assertSame(1200, (int) DB::table('payments')->where('booking_id', $booking->id)->sum('amount_cents'));

        $this->actingAs($this->ines)->get('/open-matches')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('open-matches/index')->has('matches', 1)
            ->where('matches.0.free', 2)->where('matches.0.share_cents', 1200)->where('matches.0.joined', false));

        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])
            ->assertRedirect("/bookings/{$booking->public_id}/share");
        $spot = $booking->players()->where('user_id', $this->ines->id)->first();
        $this->assertSame('paid', $spot->status);
        $this->assertNull($spot->guest_email);
        $this->assertNotNull($spot->accessPass);
        $this->assertSame(1200, (int) DB::table('payments')->where('payer_id', $this->ines->id)->sum('amount_cents'));
        // Le joueur venu par la liste ne voit que le prénom de l'organisateur.
        $this->actingAs($this->ines)->get("/bookings/{$booking->public_id}/share")->assertInertia(fn (AssertableInertia $page) => $page->where('organizer', 'Lucas'));
        // Rejoindre deux fois ne prend pas une seconde place.
        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertSessionHasErrors('booking');
        $this->assertSame(1, $booking->players()->whereNull('user_id')->whereNull('guest_email')->count());
    }

    public function test_the_list_shows_no_name_nor_email_of_the_players(): void
    {
        $booking = $this->open(1, ['marc.dumont@example.test']);

        $json = json_encode($this->actingAs($this->ines)->get('/open-matches')->viewData('page')['props']['matches']);
        $this->assertStringNotContainsString('marc.dumont', $json);
        $this->assertStringNotContainsString('Lucas', $json);
        $this->assertStringContainsString($booking->public_id, $json);
        // L'organisateur ne voit pas sa propre partie dans la liste.
        $this->actingAs($this->lucas)->get('/open-matches')->assertInertia(fn (AssertableInertia $page) => $page->has('matches', 0));
    }

    public function test_a_full_match_offers_the_waitlist_and_a_declined_invitation_frees_a_spot(): void
    {
        $guest = User::factory()->create(['email' => 'invite@example.test']);
        $booking = $this->open(1, ['invite@example.test']);
        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1']);
        $waiting = User::factory()->create();

        $this->actingAs($waiting)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertSessionHasErrors('booking');
        $this->actingAs($waiting)->post("/open-matches/{$booking->public_id}/waitlist")->assertSessionHasNoErrors();
        $this->assertTrue(WaitlistEntry::where('booking_id', $booking->id)->where('user_id', $waiting->id)->exists());

        $this->actingAs($guest)->post("/bookings/{$booking->public_id}/share/decline")->assertRedirect('/bookings');
        Notification::assertSentTo($waiting, SpotAvailable::class);
        Notification::assertNotSentTo($this->ines, SpotAvailable::class);
        $this->actingAs($guest)->get("/bookings/{$booking->public_id}/share")->assertNotFound();

        $this->actingAs($waiting)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $booking->players()->where('user_id', $waiting->id)->value('status'));
        $this->assertFalse(WaitlistEntry::where('booking_id', $booking->id)->exists());
    }

    public function test_the_waitlist_is_refused_while_a_spot_is_still_free_and_can_be_left(): void
    {
        $booking = $this->open(1);

        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/waitlist")->assertSessionHasErrors('booking');
        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1']);
        $other = User::factory()->create();
        $this->actingAs($other)->post("/open-matches/{$booking->public_id}/waitlist");
        $this->actingAs($other)->delete("/open-matches/{$booking->public_id}/waitlist")->assertSessionHasNoErrors();
        $this->assertFalse(WaitlistEntry::where('booking_id', $booking->id)->exists());
    }

    public function test_the_organizer_an_invited_player_and_an_unverified_account_cannot_take_a_spot(): void
    {
        $booking = $this->open(1, ['ines.laurent@example.test']);
        $unverified = User::factory()->create(['email_verified_at' => null]);

        foreach ([$this->lucas, $this->ines] as $user) {
            $this->actingAs($user)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertSessionHasErrors('booking');
        }
        // Un compte sans adresse confirmée est arrêté avant le contrôleur et renvoyé vers la confirmation.
        $this->actingAs($unverified)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertRedirect(route('verification.notice'));
        $this->assertSame(1, $booking->players()->whereNull('user_id')->whereNull('guest_email')->count());
    }

    public function test_at_24_hours_the_guarantee_pays_the_empty_spot_and_the_match_leaves_the_list(): void
    {
        $booking = $this->open(1);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 19:35', Clock::ZONE));

        $this->actingAs($this->ines)->get('/open-matches')->assertInertia(fn (AssertableInertia $page) => $page->has('matches', 0));
        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1'])->assertSessionHasErrors('booking');

        $this->assertSame(1, app(BookingService::class)->chargeUnpaidShares());
        $this->assertSame(4800, (int) DB::table('payments')->where('payer_id', $this->lucas->id)->sum('amount_cents'));
        $this->assertSame('covered', $booking->players()->whereNull('guest_email')->where('is_organizer', false)->value('status'));
    }

    public function test_open_spots_need_the_guarantee_and_never_exceed_three_other_players(): void
    {
        $booking = $this->open(1, [], []);
        $this->assertSame('pending_payment', $booking->status);

        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'guests' => ['a@example.test', 'b@example.test'], 'open_spots' => 2, 'accept_guarantee' => '1'])
            ->assertSessionHasErrors('booking');
        $this->assertSame('pending_payment', $booking->refresh()->status);
        $this->assertSame(0, DB::table('payments')->where('booking_id', $booking->id)->count());
    }

    public function test_the_organizer_sees_open_and_taken_spots_without_the_name_of_the_member(): void
    {
        $booking = $this->open(2);
        $this->actingAs($this->ines)->post("/open-matches/{$booking->public_id}/join", ['accept_terms' => '1']);

        $players = $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}")->viewData('page')['props']['players'];
        $this->assertCount(3, $players);
        $spots = array_slice($players, 1);
        $this->assertSame([true, true], array_column($spots, 'open'));
        $this->assertSame([null, null], array_column($spots, 'name'));
        $this->assertEqualsCanonicalizing([true, false], array_column($spots, 'taken'));
        $this->assertStringNotContainsString('Inès', json_encode($players));
    }
}
