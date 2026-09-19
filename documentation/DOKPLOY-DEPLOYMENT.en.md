# FPDP Deployment Guide — Dokploy

## 1. What is Dokploy?

[Dokploy](https://dokploy.com) is an open-source PaaS that runs on your own VPS. It deploys applications using Docker Compose, manages databases, TLS certificates, and provides a web dashboard. This guide explains how to deploy FPDP using Dokploy with the provided `dokploy-compose.yml`, `Dockerfile`, and `.env.dokploy.example`.

## 2. Prerequisites

- A VPS running Ubuntu 22.04 or newer with Docker installed.
- Dokploy installed on that VPS (see [dokploy.com/docs](https://dokploy.com/docs) for installation instructions).
- A domain name pointing to your VPS IP (e.g. `profile.example.com`).
- Git: your FPDP fork or this repository pushed to a Git provider (GitHub, GitLab, etc.) that Dokploy can access.

## 3. Project structure for Dokploy

Dokploy uses the four pre‑configured files already in this repository:

| File | Purpose |
|---|---|
| `dokploy-compose.yml` | Defines the `app` and `db` services, health checks, volumes, and environment. |
| `Dockerfile` | PHP 8.3 Apache image with MySQL extensions, Apache rewrite/headers modules, and the entrypoint. |
| `.env.dokploy.example` | Template for all required environment variables. Copy this to configure your deployment. |
| `docker/entrypoint.sh` | Waits for DB, runs migrations, bootstraps the owner account, and disables the web installer. |

> **Note:** The existing `docker-compose.yml` (if any) is for local development. `dokploy-compose.yml` is tuned for production — it enables `RUN_MIGRATIONS=true` and `DISABLE_WEB_INSTALLER=true`, and adds health checks for both services.
## 4. Step-by-step deployment

### 4.1 Create a new project in Dokploy

1. Log into your Dokploy dashboard.
2. Click **New Project** → choose **Docker Compose**.
3. Name your project (e.g. `fpdp`).
4. Select the Git repository that contains your FPDP code.
5. Set the **Branch** to `main` (or your preferred release branch).
6. Set the **Compose file path** to `dokploy-compose.yml`.

### 4.2 Add environment variables

Dokploy will prompt you for environment variables. Use `.env.dokploy.example` as your reference. The minimum required set is:

```env
APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
DB_PASSWORD=your-strong-database-password
MYSQL_ROOT_PASSWORD=your-different-strong-root-password
NODE_DOMAIN=profile.example.com
```

> **Security:** `APP_KEY` must be at least 64 hexadecimal characters. Generate one with: `openssl rand -hex 32`

Optional but recommended:

```env
APP_NAME=FPDP
NODE_NAME=My FPDP Node
NODE_DEFAULT_LOCALE=id
NODE_TIMEZONE=Asia/Jakarta
AUTH_TOKEN_TTL=604800
RATE_LIMIT_LOGIN_MAX=5
RATE_LIMIT_LOGIN_WINDOW=900
RATE_LIMIT_REGISTER_MAX=5
RATE_LIMIT_REGISTER_WINDOW=3600
```

Google OAuth for visitor auth (optional):

```env
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
VISITOR_TOKEN_TTL=2592000
CV_MAX_FILE_SIZE_BYTES=5242880
```

### 4.3 Bootstrap the owner account

On the **first deployment only**, add these bootstrap variables:

```env
BOOTSTRAP_OWNER_EMAIL=owner@example.com
BOOTSTRAP_OWNER_PASSWORD=your-very-strong-password-at-least-12-characters
BOOTSTRAP_OWNER_HANDLE=owner
BOOTSTRAP_OWNER_DISPLAY_NAME=Node Owner
BOOTSTRAP_OWNER_LOCALE=id
```

The `docker/entrypoint.sh` script detects these and creates the owner account automatically after the first migration run.

> **⚠️ Important:** After the first deployment succeeds, **remove** `BOOTSTRAP_OWNER_PASSWORD` (and optionally the other `BOOTSTRAP_OWNER_*` variables) from Dokploy and redeploy. The password is checked only during container startup and is ignored once the account exists, but leaving it in the environment is a security risk.

### 4.4 Configure the domain

1. In your Dokploy project, go to **Domains**.
2. Add your domain (e.g. `profile.example.com`).
3. Dokploy obtains a Let's Encrypt TLS certificate automatically.
4. Point your domain's DNS A/AAAA record to your VPS IP.
### 4.6 Verify the deployment

```bash
# Health endpoint (should return a 200 JSON envelope)
curl https://profile.example.com/api/v1/health

# Public profile (replace "owner" with your handle)
curl https://profile.example.com/@owner

# API timeline
curl https://profile.example.com/api/v1/timeline

# Login as the owner (replace the password)
curl -X POST https://profile.example.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"owner@example.com","password":"your-password"}'
```

## 5. Post-deployment steps

### 5.1 Remove bootstrap credentials

After the first successful deployment:

1. Go to your Dokploy project → **Environment**.
2. Remove `BOOTSTRAP_OWNER_PASSWORD` (and optionally the other `BOOTSTRAP_OWNER_*` variables).
3. Click **Redeploy**.

The bootstrap script skips automatically because the owner account already exists.

### 5.2 Set up regular backups

Dokploy does not back up Docker volumes automatically. Schedule a backup for the MySQL volume:

```bash
# Example: weekly MySQL dump via cron
docker exec fpdp_db_1 mysqldump -u fpdp -p'your-password' fpdp > /backups/fpdp-$(date +%F).sql
```

The persistent volumes are:

- `fpdp_mysql` — MySQL data directory (`/var/lib/mysql`)
- `fpdp_storage` — uploaded CV files and logs (`/var/www/html/storage`)
## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Deployment fails at "Container unhealthy" | Database not ready, or health endpoint returns non-200 | Check container logs in Dokploy; verify `DB_HOST=db` (the service name, not `localhost`); increase `start_period` in `dokploy-compose.yml` if the VPS is slow |
| `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` errors | Environment variables not set | Add them in Dokploy project → Environment and redeploy |
| Owner account not created | `BOOTSTRAP_OWNER_*` variables missing or empty | Add all five variables and redeploy; check container logs for "Owner bootstrap skipped" |
| "Owner bootstrap skipped: account already exists" | Expected after first deployment | Remove `BOOTSTRAP_OWNER_PASSWORD` as recommended |
| 404 on `/@handle` | Profile does not exist, or `NODE_DOMAIN` is wrong | Verify the handle; check that `NODE_DOMAIN` matches your public domain |
| Web installer (`install.php`) accessible | `DISABLE_WEB_INSTALLER` not `true` or `storage/installed.lock` missing | Set `DISABLE_WEB_INSTALLER=true` in environment; if the file is missing, run `touch storage/installed.lock` inside the container |
| Let's Encrypt certificate not issued | DNS record not propagated or domain not pointed to your VPS | Verify DNS with `dig profile.example.com`; wait for propagation (up to 48 hours) |

## 8. Comparison: Dokploy vs VPS vs shared hosting

| Feature | Dokploy | VPS (manual) | Shared hosting |
|---|---|---|---|
| Setup effort | Low — one-click deploy after config | Medium — manual Nginx/DB setup | Medium — upload files, run installer |
| TLS | Automatic (Let's Encrypt) | Manual (Certbot) | Usually provided |
| Database management | Automatic (Docker container) | Manual installation & maintenance | Provided by host (phpMyAdmin) |
| Updates | Redeploy button | `git pull` + `php migrate.php` | Re-upload files + manual SQL |
| Resource isolation | Full Docker isolation | Native server | Shared with other tenants |
| Cost | VPS cost only | VPS cost only | Usually cheaper |
| Persistence | Docker volumes (back up manually) | Native filesystem | Host-managed storage |

## 9. References

- [Dokploy documentation](https://dokploy.com/docs)
- [Repository README](../README.md)
- [General deployment guide (VPS & shared hosting)](DEPLOYMENT-GUIDE.en.md)
- [Development progress report](PROGRESS-REPORT.en.md)
- [.env.dokploy.example](../.env.dokploy.example) — environment variable reference
- [dokploy-compose.yml](../dokploy-compose.yml) — Docker Compose definition
- [Dockerfile](../Dockerfile) — container image definition

### 5.3 Configure Google OAuth (optional)

If you want visitor Google sign-in on profiles:

1. Go to [Google Cloud Console](https://console.cloud.google.com).
2. Create an OAuth 2.0 Client ID (Web application type).
3. Add `https://profile.example.com/api/v1/profiles/{handle}/visitor-auth/google/callback` as an authorized redirect URI.
4. Add the `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` to Dokploy environment variables.
5. Redeploy.

## 6. Updating FPDP

### 6.1 Standard update

1. Push new code to your Git repository (or merge a pull request).
2. In Dokploy, click **Redeploy**.
3. Dokploy rebuilds the image, pulls new migrations, and restarts the containers.
4. `docker/entrypoint.sh` runs `php database/migrate.php` on every start — only new, unapplied migrations execute.

### 6.2 Zero-downtime considerations

The current `Dockerfile` and `dokploy-compose.yml` do **not** configure multiple replicas or a rolling update strategy. During the few seconds the `app` container restarts, the node returns 502/503 errors. This is acceptable for a personal node.

For a production setup serving many visitors, consider:

- Adding a second `app` replica and a load balancer in front.
- Using a reverse proxy (like Nginx or Traefik) managed by Dokploy.
- Running migrations manually before deploying the new image.

### 4.5 Deploy

Click **Deploy** in the Dokploy dashboard. Dokploy will:

1. Clone the repository.
2. Build the Docker image from `Dockerfile`.
3. Start the MySQL 8.4 container (`db`).
4. Wait for MySQL to become healthy.
5. Start the `app` container.
6. `docker/entrypoint.sh` runs inside the container:
   - Waits for MySQL (up to 60 retries / ~120 seconds).
   - Runs `php database/migrate.php` — applies all pending migrations.
   - If `BOOTSTRAP_OWNER_*` variables are set and no owner exists yet, creates the owner account.
   - Creates `storage/installed.lock` to disable the web installer.
7. Dokploy's health check hits `GET /api/v1/health` — when it returns 200, the deployment is marked healthy.