<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
    }

    public function test_only_staff_can_open_the_dashboard(): void
    {
        $this->actingAs($this->lucas)->get('/admin')->assertForbidden();
        $this->actingAs($this->marc)->get('/admin')->assertOk();
        // La règle vient d'un seul endroit : la Gate « manage-club ».
        $this->assertFalse($this->lucas->can('manage-club'));
        $this->assertTrue($this->marc->can('manage-club'));
    }

    public function test_dashboard_counts_todays_bookings_and_revenue(): void
    {
        $service = app(BookingService::class);
        $service->confirmPayment($service->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '18:00')), $this->lucas);

        $this->actingAs($this->marc)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('stats.bookings_today', 1)
            ->where('stats.revenue_today_cents', 4800)
            ->has('today', 1)
            ->where('today.0.player', 'Lucas Martin')
            ->has('users.data', 5));
    }

    public function test_a_suspended_player_can_no_longer_book(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend")->assertRedirect();
        $this->assertSame('suspended', $this->lucas->refresh()->status);

        $this->expectException(BookingException::class);
        app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '18:00'));
    }

    public function test_a_player_suspended_during_the_hold_cannot_pay(): void
    {
        $service = app(BookingService::class);
        $booking = $service->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '18:00'));
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend");

        $this->expectException(BookingException::class);
        $service->confirmPayment($booking, $this->lucas->refresh());
    }

    public function test_a_suspended_player_is_logged_out_on_the_next_request(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend");

        $this->actingAs($this->lucas->refresh())->get('/bookings')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_accounts_can_be_searched_by_name_or_email(): void
    {
        $this->actingAs($this->marc)->get('/admin?q=peeters')->assertInertia(fn (Assert $page) => $page
            ->where('search', 'peeters')
            ->has('users.data', 1)
            ->where('users.data.0.email', 'thomas.peeters@example.test'));
    }

    public function test_a_suspended_player_can_be_reactivated(): void
    {
        $yassin = User::where('email', 'yassin.benali@example.test')->first();
        $this->actingAs($this->marc)->post("/admin/users/{$yassin->id}/reactivate")->assertRedirect();
        $this->assertSame('active', $yassin->refresh()->status);
    }

    public function test_staff_accounts_cannot_be_suspended_and_players_cannot_suspend(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->marc->id}/suspend")->assertForbidden();
        $this->actingAs($this->lucas)->post("/admin/users/{$this->lucas->id}/suspend")->assertForbidden();
        $this->assertSame('active', $this->marc->refresh()->status);
    }
}
