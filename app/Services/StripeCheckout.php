<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Stripe\Checkout\Session;

/**
 * F9 : un paiement fait sur la page de Stripe. La commande attend dans le cache ; elle se termine une seule fois,
 * au retour du joueur ou à la notification signée de Stripe, selon ce qui arrive en premier.
 */
class StripeCheckout
{
    public function __construct(private BookingService $bookings, private StripePayments $stripe, private MatchCallService $calls) {}

    /**
     * Ouvre la page de Stripe et met la commande de côté, un jour au plus.
     *
     * @param  string  $purpose  booking, share, join ou move
     * @param  array<string, mixed>  $order
     */
    public function start(Booking $booking, User $user, string $purpose, int $cents, string $label, bool $keepCard, string $cancelUrl, array $order = []): Session
    {
        $session = $this->stripe->checkout($booking, $user, $cents, $label, $keepCard, $purpose, $cancelUrl);
        Cache::put('checkout:'.$session->id, ['purpose' => $purpose, 'user' => $user->id] + $order, now()->addDay());

        return $session;
    }

    /**
     * Termine le paiement d'une session payée. Rend la commande, ou null s'il n'y avait rien à faire (déjà terminée).
     * Si la commande ne peut plus être honorée (trop tard, place prise, montant changé), le paiement est rendu en entier
     * et une BookingException porte le message pour le joueur.
     *
     * @return array<string, mixed>|null
     */
    public function complete(Session $session): ?array
    {
        $intent = $session->payment_intent;
        if ($session->payment_status !== 'paid' || $intent === null || Payment::where('provider_reference', $intent->id)->exists()) {
            return null;
        }
        $order = Cache::get('checkout:'.$session->id);
        $booking = Booking::where('public_id', (string) ($session->metadata['booking'] ?? ''))->first();
        try {
            // Rien n'est deviné : sans la commande, la bonne réservation et la bonne devise, le paiement est rendu.
            if (! is_array($order) || $booking === null || $session->currency !== config('services.stripe.currency')
                || (string) ($session->metadata['user'] ?? '') !== (string) $order['user']) {
                throw new BookingException(__('Le montant payé ne correspond plus à la commande.'));
            }
            $user = User::findOrFail($order['user']);
            $paid = $intent->amount_received;
            match ($order['purpose']) {
                'booking' => $this->bookings->confirmPayment($booking, $user, $order['items'], 'card', $order['guests'], $order['open'],
                    $intent->id, $order['guests'] !== [] || $order['open'] > 0 ? $intent->payment_method : null, received: $paid),
                'share' => $this->bookings->payShare($booking, $user, $intent->id, $paid),
                'join' => $this->bookings->joinOpenMatch($booking, $user, $intent->id, $paid),
                'move' => $this->bookings->move($booking, $user, Court::findOrFail($order['court']), CarbonImmutable::parse($order['starts_at']), $intent->id, $paid),
            };
            Cache::forget('checkout:'.$session->id);
        } catch (BookingException $e) {
            // Retour du joueur et notification de Stripe en même temps : l'un a terminé, l'autre n'a rien à rendre.
            if (Payment::where('provider_reference', $intent->id)->exists()) {
                return null;
            }
            try {
                $this->stripe->refund($intent->id, $intent->amount_received, 'late-'.$intent->id);
            } catch (BookingException $refusal) {
                // Rejoué à la prochaine notification de Stripe (même clé d'idempotence) ; le détail part dans le journal.
                report($refusal);
            }
            $late = ($order['purpose'] ?? null) === 'booking' && $booking !== null
                && ($booking->fresh()->status !== 'pending_payment' || $booking->hold_expires_at?->isPast());
            throw new BookingException($late
                ? __('Le paiement est arrivé après les dix minutes : le créneau a été libéré et le paiement vous est remboursé en entier.')
                : __(':reason Le paiement vous est remboursé en entier.', ['reason' => $e->getMessage()]));
        }
        // Après la confirmation : un e-mail qui échoue ne doit pas annuler une réservation payée.
        if ($order['purpose'] === 'booking' && $order['open'] > 0) {
            try {
                $this->calls->publish($booking, (int) $order['level_min'], (int) $order['level_max']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $order;
    }
}
