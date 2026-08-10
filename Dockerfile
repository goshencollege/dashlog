FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    bash \
    git \
    openssh-client \
    unzip \
    icu-dev \
    icu-libs \
    libzip-dev \
    mysql-client \
    samba-client \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        intl \
        opcache \
        zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS prod
COPY . /var/www/html
ARG APP_ENV=prod
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
