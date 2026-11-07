# RifaCloud orders-service (S3)
#
# Independent process and container that owns orders, reservations,
# order_items and number allocation, plus reservation expiry.
#
# Public routes (through Nginx):
#
#   POST /api/orders
#   GET  /api/orders/{id}
#
# Internal routes (network only, never proxied by Nginx):
#
#   POST /internal/orders/expire-reservations
#   GET  /health
#
# Authentication uses the shared Laravel session cookie and APP_KEY; no
# client-supplied identity header is trusted. Payments, webhooks and checkout
# remain in the monolith until S4.
#
# The shared MySQL schema is a deliberate transitional decision, not the target
# state: see README.md for data-ownership and isolation notes.
FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN docker-php-ext-install pdo_mysql \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY services/orders-service/apache.conf /etc/apache2/conf-available/orders.conf
RUN a2enconf orders

WORKDIR /var/www/html

COPY services/orders-service/public ./public
COPY services/orders-service/src ./src
COPY services/orders-service/tests ./tests

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=12 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);'
