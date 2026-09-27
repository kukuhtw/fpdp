#!/bin/sh
# FPDP restore from an archive made by backup.sh. Replaces the database,
# storage/, and .env with the archive's copies — everything newer is lost.
#
# Usage:  sudo FPDP_DIR=/var/www/fpdp sh deploy/ubuntu/restore.sh /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz
#
# Practise this on a spare VPS or a scratch database before you need it:
# a backup that has never been restored is not yet a backup.
set -eu

ARCHIVE=${1:?Usage: restore.sh /path/to/fpdp-YYYYmmddTHHMMSSZ.tar.gz}
FPDP_DIR=${FPDP_DIR:-/var/www/fpdp}

umask 077
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
tar -C "$WORK" -xzf "$ARCHIVE"
for part in database.sql env storage.tar; do
    [ -f "$WORK/$part" ] || { echo "Archive is missing $part" >&2; exit 1; }
done

env_value() {
    sed -n "s/^$1=//p" "$WORK/env" | tail -n 1 | sed 's/^"\(.*\)"$/\1/'
}
cat > "$WORK/my.cnf" <<EOF
[client]
host=$(env_value DB_HOST)
port=$(env_value DB_PORT)
user=$(env_value DB_USERNAME)
password=$(env_value DB_PASSWORD)
EOF
DB_DATABASE=$(env_value DB_DATABASE)

printf 'This replaces database "%s", %s/storage and %s/.env. Type RESTORE to continue: ' "$DB_DATABASE" "$FPDP_DIR" "$FPDP_DIR"
read -r answer
[ "$answer" = "RESTORE" ] || { echo "Aborted."; exit 1; }

# Stop the background jobs while the data is swapped.
[ -f /etc/cron.d/fpdp ] && mv /etc/cron.d/fpdp /etc/cron.d/fpdp.disabled-by-restore

mysql --defaults-extra-file="$WORK/my.cnf" "$DB_DATABASE" < "$WORK/database.sql"

if [ -d "$FPDP_DIR/storage" ]; then
    mv "$FPDP_DIR/storage" "$FPDP_DIR/storage.before-restore.$(date -u +%Y%m%dT%H%M%SZ)"
fi
tar -C "$FPDP_DIR" -xf "$WORK/storage.tar"
cp "$WORK/env" "$FPDP_DIR/.env"
chown -R www-data:www-data "$FPDP_DIR/storage" "$FPDP_DIR/.env"
chmod 600 "$FPDP_DIR/.env"

# Apply any migration newer than the backup (a restore onto newer code).
sudo -u www-data php "$FPDP_DIR/database/migrate.php"

[ -f /etc/cron.d/fpdp.disabled-by-restore ] && mv /etc/cron.d/fpdp.disabled-by-restore /etc/cron.d/fpdp

echo "Restored from $ARCHIVE. Check: curl https://YOUR-DOMAIN/api/v1/health, log in, open a post with an image. Full guide: documentation/BACKUP-RESTORE.en.md"
