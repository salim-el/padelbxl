<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->ensureStaff($request);
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return Inertia::render('settings/two-factor', ['enabled' => true, 'qr' => null, 'secret' => null]);
        }
        // Le secret reste en session tant que le code n'est pas confirmé : un scan raté ne bloque pas le compte.
        $secret = $request->session()->get('two_factor.setup') ?? (new Google2FA)->generateSecretKey();
        $request->session()->put('two_factor.setup', $secret);

        return Inertia::render('settings/two-factor', [
            'enabled' => false,
            'qr' => (new QRCode)->render((new Google2FA)->getQRCodeUrl(config('app.name'), $user->email, $secret)),
            'secret' => $secret,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        // Déjà activée : un secret resté dans une autre session ne doit jamais remplacer le secret en place.
        abort_if($request->user()->hasTwoFactor(), 409);
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $secret = $request->session()->get('two_factor.setup');
        $counter = $secret ? (new Google2FA)->verifyKeyNewer($secret, $code, 0) : false;
        if ($counter === false) {
            return back()->withErrors(['code' => 'Code incorrect. Vérifiez l’heure de votre téléphone et réessayez.']);
        }
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_counter' => $counter])->save();
        $request->session()->forget('two_factor.setup');
        // Cette session vient de prouver le code ; les autres sessions du compte devront le saisir (RequireStaffTwoFactor).
        $request->session()->put('two_factor.passed', true);
        activity('staff')->performedOn($request->user())->event('two-factor-enabled')->log('Double authentification activée');

        return to_route('two-factor.edit')->with('success', 'Double authentification activée. Le code vous sera demandé à chaque connexion.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        $user = $request->user();
        // Retirer la protection demande les deux preuves : le mot de passe et le téléphone.
        $code = $request->validate(['password' => ['required', 'current_password'], 'code' => ['required', 'digits:6']])['code'];
        if (! $user->verifyTwoFactorCode($code)) {
            return back()->withErrors(['code' => 'Code incorrect.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_counter' => null])->save();
        activity('staff')->performedOn($user)->event('two-factor-disabled')->log('Double authentification désactivée');

        return to_route('two-factor.edit')->with('success', 'Double authentification désactivée.');
    }

    private function ensureStaff(Request $request): void
    {
        abort_unless($request->user()->can('manage-club'), 403);
    }
}
