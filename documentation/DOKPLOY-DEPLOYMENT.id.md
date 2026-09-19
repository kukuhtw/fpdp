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