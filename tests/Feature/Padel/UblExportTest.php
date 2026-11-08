<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use SimpleXMLElement;
use Tests\TestCase;

class UblExportTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        // Club fictif des tests : le vrai club renseigne ses données dans le .env.
        config(['invoicing.seller' => ['name' => 'Club de test SRL', 'vat' => 'BE0999999999', 'street' => 'Rue du Test 1', 'postcode' => '1070', 'city' => 'Anderlecht']]);
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->lucas->forceFill(['account_type' => 'company', 'company_name' => 'Atelier Nova SRL', 'vat_number' => 'BE0123456749'])->save();
    }

    /** Terrain à 6 % et deux raquettes louées à 21 % : deux taux de TVA sur la même facture. */
    private function paidBooking(User $user): Booking
    {
        $service = app(BookingService::class);
        $racket = Product::where('name', 'Location de raquette')->firstOrFail();
        $hold = $service->hold($user, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', '19:30'));

        return $service->confirmPayment($hold, $user, null, [$racket->id => 2]);
    }

    private function ubl(Booking $booking): SimpleXMLElement
    {
        $number = Invoice::where('booking_id', $booking->id)->sole()->number;
        $response = $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}/invoices/{$number}/ubl")
            ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = new SimpleXMLElement($response->getContent());
        $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xml;
    }

    private function sum(SimpleXMLElement $xml, string $path): float
    {
        return array_sum(array_map('floatval', $xml->xpath($path)));
    }

    public function test_a_company_downloads_a_peppol_invoice_whose_totals_add_up(): void
    {
        $booking = $this->paidBooking($this->lucas);
        $invoice = Invoice::where('booking_id', $booking->id)->sole();
        $xml = $this->ubl($booking);

        $this->assertSame('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', (string) $xml->xpath('cbc:ProfileID')[0]);
        $this->assertSame($invoice->number, (string) $xml->xpath('cbc:ID')[0]);
        $this->assertSame('0123456749', (string) $xml->xpath('cac:AccountingCustomerParty/cac:Party/cbc:EndpointID')[0]);
        $this->assertSame('Club de test SRL', (string) $xml->xpath('cac:AccountingSupplierParty//cbc:RegistrationName')[0]);
        // Les montants se recoupent : lignes, sous-totaux par taux et totaux de la facture.
        $this->assertCount(2, $xml->xpath('cac:TaxTotal/cac:TaxSubtotal'));
        $this->assertEqualsWithDelta($invoice->net_cents / 100, $this->sum($xml, 'cac:InvoiceLine/cbc:LineExtensionAmount'), 0.001);
        $this->assertEqualsWithDelta($invoice->vat_cents / 100, $this->sum($xml, 'cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'), 0.001);
        $this->assertEqualsWithDelta($invoice->total_cents / 100, (float) $xml->xpath('cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')[0], 0.001);
        $this->assertSame('0.00', (string) $xml->xpath('cac:LegalMonetaryTotal/cbc:PayableAmount')[0]);
    }

    public function test_the_booking_page_offers_the_download_only_to_a_company(): void
    {
        $booking = $this->paidBooking($this->lucas);
        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}")->assertInertia(fn (Assert $page) => $page->where('invoices.0.ubl', true));
    }

    public function test_an_invoice_for_an_individual_has_no_ubl(): void
    {
        $this->lucas->forceFill(['account_type' => 'individual', 'company_name' => null, 'vat_number' => null])->save();
        $booking = $this->paidBooking($this->lucas);
        $number = Invoice::where('booking_id', $booking->id)->sole()->number;

        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}/invoices/{$number}/ubl")->assertNotFound();
    }

    public function test_another_player_cannot_download_it(): void
    {
        $booking = $this->paidBooking($this->lucas);
        $number = Invoice::where('booking_id', $booking->id)->sole()->number;
        $other = User::where('role', 'player')->where('id', '!=', $this->lucas->id)->firstOrFail();

        $this->actingAs($other)->get("/bookings/{$booking->public_id}/invoices/{$number}/ubl")->assertNotFound();
    }

    public function test_without_the_club_details_the_export_says_so_instead_of_sending_an_incomplete_invoice(): void
    {
        config(['invoicing.seller.vat' => null]);
        $booking = $this->paidBooking($this->lucas);
        $number = Invoice::where('booking_id', $booking->id)->sole()->number;

        $this->actingAs($this->lucas)->get("/bookings/{$booking->public_id}/invoices/{$number}/ubl")->assertStatus(503);
    }
}
