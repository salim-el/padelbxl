<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Facture ou note de crédit en PDF, le document du joueur particulier. Mêmes données que UblInvoice :
 * le vendeur vient du .env, le client et les lignes sont figés sur la facture au moment du paiement.
 */
class InvoicePdf
{
    public function render(Invoice $invoice, ?CreditNote $note = null): string
    {
        $lines = InvoiceLine::where('invoice_id', $invoice->id)->orderBy('id')->get();

        return Pdf::loadView('pdf.invoice', [
            'seller' => config('invoicing.seller'),
            'invoice' => $invoice,
            'note' => $note,
            'lines' => $lines,
            'payment' => Payment::find($invoice->payment_id),
            // Une ligne de TVA par taux : 6 % pour le terrain, 21 % pour le matériel.
            'rates' => $lines->groupBy(fn (InvoiceLine $l) => (string) $l->vat_rate)
                ->map(fn ($group) => ['net' => $group->sum('net_cents'), 'vat' => $group->sum('vat_cents')]),
        ])->setOption('isFontSubsettingEnabled', true)->output(); // seuls les caractères utilisés de la police : 20 Ko au lieu de 850
    }
}
