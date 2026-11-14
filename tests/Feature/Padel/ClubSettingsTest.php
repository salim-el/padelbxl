<?php

namespace Tests\Feature\Padel;

use App\Models\ClubSetting;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F24 : heures d'ouverture et durée des créneaux réglées par le gérant. */
class ClubSettingsTest extends TestCase
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

    public function test_default_hours_give_the_nine_slots_of_ninety_minutes(): void
    {
        $this->assertSame(['09:00', '10:30', '12:00', '13:30', '15:00', '16:30', '18:00', '19:30', '21:00'], Clock::slots());
        $this->assertSame(90, Clock::slotMinutes());
    }

    public function test_new_hours_change_the_planning(): void
    {
        $this->actingAs($this->marc)->put('/admin/settings', ['opens_at' => '08:00', 'closes_at' => '22:00', 'slot_minutes' => 60])->assertSessionHasNoErrors();

        $this->assertSame(14, count(ClubSetting::firstOrFail()->slots()));
        $this->actingAs($this->marc)->get('/planning?date=2026-11-20')->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'club-settings-updated']);
    }

    public function test_hours_that_would_strand_an_upcoming_booking_are_refused(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->first();
        $service = app(BookingService::class);
        $service->confirmPayment($service->hold($lucas, Court::first(), Clock::slotStart('2026-11-20', '21:00')), $lucas);

        // 21 h – 22 h 30 ne tient plus si le club ferme à 22 h.
        $this->actingAs($this->marc)->put('/admin/settings', ['opens_at' => '09:00', 'closes_at' => '22:00', 'slot_minutes' => 90])->assertSessionHasErrors('settings');
        $this->actingAs($this->marc)->put('/admin/settings', ['opens_at' => '22:00', 'closes_at' => '22:30', 'slot_minutes' => 60])->assertSessionHasErrors('settings');
        $this->assertSame('23:00:00', ClubSetting::firstOrFail()->closes_at);
    }

    public function test_players_cannot_change_hours(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->first();

        $this->actingAs($lucas)->put('/admin/settings', ['opens_at' => '06:00', 'closes_at' => '23:00', 'slot_minutes' => 60])->assertForbidden();
    }
}
