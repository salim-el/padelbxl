<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Écrans du padel et du foot à 5 : choix du sport, planning, comptoir, accueil et tableau de bord du gérant. */
class SportScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
    }

    public function test_without_a_sport_the_planning_first_asks_what_to_book(): void
    {
        $this->get('/planning')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('planning')
            ->where('sport_chosen', false)
            ->where('sports', ['padel', 'foot5'])
            ->where('minutes', ['padel' => 90, 'foot5' => 60])
            ->where('players', ['padel' => 4, 'foot5' => 10])
            ->where('from_prices', ['padel' => 3360, 'foot5' => 7000]));

        // Le prix « dès » ne compte que les tarifs en vigueur : ni celui qui commence plus tard, ni celui qui est clos.
        PricingRule::create(['name' => 'Promo hiver', 'sport' => 'foot5', 'start_time' => '09:00', 'end_time' => '12:00', 'price_cents' => 5000, 'vat_rate' => 6, 'valid_from' => '2026-12-01']);
        PricingRule::where('sport', 'foot5')->where('name', 'Heures creuses')->update(['valid_until' => '2026-10-19']);
        $this->get('/planning')->assertInertia(fn (Assert $page) => $page->where('from_prices', ['padel' => 3360, 'foot5' => 9000]));
    }

    public function test_a_chosen_sport_a_move_or_a_single_sport_opens_the_planning_directly(): void
    {
        $this->get('/planning?sport=foot5')->assertInertia(fn (Assert $page) => $page->where('sport_chosen', true)->where('sport', 'foot5'));

        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($service->hold($lucas, Court::where('name', 'Foot 1')->firstOrFail(), Clock::slotStart('2026-10-23', '17:00')), $lucas);
        $this->actingAs($lucas)->get("/planning?date=2026-10-24&move={$booking->public_id}")
            ->assertInertia(fn (Assert $page) => $page->where('sport_chosen', true)->where('sport', 'foot5'));

        // Sans terrain de foot actif, il n'y a rien à choisir : le planning du padel s'ouvre.
        Court::where('sport', 'foot5')->update(['is_active' => false]);
        $this->get('/planning')->assertInertia(fn (Assert $page) => $page->where('sport_chosen', true)->where('sport', 'padel'));
    }

    public function test_the_counter_offers_the_start_times_of_each_sport(): void
    {
        $this->actingAs($this->marc)->get('/admin/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/counter')
            ->where('courts', fn ($courts) => $courts->pluck('sport')->countBy()->all() === ['padel' => 6, 'foot5' => 4])
            ->where('slots.padel', ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30', '18:00', '19:30', '21:00'])
            ->where('slots.foot5', array_map(fn (int $h) => sprintf('%02d:00', $h), range(9, 22))));
    }

    public function test_the_counter_books_a_football_court_at_five_pm_at_the_peak_price(): void
    {
        $court = Court::where('name', 'Foot 1')->firstOrFail();
        $book = fn (string $time) => $this->actingAs($this->marc)->post('/admin/counter', [
            'court_id' => $court->id, 'date' => '2026-10-23', 'time' => $time,
            'name' => 'Sofia Janssens', 'contact' => '0470 12 34 56', 'method' => 'cash',
        ]);

        // 10:30 est une heure du padel : refusée sur un terrain de foot, rien n'est encaissé.
        $book('10:30')->assertSessionHasErrors('booking');
        $this->assertSame(0, Booking::whereNotNull('walk_in_name')->count());

        $book('17:00')->assertSessionHasNoErrors()->assertRedirect();
        $booking = Booking::where('walk_in_name', 'Sofia Janssens')->sole();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(9000, $booking->price_cents);
        $this->assertSame('18:00', Clock::local($booking->ends_at)->format('H:i'));
    }

    public function test_home_and_dashboard_give_the_players_and_the_slot_length_of_each_sport(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('prices', fn ($prices) => $prices->every(fn (array $p) => [$p['players'], $p['minutes']] === ($p['sport'] === 'padel' ? [4, 90] : [10, 60]))));

        $this->actingAs($this->marc)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('minutes', ['padel' => 90, 'foot5' => 60])
            ->where('players', ['padel' => 4, 'foot5' => 10]));
    }
}
