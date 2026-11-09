<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSlotTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-23 16:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
    }

    public function test_a_free_slot_less_than_3_hours_ahead_costs_20_percent_less(): void
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '18:00'));
        $this->assertSame(3840, $booking->price_cents);
    }

    public function test_a_slot_further_away_keeps_its_price(): void
    {
        $booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30'));
        $this->assertSame(4800, $booking->price_cents);
    }

    public function test_the_planning_flags_flash_slots(): void
    {
        $data = app(SlotService::class)->forDate('2026-10-23');
        $row = collect($data['rows'])->firstWhere('time', '18:00');
        $this->assertTrue($row['cells'][0]['flash']);
        $this->assertSame(3840, $row['cells'][0]['price_cents']);
        $this->assertFalse(collect($data['rows'])->firstWhere('time', '19:30')['cells'][0]['flash']);
    }
}
