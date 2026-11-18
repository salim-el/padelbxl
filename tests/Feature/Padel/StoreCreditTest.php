<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StoreCredit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\DisputeService;
use App\Services\StoreCreditService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F15 : l'avoir se déduit en priorité au paiement, vaut un an, et ce qu'il a payé revient en avoir. */
class StoreCreditTest extends TestCase
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

    /** Partie de 19 h 30 le 23/10, aux heures pleines : 48 €. */
    private function book(): Booking
    {
        $court = Court::where('name', 'Terrain 1')->firstOrFail();

        return $this->bookings->confirmPayment($this->bookings->hold($this->lucas, $court, Clock::slotStart('2026-10-23', '19:30')), $this->lucas);
    }

    private function credit(int $cents, ?\DateTimeInterface $expires = null): StoreCredit
    {
        $credit = app(StoreCreditService::class)->grant($this->lucas, $cents, 'goodwill');
        if ($expires) {
            $credit->update(['expires_at' => $expires]);
        }

        return $credit;
    }

    public function test_a_credit_large_enough_pays_the_whole_booking(): void
    {
        $credit = $this->credit(6000);
        $payment = Payment::where('booking_id', $this->book()->id)->sole();

        $this->assertSame('store_credit', $payment->method);
        $this->assertNull($payment->provider_reference);
        $this->assertSame(4800, $payment->amount_cents);
        $this->assertSame(1200, $credit->fresh()->remaining_cents);
        $this->assertSame(1200, StoreCreditService::balance($this->lucas));
    }

    public function test_the_card_pays_only_what_the_credit_does_not_cover(): void
    {
        $old = $this->credit(500, now()->addMonth());
        $new = $this->credit(1000);
        $payment = Payment::where('booking_id', $this->book()->id)->sole();

        $this->assertSame('card', $payment->method);
        $this->assertSame(4800, $payment->amount_cents);
        // L'avoir qui expire le plus tôt sert d'abord.
        $this->assertSame([0, 0], [$old->fresh()->remaining_cents, $new->fresh()->remaining_cents]);
        $this->assertSame(1500, StoreCreditService::creditPart($payment));
    }

    public function test_an_expired_credit_is_not_used(): void
    {
        $credit = $this->credit(6000, now()->subDay());
        $payment = Payment::where('booking_id', $this->book()->id)->sole();

        $this->assertSame('card', $payment->method);
        $this->assertSame(0, StoreCreditService::creditPart($payment));
        $this->assertSame(6000, $credit->fresh()->remaining_cents);
        $this->assertSame(0, StoreCreditService::balance($this->lucas));
    }

    public function test_a_credit_is_valid_for_one_year(): void
    {
        $this->assertTrue($this->credit(1000)->expires_at->equalTo(now()->addYear()));
    }

    public function test_what_a_credit_paid_comes_back_as_credit_and_the_rest_on_the_card(): void
    {
        $this->credit(1000);
        $booking = $this->book();
        $this->bookings->cancel($booking, $this->lucas);

        $refunds = Refund::where('payment_id', Payment::where('booking_id', $booking->id)->sole()->id)->get()->keyBy('method');
        $this->assertSame(1000, $refunds['store_credit']->amount_cents);
        $this->assertSame(3800, $refunds['original_payment']->amount_cents);
        // Une seule note de crédit pour les 48 €.
        $this->assertSame($refunds['store_credit']->credit_note_id, $refunds['original_payment']->credit_note_id);
        $this->assertSame(4800, CreditNote::sole()->total_cents);
        $back = StoreCredit::where('refund_id', $refunds['store_credit']->id)->sole();
        $this->assertSame(['refund', 1000], [$back->origin, $back->remaining_cents]);
        $this->assertSame(1000, StoreCreditService::balance($this->lucas));
    }

    public function test_the_manager_can_accept_a_request_as_credit(): void
    {
        $booking = $this->book();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00', Clock::ZONE));
        $dispute = app(DisputeService::class)->open($this->lucas, $booking, 'Hospitalisation la veille du match, certificat joint.', null);
        $marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();

        $this->actingAs($marc)->post("/admin/disputes/{$dispute->id}/decision", ['decision' => 'accepted_credit', 'decision_note' => 'Certificat valable, avoir accordé.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['accepted_credit', 4800], [$dispute->fresh()->status, $dispute->fresh()->refunded_cents]);
        $refund = Refund::sole();
        $this->assertSame('store_credit', $refund->method);
        $credit = StoreCredit::where('refund_id', $refund->id)->sole();
        $this->assertSame(['dispute', 4800], [$credit->origin, $credit->amount_cents]);
        $this->assertSame(4800, StoreCreditService::balance($this->lucas));
    }
}
