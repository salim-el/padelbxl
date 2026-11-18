<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\StoreCredit;
use App\Models\StoreCreditUsage;
use App\Models\User;
use Illuminate\Support\Collection;

/** F15 : l'avoir d'un joueur vaut un an. Il se déduit en priorité au paiement, et ce qu'il a payé revient en avoir. */
class StoreCreditService
{
    public const VALID_MONTHS = 12;

    /** Solde utilisable du joueur, en centimes. */
    public static function balance(User $user): int
    {
        return (int) StoreCredit::usable()->where('user_id', $user->id)->sum('remaining_cents');
    }

    public function grant(User $user, int $amount, string $origin, ?Refund $refund = null): StoreCredit
    {
        return StoreCredit::create([
            'user_id' => $user->id, 'amount_cents' => $amount, 'remaining_cents' => $amount,
            'origin' => $origin, 'refund_id' => $refund?->id, 'expires_at' => now()->addMonths(self::VALID_MONTHS),
        ]);
    }

    /**
     * Avoirs utilisables, verrouillés jusqu'à la fin de la transaction : deux paiements simultanés ne dépensent pas
     * le même avoir. Ceux qui expirent le plus tôt passent d'abord. À appeler dans une transaction.
     *
     * @return Collection<int, StoreCredit>
     */
    public function lockUsable(User $user): Collection
    {
        return StoreCredit::usable()->where('user_id', $user->id)->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Prend $amount sur les avoirs verrouillés et note chaque utilisation sur le paiement.
     *
     * @param  Collection<int, StoreCredit>  $credits
     */
    public function spend(Collection $credits, Payment $payment, int $amount): void
    {
        foreach ($credits as $credit) {
            $part = min($amount, $credit->remaining_cents);
            if ($part <= 0) {
                break;
            }
            $credit->decrement('remaining_cents', $part);
            StoreCreditUsage::create(['store_credit_id' => $credit->id, 'payment_id' => $payment->id, 'amount_cents' => $part]);
            $amount -= $part;
        }
    }

    /** Part d'un paiement réglée avec un avoir et pas encore rendue en avoir. */
    public static function creditPart(Payment $payment): int
    {
        return (int) StoreCreditUsage::where('payment_id', $payment->id)->sum('amount_cents')
            - (int) Refund::where('payment_id', $payment->id)->where('method', 'store_credit')->where('status', '<>', 'failed')->sum('amount_cents');
    }
}
