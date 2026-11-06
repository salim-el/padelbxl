<?php

use App\Support\ViteCspNonce;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Presets\Basic;
use Spatie\Csp\Scheme;

// Le navigateur n'accepte que nos propres fichiers. Les scripts et styles inline doivent porter le nonce de la requête.
return [
    'presets' => [
        Basic::class,
    ],

    'directives' => [
        [Directive::IMG, Scheme::DATA], // QR code d'accès, rendu en image SVG inline
        [Directive::FRAME_ANCESTORS, Keyword::NONE],
        // Inertia masque sa barre de chargement avec « #nprogress { display: none; } » : seul ce texte exact est permis.
        [Directive::STYLE, "'sha256-s0f9DrFYo3bm+TdJu1cRcxZWbQ+Kdh13sSjexIM3gcI='"],
    ],

    'report_uri' => env('CSP_REPORT_URI', ''),

    'enabled' => env('CSP_ENABLED', true),

    'nonce_generator' => ViteCspNonce::class,
];
