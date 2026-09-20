#!/bin/sh
set -eu

cd /var/www/html

if [ "${APP_ENV:-production}" = "production" ]; then
    case "${APP_KEY:-}" in
        ""|replace-with-*) echo "APP_KEY must be replaced with a production secret." >&2; exit 1 ;;
    esac
    if [ "${#APP_KEY}" -lt 64 ]; then
        echo "APP_KEY must contain at least 64 characters." >&2
        exit 1
    fi
    case "${DB_PASSWORD:-}" in
        ""|replace-with-*) echo "DB_PASSWORD must be replaced with a production secret." >&2; exit 1 ;;
    esac
    if [ -n "${BOOTSTRAP_OWNER_PASSWORD:-}" ]; then
        case "$BOOTSTRAP_OWNER_PASSWORD" in
            replace-with-*) echo "BOOTSTRAP_OWNER_PASSWORD still contains the template placeholder." >&2; exit 1 ;;
        esac
    fi
fi

mkdir -p storage/cv storage/media storage/logs storage/tmp
chown -R www-data:www-data storage

if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    attempts=0
    until mysqladmin ping \
        --skip-ssl \
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
