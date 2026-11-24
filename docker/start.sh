#!/bin/sh
# Démarrage du conteneur : caches de production, schéma de la base à jour pour le serveur web, puis la commande demandée.
set -e
# Render génère APP_KEY en base64 sans le préfixe « base64: » que Laravel attend.
case "$APP_KEY" in ''|base64:*) ;; *) export APP_KEY="base64:$APP_KEY" ;; esac
# Render donne l'adresse publique du service : les liens des e-mails envoyés par le scheduler en ont besoin.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi
php artisan optimize
if [ "$1" = "frankenphp" ]; then
    php artisan migrate --force
    # Démo en ligne : une base sans terrain reçoit une seule fois les terrains, les tarifs et les comptes de démo.
    if [ "$SEED_DEMO" = "1" ] && php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(App\Models\Court::exists() ? 1 : 0);'; then
        php artisan db:seed --force
    fi
    # Plan gratuit de Render : pas de worker, le scheduler tourne à côté du serveur web.
    # shortcut: il dort avec le service gratuit et n'est pas relancé s'il s'arrête ; un worker Render payant le remplace.
    if [ -n "$RENDER" ]; then
        php artisan schedule:work &
    fi
fi
exec "$@"
