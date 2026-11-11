@php
    // Montants en euros, à la belge : 1 234,56 €.
    $eur = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' €';
    $date = fn ($at) => \App\Services\Clock::local($at)->format('d/m/Y');
    $rate = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',').' %';
    $doc = $note ?? $invoice;
    $methods = ['card' => 'carte', 'cash' => 'espèces', 'terminal' => 'terminal de paiement'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $note ? 'Note de crédit' : 'Facture' }} {{ $doc->number }}</title>
    {{-- Document rendu par Dompdf, jamais servi comme page : la CSP du site ne s'y applique pas, et il n'y a aucun script. --}}
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #0E1B2C; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; width: 50%; padding: 0 0 18pt; }
        .lines th { text-align: left; border-bottom: 1pt solid #0E1B2C; padding: 4pt; font-size: 9pt; }
        .lines td { border-bottom: 0.5pt solid #DAD7CC; padding: 4pt; }
        .num { text-align: right; }
        .totals { width: 45%; margin-left: 55%; margin-top: 12pt; }
        .totals td { padding: 3pt 4pt; }
        .grand td { border-top: 1pt solid #0E1B2C; font-weight: bold; }
        .muted { color: #5F6B78; font-size: 9pt; }
    </style>
</head>
<body>
    <table class="parties">
        <tr>
            <td>
                <strong>{{ $seller['name'] }}</strong><br>
                {{ $seller['street'] }}<br>
                {{ $seller['postcode'] }} {{ $seller['city'] }}<br>
                TVA {{ $seller['vat'] }}
            </td>
            <td>
                <h1>{{ $note ? 'Note de crédit' : 'Facture' }}</h1>
                N° {{ $doc->number }}<br>
                Date : {{ $date($doc->issued_at) }}
                @if ($note)
                    <br>Se rapporte à la facture {{ $invoice->number }} du {{ $date($invoice->issued_at) }}
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="muted">Client</span><br>
                @if ($invoice->customer_company)
                    <strong>{{ $invoice->customer_company }}</strong><br>
                    {{ $invoice->customer_name }}<br>
                    TVA {{ $invoice->customer_vat }}
                @else
                    <strong>{{ $invoice->customer_name }}</strong>
                @endif
            </td>
        </tr>
    </table>

    @if ($note)
        <p>Motif : {{ $note->reason }}</p>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Quantité</th>
                <th class="num">Prix unitaire TTC</th>
                <th class="num">TVA</th>
                <th class="num">Hors TVA</th>
                <th class="num">Montant TVA</th>
                <th class="num">TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->label }}</td>
                    <td class="num">{{ $line->quantity }}</td>
                    <td class="num">{{ $eur($line->unit_price_cents) }}</td>
                    <td class="num">{{ $rate($line->vat_rate) }}</td>
                    <td class="num">{{ $eur($line->net_cents) }}</td>
                    <td class="num">{{ $eur($line->vat_cents) }}</td>
                    <td class="num">{{ $eur($line->total_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach ($rates as $value => $amounts)
            <tr>
                <td>TVA {{ $rate($value) }} sur {{ $eur($amounts['net']) }}</td>
                <td class="num">{{ $eur($amounts['vat']) }}</td>
            </tr>
        @endforeach
        <tr>
            <td>Total hors TVA</td>
            <td class="num">{{ $eur($doc->net_cents) }}</td>
        </tr>
        <tr>
            <td>Total TVA</td>
            <td class="num">{{ $eur($doc->vat_cents) }}</td>
        </tr>
        <tr class="grand">
            <td>{{ $note ? 'Total remboursé TTC' : 'Total TTC' }}</td>
            <td class="num">{{ $eur($doc->total_cents) }}</td>
        </tr>
    </table>

    <p class="muted">
        @if ($note)
            Montant remboursé au client. Cette note de crédit annule la facture {{ $invoice->number }}.
        @else
            Payée le {{ $date($payment->paid_at) }} par {{ $methods[$payment->method] ?? $payment->method }}. Reste à payer : {{ $eur(0) }}.
        @endif
    </p>
</body>
</html>
