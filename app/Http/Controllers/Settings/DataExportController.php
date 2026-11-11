<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Droit à la portabilité (RGPD, art. 20) : les données du joueur dans un fichier JSON lisible par une machine.
 * Seules les siennes : ni nom ni e-mail d'un partenaire, d'un invité ou de l'organisateur d'une autre partie.
 */
class DataExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $booking = fn (Booking $b) => [
            'id' => $b->public_id,
            'court' => $b->court->name,
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'status' => $b->status,
        ];
        $payments = Payment::where('payer_id', $user->id)->with('booking')->orderBy('paid_at')->get();
        $invoices = Invoice::whereIn('payment_id', $payments->pluck('id'))->orderBy('issued_at')->get();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'locale' => $user->locale,
                'account_type' => $user->account_type,
                'company_name' => $user->company_name,
                'vat_number' => $user->vat_number,
                'role' => $user->role,
                'status' => $user->status,
                'two_factor_enabled' => $user->hasTwoFactor(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            // Réservations faites pour un client du comptoir exclues : ce sont les données de ce client.
            'bookings_as_organizer' => Booking::with('court', 'items.product', 'players')->where('organizer_id', $user->id)->whereNull('walk_in_name')
                ->orderBy('starts_at')->get()->map(fn (Booking $b) => [
                    ...$booking($b),
                    'price_cents' => $b->price_cents,
                    'vat_rate' => (float) $b->vat_rate,
                    'cancelled_at' => $b->cancelled_at?->toIso8601String(),
                    'items' => $b->items->map(fn ($i) => ['name' => $i->product->name, 'quantity' => $i->quantity, 'unit_price_cents' => $i->unit_price_cents])->all(),
                    // Le nombre de partenaires, jamais leur identité.
                    'partners' => $b->players->where('is_organizer', false)->count(),
                    'my_share_cents' => $b->players->firstWhere('is_organizer', true)?->share_cents,
                ])->all(),
            'invitations' => BookingService::invitationsOf(BookingPlayer::with('booking.court'), $user)->get()
                ->sortBy('booking.starts_at')->values()->map(fn (BookingPlayer $p) => [
                    'booking' => $booking($p->booking),
                    'share_cents' => $p->share_cents,
                    'share_status' => $p->status,
                ])->all(),
            'payments' => $payments->map(fn (Payment $p) => [
                'booking' => $p->booking->public_id,
                'amount_cents' => $p->amount_cents,
                'method' => $p->method,
                'status' => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all(),
            'invoices' => $invoices->map(fn (Invoice $i) => ['number' => $i->number, 'issued_at' => $i->issued_at->toIso8601String(), 'total_cents' => $i->total_cents])->all(),
            'credit_notes' => CreditNote::whereIn('invoice_id', $invoices->pluck('id'))->orderBy('issued_at')->get()
                ->map(fn (CreditNote $n) => ['number' => $n->number, 'issued_at' => $n->issued_at->toIso8601String(), 'total_cents' => $n->total_cents])->all(),
            'waitlist' => WaitlistEntry::with('booking.court')->where('user_id', $user->id)->get()->map(fn (WaitlistEntry $w) => [
                'booking' => $booking($w->booking),
                'joined_at' => $w->created_at?->toIso8601String(),
            ])->all(),
        ];
        activity('account')->causedBy($user)->performedOn($user)->event('data-exported')->log('Export des données personnelles');

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="padelbxl-mes-donnees.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
