<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Défauts relevés par la relecture du 14 novembre 2026 sur les remboursements et les comptes. */
class RefundGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $ines;

    private User $marc;

    private BookingService $service;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        config(['invoicing.seller' => ['name' => 'Club de test SRL', 'vat' => 'BE0999999999', 'street' => 'Rue du Test 1', 'postcode' => '1070', 'city' => 'Anderlecht']]);
        // Mardi 20/10 à 10 h. La partie de Lucas est le vendredi 23/10 à 19 h 30, aux heures pleines : 48 €.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->service = app(BookingService::class);
        $this->booking = $this->service->confirmPayment($this->hold('19:30'), $this->lucas);
    }

    private function hold(string $time): Booking
    {
        return $this->service->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', $time));
    }

    private function payment(): Payment
    {
        return Payment::where('booking_id', $this->booking->id)->where('payer_id', $this->lucas->id)->sole();
    }

    public function test_a_cancellation_refunds_what_is_left_after_a_partial_refund(): void
    {
        $payment = $this->payment();
        // Le gérant a déjà rendu 10 € sur une demande exceptionnelle.
        DB::transaction(fn () => $this->service->refund($payment, 1000, 'Demande exceptionnelle acceptée.', 'dispute_accepted'));
        $this->assertSame('partially_refunded', $payment->fresh()->status);

        $booking = $this->service->cancel($this->booking, $this->lucas);

        $this->assertSame('cancelled_refunded', $booking->status);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(4800, (int) Refund::where('payment_id', $payment->id)->sum('amount_cents'));
        $this->assertSame([1000, 3800], CreditNote::orderBy('id')->pluck('total_cents')->all());
    }

    public function test_several_credit_notes_add_up_to_the_invoice_to_the_cent(): void
    {
        $payment = $this->payment();
        $invoice = Invoice::where('payment_id', $payment->id)->sole();
        // Trois remboursements de 16 € : la part hors TVA de chacun s'arrondit, le dernier prend le reste.
        foreach ([1600, 1600, 1600] as $amount) {
            DB::transaction(fn () => $this->service->refund($payment, $amount, 'Geste du club.', 'dispute_accepted'));
        }

        $notes = $invoice->creditNotes;
        $this->assertCount(3, $notes);
        $this->assertSame($invoice->net_cents, $notes->sum('net_cents'));
        $this->assertSame($invoice->vat_cents, $notes->sum('vat_cents'));
        $this->assertSame($invoice->total_cents, $notes->sum('total_cents'));

        // Chaque note se télécharge et la page de la réservation les liste toutes.
        $page = "/bookings/{$this->booking->public_id}";
        foreach ($notes as $note) {
            $this->actingAs($this->lucas)->get("{$page}/invoices/{$invoice->number}/credit-notes/{$note->number}/pdf")
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->actingAs($this->lucas)->get($page)->assertInertia(fn (Assert $p) => $p->where('invoices.0.credit_notes', $notes->pluck('number')->all()));
    }

    public function test_deactivating_an_account_deletes_its_api_tokens(): void
    {
        $this->lucas->createToken('Application mobile', ['bookings']);
        // Après le match : plus de réservation à venir, la désactivation passe.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));

        $this->actingAs($this->lucas)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect('/');

        $this->assertSame('deactivated', User::withTrashed()->findOrFail($this->lucas->id)->status);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $this->lucas->id)->count());
    }
}
