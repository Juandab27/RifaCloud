# RifaCloud payments-service (S4)
#
# Independent process and container that owns payments and provider webhook
# events, including the Mercado Pago Checkout Pro integration.
#
# Public routes (through Nginx):
#
#   POST /api/orders/{order}/payments
#   POST /api/orders/{order}/payments/{payment}/verify
#   POST /api/admin/orders/{order}/payments/{payment}/resolve
#   POST /api/webhooks/mercadopago
#
# Internal routes (network only, never proxied by Nginx):
#
#   POST /internal/payments/reconcile
#   GET  /health
#
# Authentication uses the shared Laravel session cookie and APP_KEY; the
# provider webhook uses the Mercado Pago signature. No client-supplied identity
# header is trusted. Order and number state changes are delegated to
# orders-service through a signed internal command.
FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN docker-php-ext-install pdo_mysql \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY services/payments-service/apache.conf /etc/apache2/conf-available/payments.conf
RUN a2enconf payments

WORKDIR /var/www/html

COPY services/payments-service/public ./public
COPY services/payments-service/src ./src
COPY services/payments-service/tests ./tests

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=12 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);'
