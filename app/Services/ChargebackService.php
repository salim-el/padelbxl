<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Chargeback;
use App\Models\FraudFlag;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * F30 : contestation bancaire signalée par Stripe. La réservation est gelée et le compte signalé au gérant,
 * sans restriction automatique (RGPD, art. 22) : le gérant rassemble les preuves (passage à la borne) et répond chez Stripe.
 */
class ChargebackService
{
    public function __construct(private BookingService $bookings) {}

    /** Ouverture. Rejouée par Stripe, elle ne fait rien de plus : la référence de la contestation est unique. */
    public function opened(string $disputeId, string $paymentIntent, int $amountCents, string $reason): ?Chargeback
    {
        $payment = Payment::where('provider_reference', $paymentIntent)->first();
        // Un paiement inconnu ne vient pas d’ArenaBxl (autre usage du compte Stripe) : rien à faire.
        if ($payment === null) {
            return null;
        }

        return DB::transaction(function () use ($disputeId, $payment, $amountCents, $reason) {
            $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
            $chargeback = Chargeback::firstOrCreate(['stripe_dispute_id' => $disputeId], ['payment_id' => $payment->id, 'status' => 'open', 'opened_at' => now()]);
            if (! $chargeback->wasRecentlyCreated) {
                return $chargeback;
            }
            $this->bookings->freeze($booking, 'Contestation bancaire ouverte.');
            FraudFlag::create([
                'user_id' => $payment->payer_id,
                'type' => 'chargeback',
                'details' => sprintf('Contestation %s de %s € sur le paiement %d (réservation %s). Motif donné par la banque : %s.',
                    $disputeId, number_format($amountCents / 100, 2, ',', ' '), $payment->id, $booking->public_id, $reason),
            ]);
            activity('stripe')->performedOn($chargeback)->event('chargeback-opened')->log('Contestation bancaire ouverte');

            return $chargeback;
        });
    }

    /** Clôture : gagnée (l'argent reste au club) ou perdue (la banque l'a rendu au joueur). */
    public function closed(string $disputeId, string $stripeStatus): ?Chargeback
    {
        return DB::transaction(function () use ($disputeId, $stripeStatus) {
            $chargeback = Chargeback::where('stripe_dispute_id', $disputeId)->lockForUpdate()->first();
            if ($chargeback === null || $chargeback->status !== 'open') {
                return $chargeback;
            }
            // Stripe clôt aussi en « warning_closed » une alerte qui n'a rien coûté : l'argent reste au club.
            $won = $stripeStatus !== 'lost';
            $chargeback->update(['status' => $won ? 'won' : 'lost', 'closed_at' => now()]);
            $booking = Booking::whereKey($chargeback->payment->booking_id)->lockForUpdate()->firstOrFail();
            $this->bookings->thaw($booking, $won, $won ? 'Contestation bancaire gagnée.' : 'Contestation bancaire perdue : la banque a rendu le paiement.');
            activity('stripe')->performedOn($chargeback)->event($won ? 'chargeback-won' : 'chargeback-lost')->log('Contestation bancaire close');

            return $chargeback;
        });
    }
}
