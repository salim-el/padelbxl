<?php

namespace App\Http\Middleware;

use App\Services\LegalConsents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** F7 : un joueur accepte la version en vigueur des conditions et de la vie privée avant de réserver. */
class EnsureLegalConsents
{
    public function __construct(private LegalConsents $consents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->isStaff() && $this->consents->missing($user)->isNotEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('Acceptez d’abord la nouvelle version des conditions dans l’application.')], 403);
            }

            return redirect()->guest(route('legal.accept'));
        }

        return $next($request);
    }
}
