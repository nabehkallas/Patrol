# syntax=docker/dockerfile:1

# The frontend build (`npm run build`) itself shells out to `php artisan wayfinder:generate`
# via the @laravel/vite-plugin-wayfinder Vite plugin — so unlike a typical Laravel+Vite setup,
# the JS build can't happen in an isolated Node-only stage; PHP and Node have to coexist in the
# same build environment.
FROM php:8.3-fpm-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor curl unzip git sqlite3 ca-certificates gnupg \
        libsqlite3-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev libicu-dev libsodium-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo pdo_sqlite bcmath gd zip intl opcache sodium pcntl \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Litestream: continuously replicates every SQLite file to S3-compatible storage so station
# data survives more than just a container restart (config is generated at runtime — see
# deploy/litestream-watch.sh and deploy/entrypoint.sh).
ADD https://github.com/benbjohnson/litestream/releases/download/v0.3.13/litestream-v0.3.13-linux-amd64.tar.gz /tmp/litestream.tar.gz
RUN tar -C /usr/local/bin -xzf /tmp/litestream.tar.gz litestream && rm /tmp/litestream.tar.gz

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# A throwaway .env + app key, used only so the artisan commands below (and the wayfinder
# plugin's own artisan call during `npm run build`) can boot the framework during the build.
# Removed before the image is finalized — Fly injects the real production config as actual
# environment variables at container runtime, no .env file involved.
RUN cp .env.example .env \
    && php artisan key:generate --force \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form \
    && npm run build \
    && php artisan event:cache \
    && rm .env \
    && rm -rf node_modules

COPY deploy/nginx.conf /etc/nginx/sites-enabled/default
COPY deploy/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY deploy/litestream-watch.sh /usr/local/bin/litestream-watch.sh
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh /usr/local/bin/litestream-watch.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
