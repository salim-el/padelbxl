<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourtManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
    }

    private function court(int $n): Court
    {
        return Court::where('name', "Terrain $n")->first();
    }

    public function test_the_manager_changes_a_price_and_new_bookings_follow_it(): void
    {
        $rule = PricingRule::where('name', 'Heures pleines')->first();
        $this->actingAs($this->marc)->patch("/admin/pricing-rules/{$rule->id}", ['price_cents' => 5000])->assertSessionHasNoErrors();
        $booking = app(BookingService::class)->hold($this->lucas, $this->court(1), Clock::slotStart('2026-10-23', '19:30'));
        $this->assertSame(5000, $booking->price_cents);
    }

    public function test_players_cannot_change_prices(): void
    {
        $rule = PricingRule::where('name', 'Heures pleines')->first();
        $this->actingAs($this->lucas)->patch("/admin/pricing-rules/{$rule->id}", ['price_cents' => 100])->assertForbidden();
        $this->assertSame(4800, $rule->refresh()->price_cents);
    }

    public function test_a_closed_court_cannot_be_booked_and_shows_as_closed(): void
    {
        $this->actingAs($this->marc)->post('/admin/closures', ['court_id' => $this->court(5)->id, 'from' => '2026-10-23T17:00', 'to' => '2026-10-23T23:00', 'reason' => 'Vitre à remplacer'])->assertSessionHasNoErrors();
        $row = collect(app(SlotService::class)->forDate('2026-10-23')['rows'])->firstWhere('time', '19:30');
        $this->assertTrue($row['cells'][4]['closed']);
        $this->assertFalse($row['cells'][4]['available']);
        $this->expectException(BookingException::class);
        app(BookingService::class)->hold($this->lucas, $this->court(5), Clock::slotStart('2026-10-23', '19:30'));
    }

    public function test_a_closure_over_an_active_booking_is_refused(): void
    {
        app(BookingService::class)->hold($this->lucas, $this->court(2), Clock::slotStart('2026-10-23', '19:30'));
        $this->actingAs($this->marc)->post('/admin/closures', ['court_id' => $this->court(2)->id, 'from' => '2026-10-23T19:00', 'to' => '2026-10-23T22:00', 'reason' => 'Panne'])->assertSessionHasErrors('closure');
        $this->assertSame(0, CourtClosure::count());
    }

    public function test_reopening_a_court_makes_it_bookable_again(): void
    {
        $this->actingAs($this->marc)->post('/admin/closures', ['court_id' => $this->court(3)->id, 'from' => '2026-10-23T09:00', 'to' => '2026-10-23T23:00', 'reason' => 'Entretien']);
        $this->actingAs($this->marc)->delete('/admin/closures/'.CourtClosure::first()->id)->assertSessionHasNoErrors();
        $this->assertSame('pending_payment', app(BookingService::class)->hold($this->lucas, $this->court(3), Clock::slotStart('2026-10-23', '19:30'))->status);
    }
}
