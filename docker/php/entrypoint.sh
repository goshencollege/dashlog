#!/bin/sh
set -e

# Enable display_errors in non-production environments
mkdir -p /tmp/php-conf
if [ "${APP_ENV}" != "prod" ]; then
    echo "display_errors = On" > /tmp/php-conf/env.ini
fi
export PHP_INI_SCAN_DIR=":/tmp/php-conf"

# Ensure Symfony var subdirectories exist and are writable by www-data
for dir in /var/www/html/var/cache /var/www/html/var/log; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done

exec docker-php-entrypoint "$@"
