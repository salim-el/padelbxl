<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Notifications\MatchCall;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\MatchCallService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** F11 : appel envoyé aux joueurs d'un niveau proche, libres sur le créneau. */
class MatchCallTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->lucas->update(['level' => 4]);
    }

    private function player(string $name, ?int $level, array $extra = []): User
    {
        return User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test', 'level' => $level, ...$extra]);
    }

    private function open(array $levels = ['level_min' => 3, 'level_max' => 5]): Booking
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'open_spots' => 2, 'accept_guarantee' => '1', ...$levels])
            ->assertSessionHasNoErrors();

        return $booking->refresh();
    }

    public function test_players_of_a_close_level_receive_the_call(): void
    {
        $sara = $this->player('Sara Close', 4);
        $tom = $this->player('Tom Edge', 5);
        $far = $this->player('Bob Far', 7);
        $unknown = $this->player('Ann Unknown', null);

        $booking = $this->open();

        $this->assertSame([3, 5], [$booking->level_min, $booking->level_max]);
        Notification::assertSentTo([$sara, $tom], MatchCall::class);
        Notification::assertNotSentTo([$far, $unknown, $this->lucas], MatchCall::class);
        $this->actingAs($sara)->get('/open-matches')->assertInertia(fn (AssertableInertia $page) => $page->where('matches.0.level', '3–5'));
    }

    public function test_players_busy_on_that_slot_staff_and_suspended_accounts_are_skipped(): void
    {
        $busy = $this->player('Busy Player', 4);
        $staff = $this->player('Staff Member', 4, ['role' => 'manager']);
        $suspended = $this->player('Sus Pended', 4, ['status' => 'suspended']);
        $free = $this->player('Free Player', 4);
        // Busy joue déjà à 19 h 30 sur un autre terrain.
        $other = app(BookingService::class)->hold($busy, Court::where('name', 'Terrain 2')->first(), Clock::slotStart('2026-10-23', '19:30'));
        app(BookingService::class)->confirmPayment($other, $busy);

        $this->open();

        Notification::assertSentTo($free, MatchCall::class);
        Notification::assertNotSentTo([$busy, $staff, $suspended], MatchCall::class);
    }

    public function test_the_level_is_required_and_ordered(): void
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'open_spots' => 1, 'accept_guarantee' => '1'])
            ->assertSessionHasErrors(['level_min', 'level_max']);
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'open_spots' => 1, 'accept_guarantee' => '1', 'level_min' => 5, 'level_max' => 3])
            ->assertSessionHasErrors('level_max');
        $this->assertSame('pending_payment', $booking->refresh()->status);
        // Sans place ouverte, aucun niveau n'est demandé.
        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($booking->refresh()->level_min);
    }

    public function test_the_call_goes_to_twenty_players_at_most_closest_level_first(): void
    {
        foreach (range(1, MatchCallService::MAX_RECIPIENTS) as $i) {
            $this->player("Edge Player$i", 3);
        }
        $centre = $this->player('Centre Player', 4);

        $this->open();

        Notification::assertSentTo($centre, MatchCall::class);
        Notification::assertSentTimes(MatchCall::class, MatchCallService::MAX_RECIPIENTS);
    }
}
