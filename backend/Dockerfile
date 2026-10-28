FROM dunglas/frankenphp:1-php8.2-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    pdo_mysql \
    curl \
    simplexml \
    mbstring \
    bcmath \
    pcntl \
    zip \
    opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    && composer check-platform-reqs --no-dev

COPY Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp.ini "$PHP_INI_DIR/conf.d/zz-kps-production.ini"

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    /config/caddy \
    /data/caddy \
    && chown -R www-data:www-data storage bootstrap/cache /config /data

# Port 10000 is unprivileged, so the runtime does not need the image's
# low-port capability. The command is best-effort across image revisions.
RUN setcap -r /usr/local/bin/frankenphp 2>/dev/null || true

USER www-data

EXPOSE 10000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$port = getenv("PORT") ?: "10000"; exit(@file_get_contents("http://127.0.0.1:".$port."/up") === false ? 1 : 0);'

# Schema changes are an explicit, separately approved deployment operation.
# Container startup must never mutate the database. exec keeps FrankenPHP as
# PID 1 after configuration caching so Render signals are handled directly.
CMD ["sh", "-c", "php artisan config:cache && exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"]
