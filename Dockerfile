FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libonig-dev poppler-utils \
    && docker-php-ext-install pdo_mysql mbstring gd \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

# Render's php:apache image sets AllowOverride None by default — the app's
# .htaccess files (blocking config/, database/, includes/, storage/, and
# dotfiles) need AllowOverride All to take effect.
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN chown -R www-data:www-data /var/www/html/storage

# Render injects a $PORT env var and routes traffic there — Apache must
# listen on it instead of the default 80.
RUN echo '#!/bin/sh\nset -e\nsed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-enabled/000-default.conf\nexec apache2-foreground' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["/usr/local/bin/start.sh"]
