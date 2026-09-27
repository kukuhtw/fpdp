# FPDP Deployment Guide — English

## 1. Scope

This guide covers deploying FPDP to a **VPS** (full server access) and to **shared hosting** (cPanel/Plesk-style, usually no SSH), and explains the **web install wizard** (`public/install.php`) that turns an uploaded copy of the code into a running node: it checks the environment, writes `.env`, runs database migrations, and creates the first owner account.

It assumes the [repository README](../README.md) quick start already works on your own machine. Nothing here requires Composer packages — the app ships its own PSR-4 autoloader (`vendor/autoload.php`) with zero external dependencies, which is why it also runs on shared hosts with no Composer or SSH access.

## 2. Requirements

- PHP 8.2 or newer, with the `pdo_mysql`, `simplexml`, `json`, `mbstring`, `openssl`, and `fileinfo` extensions enabled.
- MySQL 8 (or a compatible MariaDB version) with a database and a user that has full privileges on it.
- The web server's document root pointed at the repository's `public/` folder (see [§5](#5-shared-hosting-deployment) if your host will not let you change it).
- HTTPS in production — FPDP sends bearer tokens and passwords over HTTP, so plain HTTP is only acceptable for local development.

## 3. Choose a path

For a container-managed VPS, use the dedicated [Dokploy deployment guide](DOKPLOY-DEPLOYMENT.en.md). It includes Compose, health checks, persistent volumes, automatic migrations, and first-owner bootstrap.

| | VPS | Shared hosting |
|---|---|---|
| Shell/SSH access | Yes | Usually no |
| Who sets the document root | You (Nginx/Apache config) | The hosting panel (cPanel/Plesk) |
| How you run migrations | CLI: `php database/migrate.php` | Web installer (no CLI needed) |
| How you create the owner account | CLI `curl` against the API, or the web installer | Web installer |
| TLS | You configure it (e.g. Certbot) | Usually provided by the panel |

Both paths converge on the same three things: get the code onto the server, point the document root at `public/`, and either run `public/install.php` in a browser or do the equivalent steps by hand over SSH.

## 4. VPS deployment

Example uses Ubuntu 22.04+, Nginx, and PHP-FPM; adapt package names for your distribution.

### 4.1 Install packages

Use **Ubuntu 24.04 LTS**: its default PHP is 8.3. (Ubuntu 22.04 ships PHP 8.1, which is too old — add `ppa:ondrej/php` and install the `php8.3-*` packages there.) The unversioned package names below always install the distribution's default PHP:

```bash
sudo apt update
sudo apt install -y nginx mysql-server php-fpm php-cli php-mysql php-xml php-mbstring git
php -v   # note the version (e.g. 8.3) for the paths below
```

`openssl`, `fileinfo`, and `json` are built into Ubuntu's PHP. FPDP does not need `sodium` or `curl`.

### 4.2 Create the database

```bash
sudo mysql -e "CREATE DATABASE fpdp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'fpdp'@'localhost' IDENTIFIED BY 'change-this-password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON fpdp.* TO 'fpdp'@'localhost'; FLUSH PRIVILEGES;"
```

### 4.3 Get the code

```bash
sudo mkdir -p /var/www/fpdp
sudo chown "$USER" /var/www/fpdp
git clone <your-fork-or-repo-url> /var/www/fpdp
cd /var/www/fpdp
```

If Composer is available, run `composer install && composer dump-autoload` to keep the autoloader in sync with `composer.json`; it is optional today since the checked-in `vendor/autoload.php` already works standalone.

### 4.4 Configure Nginx and PHP

The repository ships a ready site file, [`deploy/ubuntu/nginx-fpdp.conf`](../deploy/ubuntu/nginx-fpdp.conf):

```bash
sudo cp deploy/ubuntu/nginx-fpdp.conf /etc/nginx/sites-available/fpdp
sudo sed -i 's/fpdp.example.com/your-domain.example/g' /etc/nginx/sites-available/fpdp
sudo ln -s /etc/nginx/sites-available/fpdp /etc/nginx/sites-enabled/fpdp
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Two things in it matter and are easy to get wrong in a hand-written config:

- **`/.well-known/` must reach PHP.** It serves WebFinger, which is how Mastodon and other FPDP nodes find `@you@your-domain`. A plain "deny all dotfiles" rule (`location ~ /\.`) also blocks `/.well-known/`, and then nobody can follow you.
- **`client_max_body_size 32m`.** Uploads are base64 JSON, so a 20 MB product file is a ~27 MB request; Nginx's default is 1 MB.

PHP needs matching limits, for PHP-FPM and for the CLI that runs the background jobs ([`deploy/ubuntu/php-fpdp.ini`](../deploy/ubuntu/php-fpdp.ini)):

```bash
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo cp deploy/ubuntu/php-fpdp.ini /etc/php/$PHPV/fpm/conf.d/99-fpdp.ini
sudo cp deploy/ubuntu/php-fpdp.ini /etc/php/$PHPV/cli/conf.d/99-fpdp.ini
sudo systemctl restart php$PHPV-fpm
```

(Apache on a VPS can use the `public/.htaccess` already in the repository — point `DocumentRoot` at `public/` and enable `AllowOverride All` for it.)

### 4.5 Add TLS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.example
```

### 4.6 Run the installer over SSH (recommended on a VPS)

You can use the web wizard (§6) here too, but on a VPS it is usually simpler — and keeps `install.php` from ever being reachable on the public internet — to do the equivalent steps by hand:

```bash
cp .env.example .env
# edit .env: set DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD, NODE_DOMAIN, APP_ENV=production
php -r "echo bin2hex(random_bytes(32));"   # paste the result into APP_KEY in .env

php database/migrate.php

curl -s -X POST https://your-domain.example/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"display_name":"Your Name","handle":"yourhandle","email":"you@example.com","password":"a-strong-password-12+chars","locale":"en"}'
```

The `curl` call is the exact same `AuthService::register()` path the web installer's last step calls — it hashes the password, creates the node/user/profile rows, and returns a bearer token. Save that token or discard it and log in again with `POST /api/v1/auth/login`.

Finally, mark the node installed so `install.php` (if it is ever reached) refuses to run:

```bash
mkdir -p storage
touch storage/installed.lock
```

### 4.7 File permissions

```bash
sudo chown -R www-data:www-data /var/www/fpdp
sudo chmod 600 /var/www/fpdp/.env
```

### 4.8 Background jobs

Three jobs must run on a schedule, or federation, feeds, and payments silently stall: delivering queued ActivityPub activities (every minute), syncing external feeds, and reconciling payments with the gateways (every 15 minutes each) refreshing the owner's orders on other FPDP nodes (every 15 minutes), and pruning old analytics events (daily). [`deploy/ubuntu/fpdp.cron`](../deploy/ubuntu/fpdp.cron) runs them as `www-data`, with `flock` so a slow run never overlaps the next, plus the nightly backup:

```bash
sudo mkdir -p /var/log/fpdp && sudo chown www-data:www-data /var/log/fpdp
sudo cp deploy/ubuntu/fpdp.cron /etc/cron.d/fpdp && sudo chmod 644 /etc/cron.d/fpdp
tail -f /var/log/fpdp/federation.log   # a line per minute once it runs
```

`reconcile.log` lists every payment it changed; a `MISMATCH` line means a payment cancelled in FPDP was paid at the gateway anyway — it has been marked paid and fulfilled, and should be refunded from the dashboard if the sale is unwanted.

### 4.9 Backup and restore

Full explanation — what is in a backup, copying it to your laptop, restoring on the same or a new server, Dokploy, troubleshooting: [Backup and Restore](BACKUP-RESTORE.en.md).

[`deploy/ubuntu/backup.sh`](../deploy/ubuntu/backup.sh) writes one consistent recovery point — a `mysqldump --single-transaction`, `storage/` (media, product files), and `.env` — to `/var/backups/fpdp/fpdp-<UTC time>.tar.gz`, mode 600, keeping 14 days. The cron file above runs it nightly; run it by hand with `sudo sh deploy/ubuntu/backup.sh`.

- **Keep `.env` with the database.** `APP_KEY` in it decrypts the stored gateway and OAuth credentials; a database restored with a different key cannot use them.
- **Copy backups off the VPS** (another provider or region) and encrypt them there. They contain secrets and buyers' personal data, so treat them like the production database.
- **Practise a restore** on a spare VPS or scratch database before you need it:

```bash
sudo FPDP_DIR=/var/www/fpdp sh deploy/ubuntu/restore.sh /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz
```

`restore.sh` asks for confirmation, pauses the cron jobs, loads the dump, swaps `storage/` (the old one is kept as `storage.before-restore.*`) and `.env`, runs any newer migration, and turns the jobs back on.

### 4.9a Removing old CV data

The paid CV download was removed (September 27, 2026). On a node that used it, migration `0066` only renames its tables to `archived_cv_documents` / `archived_cv_access_grants` — data intact, because migrations run on every deploy. To archive and then delete them together with `storage/cv/`:

```bash
sudo -u www-data php scripts/archive-cv-data.php            # summary, changes nothing
sudo -u www-data php scripts/archive-cv-data.php --archive  # storage/archive/cv-archive-<time>.tar.gz, verified, chmod 600
sudo -u www-data php scripts/archive-cv-data.php --delete --archive-file=storage/archive/cv-archive-<time>.tar.gz --confirm
```

`--delete` re-verifies the archive and refuses if the rows or files changed since it was made. The archive holds buyer identity: move it off the server encrypted (e.g. `gpg -c`), keep it only as long as needed, then delete it. On Dokploy, run the same commands in the `app` container.

### 4.10 Check the server

```bash
sudo -u www-data php scripts/check-requirements.php --http
```

It checks PHP and its extensions, upload limits, RSA key generation for federation signing, `.env` production settings, the database and pending migrations, writable `storage/`, and — with `--http` — that `/api/v1/health` answers, that WebFinger finds the owner, and that `/.env` is not served. It also warns when the clock is not NTP-synchronized: two-factor codes stop being accepted when the server clock is more than about 30 seconds off (`sudo timedatectl set-ntp true`). It exits non-zero if anything fails; run it after every upgrade too.

**Locked out of 2FA?** If the owner has lost both the phone and the recovery codes, turn 2FA off from the server (shell access is the proof of ownership), then sign in with the password and set it up again:

```bash
sudo -u www-data php scripts/disable-2fa.php --email=owner@example.com
```

## 5. Shared hosting deployment

Steps use cPanel terminology; Plesk and other panels have equivalent screens.

### 5.1 Create the database

In cPanel → **MySQL Databases**: create a database, a user, a strong password, and add the user to the database with **All Privileges**. Note the full database name and username — shared hosts usually prefix them with your account name (e.g. `cpaneluser_fpdp`).

### 5.2 Upload the code

- If your host offers SSH and Git, `git clone` the repository as in §4.3.
- Otherwise, download or build a zip of the repository and upload it through **File Manager**, then extract it.

### 5.3 Point the document root at `public/`

This is the important step: FPDP's entry point is `public/index.php`, not the repository root.

- **Addon domain or subdomain:** cPanel → **Domains** lets you set a custom **Document Root** when creating the (sub)domain — set it to the `public/` folder inside your uploaded copy (e.g. `fpdp/public`). This is the cleanest option and needs no extra files.
- **Main domain, root folder only (`public_html`) and no way to change it:** upload the repository *outside* `public_html` (for example as a sibling folder `fpdp/`) and add a small shim so `public_html` only ever exposes the front controller:

  `public_html/index.php`:
  ```php
  <?php
  require __DIR__ . '/../fpdp/public/index.php';
  ```

  `public_html/install.php`:
  ```php
  <?php
  require __DIR__ . '/../fpdp/public/install.php';
  ```

  `public_html/.htaccess` (routes everything else through the shim, same pattern as `public/.htaccess`):
  ```apache
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]
  RewriteRule ^ index.php [L]
  ```

  Only use this fallback when you truly cannot set a custom document root — a dedicated (sub)domain document root is simpler and keeps `app/`, `database/`, and `documentation/` outside any web-servable folder entirely.

The repository already includes `public/.htaccess`, which rewrites requests to `public/index.php` and denies direct access to dotfiles — no extra configuration is needed once the document root is correct.

### 5.4 Set the PHP version

In cPanel → **MultiPHP Manager** (or similar), select PHP 8.2+ for the domain and enable `pdo_mysql`, `simplexml`, `mbstring` under **PHP Extensions** if they are not already on.

### 5.5 Run the web installer

Visit `https://your-domain.example/install.php` and follow the four steps in §6 — this replaces the CLI steps from §4.6, since shared hosting usually has no shell access to run `php database/migrate.php` or Composer directly.

## 6. Using the install mechanism (`public/install.php`)

The installer is a self-contained wizard that does **not** depend on `.env` or the database existing yet, so it works on a completely fresh copy of the code.

```mermaid
flowchart LR
    S1["Step 1<br/>Requirements check"] --> S2["Step 2<br/>Database & node settings<br/>→ writes .env"]
    S2 --> S3["Step 3<br/>Run migrations"]
    S3 --> S4["Step 4<br/>Create owner account<br/>→ writes storage/installed.lock"]
    S4 --> Done["Locked:<br/>install.php now refuses to run"]
```

1. **Requirements check.** Verifies the PHP version, the `pdo_mysql`/`simplexml`/`json`/`mbstring` extensions, that the project root is writable (to create `.env`), and that `storage/` is writable (for the install lock). Nothing is written yet. Fix any `FAIL` row before continuing — the "Continue" button stays disabled until every check passes.
2. **Database & node settings.** Enter the database host/port/name/username/password (the database must already exist — the installer creates its *tables*, not the database itself) plus the node's domain, display name, default locale, and timezone. On submit the installer opens a real connection to confirm the credentials work, then writes `.env` (including a freshly generated `APP_KEY`) — it never proceeds past this step with untested credentials.
3. **Run migrations.** Applies every file in `database/migrations/` in order, the same `MigrationRunner` the CLI (`php database/migrate.php`) uses. This step is safe to click more than once: already-applied migrations are tracked in a `migrations` table and skipped.
4. **Create the owner account.** Collects display name, handle, email, and password, and calls the same `AuthService::register()` code path as `POST /api/v1/auth/register` (bcrypt hashing, a node/user/profile created together, a bearer token issued). On success the installer writes `storage/installed.lock` and shows the access token **once**.

After step 4, every visit to `install.php` — including a plain reload — shows an "already installed" page instead of the wizard, because `storage/installed.lock` now exists. This is the installer's only safeguard against someone reaching it after you have finished setup, so:

- **Delete `public/install.php`** once you are done (simplest option), or
- keep it but **block public access** at the web-server level (an IP allowlist or HTTP Basic Auth in front of `install.php` during the setup window), and rely on the lock file otherwise.

To intentionally reinstall (for example onto a fresh staging copy), delete `storage/installed.lock` and reload the page — nothing else needs to be reset, since step 2 will happily overwrite `.env` again and step 3's migrations are idempotent.

### Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Step 1 shows `FAIL` on an extension | Extension not enabled for the PHP version the site actually runs | Enable it in `php.ini` or the host's PHP Extensions panel, for the *same* PHP version selected for the domain |
| Step 1 shows `FAIL` on "project root is writable" | Web server user cannot write to the repository root | `chmod`/`chown` the folder so the PHP process (e.g. `www-data`) can write `.env`, or ask your host to fix folder ownership |
| Step 2: "Could not connect to that database" | Wrong host/port/credentials, or the database does not exist yet | Re-check the values from §5.1/§4.2; on shared hosts the DB host is often `localhost` and the DB/user names are prefixed with your account name |
| Step 3 fails with a duplicate-table or syntax error | Migrations were partially applied by another method, or the database already has non-FPDP tables with the same names | Use a dedicated, empty database for FPDP; if migrations were already applied by hand, `php database/migrate.php` and this step both skip files already recorded in the `migrations` table |
| Step 4: "Email or handle is already registered" | An owner account already exists (perhaps from a previous attempt before the lock was written) | Log in via `POST /api/v1/auth/login` instead, or pick a different email/handle |
| `install.php` immediately shows "already installed" | `storage/installed.lock` exists from a previous run | Expected once setup is done; delete that file only if you deliberately want to run the wizard again |

## 7. Post-deployment checklist

- `sudo -u www-data php scripts/check-requirements.php --http` passes (VPS).
- `/etc/cron.d/fpdp` is installed and `/var/log/fpdp/federation.log` gets a line every minute (VPS).
- A backup exists under `/var/backups/fpdp/` and a copy is stored off the server.

- `curl https://your-domain.example/api/v1/health` returns a success envelope.
- `.env` is not publicly reachable (`curl https://your-domain.example/.env` must **not** return its contents — `public/.htaccess` already denies this once the document root is correct).
- `public/install.php` is deleted, or provably blocked and covered by `storage/installed.lock`.
- `.env` has `APP_ENV=production` and `APP_DEBUG=false`.
- `.env` file permissions are restrictive (`chmod 600` on a VPS; on shared hosting, keep it out of any web-servable folder as described in §5.3).
- You can log in as the owner account created during installation.
- Recommended: turn on two-factor authentication under Settings → Keamanan akun, and keep the recovery codes off the server.

## 8. Updating a deployed node

```bash
cd /var/www/fpdp   # or wherever the code lives
git pull
php database/migrate.php   # safe to run repeatedly; only pending migrations apply
sudo -u www-data php scripts/check-requirements.php
```

Take a backup (`sudo sh deploy/ubuntu/backup.sh`) before pulling, so a failed upgrade can be rolled back — code rollback alone does not undo a migration.

On shared hosting without SSH, re-upload the changed files through File Manager/FTP, then either re-run `install.php` step 3 only (it is safe — delete `storage/installed.lock`, click through steps 1–2 with the *same* database values so `.env` is rewritten identically, run step 3, then stop — do not repeat step 4) or apply the SQL in any new `database/migrations/*.sql` files by hand through phpMyAdmin.

## 9. References

- [Repository README](../README.md) — local quick start and current implementation status.
- [Development progress report](PROGRESS-REPORT.en.md) — what is and is not implemented yet.
- [Roadmap](ROADMAP.en.md) — Phase 4 ("Operations and MVP hardening") covers the deployment hardening still ahead (CI, backups, rollback drills).
