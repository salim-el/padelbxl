<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * F9 : passerelle vers Stripe en mode test. Sans clé secrète, enabled() est faux et le paiement reste simulé
 * (tests, démonstration hors ligne). Les montants sont en centimes d'euro.
 */
class StripePayments
{
    /** Stripe garde une page de paiement au moins trente minutes ; le créneau, lui, n'est bloqué que dix. */
    private const SESSION_MINUTES = 30;

    private ?StripeClient $client = null;

    public function enabled(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Page de paiement hébergée par Stripe (3-D Secure compris). Pour un prix partagé, la carte est gardée
     * en garantie : elle paiera hors session les parts encore impayées 24 h avant le match.
     *
     * @param  string  $purpose  booking (organisateur), share (invité), join (partie ouverte) ou move (supplément d'un déplacement)
     */
    public function checkout(Booking $booking, User $user, int $amountCents, string $label, bool $keepCard, string $purpose = 'booking', ?string $cancelUrl = null): Session
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => config('services.stripe.currency'), 'unit_amount' => $amountCents, 'product_data' => ['name' => $label],
            ]]],
            'success_url' => route('bookings.paid', $booking).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl ?? route('bookings.checkout', $booking),
            'client_reference_id' => $booking->public_id,
            // Numéro interne du compte, jamais son e-mail : la notification signée retrouve ainsi le payeur.
            'metadata' => ['booking' => $booking->public_id, 'purpose' => $purpose, 'user' => (string) $user->id],
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
            'locale' => app()->getLocale(),
        ];
        $params += $keepCard
            ? ['customer' => $this->customer($user), 'payment_intent_data' => ['setup_future_usage' => 'off_session']]
            : ['customer_email' => $user->email];

        return $this->call(fn () => $this->client()->checkout->sessions->create($params));
    }

    /**
     * Notification de Stripe, acceptée seulement si sa signature est bonne (secret STRIPE_WEBHOOK_SECRET, cinq minutes
     * de tolérance). Une signature fausse ou un contenu illisible lève une exception : la route répond 400.
     */
    public function event(string $payload, string $signature): Event
    {
        return Webhook::constructEvent($payload, $signature, (string) config('services.stripe.webhook_secret'));
    }

    /** Session relue chez Stripe : l'adresse de retour ne prouve rien, seul Stripe dit si le paiement est fait. */
    public function session(string $id): Session
    {
        return $this->call(fn () => $this->client()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']]));
    }

    /** Rembourse sur la carte d'origine. La clé d'idempotence empêche un double remboursement si l'appel est rejoué. */
    public function refund(string $paymentIntent, int $amountCents, string $idempotencyKey): string
    {
        return $this->call(fn () => $this->client()->refunds->create(
            ['payment_intent' => $paymentIntent, 'amount' => $amountCents], ['idempotency_key' => $idempotencyKey],
        ), __('Le remboursement n’a pas abouti. Réessayez dans un instant.'))->id;
    }

    /** Débite la carte de garantie sans l'organisateur (hors session). Une carte refusée lève une BookingException. */
    public function chargeGuarantee(User $organizer, string $paymentMethod, int $amountCents, string $description, string $idempotencyKey): string
    {
        return $this->call(fn () => $this->client()->paymentIntents->create([
            'amount' => $amountCents, 'currency' => config('services.stripe.currency'), 'customer' => $organizer->stripe_customer_id,
            'payment_method' => $paymentMethod, 'off_session' => true, 'confirm' => true, 'description' => $description,
        ], ['idempotency_key' => $idempotencyKey]), __('La carte de garantie a été refusée.'))->id;
    }

    /** Client Stripe du joueur, créé à son premier paiement qui garde la carte. */
    private function customer(User $user): string
    {
        if ($user->stripe_customer_id === null) {
            $customer = $this->call(fn () => $this->client()->customers->create(['email' => $user->email, 'name' => $user->name], ['idempotency_key' => 'customer-'.$user->id]));
            $user->forceFill(['stripe_customer_id' => $customer->id])->save();
        }

        return $user->stripe_customer_id;
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }

    /**
     * Une erreur de Stripe devient un message pour le joueur ; le détail part dans le journal.
     *
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(callable $request, ?string $message = null): mixed
    {
        try {
            return $request();
        } catch (ApiErrorException $e) {
            report($e);
            throw new BookingException($message ?? __('Le paiement en ligne est indisponible pour le moment. Rien n’a été débité.'));
        }
    }
}
