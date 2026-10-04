FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

ARG INSTALL_DEV=false

RUN if [ "$INSTALL_DEV" = "true" ]; then DEV_FLAGS=""; else DEV_FLAGS="--no-dev"; fi \
    && composer install $DEV_FLAGS \
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

FROM php:8.4-fpm-alpine AS application

WORKDIR /app

RUN apk add --no-cache libpq nginx \
    && apk add --no-cache --virtual .build-dependencies $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pcntl pdo_pgsql \
    && apk del .build-dependencies

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build
COPY nginx.conf /etc/nginx/nginx.conf

RUN cp .env.example .env \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/log/nginx \
    && printf '[www]\nlisten = 127.0.0.1:9000\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-app.conf

USER www-data

EXPOSE 8000

CMD ["sh", "-c", "php-fpm -D && exec nginx -g 'daemon off;'"]
