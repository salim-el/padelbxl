<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureLegalConsents;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(AddSecurityHeaders::class);
        // F3 : chaque session garde l'empreinte du mot de passe. Un nouveau mot de passe ferme les autres sessions
        // et les cookies « Rester connecté » ; la session qui fait le changement reste ouverte.
        $middleware->authenticateSessions();
        $middleware->web(append: [
            EnsureAccountIsActive::class,
            RequireStaffTwoFactor::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AddCspHeaders::class,
        ]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'consented' => EnsureLegalConsents::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Un logiciel partenaire reçoit toujours du JSON, jamais une page HTML d'erreur.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
    })->create();
