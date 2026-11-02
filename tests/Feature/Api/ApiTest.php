<?php

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
    }

    private function court(int $n): Court
    {
        return Court::where('name', "Terrain $n")->firstOrFail();
    }

    private function login(string $email = 'lucas.martin@example.test'): string
    {
        return $this->postJson('/api/v1/tokens', ['email' => $email, 'password' => DatabaseSeeder::DEMO_PASSWORD, 'device_name' => 'tests'])
            ->assertCreated()->json('token');
    }

    private function confirmedBooking(User $user, int $court, string $day, string $time): Booking
    {
        $service = app(BookingService::class);

        return $service->confirmPayment($service->hold($user, $this->court($court), Clock::slotStart($day, $time)), $user);
    }

    public function test_courts_and_availability_are_public_and_validated(): void
    {
        $this->getJson('/api/v1/courts')->assertOk()->assertJsonCount(Court::where('is_active', true)->count(), 'data');
        $this->getJson('/api/v1/availability?date=2026-10-23')->assertOk()->assertJsonPath('date', '2026-10-23')->assertJsonCount(count(Clock::SLOTS), 'rows');
        $this->getJson('/api/v1/availability?date=23-10-2026')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/availability?date=2026-12-31')->assertUnprocessable();
    }

    public function test_private_routes_need_a_token(): void
    {
        $this->getJson('/api/v1/bookings')->assertUnauthorized();
        $this->postJson('/api/v1/bookings', [])->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_password_gives_no_token(): void
    {
        $this->postJson('/api/v1/tokens', ['email' => 'lucas.martin@example.test', 'password' => 'mauvais', 'device_name' => 'tests'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/tokens', ['email' => 'inconnu@example.test', 'password' => 'mauvais', 'device_name' => 'tests'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Identifiants incorrects.');
    }

    public function test_a_player_books_lists_and_cancels_with_his_token(): void
    {
        $token = $this->login();
        $start = Clock::slotStart('2026-10-23', '19:30');

        $id = $this->withToken($token)->postJson('/api/v1/bookings', ['court_id' => $this->court(1)->id, 'starts_at' => $start->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment')->assertJsonStructure(['payment_url'])->json('data.id');
        $this->withToken($token)->getJson('/api/v1/bookings')->assertOk()->assertJsonPath('data.0.id', $id);

        // Le même créneau ne peut pas être pris deux fois : la règle métier répond 422.
        $this->withToken($token)->postJson('/api/v1/bookings', ['court_id' => $this->court(1)->id, 'starts_at' => $start->toIso8601String()])->assertUnprocessable();

        app(BookingService::class)->confirmPayment(Booking::where('public_id', $id)->firstOrFail(), $this->lucas);
        $this->withToken($token)->deleteJson("/api/v1/bookings/$id")->assertOk()->assertJsonPath('data.status', 'cancelled_refunded');
    }

    public function test_a_player_cannot_see_another_players_booking(): void
    {
        $marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $other = $this->confirmedBooking($marc, 2, '2026-10-23', '18:00');

        $token = $this->login();
        $this->withToken($token)->getJson("/api/v1/bookings/{$other->public_id}")->assertNotFound();
        $this->withToken($token)->deleteJson("/api/v1/bookings/{$other->public_id}")->assertNotFound();
        $this->assertSame('confirmed', $other->fresh()->status);
    }

    public function test_a_player_updates_name_and_language_only(): void
    {
        $token = $this->login();

        $this->withToken($token)->putJson('/api/v1/me', ['name' => 'Lucas M.', 'locale' => 'nl', 'email' => 'pirate@example.test'])
            ->assertOk()->assertJsonPath('data.locale', 'nl')->assertJsonPath('data.email', 'lucas.martin@example.test');
        $this->withToken($token)->putJson('/api/v1/me', ['locale' => 'de'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertSame('Lucas M.', $this->lucas->fresh()->name);
    }

    public function test_a_suspended_account_is_refused_even_with_a_valid_token(): void
    {
        $token = $this->login();
        $this->lucas->forceFill(['status' => 'suspended'])->save();

        $this->withToken($token)->getJson('/api/v1/bookings')->assertForbidden();
    }

    public function test_a_revoked_token_no_longer_works(): void
    {
        $token = $this->login();
        $this->withToken($token)->deleteJson('/api/v1/tokens/current')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/bookings')->assertUnauthorized();
    }

    public function test_a_player_token_cannot_drive_the_club_devices(): void
    {
        $token = $this->login();

        $this->withToken($token)->getJson("/api/v1/terminal/courts/{$this->court(1)->id}/current")->assertForbidden();
    }

    public function test_the_led_controller_reads_the_current_game_without_personal_data(): void
    {
        $booking = $this->confirmedBooking($this->lucas, 1, '2026-10-23', '19:30');
        $this->artisan('api:partner-token', ['email' => 'marc.dumont@example.test', 'name' => 'LED terrain 1'])->assertSuccessful();
        $token = User::where('email', 'marc.dumont@example.test')->firstOrFail()->createToken('LED terrain 1', ['terminal'])->plainTextToken;

        $this->travelTo($booking->starts_at->addMinutes(5));
        $response = $this->withToken($token)->getJson("/api/v1/terminal/courts/{$this->court(1)->id}/current")
            ->assertOk()->assertJsonPath('current.starts_at', $booking->starts_at->toIso8601String());
        $this->assertStringNotContainsString('Lucas', $response->getContent());

        // Un joueur qui n'est pas gérant ne reçoit pas de jeton d'appareil.
        $this->artisan('api:partner-token', ['email' => 'lucas.martin@example.test', 'name' => 'LED'])->assertFailed();
    }

    public function test_the_qr_reader_opens_once_through_the_api(): void
    {
        $booking = $this->confirmedBooking($this->lucas, 2, '2026-10-23', '19:30');
        $qr = BookingService::passToken($booking->players()->first());
        $token = User::where('email', 'marc.dumont@example.test')->firstOrFail()->createToken('Lecteur entrée', ['terminal'])->plainTextToken;

        $this->travelTo($booking->starts_at->subMinutes(10));
        $this->withToken($token)->postJson('/api/v1/terminal/access', ['court_id' => $this->court(2)->id, 'token' => $qr])->assertOk()->assertJsonPath('result', 'granted');
        $this->withToken($token)->postJson('/api/v1/terminal/access', ['court_id' => $this->court(2)->id, 'token' => $qr])->assertOk()->assertJsonPath('reason', 'already_used');
    }
}
