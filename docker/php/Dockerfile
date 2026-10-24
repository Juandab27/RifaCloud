# RifaCloud application image (PHP-FPM)
FROM php:8.4-fpm

# Runtime dependencies for Composer and the MySQL driver
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer binary (pinned to the Composer 2 image, not installed from the network at runtime)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-rifacloud.ini

WORKDIR /var/www

EXPOSE 9000
CMD ["php-fpm"]
