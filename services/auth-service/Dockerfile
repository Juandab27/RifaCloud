# RifaCloud auth-service (PHP-FPM, independently deployable)
FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dependencies are installed from the locked manifest, never from source code.
COPY services/auth-service/composer.json services/auth-service/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-progress

COPY services/auth-service/ ./

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

EXPOSE 9000

CMD ["php-fpm"]
