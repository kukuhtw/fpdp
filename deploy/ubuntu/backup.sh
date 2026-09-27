#!/bin/sh
# FPDP backup: one consistent recovery point = database dump + storage/
# (media, product files) + .env (APP_KEY decrypts stored gateway and
# OAuth credentials — without it an encrypted database is useless).
#
# Usage:  sudo FPDP_DIR=/var/www/fpdp BACKUP_DIR=/var/backups/fpdp KEEP_DAYS=14 sh deploy/ubuntu/backup.sh
# Restore: see deploy/ubuntu/restore.sh and documentation/BACKUP-RESTORE.en.md (.id.md).
#
# The archive holds secrets and buyers' personal data: it is written 600,
# root-only. Copy it off the server (another region/provider) and encrypt it
# there — a backup on the same disk does not survive losing the VPS.
set -eu

FPDP_DIR=${FPDP_DIR:-/var/www/fpdp}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/fpdp}
KEEP_DAYS=${KEEP_DAYS:-14}

env_value() {
    # Reads KEY=value from .env without executing it.
    sed -n "s/^$1=//p" "$FPDP_DIR/.env" | tail -n 1 | sed 's/^"\(.*\)"$/\1/'
}

DB_HOST=$(env_value DB_HOST)
DB_PORT=$(env_value DB_PORT)
DB_DATABASE=$(env_value DB_DATABASE)
DB_USERNAME=$(env_value DB_USERNAME)
DB_PASSWORD=$(env_value DB_PASSWORD)

umask 077
mkdir -p "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Password via a temporary option file, not the command line (visible in ps).
cat > "$WORK/my.cnf" <<EOF
[client]
host=${DB_HOST:-127.0.0.1}
port=${DB_PORT:-3306}
user=${DB_USERNAME}
password=${DB_PASSWORD}
EOF

# --single-transaction: a consistent InnoDB snapshot without locking the site.
mysqldump --defaults-extra-file="$WORK/my.cnf" --single-transaction --routines --no-tablespaces "$DB_DATABASE" > "$WORK/database.sql"
cp "$FPDP_DIR/.env" "$WORK/env"
tar -C "$FPDP_DIR" -cf "$WORK/storage.tar" storage

ARCHIVE="$BACKUP_DIR/fpdp-$STAMP.tar.gz"
tar -C "$WORK" -czf "$ARCHIVE" database.sql env storage.tar
chmod 600 "$ARCHIVE"

find "$BACKUP_DIR" -name 'fpdp-*.tar.gz' -type f -mtime "+$KEEP_DAYS" -delete

echo "[$(date -u +%FT%TZ)] backup written: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
