<?php

namespace Tests\Feature\Settings;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithBooking(string $day): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $player = User::where('email', 'lucas.martin@example.test')->first();
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($service->hold($player, Court::where('name', 'Terrain 1')->first(), Clock::slotStart($day, '19:30')), $player);

        return [$player, $booking];
    }

    public function test_a_member_with_past_bookings_is_soft_deleted_and_keeps_his_invoices(): void
    {
        [$player, $booking] = $this->playerWithBooking('2026-10-23');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));

        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect('/');

        $this->assertGuest();
        $this->assertSoftDeleted($player, deletedAtColumn: 'deactivated_at');
        $this->assertSame('deactivated', User::withTrashed()->find($player->id)->status);
        $this->assertSame($player->id, Booking::find($booking->id)->organizer->id);
        $this->assertTrue(DB::table('invoices')->where('booking_id', $booking->id)->exists());
    }

    public function test_a_member_must_cancel_upcoming_bookings_first(): void
    {
        [$player] = $this->playerWithBooking('2026-10-23');

        $this->actingAs($player)->from('/settings/profile')->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD])
            ->assertSessionHasErrors('password')->assertRedirect('/settings/profile');

        $this->assertNotSoftDeleted($player, deletedAtColumn: 'deactivated_at');
    }

    public function test_a_deleted_member_can_no_longer_log_in(): void
    {
        [$player] = $this->playerWithBooking('2026-10-23');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));
        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD]);

        $this->post('/login', ['email' => 'lucas.martin@example.test', 'password' => DatabaseSeeder::DEMO_PASSWORD]);

        $this->assertGuest();
    }

    public function test_accounts_are_anonymized_thirty_days_after_deletion_only(): void
    {
        [$player, $booking] = $this->playerWithBooking('2026-10-23');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));
        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD]);
        $name = DB::table('invoices')->where('booking_id', $booking->id)->value('customer_name');

        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', Clock::ZONE));
        $this->artisan('accounts:anonymize')->assertSuccessful();
        $this->assertNull(User::withTrashed()->find($player->id)->anonymized_at);

        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', Clock::ZONE));
        $this->artisan('accounts:anonymize')->assertSuccessful();
        $anonymized = User::withTrashed()->find($player->id);
        $this->assertSame('anonymized', $anonymized->status);
        $this->assertSame("supprime-{$player->id}@padelbxl.invalid", $anonymized->email);
        $this->assertNull($anonymized->phone);
        // La facture garde le nom du client : obligation de conservation pendant sept ans.
        $this->assertSame($name, DB::table('invoices')->where('booking_id', $booking->id)->value('customer_name'));
    }
}
