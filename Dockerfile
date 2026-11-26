# Image d’ArenaBxl. FrankenPHP réunit PHP 8.4 et le serveur web ; il sert le dossier public/.

# 1. PHP et les dépendances de production.
FROM dunglas/frankenphp:1-php8.4 AS php
# pdo_pgsql : PostgreSQL. gd : factures PDF et QR codes. intl : montants et dates en trois langues.
RUN install-php-extensions pdo_pgsql gd intl zip opcache
# CVE-2026-115560 : ArenaBxl n'utilise pas la progression des envois, l'option reste coupée en production.
RUN echo "session.upload_progress.enabled = Off" > "$PHP_INI_DIR/conf.d/zz-padelbxl.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# 2. Le front, construit à part. Vite lit Ziggy dans vendor/.
FROM node:22-alpine AS front
WORKDIR /app
# .env reste hors de l'image : Vite prend ici le nom affiché dans les onglets.
ARG VITE_APP_NAME=ArenaBxl
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=php /app/vendor/tightenco/ziggy vendor/tightenco/ziggy
RUN npm run build

# 3. L'application, sans node_modules ni outils de développement.
FROM php
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr SERVER_NAME=:8080
COPY . .
COPY --from=front /app/public/build public/build
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy
# Le serveur ne tourne pas en root.
USER www-data
EXPOSE 8080
ENTRYPOINT ["sh", "docker/start.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
