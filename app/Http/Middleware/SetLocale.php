<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['fr', 'nl', 'en'];

    // Langue choisie dans la session, sinon celle du compte, sinon le français du club (APP_LOCALE).
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', $request->user()?->locale);
        if (in_array($locale, self::LOCALES, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
