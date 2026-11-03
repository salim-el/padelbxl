<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le personnel ne navigue qu'avec la double authentification activée et prouvée dans la session (T16).
 * Appliqué à toutes les pages web : seules l'activation, la saisie du code et la déconnexion restent ouvertes.
 */
class RequireStaffTwoFactor
{
    private const OPEN = ['two-factor.edit', 'two-factor.store', 'two-factor.login', 'logout', 'locale.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['manager', 'admin'], true) || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }
        // Session ouverte sans le code (« rester connecté », ou ouverte avant l'activation) : on redemande le code.
        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor.passed')) {
            $remember = $request->hasCookie(Auth::guard('web')->getRecallerName());
            Auth::guard('web')->logout();
            $request->session()->put(['login.id' => $user->id, 'login.remember' => $remember, 'login.at' => now()->timestamp, 'url.intended' => $request->fullUrl()]);

            return to_route('two-factor.login');
        }
        if (config('auth.staff_two_factor') && ! $user->hasTwoFactor()) {
            return to_route('two-factor.edit');
        }

        return $next($request);
    }
}
