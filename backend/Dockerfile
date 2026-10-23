FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libcurl4-openssl-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install \
    pdo_mysql \
    curl \
    simplexml \
    mbstring \
    bcmath \
    pcntl \
    zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    && composer check-platform-reqs --no-dev

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Schema changes are an explicit, separately approved deployment operation.
# Container startup must never mutate the database.
CMD ["sh", "-c", "php artisan config:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
