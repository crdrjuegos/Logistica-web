# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4

# ---------- Base: PHP + extensiones + Composer (dev y producción) ----------
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base
# Debian por HTTPS: algunos proxies (p. ej. el de Docker Desktop) responden 403 al apt por HTTP.
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources
RUN install-php-extensions pdo_pgsql pgsql pcntl bcmath intl opcache zip @composer
ENV COMPOSER_PROCESS_TIMEOUT=900 COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# ---------- Dev: herramientas (artisan, pest, pint...) con el código montado por volumen ----------
FROM base AS dev

# ---------- Vendor de producción ----------
FROM base AS vendor
COPY composer.json composer.lock ./
# Reintentos: GitHub corta a veces la conexión durante la descarga (curl error 56).
RUN for i in 1 2 3 4; do \
      composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction && exit 0; \
      echo "Reintentando composer install ($i/4)..."; sleep 8; \
    done; exit 1
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------- Assets (Vite) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY resources ./resources
COPY vite.config.* ./
COPY public ./public
RUN npm run build

# ---------- Imagen final ----------
FROM base AS app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN rm -f bootstrap/cache/*.php \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && php artisan package:discover --ansi \
 && mkdir -p /data/caddy /config/caddy  && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy
USER www-data
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/up") === false ? 1 : 0);'
# FrankenPHP clásico (sin Octane): sirve public/ por HTTP en el puerto 8000; Caddy pone el HTTPS delante.
CMD ["frankenphp", "php-server", "--listen", "0.0.0.0:8000", "--root", "/app/public"]
