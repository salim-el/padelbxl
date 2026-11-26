<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\DisputeDecided;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** F17 et F27 : le joueur introduit une demande pour un imprévu grave ; le gérant l'examine et décide. */
class DisputeService
{
    /** Délai pour introduire une demande après la fin du créneau. */
    public const DAYS_AFTER = 7;

    public function __construct(private BookingService $bookings) {}

    /** Paiements du joueur sur cette réservation qui peuvent encore être remboursés. */
    public function payments(Booking $booking, User $user)
    {
        return Payment::where('booking_id', $booking->id)->where('payer_id', $user->id)->whereIn('status', ['succeeded', 'partially_refunded'])->get();
    }

    public function open(User $user, Booking $booking, string $reason, ?UploadedFile $file): Dispute
    {
        // Au comptoir, le paiement est au nom du personnel : il pourrait sinon s'accorder un avoir.
        if ($booking->walk_in_name !== null) {
            throw new BookingException(__('Une réservation encodée au comptoir ne fait pas l’objet d’une demande en ligne.'));
        }
        if ($this->payments($booking, $user)->isEmpty()) {
            throw new BookingException(__('Aucun paiement à votre nom sur cette réservation.'));
        }
        if (now()->gt($booking->ends_at->addDays(self::DAYS_AFTER))) {
            throw new BookingException(__('Une demande s’introduit au plus tard sept jours après le match.'));
        }
        if (Dispute::where('booking_id', $booking->id)->where('user_id', $user->id)->whereNull('appeal_of_id')->exists()) {
            throw new BookingException(__('Vous avez déjà introduit une demande pour cette réservation.'));
        }
        // Pièce justificative privée : disque local, jamais servi directement.
        $path = $file?->store('disputes', 'local');
        try {
            return Dispute::create(['booking_id' => $booking->id, 'user_id' => $user->id, 'reason' => $reason, 'attachment_path' => $path]);
        } catch (QueryException) {
            throw new BookingException(__('Vous avez déjà introduit une demande pour cette réservation.'));
        }
    }

    public function review(Dispute $dispute): void
    {
        if ($dispute->status === 'received') {
            $dispute->update(['status' => 'in_review']);
        }
    }

    /** Décision motivée : remboursement total ou partiel des paiements du joueur, avoir, ou refus. Le joueur est prévenu. */
    public function decide(Dispute $dispute, User $staff, string $decision, string $note, ?int $amountCents = null): Dispute
    {
        // Séparation des tâches : un membre du personnel qui joue aussi ne tranche pas sa propre demande.
        if ($dispute->user_id === $staff->id) {
            throw new BookingException(__('Un autre membre du personnel doit décider de votre demande.'));
        }
        $dispute = DB::transaction(function () use ($dispute, $staff, $decision, $note, $amountCents) {
            $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen()) {
                throw new BookingException(__('Cette demande a déjà reçu une décision.'));
            }
            $payments = $this->payments($dispute->booking, $dispute->user);
            $refundable = $payments->sum(fn (Payment $p) => $p->amount_cents - $p->refunds()->where('status', '<>', 'failed')->sum('amount_cents'));
            $amount = match ($decision) {
                'accepted_full', 'accepted_credit' => $refundable,
                'accepted_partial' => (int) $amountCents,
                default => 0,
            };
            if ($decision === 'accepted_partial' && ($amount <= 0 || $amount >= $refundable)) {
                throw new BookingException(__('Le montant partiel doit être supérieur à zéro et inférieur à :max.', ['max' => number_format($refundable / 100, 2, ',', ' ').' €']));
            }
            $left = $amount;
            foreach ($payments as $payment) {
                $part = min($left, $payment->amount_cents - $payment->refunds()->where('status', '<>', 'failed')->sum('amount_cents'));
                if ($part > 0) {
                    // F15 : acceptée en avoir, tout le montant revient en avoir, valable un an.
                    $this->bookings->refund($payment, $part, 'Demande exceptionnelle acceptée.', 'dispute_accepted', asCredit: $decision === 'accepted_credit');
                    $left -= $part;
                }
            }
            $dispute->update(['status' => $decision, 'decision_note' => $note, 'refunded_cents' => $amount ?: null, 'decided_by' => $staff->id, 'decided_at' => now()]);
            // Acceptée en entier avant le match : le joueur est remboursé, il ne joue donc plus. Son QR code ne passe plus.
            if (in_array($decision, ['accepted_full', 'accepted_credit'], true)) {
                $this->bookings->withdraw($dispute->booking, $dispute->user, $staff, 'Demande exceptionnelle acceptée.', 'dispute_accepted');
            }
            activity('staff')->performedOn($dispute)->causedBy($staff)->event('dispute-decided')
                ->withProperties(['decision' => $decision, 'refunded_cents' => $amount])->log('Décision sur une demande exceptionnelle');

            return $dispute;
        });
        $dispute->user->notify(new DisputeDecided($dispute));

        return $dispute;
    }
}
