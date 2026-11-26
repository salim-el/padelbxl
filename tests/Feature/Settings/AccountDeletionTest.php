<?php

namespace Tests\Feature\Settings;

use App\Models\Booking;
use App\Models\BookingPlayer;
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

    public function test_a_member_can_ask_for_immediate_erasure(): void
    {
        [$player, $booking] = $this->playerWithBooking('2026-10-23');
        $player->createToken('Application mobile', ['bookings']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));
        $name = DB::table('invoices')->where('booking_id', $booking->id)->value('customer_name');

        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD, 'erase_now' => true])->assertRedirect('/');

        // Pas d'attente de trente jours : les données personnelles sont remplacées dans la même requête.
        $erased = User::withTrashed()->find($player->id);
        $this->assertSame('anonymized', $erased->status);
        $this->assertSame("supprime-{$player->id}@padelbxl.invalid", $erased->email);
        $this->assertNotNull($erased->anonymized_at);
        $this->assertSame(0, $erased->tokens()->count());
        $this->assertSame($name, DB::table('invoices')->where('booking_id', $booking->id)->value('customer_name'));
        // L'adresse est libérée : la même personne peut recréer un compte.
        $this->assertDatabaseMissing('users', ['email' => 'lucas.martin@example.test']);
    }

    public function test_erasure_also_removes_the_address_typed_by_an_organizer(): void
    {
        [$player] = $this->playerWithBooking('2026-10-23');
        // Inès avait invité Lucas par son adresse : une place liée à son compte, une autre encore sans compte.
        $ines = User::where('email', 'ines.laurent@example.test')->first();
        $service = app(BookingService::class);
        $court = Court::where('name', 'Terrain 2')->first();
        $linked = $service->confirmPayment($service->hold($ines, $court, Clock::slotStart('2026-10-22', '18:00')), $ines);
        $unlinked = $service->confirmPayment($service->hold($ines, $court, Clock::slotStart('2026-10-23', '18:00')), $ines);
        BookingPlayer::create(['booking_id' => $linked->id, 'user_id' => $player->id, 'guest_email' => 'lucas.martin@example.test', 'is_organizer' => false, 'share_cents' => 0, 'status' => 'paid']);
        BookingPlayer::create(['booking_id' => $unlinked->id, 'user_id' => null, 'guest_email' => 'Lucas.Martin@example.test', 'is_organizer' => false, 'share_cents' => 0, 'status' => 'withdrawn']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));

        $this->actingAs($player)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD, 'erase_now' => true])->assertRedirect('/');

        $this->assertSame(0, BookingPlayer::whereRaw("lower(guest_email) = 'lucas.martin@example.test'")->count());
        $this->assertSame(2, BookingPlayer::where('guest_email', "supprime-{$player->id}@padelbxl.invalid")->count());
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
        $player->createToken('Application mobile', ['bookings']);
        $player->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
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
        $this->assertNull($anonymized->two_factor_secret);
        $this->assertSame(0, $anonymized->tokens()->count());
        // La facture garde le nom du client : obligation de conservation pendant sept ans.
        $this->assertSame($name, DB::table('invoices')->where('booking_id', $booking->id)->value('customer_name'));
    }
}
