# FPDP Backup and Restore

How an FPDP node is backed up, how to get a backup off the server, and how to restore it. The scripts are [`deploy/ubuntu/backup.sh`](../deploy/ubuntu/backup.sh) and [`deploy/ubuntu/restore.sh`](../deploy/ubuntu/restore.sh) for an Ubuntu VPS; §9 covers Dokploy/Docker.

Bahasa Indonesia: [BACKUP-RESTORE.id.md](BACKUP-RESTORE.id.md).

## 1. Summary

| | |
|---|---|
| What | Database dump + the whole `storage/` folder + `.env` |
| Where | `/var/backups/fpdp/fpdp-<UTC time>.tar.gz`, e.g. `fpdp-20260927T023000Z.tar.gz` |
| When | Every night at 02:30 (server time), from `/etc/cron.d/fpdp` |
| How long | 14 days; older archives are deleted automatically |
| Who can read it | root only (mode `600`) |
| Log | `/var/log/fpdp/backup.log` |
| Restore | `sudo sh deploy/ubuntu/restore.sh <archive>`, manual, over SSH |

Backup and restore are **not** available from the owner dashboard: they run on the server.

## 2. What is in a backup

Each archive is one consistent recovery point with three files:

| File | Contents | Why it is needed |
|---|---|---|
| `database.sql` | `mysqldump` of the FPDP database: profile, posts, products, orders, payments, federation (including the node's signing keys), settings, audit trail | Everything the application stores in MySQL |
| `storage.tar` | The `storage/` folder: uploaded media, digital product files, RAG documents, `installed.lock` | Records in the database point to these files |
| `env` | A copy of `.env` | `APP_KEY` decrypts the stored payment gateway, LLM and OAuth credentials and the 2FA secrets. A database restored with a different `APP_KEY` cannot use them. It also holds the database password restore connects with |

**Not included** — keep these some other way:

- the application code (restore it from git at the same or a newer version);
- server configuration: Nginx (`deploy/ubuntu/nginx-fpdp.conf`), PHP settings, `/etc/cron.d/fpdp`, TLS certificates (Certbot re-issues them);
- logs in `/var/log/fpdp/`;
- anything in `storage/archive/` is included (it is under `storage/`), so remove old CV archives there once they are copied off the server.

## 3. How the backup works

`backup.sh` runs as root and:

1. reads the database host, port, name, user and password from `.env` (without executing the file);
2. writes the password to a temporary option file readable only by root, so it never appears on the command line (`ps`);
3. dumps the database with `mysqldump --single-transaction --routines`: a consistent InnoDB snapshot **without locking the site** — visitors and the owner keep working during the backup;
4. copies `.env` and packs `storage/` into `storage.tar`;
5. packs the three into `/var/backups/fpdp/fpdp-<UTC time>.tar.gz` with mode `600` (`umask 077` from the start);
6. deletes archives older than 14 days;
7. removes the temporary folder, including the password file, even if a step fails.

Settings, as environment variables: `FPDP_DIR` (default `/var/www/fpdp`), `BACKUP_DIR` (default `/var/backups/fpdp`), `KEEP_DAYS` (default `14`).

### Setup (once)

```bash
sudo mkdir -p /var/log/fpdp && sudo chown www-data:www-data /var/log/fpdp
sudo cp /var/www/fpdp/deploy/ubuntu/fpdp.cron /etc/cron.d/fpdp && sudo chmod 644 /etc/cron.d/fpdp
```

The cron file also runs the other background jobs (federation delivery, feed sync, payment reconciliation). The backup line is:

```text
30 2 * * *   root   FPDP_DIR=$FPDP_DIR /bin/sh $FPDP_DIR/deploy/ubuntu/backup.sh >> /var/log/fpdp/backup.log 2>&1
```

### Run a backup by hand

Before an upgrade, a migration that changes data, or a risky change:

```bash
sudo FPDP_DIR=/var/www/fpdp sh /var/www/fpdp/deploy/ubuntu/backup.sh
# [2026-09-27T09:15:02Z] backup written: /var/backups/fpdp/fpdp-20260927T091500Z.tar.gz (48M)
```

### Check that backups are being made

```bash
sudo ls -lh /var/backups/fpdp/          # one archive per night, 14 at most
sudo tail -n 5 /var/log/fpdp/backup.log # one "backup written" line per run
sudo tar -tzf /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz   # database.sql, env, storage.tar
```

An empty or missing log line means the job did not run (cron file not installed, wrong `FPDP_DIR`) or failed (`mysqldump` missing, wrong database password in `.env`).

## 4. Copy backups off the server

A backup on the same disk is lost together with the VPS. Copy archives regularly to another place — your laptop, another provider or region.

The archives are root-only, so copy one to your home folder first:

```bash
# on the VPS
sudo cp /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz ~/
sudo chown $USER: ~/fpdp-20260927T023000Z.tar.gz
gpg -c ~/fpdp-20260927T023000Z.tar.gz          # optional but recommended: encrypt with a passphrase
rm ~/fpdp-20260927T023000Z.tar.gz              # keep only the .gpg copy in your home folder
```

```powershell
# on the laptop (Windows PowerShell; scp is built in on Windows 10/11)
scp user@VPS_IP:~/fpdp-20260927T023000Z.tar.gz.gpg "$HOME\Downloads\"
# other SSH port: scp -P 2222 ...    key file: scp -i C:\Users\you\.ssh\id_ed25519 ...
```

```bash
# on the VPS, afterwards
rm ~/fpdp-20260927T023000Z.tar.gz.gpg
```

WinSCP or FileZilla (SFTP) work too. Decrypt later with `gpg -d file.tar.gz.gpg > file.tar.gz` (Gpg4win on Windows).

## 5. How the restore works

```bash
sudo FPDP_DIR=/var/www/fpdp sh /var/www/fpdp/deploy/ubuntu/restore.sh /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz
```

`restore.sh`:

1. unpacks the archive into a temporary root-only folder and checks that `database.sql`, `env` and `storage.tar` are all there;
2. reads the database connection from the **archive's** `env`;
3. asks you to type `RESTORE` — anything else aborts with nothing changed;
4. pauses the background jobs (renames `/etc/cron.d/fpdp`), so nothing writes while data is swapped;
5. loads `database.sql` into the database: every table in the dump is dropped and recreated with the backup's rows;
6. moves the current `storage/` aside to `storage.before-restore.<time>` (kept, not deleted) and unpacks the backup's `storage/`;
7. puts the backup's `.env` in place (`600`, owned by `www-data`);
8. runs `database/migrate.php`, so a backup restored onto newer code gets the newer migrations;
9. turns the background jobs back on.

**Everything written after the backup is lost**: posts, orders, payments, followers, uploads since that night. Payments confirmed at a gateway after the backup can be brought back with the payment reconciliation job (`scripts/reconcile-payments.php`, also in the cron), for gateways that have a status API.

## 6. Restore on the same server

1. Take a fresh backup first (§3), in case you need to go back.
2. Run `restore.sh` with the archive you want (§5).
3. Check the node (§8).
4. When all is well, delete the old storage copy: `sudo rm -rf /var/www/fpdp/storage.before-restore.*`.

## 7. Restore on a new server (moving, or after losing the VPS)

1. Install the server as in the [Deployment Guide](DEPLOYMENT-GUIDE.en.md) §4.1–4.5: packages, Nginx, PHP, TLS, and the code at the **same or a newer** version (`git clone`, `git checkout <tag>`).
2. Create the database and user **with the same name, user and password as in the backup's `env`** — restore connects with those. To see them: `sudo tar -xzf fpdp-….tar.gz -C /tmp/fpdp-check env && sudo grep ^DB_ /tmp/fpdp-check/env && sudo rm -rf /tmp/fpdp-check` (create the folder first with `sudo mkdir /tmp/fpdp-check`).
3. Do **not** run the web installer or register a new owner: the restore brings the owner account back.
4. Copy the archive to the server (`scp` from your laptop, the other direction of §4), then run `restore.sh`.
5. Point the domain's DNS at the new server and install the cron file (§3 Setup).

Keep the **same domain** (`NODE_DOMAIN`). The node's fediverse identity — `@handle@domain` and its signing keys — comes with the database; on another domain, other servers would see a different account.

## 8. Check after a restore

```bash
curl -s https://YOUR-DOMAIN/api/v1/health            # success envelope
sudo -u www-data php /var/www/fpdp/scripts/check-requirements.php --http
```

Then in the browser: sign in to the dashboard (with 2FA if it was on), open a post with an image, open a product, open Settings → Payments (gateways still configured — proves `APP_KEY` matches), and check the Federation page lists your followers.

## 9. Dokploy / Docker

`backup.sh` and `restore.sh` are for a plain VPS. On Dokploy the data lives in two named volumes, and `.env` values are set in the Dokploy UI:

| Data | Where on Dokploy |
|---|---|
| Database | volume `fpdp_mysql` (service `db`) |
| `storage/` | volume `fpdp_storage` (service `app`) |
| `.env` values, including `APP_KEY` | Dokploy → the app → Environment. Keep a copy of `APP_KEY` in a password manager: without it the database's encrypted credentials cannot be read |

Back up both together, from the server shell:

```bash
# database
docker compose -f dokploy-compose.yml exec -T db \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers fpdp > fpdp-db-$(date +%F-%H%M).sql

# storage volume (find its full name first: docker volume ls | grep fpdp_storage)
docker run --rm -v <project>_fpdp_storage:/data -v "$PWD":/backup alpine tar -czf /backup/fpdp-storage-$(date +%F-%H%M).tar.gz -C /data .
```

Restore (stop the `app`, `federation-worker` and `scheduler` services first so nothing writes; `db` keeps running):

```bash
docker compose -f dokploy-compose.yml exec -T db mysql -u root -p"$MYSQL_ROOT_PASSWORD" fpdp < fpdp-db-….sql
docker run --rm -v <project>_fpdp_storage:/data -v "$PWD":/backup alpine sh -c 'rm -rf /data/* && tar -xzf /backup/fpdp-storage-….tar.gz -C /data'
```

Start the services again; the `app` container runs pending migrations on start. Dokploy's own volume backups (to S3-compatible storage) are a good alternative; restore both volumes from the same point in time. See also [Dokploy Deployment](DOKPLOY-DEPLOYMENT.en.md) §10.

## 10. Practise a restore

A backup that has never been restored is not yet a backup. Every few months, and after large upgrades:

1. create a spare VPS (or a scratch database and folder on a test server);
2. restore last night's archive there (§7, without changing DNS);
3. sign in and run the checks in §8;
4. delete the spare server and the archive copy on it.

Note the date and the time it took; that is your real recovery time.

## 11. Security

Backups contain the production database (buyers' names, emails, phone numbers and addresses, orders, payments), encrypted credentials, and `.env` with `APP_KEY` — enough to read those credentials. Treat every copy like the production server (ISO/IEC 27001:2022 A.8.13 information backup, A.5.34 privacy and PII):

- keep archives root-only on the server; do not put them in a web-served folder;
- encrypt copies that leave the server (`gpg -c`, BitLocker/FileVault on the laptop); never send them unencrypted over chat or e-mail, or to shared drives;
- keep them only as long as needed (14 days on the server by default; decide a retention period for off-server copies too), and delete old copies;
- restrict who has SSH/sudo access to the server;
- test restores (§10).

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| No new archive, no log line | Cron file not installed, or wrong `FPDP_DIR` in it | Install `/etc/cron.d/fpdp` (§3), check `FPDP_DIR` |
| `mysqldump: command not found` | MySQL client not installed | `sudo apt install mysql-client` (or `mariadb-client`) |
| `Access denied for user` during backup | Database password in `.env` changed or wrong | Fix `.env`, run the backup by hand |
| `Archive is missing database.sql` | Archive incomplete or not made by `backup.sh` | Use another archive; check that the disk was not full |
| Restore: `Access denied` | New server's database user/password differ from the backup's `env` | Create them as in §7 step 2 |
| After restore, payment gateways fail or 2FA codes are refused | `.env` / `APP_KEY` not from the same backup | Restore `.env` from the same archive; never mix a database with another backup's `APP_KEY` |
| Images or product files missing after restore | `storage.tar` from a different point in time, or permissions | Restore database and storage from the same archive; `sudo chown -R www-data:www-data /var/www/fpdp/storage` |
| Disk filling up | Many large archives, or old `storage.before-restore.*` folders | Lower `KEEP_DAYS`, remove old `storage.before-restore.*` after checking the restore |
