FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip libicu-dev \
    && docker-php-ext-install pdo_mysql intl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY backend/composer.* ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY backend/ ./
RUN mkdir -p var && chown -R www-data:www-data var
USER www-data
