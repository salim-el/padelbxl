<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ReactivateAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** F4 : pendant trente jours, le joueur qui a désactivé son compte peut le retrouver avec son historique. */
class AccountReactivationController extends Controller
{
    public const DAYS = 30;

    public function create(Request $request): Response
    {
        return Inertia::render('auth/reactivate-account', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $email = mb_strtolower($request->string('email'));
        // Recherche et envoi après la réponse : même texte et même durée que le compte existe ou non,
        // la page ne dit pas qui est inscrit.
        defer(fn () => $this->reactivable()->whereRaw('lower(email) = ?', [$email])->first()?->notify(new ReactivateAccount));

        return back()->with('status', __('Si un compte désactivé depuis moins de trente jours utilise cette adresse, un lien de réactivation vient d’y être envoyé.'));
    }

    public function update(int $user): RedirectResponse
    {
        // Un compte déjà réactivé, anonymisé ou hors délai n'est plus trouvé : le lien ne sert qu'une fois.
        $account = $this->reactivable()->findOrFail($user);
        $account->restore();
        $account->forceFill(['status' => 'active'])->save();
        activity('account')->performedOn($account)->causedBy($account)->event('account-reactivated-by-owner')->log('Compte réactivé par son titulaire');

        return to_route('login')->with('status', __('Votre compte est réactivé. Vous pouvez vous connecter.'));
    }

    /** @return Builder<User> */
    private function reactivable(): Builder
    {
        return User::onlyTrashed()->where('status', 'deactivated')->whereNull('anonymized_at')
            ->where('deactivated_at', '>', now()->subDays(self::DAYS));
    }
}
