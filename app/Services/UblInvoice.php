<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use XMLWriter;

/**
 * Facture au format UBL 2.1, profil Peppol BIS Billing 3.0, pour les clients entreprises (numéro de TVA belge).
 * Le client la reçoit déjà payée : le montant restant dû est nul.
 */
class UblInvoice
{
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private XMLWriter $x;

    public static function sellerIsConfigured(): bool
    {
        return ! in_array(null, config('invoicing.seller'), true) && preg_match('/^BE[01]\d{9}$/', (string) config('invoicing.seller.vat')) === 1;
    }

    public function toXml(Invoice $invoice, string $buyerReference): string
    {
        $seller = config('invoicing.seller');
        $lines = InvoiceLine::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->x = new XMLWriter;
        $this->x->openMemory();
        $this->x->setIndent(true);
        $this->x->startDocument('1.0', 'UTF-8');
        $this->x->startElementNs(null, 'Invoice', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $this->x->writeAttribute('xmlns:cac', self::CAC);
        $this->x->writeAttribute('xmlns:cbc', self::CBC);

        $this->cbc('CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $this->cbc('ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->cbc('ID', $invoice->number);
        $this->cbc('IssueDate', Clock::local($invoice->issued_at)->format('Y-m-d'));
        $this->cbc('InvoiceTypeCode', '380');
        $this->cbc('DocumentCurrencyCode', 'EUR');
        $this->cbc('BuyerReference', $buyerReference);

        $this->party('AccountingSupplierParty', $seller['name'], $seller['vat'], $seller);
        $this->party('AccountingCustomerParty', $invoice->customer_company, $invoice->customer_vat, null);

        // Une ligne de TVA par taux : 6 % pour le terrain, 21 % pour le matériel.
        $this->cac('TaxTotal', function () use ($invoice, $lines) {
            $this->amount('TaxAmount', $invoice->vat_cents);
            foreach ($lines->groupBy(fn (InvoiceLine $l) => (string) $l->vat_rate) as $rate => $group) {
                $this->cac('TaxSubtotal', function () use ($rate, $group) {
                    $this->amount('TaxableAmount', $group->sum('net_cents'));
                    $this->amount('TaxAmount', $group->sum('vat_cents'));
                    $this->taxCategory('TaxCategory', (string) $rate);
                });
            }
        });
        $this->cac('LegalMonetaryTotal', function () use ($invoice) {
            $this->amount('LineExtensionAmount', $invoice->net_cents);
            $this->amount('TaxExclusiveAmount', $invoice->net_cents);
            $this->amount('TaxInclusiveAmount', $invoice->total_cents);
            $this->amount('PrepaidAmount', $invoice->total_cents);
            $this->amount('PayableAmount', 0);
        });
        foreach ($lines as $i => $line) {
            $this->cac('InvoiceLine', function () use ($i, $line) {
                $this->cbc('ID', (string) ($i + 1));
                $this->cbc('InvoicedQuantity', (string) $line->quantity, ['unitCode' => 'C62']);
                $this->amount('LineExtensionAmount', $line->net_cents);
                $this->cac('Item', function () use ($line) {
                    $this->cbc('Name', $line->label);
                    $this->taxCategory('ClassifiedTaxCategory', (string) $line->vat_rate);
                });
                // Prix unitaire hors TVA, avec quatre décimales pour que quantité × prix retombe sur le montant de la ligne.
                $this->cac('Price', fn () => $this->cbc('PriceAmount', number_format($line->net_cents / 100 / $line->quantity, 4, '.', ''), ['currencyID' => 'EUR']));
            });
        }

        $this->x->endElement();
        $this->x->endDocument();

        return $this->x->outputMemory();
    }

    /** @param array{street: string, postcode: string, city: string}|null $address */
    private function party(string $role, string $name, string $vat, ?array $address): void
    {
        $this->cac($role, fn () => $this->cac('Party', function () use ($name, $vat, $address) {
            // 0208 : numéro d'entreprise belge (BCE), soit le numéro de TVA sans le préfixe BE.
            $this->cbc('EndpointID', substr($vat, 2), ['schemeID' => '0208']);
            $this->cac('PostalAddress', function () use ($address) {
                if ($address) {
                    $this->cbc('StreetName', $address['street']);
                    $this->cbc('CityName', $address['city']);
                    $this->cbc('PostalZone', $address['postcode']);
                }
                $this->cac('Country', fn () => $this->cbc('IdentificationCode', 'BE'));
            });
            $this->cac('PartyTaxScheme', function () use ($vat) {
                $this->cbc('CompanyID', $vat);
                $this->cac('TaxScheme', fn () => $this->cbc('ID', 'VAT'));
            });
            $this->cac('PartyLegalEntity', fn () => $this->cbc('RegistrationName', $name));
        }));
    }

    private function taxCategory(string $element, string $rate): void
    {
        $this->cac($element, function () use ($rate) {
            $this->cbc('ID', 'S');
            $this->cbc('Percent', number_format((float) $rate, 2, '.', ''));
            $this->cac('TaxScheme', fn () => $this->cbc('ID', 'VAT'));
        });
    }

    private function amount(string $element, int $cents): void
    {
        $this->cbc($element, number_format($cents / 100, 2, '.', ''), ['currencyID' => 'EUR']);
    }

    private function cac(string $element, callable $children): void
    {
        $this->x->startElement('cac:'.$element);
        $children();
        $this->x->endElement();
    }

    /** @param array<string, string> $attributes */
    private function cbc(string $element, string $value, array $attributes = []): void
    {
        $this->x->startElement('cbc:'.$element);
        foreach ($attributes as $name => $v) {
            $this->x->writeAttribute($name, $v);
        }
        $this->x->text($value);
        $this->x->endElement();
    }
}
