<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\BlockedIdentity;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * F35 : l'administrateur ajoute un membre du personnel et change son rôle.
 * Le rôle est relu en base à chaque requête : le changement vaut dès la requête suivante.
 */
class StaffService
{
    public const ROLES = ['manager', 'admin'];

    /** Nouveau compte, ou joueur actif promu. */
    public function invite(User $admin, string $name, string $email, string $role): User
    {
        $email = mb_strtolower(trim($email));
        if (BlockedIdentity::blocksEmail($email)) {
            throw new BookingException(__('Cette adresse appartient à un compte révoqué.'));
        }
        $user = User::withTrashed()->where('email', $email)->first();
        if ($user?->isStaff()) {
            throw new BookingException(__('Cette personne fait déjà partie du personnel.'));
        }
        if ($user?->trashed() || ($user && $user->status !== 'active')) {
            throw new BookingException(__('Ce compte est désactivé ou suspendu : réglez d’abord sa situation.'));
        }

        $created = $user === null;
        $user = DB::transaction(function () use ($user, $name, $email, $role) {
            if ($user === null) {
                // Personne ne connaît ce mot de passe : seul le lien envoyé à l'adresse permet d'entrer, ce qui la confirme.
                $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::password(40)]);
                $user->forceFill(['email_verified_at' => now()]);
            }
            // Le personnel n'utilise pas l'API (TokenController) : les jetons d'un joueur promu tombent.
            $user->tokens()->delete();
            $user->forceFill(['role' => $role])->save();

            return $user;
        });

        activity('staff')->performedOn($user)->causedBy($admin)->event($created ? 'staff-invited' : 'staff-role-changed')
            ->withProperties(['role' => $role])->log($created ? 'Membre du personnel ajouté' : 'Rôle modifié');
        $user->notify(new StaffInvitation($role, $created ? $this->resetUrl($user) : null));

        return $user;
    }

    /**
     * Rôle « player » : la personne quitte le personnel et garde un compte joueur.
     * L'administrateur ne change pas son propre rôle : il reste donc toujours au moins un administrateur.
     */
    public function changeRole(User $admin, User $user, string $role): void
    {
        if ($admin->is($user)) {
            throw new BookingException(__('Vous ne pouvez pas changer votre propre rôle.'));
        }
        if (! $user->isStaff()) {
            throw new BookingException(__('Ce compte ne fait pas partie du personnel.'));
        }
        $old = $user->role;
        DB::transaction(function () use ($user, $role) {
            $user->tokens()->delete();
            $user->forceFill(['role' => $role])->save();
        });
        activity('staff')->performedOn($user)->causedBy($admin)->event('staff-role-changed')
            ->withProperties(['old' => $old, 'role' => $role])->log('Rôle modifié');
    }

    /** Lien expiré ou e-mail perdu : un nouveau lien remplace l'ancien. */
    public function resendInvitation(User $user): void
    {
        if (! $user->isStaff()) {
            throw new BookingException(__('Ce compte ne fait pas partie du personnel.'));
        }
        $user->notify(new StaffInvitation($user->role, $this->resetUrl($user)));
    }

    private function resetUrl(User $user): string
    {
        return route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email]);
    }
}
