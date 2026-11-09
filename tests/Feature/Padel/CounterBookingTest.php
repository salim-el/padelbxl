<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CounterBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->court = Court::where('name', 'Terrain 2')->firstOrFail();
    }

    private function book(array $override = []): TestResponse
    {
        return $this->actingAs($this->marc)->post('/admin/counter', [
            'court_id' => $this->court->id, 'date' => '2026-10-23', 'time' => '18:00',
            'name' => 'Sofia Janssens', 'contact' => '0470 12 34 56', 'method' => 'cash',
            ...$override,
        ]);
    }

    public function test_a_walk_in_client_pays_on_site_and_gets_a_confirmed_booking_with_an_invoice_in_their_name(): void
    {
        $this->book()->assertRedirect();

        $booking = Booking::where('walk_in_name', 'Sofia Janssens')->sole();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('0470 12 34 56', $booking->walk_in_contact);
        $payment = Payment::where('booking_id', $booking->id)->sole();
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->provider_reference);
        $this->assertSame('Sofia Janssens', Invoice::where('booking_id', $booking->id)->sole()->customer_name);
        $this->assertSame('counter-booking-created', Activity::sole()->event);
    }

    public function test_the_ticket_shows_the_access_qr_code_to_print(): void
    {
        $this->book();
        $booking = Booking::where('walk_in_name', 'Sofia Janssens')->sole();

        $this->actingAs($this->marc)->get("/admin/counter/{$booking->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/counter-ticket')
            ->where('booking.name', 'Sofia Janssens')
            ->where('qr', fn ($qr) => str_starts_with($qr, 'data:image/svg+xml')));
    }

    public function test_a_terminal_payment_is_recorded(): void
    {
        $this->book(['time' => '18:00', 'name' => 'Tom Peeters', 'method' => 'terminal'])->assertRedirect();

        $booking = Booking::where('walk_in_name', 'Tom Peeters')->sole();
        $this->assertSame('terminal', Payment::where('booking_id', $booking->id)->sole()->method);
    }

    public function test_a_slot_already_taken_is_refused_and_nothing_is_charged(): void
    {
        $this->book();
        $this->book(['name' => 'Tom Peeters'])->assertSessionHasErrors('booking');

        $this->assertSame(0, Booking::where('walk_in_name', 'Tom Peeters')->count());
        $this->assertSame(1, Payment::count());
    }

    public function test_the_manager_is_not_limited_to_three_bookings_and_they_stay_out_of_the_manager_own_list(): void
    {
        foreach (['09:00', '10:30', '12:00', '13:30'] as $i => $time) {
            $this->book(['time' => $time, 'name' => "Client $i"])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->marc)->get('/bookings')->assertInertia(fn (Assert $page) => $page->where('bookings', []));
    }

    public function test_cancelling_more_than_24_hours_before_issues_a_credit_note(): void
    {
        $this->book();
        $booking = Booking::where('walk_in_name', 'Sofia Janssens')->sole();

        $this->actingAs($this->marc)->post("/admin/counter/{$booking->public_id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled_refunded', $booking->fresh()->status);
    }

    public function test_a_bad_contact_or_an_unknown_payment_method_is_refused(): void
    {
        $this->book(['contact' => 'pas un contact'])->assertSessionHasErrors('contact');
        $this->book(['method' => 'card'])->assertSessionHasErrors('method');
        $this->assertSame(0, Booking::whereNotNull('walk_in_name')->count());
    }

    public function test_players_cannot_use_the_counter(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->actingAs($lucas)->get('/admin/counter')->assertForbidden();
        $this->actingAs($lucas)->post('/admin/counter', [])->assertForbidden();
    }
}
