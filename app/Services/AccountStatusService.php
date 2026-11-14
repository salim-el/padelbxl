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
            ->each(function (User $user) use (&$count) {
                $this->reactivate($user, null, 'Fin de la durée de suspension.');
                $count++;
            });

        return $count;
    }

    /** F29 : le joueur suspendu répond au motif, une seule fois par suspension. */
    public function appeal(User $user, AccountStatusHistory $suspension, string $message): SuspensionAppeal
    {
        if ($suspension->user_id !== $user->id || $suspension->to_status !== 'suspended' || $user->status !== 'suspended') {
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
            if ($appeal->suspension->changed_by === $staff->id) {
                throw new BookingException(__('La contestation est examinée par une autre personne que celle qui a suspendu le compte.'));
            }
            $appeal->update(['status' => $accepted ? 'accepted' : 'rejected', 'decision_note' => $note, 'decided_by' => $staff->id, 'decided_at' => now()]);
            if ($accepted && $appeal->user->status === 'suspended') {
                $this->reactivate($appeal->user, $staff, 'Contestation acceptée : '.$note);
            }

            return $appeal;
        });
        activity('staff')->performedOn($appeal->user)->causedBy($staff)->event('suspension-appeal-decided')
            ->withProperties(['accepted' => $accepted])->log('Décision sur une contestation de suspension');
        $appeal->user->notify(new SuspensionAppealDecided($appeal));

        return $appeal;
    }

    private function change(User $user, string $to, string $reason, ?User $staff, mixed $until): AccountStatusHistory
    {
        $from = $user->status;
        $user->forceFill(['status' => $to, 'suspended_until' => $to === 'suspended' ? $until : null])->save();

        return AccountStatusHistory::create(['user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'until' => $until, 'changed_by' => $staff?->id]);
    }
}
