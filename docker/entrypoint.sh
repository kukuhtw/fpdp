#!/bin/sh
set -eu

cd /var/www/html

mkdir -p storage/cv storage/logs storage/tmp
chown -R www-data:www-data storage

if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    attempts=0
    until mysqladmin ping \
        --host="${DB_HOST:-db}" \
        --port="${DB_PORT:-3306}" \
        --user="${DB_USERNAME:-fpdp}" \
        --password="${DB_PASSWORD:-}" \
        --silent; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "Database did not become ready within 120 seconds." >&2
            exit 1
        fi
        echo "Waiting for database (${attempts}/60)..."
        sleep 2
    done
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php database/migrate.php
fi

if [ -n "${BOOTSTRAP_OWNER_EMAIL:-}" ]; then
    php database/bootstrap-owner.php
fi

if [ "${DISABLE_WEB_INSTALLER:-true}" = "true" ]; then
    touch storage/installed.lock
    chown www-data:www-data storage/installed.lock
fi

exec "$@"
