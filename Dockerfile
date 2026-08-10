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
        zip \
    # libsmbclient-php gives icewind/smb (CIFS backend) a native, in-process client
    # instead of shelling out to smbclient — faster and avoids the CLI wrapper's
    # write-completion race. samba-dev/build tools are build-only: libsmbclient
    # itself stays installed afterward since samba-client already depends on it.
    && apk add --no-cache --virtual .smbclient-build-deps ${PHPIZE_DEPS} samba-dev \
    && pecl install smbclient \
    && docker-php-ext-enable smbclient \
    && apk del .smbclient-build-deps

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
