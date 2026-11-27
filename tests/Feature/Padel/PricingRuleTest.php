<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F24 : créer, programmer et clôturer un tarif ; un tarif ne change jamais une réservation payée. */
class PricingRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
    }

    private function rule(array $override = [])
    {
        return $this->actingAs($this->marc)->post('/admin/pricing-rules', $override + [
            'name' => 'Heures creuses hiver', 'start_time' => '09:00', 'end_time' => '17:00',
            'price_cents' => 3000, 'vat_rate' => 6, 'valid_from' => '2026-12-01',
        ]);
    }

    public function test_a_scheduled_rule_applies_from_its_date_and_keeps_paid_bookings_unchanged(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->first();
        $service = app(BookingService::class);
        $paid = $service->confirmPayment($service->hold($lucas, Court::first(), Clock::slotStart('2026-12-03', '10:30')), $lucas);

        $this->rule()->assertSessionHasNoErrors();

        $slots = app(SlotService::class);
        $this->assertSame(3360, $slots->priceFor(Clock::slotStart('2026-11-30', '10:30'))->price_cents);
        $this->assertSame(3000, $slots->priceFor(Clock::slotStart('2026-12-01', '10:30'))->price_cents);
        // Les heures pleines ne sont pas touchées.
        $this->assertSame(4800, $slots->priceFor(Clock::slotStart('2026-12-01', '18:00'))->price_cents);
        $this->assertSame(3360, $paid->fresh()->price_cents);
        $this->assertDatabaseHas('activity_log', ['event' => 'price-rule-created', 'causer_id' => $this->marc->id]);
    }

    public function test_invalid_rules_are_refused(): void
    {
        $this->rule(['valid_from' => '2026-11-15'])->assertSessionHasErrors('valid_from');
        $this->rule(['start_time' => '08:00'])->assertSessionHasErrors('start_time');
        $this->rule(['end_time' => '08:59'])->assertSessionHasErrors('end_time');
        $this->rule(['vat_rate' => 12])->assertSessionHasErrors('vat_rate');
        $this->rule()->assertSessionHasNoErrors();
        // Même jour de départ, heures communes : ambigu, refusé.
        $this->rule(['name' => 'Midi', 'start_time' => '12:00', 'end_time' => '14:00'])->assertSessionHasErrors('pricing');
        // Les 4 tarifs du club (2 de padel, 2 de foot) et le nouveau.
        $this->assertSame(5, PricingRule::count());
    }

    public function test_a_closed_rule_no_longer_prices_slots_after_its_last_day(): void
    {
        $offPeak = PricingRule::where('name', 'Heures creuses')->first();

        $this->actingAs($this->marc)->post("/admin/pricing-rules/{$offPeak->id}/close", ['valid_until' => '2026-11-15'])->assertSessionHasErrors('valid_until');
        $this->actingAs($this->marc)->post("/admin/pricing-rules/{$offPeak->id}/close", ['valid_until' => '2026-11-30'])->assertSessionHasNoErrors();

        $slots = app(SlotService::class);
        $this->assertNotNull($slots->priceFor(Clock::slotStart('2026-11-30', '10:30')));
        $this->assertNull($slots->priceFor(Clock::slotStart('2026-12-01', '10:30')));
    }

    public function test_players_cannot_manage_rules(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->first();

        $this->actingAs($lucas)->post('/admin/pricing-rules', ['name' => 'Gratuit'])->assertForbidden();
        $this->actingAs($lucas)->post('/admin/pricing-rules/'.PricingRule::first()->id.'/close', ['valid_until' => '2026-12-01'])->assertForbidden();
    }
}
