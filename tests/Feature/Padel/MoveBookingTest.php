<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\AccessPass;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F14 : l'organisateur déplace sa réservation jusqu'à 24 h avant ; la différence de prix est payée ou remboursée. */
class MoveBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private BookingService $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->bookings = app(BookingService::class);
    }

    private function court(int $n): Court
    {
        return Court::where('name', "Terrain $n")->firstOrFail();
    }

    /** Heures pleines à 19 h 30 (48 €), heures creuses à 10 h 30 (33,60 €). */
    private function book(string $time, array $guests = []): Booking
    {
        return $this->bookings->confirmPayment($this->bookings->hold($this->lucas, $this->court(1), Clock::slotStart('2026-10-23', $time)), $this->lucas, [], 'card', $guests);
    }

    public function test_the_same_booking_moves_and_its_qr_code_follows_the_new_slot(): void
    {
        $booking = $this->book('19:30');
        $to = Clock::slotStart('2026-10-24', '19:30');

        $moved = $this->bookings->move($booking, $this->lucas, $this->court(2), $to);

        $this->assertSame([$booking->id, $this->court(2)->id, 4800], [$moved->id, $moved->court_id, $moved->price_cents]);
        $this->assertTrue($moved->starts_at->equalTo($to));
        $pass = AccessPass::where('booking_player_id', $moved->players()->sole()->id)->sole();
        $this->assertTrue($pass->valid_from->equalTo($to->subMinutes(15)));
        // Même prix : ni paiement ni remboursement de plus.
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
        $this->assertSame(0, CreditNote::count());
        $this->assertStringContainsString('Déplacée depuis Terrain 1', DB::table('booking_status_histories')->where('booking_id', $booking->id)->latest('id')->value('reason'));
        // L'ancien créneau est libre pour un autre joueur.
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $this->assertSame('pending_payment', $this->bookings->hold($ines, $this->court(1), Clock::slotStart('2026-10-23', '19:30'))->status);
    }

    public function test_a_cheaper_slot_refunds_the_difference_with_a_credit_note(): void
    {
        $booking = $this->book('19:30');

        $this->bookings->move($booking, $this->lucas, $this->court(1), Clock::slotStart('2026-10-23', '10:30'));

        $this->assertSame(1440, CreditNote::sole()->total_cents);
        $this->assertSame('partially_refunded', Payment::where('booking_id', $booking->id)->sole()->status);
        $this->assertSame(3360, $booking->fresh()->players()->sole()->share_cents);
    }

    public function test_a_dearer_slot_charges_the_difference_on_a_new_invoice(): void
    {
        $booking = $this->book('10:30');

        $this->bookings->move($booking, $this->lucas, $this->court(1), Clock::slotStart('2026-10-23', '19:30'));

        $extra = Payment::where('booking_id', $booking->id)->latest('id')->first();
        $this->assertSame([1440, 'card'], [$extra->amount_cents, $extra->method]);
        $line = InvoiceLine::where('invoice_id', Invoice::where('payment_id', $extra->id)->sole()->id)->sole();
        $this->assertStringStartsWith('Supplément de déplacement', $line->label);
        $this->assertSame(4800, $booking->fresh()->price_cents);
    }

    public function test_a_move_is_refused_within_24_hours_for_a_shared_match_and_on_a_taken_slot(): void
    {
        $booking = $this->book('19:30');
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $taken = Clock::slotStart('2026-10-24', '21:00');
        $this->bookings->confirmPayment($this->bookings->hold($ines, $this->court(1), $taken), $ines);

        $refused = function (callable $move, string $message) {
            try {
                $move();
                $this->fail('Le déplacement aurait dû être refusé.');
            } catch (BookingException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };
        $refused(fn () => $this->bookings->move($booking, $this->lucas, $this->court(1), $taken), 'quelqu’un d’autre');
        $refused(fn () => $this->bookings->move($booking, $ines, $this->court(2), Clock::slotStart('2026-10-24', '19:30')), 'ne peut plus être déplacée');
        $shared = $this->book('21:00', [$ines->email]);
        $refused(fn () => $this->bookings->move($shared, $this->lucas, $this->court(2), Clock::slotStart('2026-10-24', '19:30')), 'partie partagée');

        $this->travelTo(CarbonImmutable::parse('2026-10-22 20:00', Clock::ZONE));
        $refused(fn () => $this->bookings->move($booking, $this->lucas, $this->court(2), Clock::slotStart('2026-10-24', '19:30')), '24 h avant');
        $this->assertSame($this->court(1)->id, $booking->fresh()->court_id);
    }

    public function test_rented_rackets_must_still_be_free_on_the_new_slot(): void
    {
        // Trois raquettes au club : Lucas en loue deux, Inès deux autres sur le créneau visé.
        $racket = Product::where('kind', 'rental')->firstOrFail();
        $racket->update(['stock' => 3]);
        $booking = $this->bookings->confirmPayment($this->bookings->hold($this->lucas, $this->court(1), Clock::slotStart('2026-10-23', '19:30')), $this->lucas, [$racket->id => 2]);
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $to = Clock::slotStart('2026-10-24', '19:30');
        $this->bookings->confirmPayment($this->bookings->hold($ines, $this->court(1), $to), $ines, [$racket->id => 2]);

        $this->expectExceptionMessage('il en reste 1');
        $this->bookings->move($booking, $this->lucas, $this->court(2), $to);
    }

    public function test_the_organizer_moves_from_the_planning_and_others_get_a_404(): void
    {
        $booking = $this->book('19:30');
        $url = "/bookings/{$booking->getRouteKey()}/move";
        $to = ['court_id' => $this->court(2)->id, 'starts_at' => Clock::slotStart('2026-10-24', '19:30')->toIso8601String()];

        $this->actingAs(User::where('email', 'ines.laurent@example.test')->firstOrFail())->post($url, $to)->assertNotFound();
        $this->actingAs($this->lucas)->get("/planning?move={$booking->getRouteKey()}")
            ->assertInertia(fn ($page) => $page->where('moving.id', $booking->getRouteKey())->where('moving.court', 'Terrain 1'));
        $this->actingAs($this->lucas)->get("/bookings/{$booking->getRouteKey()}")->assertInertia(fn ($page) => $page->where('movable', true));

        $this->actingAs($this->lucas)->post($url, $to)->assertRedirect("/bookings/{$booking->getRouteKey()}")->assertSessionHas('success');
        $this->assertSame($this->court(2)->id, $booking->fresh()->court_id);
    }
}
