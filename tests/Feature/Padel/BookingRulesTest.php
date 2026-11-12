<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->service = app(BookingService::class);
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
    }

    private function slot(string $date = '2026-10-23', string $time = '19:30'): CarbonImmutable
    {
        return Clock::slotStart($date, $time);
    }

    // Les séquences PostgreSQL ne reviennent pas à 1 entre deux tests : on cherche le terrain par son nom.
    private function court(int $n): Court
    {
        return Court::where('name', "Terrain $n")->firstOrFail();
    }

    public function test_hold_blocks_the_slot_for_ten_minutes(): void
    {
        $booking = $this->service->hold($this->lucas, $this->court(2), $this->slot());
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame(4800, $booking->price_cents);

        $other = User::factory()->create();
        $this->expectException(BookingException::class);
        $this->service->hold($other, $this->court(2), $this->slot());
    }

    public function test_expired_hold_frees_the_slot(): void
    {
        $this->service->hold($this->lucas, $this->court(2), $this->slot());
        $this->travel(11)->minutes();
        $other = User::factory()->create();
        $booking = $this->service->hold($other, $this->court(2), $this->slot());
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame(1, Booking::where('status', 'expired')->count());
    }

    public function test_database_refuses_overlapping_bookings(): void
    {
        $booking = $this->service->hold($this->lucas, $this->court(1), $this->slot());
        $this->expectException(QueryException::class);
        DB::table('bookings')->insert([
            'public_id' => (string) Str::uuid(), 'court_id' => $this->court(1)->id, 'organizer_id' => $this->lucas->id,
            'starts_at' => $booking->starts_at->addMinutes(30), 'ends_at' => $booking->ends_at->addMinutes(30),
            'status' => 'confirmed', 'price_cents' => 4800, 'vat_rate' => 6,
        ]);
    }

    public function test_a_player_cannot_hold_more_than_three_future_bookings(): void
    {
        foreach (['15:00', '16:30', '18:00'] as $t) {
            $this->service->hold($this->lucas, $this->court(1), $this->slot('2026-10-23', $t));
        }
        $this->expectException(BookingException::class);
        $this->service->hold($this->lucas, $this->court(1), $this->slot('2026-10-23', '19:30'));
    }

    public function test_payment_confirms_and_issues_continuous_invoice_numbers(): void
    {
        $a = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(1), $this->slot('2026-10-23', '09:00')), $this->lucas);
        $b = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(2), $this->slot('2026-10-23', '09:00')), $this->lucas);
        $this->assertSame('confirmed', $a->status);
        $this->assertSame(['F2026-000001', 'F2026-000002'], DB::table('invoices')->orderBy('id')->pluck('number')->all());
        $invoice = DB::table('invoices')->orderBy('id')->first();
        $this->assertSame(3360, $invoice->total_cents);
        $this->assertSame(190, $invoice->vat_cents);
        $this->assertSame(1, DB::table('access_passes')->where('booking_player_id', $b->players()->first()->id)->count());
    }

    public function test_an_issued_invoice_cannot_be_changed(): void
    {
        $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(1), $this->slot()), $this->lucas);
        $this->expectException(QueryException::class);
        DB::table('invoices')->update(['total_cents' => 1]);
    }

    public function test_payment_after_hold_expiry_is_refused(): void
    {
        $booking = $this->service->hold($this->lucas, $this->court(1), $this->slot());
        $this->travel(11)->minutes();
        $this->expectException(BookingException::class);
        $this->service->confirmPayment($booking, $this->lucas);
    }

    public function test_a_slot_that_has_already_started_cannot_be_held(): void
    {
        $this->travelTo($this->slot()->addMinutes(5));

        $this->expectException(BookingException::class);
        $this->service->hold($this->lucas, $this->court(1), $this->slot());
    }

    public function test_a_game_that_has_started_cannot_be_cancelled(): void
    {
        $booking = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(1), $this->slot()), $this->lucas);
        $this->travelTo($booking->starts_at->addMinutes(5));

        try {
            $this->service->cancel($booking, $this->lucas);
            $this->fail('Une partie commencée ne doit pas pouvoir être annulée.');
        } catch (BookingException) {
            $this->assertSame('confirmed', $booking->refresh()->status);
        }
    }

    public function test_cancellation_24_hours_before_is_refunded_with_a_credit_note(): void
    {
        $booking = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(1), $this->slot()), $this->lucas);
        $this->travelTo($booking->starts_at->subHours(24));
        $booking = $this->service->cancel($booking, $this->lucas);
        $this->assertSame('cancelled_refunded', $booking->status);
        $this->assertSame(1, DB::table('credit_notes')->count());
        $this->assertSame('refunded', DB::table('payments')->value('status'));
        $this->assertNotNull(DB::table('access_passes')->value('revoked_at'));
    }

    public function test_late_cancellation_is_not_refunded(): void
    {
        $booking = $this->service->confirmPayment($this->service->hold($this->lucas, $this->court(1), $this->slot()), $this->lucas);
        $this->travelTo($booking->starts_at->subHours(23));
        $this->assertSame('cancelled_unrefunded', $this->service->cancel($booking, $this->lucas)->status);
        $this->assertSame(0, DB::table('credit_notes')->count());
    }

    public function test_another_player_gets_404_on_a_booking(): void
    {
        $booking = $this->service->hold($this->lucas, $this->court(1), $this->slot());
        $this->actingAs(User::factory()->create())->get("/bookings/{$booking->public_id}")->assertNotFound();
        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}/checkout")->assertOk();
    }

    public function test_planning_is_public_and_shows_no_personal_data(): void
    {
        $this->service->hold($this->lucas, $this->court(1), $this->slot());
        $response = $this->get('/planning?date=2026-10-23')->assertOk();
        $this->assertStringNotContainsString('lucas', strtolower($response->getContent()));
    }
}
