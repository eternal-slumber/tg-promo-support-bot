FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources

RUN npm ci && npm run build

FROM php:8.4-cli-alpine AS application

WORKDIR /app

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-dependencies $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pcntl pdo_pgsql \
    && apk del .build-dependencies

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN cp .env.example .env \
    && rm -f bootstrap/cache/*.php \
    && php artisan key:generate --force \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
