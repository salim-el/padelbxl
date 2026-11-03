<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/** Deuxième étape de connexion : le mot de passe est bon, il manque le code de l'application d'authentification. */
class TwoFactorChallengeController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const CHALLENGE_MINUTES = 5;

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return to_route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $user = $this->pendingUser($request);
        if ($user === null) {
            return to_route('login')->withErrors(['email' => 'La vérification a expiré. Reconnectez-vous.']);
        }
        // Compté par compte et non par adresse IP : changer de réseau ne donne pas de nouveaux essais.
        // L'essai est compté avant la vérification : des requêtes envoyées en même temps ne passent pas entre les mailles.
        $key = 'two-factor|'.$user->id;
        if (RateLimiter::hit($key, 15 * 60) > self::MAX_ATTEMPTS) {
            $request->session()->forget(['login.id', 'login.remember', 'login.at']);

            return to_route('login')->withErrors(['email' => 'Trop de codes incorrects. Réessayez dans 15 minutes.']);
        }
        if (! $user->verifyTwoFactorCode($code)) {
            return back()->withErrors(['code' => 'Code incorrect.']);
        }
        RateLimiter::clear($key);
        Auth::login($user, (bool) $request->session()->pull('login.remember'));
        $request->session()->forget(['login.id', 'login.at']);
        $request->session()->regenerate();
        $request->session()->put('two_factor.passed', true);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    // Le défi ne vaut que quelques minutes après le mot de passe : une session abandonnée ne reste pas à mi-chemin.
    private function pendingUser(Request $request): ?User
    {
        $at = $request->session()->get('login.at');
        if (! $request->session()->has('login.id') || $at === null || now()->timestamp - $at > self::CHALLENGE_MINUTES * 60) {
            return null;
        }

        return User::find($request->session()->get('login.id'));
    }
}
