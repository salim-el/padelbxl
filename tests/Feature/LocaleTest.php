<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_interface_is_in_french_by_default(): void
    {
        $this->get('/')->assertSee('<html lang="fr">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    }

    public function test_a_visitor_can_switch_to_dutch_and_english(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'nl'])->assertRedirect('/')->assertSessionHas('locale', 'nl');
        $this->get('/')->assertSee('<html lang="nl">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'nl'));

        $this->post('/locale', ['locale' => 'en']);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    }

    public function test_validation_messages_follow_the_chosen_language(): void
    {
        $this->post('/locale', ['locale' => 'nl']);
        $this->post('/login', [])->assertSessionHasErrors(['email' => 'E-mailadres is verplicht.']);

        $this->post('/locale', ['locale' => 'en']);
        $this->post('/login', [])->assertSessionHasErrors(['email' => 'The email address field is required.']);
    }

    public function test_a_dutch_player_reads_dates_and_booking_errors_in_dutch(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $lucas->forceFill(['locale' => 'nl'])->save();
        $court = Court::where('name', 'Terrain 1')->firstOrFail();
        $start = Clock::slotStart('2026-10-23', '19:30');
        $service = app(BookingService::class);
        $service->confirmPayment($service->hold($lucas, $court, $start), $lucas);

        $this->actingAs($lucas)->get('/bookings')
            ->assertInertia(fn (Assert $page) => $page->where('bookings', fn ($bookings) => collect($bookings)->contains('date', 'vrijdag 23 oktober 2026')));
        $this->post('/bookings', ['court_id' => $court->id, 'starts_at' => $start->toIso8601String()])
            ->assertSessionHasErrors(['booking' => 'Iemand anders heeft dit tijdslot net gereserveerd.']);
    }

    public function test_a_player_finds_his_language_again_at_the_next_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/locale', ['locale' => 'en']);
        $this->assertSame('en', $user->fresh()->locale);

        // Nouvelle session : la langue vient du compte.
        $this->flushSession();
        $this->actingAs($user->fresh())->get('/planning')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }
}
