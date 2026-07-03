#!/bin/sh
set -e

wait_for_db() {
    host="${DB_HOST:-postgres}"
    port="${DB_PORT:-5432}"
    echo "Waiting for database at ${host}:${port}..."
    until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
        sleep 2
    done
    echo "Database is reachable."
}

wait_for_db

if [ "$1" = "php-fpm" ]; then
    php artisan app:data-migrate --import
    php artisan import:csv WIS_Ayeduase.csv
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
