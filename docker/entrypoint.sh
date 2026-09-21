#!/bin/sh
set -e

mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/cache \
         /var/www/html/storage/updates \
         /var/www/html/config

chown -R www-data:www-data /var/www/html/storage /var/www/html/config 2>/dev/null || true
chmod -R ug+rwx /var/www/html/storage /var/www/html/config 2>/dev/null || true

exec "$@"
