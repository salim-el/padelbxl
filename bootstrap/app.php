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
        // Chez Render, l'appli ne voit que son répartiteur : il ajoute à X-Forwarded-For l'adresse qui l'appelle,
        // celle d'un serveur Cloudflare quand Cloudflare est devant lui. Laravel remonte la liste tant qu'il croise
        // un proxy connu (pair direct, réseau privé, Cloudflare) : la première autre adresse est celle du joueur.
        // shortcut: le pair direct est toujours cru (sans proxy devant l'appli, un client choisirait son adresse) et
        // toute adresse Cloudflare aussi : un Worker Cloudflare peut encore annoncer une fausse adresse. Si Render est
        // bien derrière Cloudflare, lire plutôt CF-Connecting-IP, que Cloudflare réécrit toujours.
        // Plages de cloudflare.com/ips au 25/11/2026 : les relire si Cloudflare en ajoute.
        $middleware->trustProxies(at: [
            'REMOTE_ADDR', 'private_ranges',
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
            '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
        // F3 : chaque session garde l'empreinte du mot de passe. Un nouveau mot de passe ferme les autres sessions
        // et les cookies « Rester connecté » ; la session qui fait le changement reste ouverte.
        $middleware->authenticateSessions();
        // F9 et F30 : Stripe ne peut pas envoyer de jeton CSRF ; la signature de la notification le remplace.
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
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
