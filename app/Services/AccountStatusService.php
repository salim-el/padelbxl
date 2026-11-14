<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\AccountStatusHistory;
use App\Models\BlockedIdentity;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Notifications\AccountRevoked;
use App\Notifications\AccountSuspended;
use App\Notifications\SuspensionAppealDecided;
use Illuminate\Support\Facades\DB;

/**
 * F28 et F29 : le personnel suspend, réactive ou révoque un compte, toujours avec un motif.
 * Le système ne bloque jamais seul : il signale, une personne décide (RGPD, art. 22).
 */
class AccountStatusService
{
    /** Délai pour contester une suspension depuis le lien reçu par e-mail. */
    public const APPEAL_DAYS = 14;

    public function suspend(User $user, User $staff, string $reason, ?int $days): AccountStatusHistory
    {
        $until = $days ? now()->addDays($days) : null;
        $history = $this->change($user, 'suspended', $reason, $staff, $until);
        // Un compte suspendu n'utilise plus l'API non plus.
        $user->tokens()->delete();
        activity('staff')->performedOn($user)->causedBy($staff)->event('account-suspended')
            ->withProperties(['until' => $until?->toIso8601String()])->log('Compte suspendu');
        $user->notify(new AccountSuspended($history));

        return $history;
    }

    public function reactivate(User $user, ?User $staff, string $reason): AccountStatusHistory
    {
        if (! in_array($user->status, ['suspended', 'pending'], true)) {
            throw new BookingException(__('Ce compte n’est pas suspendu.'));
        }
        $history = $this->change($user, 'active', $reason, $staff, null);
        activity($staff ? 'staff' : 'system')->performedOn($user)->causedBy($staff)->event('account-reactivated')->log('Compte réactivé');

        return $history;
    }

    /** Révocation définitive pour fraude : l'empreinte de l'e-mail empêche de recréer un compte. */
    public function revoke(User $user, User $staff, string $reason): AccountStatusHistory
    {
        $history = DB::transaction(function () use ($user, $staff, $reason) {
            $history = $this->change($user, 'revoked', $reason, $staff, null);
            BlockedIdentity::firstOrCreate(['email_hash' => BlockedIdentity::hashEmail($user->email)], ['reason' => $reason]);
            $user->tokens()->delete();

            return $history;
        });
        activity('staff')->performedOn($user)->causedBy($staff)->event('account-revoked')->log('Compte révoqué');
        $user->notify(new AccountRevoked($reason));

        return $history;
    }

    /** Fin des suspensions à durée fixe : tâche planifiée. */
    public function liftExpired(): int
    {
        $count = 0;
        User::where('status', 'suspended')->whereNotNull('suspended_until')->where('suspended_until', '<=', now())
            ->lazyById()->each(function (User $user) use (&$count) {
                // Relu sous verrou : le personnel a pu réactiver ou prolonger la suspension entre-temps.
                DB::transaction(function () use ($user, &$count) {
                    $user = User::whereKey($user->id)->lockForUpdate()->first();
                    if ($user?->status === 'suspended' && $user->suspended_until !== null && $user->suspended_until->lte(now())) {
                        $this->reactivate($user, null, 'Fin de la durée de suspension.');
                        $count++;
                    }
                });
            });

        return $count;
    }

    /** F29 : le joueur suspendu répond au motif, une seule fois par suspension. */
    public function appeal(User $user, AccountStatusHistory $suspension, string $message): SuspensionAppeal
    {
        if ($suspension->user_id !== $user->id || $user->status !== 'suspended' || ! $this->isCurrent($suspension)) {
            throw new BookingException(__('Cette suspension ne peut plus être contestée.'));
        }
        if ($suspension->appeal()->exists()) {
            throw new BookingException(__('Vous avez déjà contesté cette suspension.'));
        }

        return SuspensionAppeal::create(['account_status_history_id' => $suspension->id, 'user_id' => $user->id, 'message' => $message]);
    }

    /** F29 : un autre membre du personnel que celui qui a suspendu examine la contestation et décide. */
    public function decideAppeal(SuspensionAppeal $appeal, User $staff, bool $accepted, string $note): SuspensionAppeal
    {
        $appeal = DB::transaction(function () use ($appeal, $staff, $accepted, $note) {
            $appeal = SuspensionAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            if ($appeal->status !== 'received') {
                throw new BookingException(__('Cette contestation a déjà reçu une décision.'));
            }
            // Le compte a pu être réactivé ou suspendu à nouveau : la contestation ne vaut que pour la suspension en vigueur.
            $user = User::whereKey($appeal->user_id)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'suspended' || ! $this->isCurrent($appeal->suspension)) {
                throw new BookingException(__('Cette suspension n’est plus en vigueur. La contestation est sans objet.'));
            }
            if ($appeal->suspension->changed_by === $staff->id) {
                throw new BookingException(__('La contestation est examinée par une autre personne que celle qui a suspendu le compte.'));
            }
            $appeal->update(['status' => $accepted ? 'accepted' : 'rejected', 'decision_note' => $note, 'decided_by' => $staff->id, 'decided_at' => now()]);
            if ($accepted) {
                $this->reactivate($user, $staff, 'Contestation acceptée : '.$note);
            }

            return $appeal;
        });
        activity('staff')->performedOn($appeal->user)->causedBy($staff)->event('suspension-appeal-decided')
            ->withProperties(['accepted' => $accepted])->log('Décision sur une contestation de suspension');
        $appeal->user->notify(new SuspensionAppealDecided($appeal));

        return $appeal;
    }

    /** La suspension est le dernier changement d'état du compte, donc celle qui est en vigueur. */
    private function isCurrent(AccountStatusHistory $suspension): bool
    {
        return $suspension->to_status === 'suspended'
            && AccountStatusHistory::where('user_id', $suspension->user_id)->max('id') === $suspension->id;
    }

    private function change(User $user, string $to, string $reason, ?User $staff, mixed $until): AccountStatusHistory
    {
        $from = $user->status;
        $user->forceFill(['status' => $to, 'suspended_until' => $to === 'suspended' ? $until : null])->save();

        return AccountStatusHistory::create(['user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'until' => $until, 'changed_by' => $staff?->id]);
    }
}
