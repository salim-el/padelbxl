<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private Booking $booking;

    private string $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        config(['invoicing.seller' => ['name' => 'Club de test SRL', 'vat' => 'BE0999999999', 'street' => 'Rue du Test 1', 'postcode' => '1070', 'city' => 'Anderlecht']]);
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $service = app(BookingService::class);
        $hold = $service->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', '19:30'));
        $this->booking = $service->confirmPayment($hold, $this->lucas, [Product::where('name', 'Location de raquette')->value('id') => 2]);
        $this->number = Invoice::where('booking_id', $this->booking->id)->sole()->number;
    }

    private function pdfUrl(): string
    {
        return "/bookings/{$this->booking->public_id}/invoices/{$this->number}/pdf";
    }

    public function test_the_payer_and_the_staff_download_the_invoice_but_not_another_player(): void
    {
        $response = $this->actingAs($this->lucas)->get($this->pdfUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs(User::where('email', 'marc.dumont@example.test')->firstOrFail())->get($this->pdfUrl())->assertOk();
        $this->actingAs(User::where('email', 'ines.laurent@example.test')->firstOrFail())->get($this->pdfUrl())->assertForbidden();
    }

    public function test_the_invoice_follows_the_language_of_the_player(): void
    {
        $invoice = Invoice::where('booking_id', $this->booking->id)->sole();

        app()->setLocale('nl');
        $nl = (new InvoicePdf)->html($invoice);
        $this->assertStringContainsString('<html lang="nl">', $nl);
        $this->assertStringContainsString('Factuur', $nl);
        $this->assertStringContainsString('Totaal incl. btw', $nl);
        $this->assertStringContainsString('met kaart', $nl);

        app()->setLocale('en');
        $this->assertStringContainsString('Total incl. VAT', (new InvoicePdf)->html($invoice));

        app()->setLocale('fr');
        $this->assertStringContainsString('Total TTC', (new InvoicePdf)->html($invoice));
        // Le libellé de la ligne reste celui figé à l'émission, quelle que soit la langue.
        $line = InvoiceLine::where('invoice_id', $invoice->id)->orderBy('id')->value('label');
        $this->assertStringContainsString(e($line), $nl);
    }

    public function test_the_credit_note_exists_only_after_a_refunded_cancellation(): void
    {
        $page = "/bookings/{$this->booking->public_id}";
        $this->actingAs($this->lucas)->get($page)->assertInertia(fn (Assert $p) => $p->where('invoices.0.credit_notes', []));
        $this->actingAs($this->lucas)->get("{$page}/invoices/{$this->number}/credit-notes/NC2026-000001/pdf")->assertNotFound();

        app(BookingService::class)->cancel($this->booking, $this->lucas);

        $note = CreditNote::sole()->number;
        $this->assertStringStartsWith('NC', $note);
        $this->actingAs($this->lucas)->get("{$page}/invoices/{$this->number}/credit-notes/{$note}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->lucas)->get($page)->assertInertia(fn (Assert $p) => $p->where('invoices.0.credit_notes', [$note]));
    }
}
