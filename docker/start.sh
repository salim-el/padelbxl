#!/bin/sh
# Démarrage du conteneur : caches de production, schéma de la base à jour pour le serveur web, puis la commande demandée.
set -e
php artisan optimize
if [ "$1" = "frankenphp" ]; then
    php artisan migrate --force
fi
exec "$@"
