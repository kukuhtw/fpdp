# Panduan Deployment FPDP — Dokploy

## 1. Apa itu Dokploy?

[Dokploy](https://dokploy.com) adalah PaaS open-source yang berjalan di VPS Anda sendiri. Ia men-deploy aplikasi menggunakan Docker Compose, mengelola database, sertifikat TLS, dan menyediakan dashboard web. Panduan ini menjelaskan cara men-deploy FPDP menggunakan Dokploy dengan file `dokploy-compose.yml`, `Dockerfile`, dan `.env.dokploy.example` yang sudah tersedia.

## 2. Prasyarat

- VPS dengan Ubuntu 22.04 atau lebih baru, Docker sudah terinstall.
- Dokploy sudah terinstall di VPS tersebut (lihat [dokploy.com/docs](https://dokploy.com/docs) untuk petunjuk instalasi).
- Nama domain yang mengarah ke IP VPS Anda (mis. `profile.example.com`).
- Git: repository FPDP Anda (fork atau ini) sudah di-push ke Git provider (GitHub, GitLab, dll) yang bisa diakses Dokploy.

## 3. Struktur proyek untuk Dokploy

Empat file yang sudah tersedia di repository ini:

| File | Tujuan |
|---|---|
| `dokploy-compose.yml` | Mendefinisikan service `app` dan `db`, health checks, volume, dan environment. |
| `Dockerfile` | Image PHP 8.3 Apache dengan ekstensi MySQL, modul rewrite/headers Apache, dan entrypoint. |
| `.env.dokploy.example` | Template untuk semua environment variable yang diperlukan. |
| `docker/entrypoint.sh` | Menunggu DB ready, menjalankan migrasi, membuat akun owner, dan menonaktifkan web installer. |

> **Catatan:** `dokploy-compose.yml` sudah diatur untuk production — `RUN_MIGRATIONS=true`, `DISABLE_WEB_INSTALLER=true`, dan health checks.

## 4. Langkah deployment

### 4.1 Buat project baru di Dokploy

1. Login ke dashboard Dokploy Anda.
2. Klik **New Project** → pilih **Docker Compose**.
3. Beri nama project (mis. `fpdp`).
4. Pilih repository Git yang berisi kode FPDP.
5. Set **Branch** ke `main` (atau branch release Anda).
6. Set **Compose file path** ke `dokploy-compose.yml`.
### 4.2 Tambah environment variables

Gunakan `.env.dokploy.example` sebagai referensi. Minimum yang diperlukan:

```env
APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
DB_PASSWORD=password-database-yang-kuat
MYSQL_ROOT_PASSWORD=password-root-yang-berbeda-dan-kuat
NODE_DOMAIN=profile.example.com
```

> **Keamanan:** `APP_KEY` minimal 64 karakter heksadesimal. Generate dengan: `openssl rand -hex 32`

Opsional:

```env
APP_NAME=FPDP
NODE_NAME=Node FPDP Saya
NODE_DEFAULT_LOCALE=id
NODE_TIMEZONE=Asia/Jakarta
AUTH_TOKEN_TTL=604800
RATE_LIMIT_LOGIN_MAX=5
RATE_LIMIT_LOGIN_WINDOW=900
RATE_LIMIT_REGISTER_MAX=5
RATE_LIMIT_REGISTER_WINDOW=3600
```

Google OAuth untuk autentikasi visitor (opsional):

```env
GOOGLE_CLIENT_ID=client-id-google-anda
GOOGLE_CLIENT_SECRET=client-secret-google-anda
VISITOR_TOKEN_TTL=2592000
CV_MAX_FILE_SIZE_BYTES=5242880
```

### 4.3 Bootstrap akun owner

**Hanya untuk deployment pertama**, tambahkan:

```env
BOOTSTRAP_OWNER_EMAIL=owner@example.com
BOOTSTRAP_OWNER_PASSWORD=password-sangat-kuat-minimal-12-karakter
BOOTSTRAP_OWNER_HANDLE=owner
BOOTSTRAP_OWNER_DISPLAY_NAME=Node Owner
BOOTSTRAP_OWNER_LOCALE=id
```

> **⚠️ Penting:** Setelah deployment pertama berhasil, **hapus** `BOOTSTRAP_OWNER_PASSWORD` dari Dokploy dan redeploy.

### 4.4 Konfigurasi domain

1. Di project Dokploy Anda, buka **Domains**.
2. Tambah domain Anda (mis. `profile.example.com`).
3. Dokploy akan mendapatkan sertifikat Let's Encrypt TLS secara otomatis.
### 4.6 Verifikasi deployment

```bash
# Health endpoint
curl https://profile.example.com/api/v1/health

# Profil publik
curl https://profile.example.com/@owner

# Timeline API
curl https://profile.example.com/api/v1/timeline

# Login sebagai owner
curl -X POST https://profile.example.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"owner@example.com","password":"password-anda"}'
```

## 5. Langkah post-deployment

### 5.1 Hapus bootstrap credentials

1. Buka project Dokploy → **Environment**.
2. Hapus `BOOTSTRAP_OWNER_PASSWORD` (dan variabel `BOOTSTRAP_OWNER_*` lainnya).
3. Klik **Redeploy**.

### 5.2 Backup rutin

Dokploy tidak mem-backup Docker volume secara otomatis:

```bash
# Contoh: dump MySQL mingguan via cron
## 6. Update FPDP

### 6.1 Update standar

1. Push kode baru ke repository Git.
2. Di Dokploy, klik **Redeploy**.
3. Dokploy rebuild image, jalankan migrasi baru, dan restart container.

### 6.2 Zero-downtime

Saat ini `Dockerfile` dan `dokploy-compose.yml` belum mengonfigurasi multiple replicas. Untuk node personal, downtime beberapa detik saat restart masih akseptabel.

## 7. Troubleshooting

| Gejala | Kemungkinan penyebab | Solusi |
|---|---|---|
| Deployment gagal "Container unhealthy" | Database belum siap, atau health endpoint error | Cek log container di Dokploy; pastikan `DB_HOST=db`; naikkan `start_period` |
| Error `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` | Environment variables belum diset | Tambah di Dokploy → Environment → Redeploy |
| Akun owner tidak dibuat | Variabel `BOOTSTRAP_OWNER_*` tidak ada/kosong | Tambah semua lima variabel dan redeploy |
| "Owner bootstrap skipped: account already exists" | Normal setelah deployment pertama | Hapus `BOOTSTRAP_OWNER_PASSWORD` |
| 404 pada `/@handle` | Profil tidak ada, atau `NODE_DOMAIN` salah | Cek handle; pastikan `NODE_DOMAIN` sesuai domain publik |
| Web installer (`install.php`) bisa diakses | `DISABLE_WEB_INSTALLER` tidak `true` | Set `DISABLE_WEB_INSTALLER=true` |
| Sertifikat Let's Encrypt tidak terbit | DNS belum propagate | Verifikasi DNS dengan `dig profile.example.com` |

## 8. Perbandingan: Dokploy vs VPS vs shared hosting

| Fitur | Dokploy | VPS (manual) | Shared hosting |
|---|---|---|---|
| Usaha setup | Rendah — satu klik deploy | Sedang — setup Nginx/DB manual | Sedang — upload file, jalankan installer |
| TLS | Otomatis (Let's Encrypt) | Manual (Certbot) | Biasanya disediakan |
| Manajemen database | Otomatis (Docker container) | Instalasi & maintenance manual | Disediakan host (phpMyAdmin) |
| Update | Tombol redeploy | `git pull` + `php migrate.php` | Upload ulang + SQL manual |
| Isolasi resource | Full Docker isolation | Server native | Sharing dengan tenant lain |
| Biaya | Harga VPS saja | Harga VPS saja | Biasanya lebih murah |
| Persistence | Docker volumes (backup manual) | Native filesystem | Host-managed storage |

## 9. Referensi

- [Dokploy documentation](https://dokploy.com/docs)
- [README Repository](../README.md)
- [Panduan deployment umum (VPS & shared hosting)](DEPLOYMENT-GUIDE.id.md)
- [Laporan progres pengembangan](PROGRESS-REPORT.id.md)
- [.env.dokploy.example](../.env.dokploy.example)
- [dokploy-compose.yml](../dokploy-compose.yml)
- [Dockerfile](../Dockerfile)
docker exec fpdp_db_1 mysqldump -u fpdp -p'password-anda' fpdp > /backups/fpdp-$(date +%F).sql
```

Volume yang perlu dibackup:
- `fpdp_mysql` — direktori data MySQL (`/var/lib/mysql`)
- `fpdp_storage` — file CV terupload dan log (`/var/www/html/storage`)

### 5.3 Konfigurasi Google OAuth (opsional)

1. Buka [Google Cloud Console](https://console.cloud.google.com).
2. Buat OAuth 2.0 Client ID (tipe Web application).
3. Tambah `https://profile.example.com/api/v1/profiles/{handle}/visitor-auth/google/callback` sebagai redirect URI.
4. Tambah `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` di environment Dokploy.
5. Redeploy.
4. Arahkan DNS A/AAAA domain Anda ke IP VPS.

### 4.5 Deploy

Klik **Deploy** di dashboard Dokploy. Prosesnya:

1. Clone repository.
2. Build Docker image dari `Dockerfile`.
3. Start container MySQL 8.4 (`db`).
4. Tunggu MySQL menjadi healthy.
5. Start container `app`.
6. `docker/entrypoint.sh` berjalan di dalam container:
   - Menunggu MySQL (sampai 60 retry / ~120 detik).
   - Menjalankan `php database/migrate.php`.
   - Jika `BOOTSTRAP_OWNER_*` ada dan owner belum ada, buat akun owner.
   - Membuat `storage/installed.lock` untuk menonaktifkan web installer.
7. Health check Dokploy memanggil `GET /api/v1/health` — deployment sukses jika response 200.