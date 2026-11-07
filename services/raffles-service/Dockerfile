# RifaCloud raffles-service (first incremental extraction)
#
# This container is an independent process with its own image and lifecycle.
# S2 exposes the public read-only catalog and raffle administration:
#
#   GET    /api/raffles
#   GET    /api/raffles/{id}
#   GET    /api/raffles/{id}/numbers
#   GET    /api/admin/raffles
#   POST   /api/admin/raffles
#   GET    /api/admin/raffles/{id}
#   PUT    /api/admin/raffles/{id}
#   DELETE /api/admin/raffles/{id}
#   POST   /api/admin/raffles/{id}/publish
#   POST   /api/admin/raffles/{id}/close
#   GET    /health
#
# Reservations, orders, payments, webhooks and authentication remain in their
# owning services. Admin requests are authorized with the shared Laravel
# session cookie and the ADMIN role; no client-supplied identity header is
# trusted.
#
# The shared schema is a deliberate transitional decision, not the target
# state: see README.md for the data-ownership risks and the eventual isolation
# design.
FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN docker-php-ext-install pdo_mysql \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY services/raffles-service/apache.conf /etc/apache2/conf-available/raffles.conf
RUN a2enconf raffles

WORKDIR /var/www/html

COPY services/raffles-service/public ./public
COPY services/raffles-service/src ./src
COPY services/raffles-service/tests ./tests

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=12 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);'
