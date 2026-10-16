# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5

# Stage 1: install production dependencies with Composer.
FROM serversideup/php:${PHP_VERSION}-cli AS vendor

# Dependency files first: this layer stays cached until composer.lock changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    && composer check-platform-reqs --no-dev

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Stage 2: runtime image with nginx + php-fpm. Composer cache and build files stay behind.
FROM serversideup/php:${PHP_VERSION}-fpm-nginx AS runtime

ENV PHP_OPCACHE_ENABLE=1

COPY --chown=www-data:www-data --from=vendor /var/www/html /var/www/html

# The base image already runs as www-data on port 8080. Explicit for readers.
USER www-data
EXPOSE 8080
