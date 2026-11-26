<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Services\ChargebackService;
use App\Services\StripeCheckout;
use App\Services\StripePayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;

/**
 * F9 et F30 : notifications signées de Stripe. Seule route exclue du CSRF : la signature la remplace.
 * Chaque notification peut arriver deux fois ou en retard ; son traitement ne fait rien la seconde fois.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePayments $stripe, StripeCheckout $checkout, ChargebackService $chargebacks): Response
    {
        abort_unless($stripe->enabled() && filled(config('services.stripe.webhook_secret')), 404);
        try {
            $event = $stripe->event($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('', 400);
        }
        $object = $event->data->object;
        try {
            match ($event->type) {
                // Le joueur a fermé l'onglet avant de revenir : la réservation se confirme quand même.
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->complete($stripe, $checkout, $object->id),
                'charge.dispute.created' => $chargebacks->opened($object->id, (string) $object->payment_intent, $object->amount, (string) $object->reason),
                'charge.dispute.closed' => $chargebacks->closed($object->id, (string) $object->status),
                default => null,
            };
        } catch (BookingException $e) {
            // Stripe injoignable pour relire la session : 503, Stripe renverra la notification plus tard.
            report($e);

            return response('', 503);
        }

        return response('', 200);
    }

    private function complete(StripePayments $stripe, StripeCheckout $checkout, string $sessionId): void
    {
        $session = $stripe->session($sessionId);
        try {
            $checkout->complete($session);
        } catch (BookingException) {
            // Commande impossible à honorer : le paiement a été rendu ; le joueur voit le message à son retour.
        }
    }
}
