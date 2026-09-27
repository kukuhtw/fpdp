# Backup dan Restore FPDP

Cara node FPDP di-backup, cara membawa backup keluar dari server, dan cara me-restore-nya. Script yang dipakai adalah [`deploy/ubuntu/backup.sh`](../deploy/ubuntu/backup.sh) dan [`deploy/ubuntu/restore.sh`](../deploy/ubuntu/restore.sh) untuk VPS Ubuntu; §9 membahas Dokploy/Docker.

English: [BACKUP-RESTORE.en.md](BACKUP-RESTORE.en.md).

## 1. Ringkasan

| | |
|---|---|
| Apa | Dump database + seluruh folder `storage/` + `.env` |
| Di mana | `/var/backups/fpdp/fpdp-<waktu UTC>.tar.gz`, mis. `fpdp-20260927T023000Z.tar.gz` |
| Kapan | Setiap malam pukul 02.30 (waktu server), dari `/etc/cron.d/fpdp` |
| Berapa lama | 14 hari; arsip yang lebih lama dihapus otomatis |
| Siapa yang bisa membaca | Hanya root (mode `600`) |
| Log | `/var/log/fpdp/backup.log` |
| Restore | `sudo sh deploy/ubuntu/restore.sh <arsip>`, manual, lewat SSH |

Backup dan restore **tidak** tersedia dari dashboard owner: keduanya dijalankan di server.

## 2. Isi backup

Setiap arsip adalah satu recovery point yang konsisten, berisi tiga file:

| File | Isi | Kenapa diperlukan |
|---|---|---|
| `database.sql` | `mysqldump` database FPDP: profil, post, produk, pesanan, pembayaran, federasi (termasuk kunci tanda tangan node), pengaturan, audit trail | Semua yang disimpan aplikasi di MySQL |
| `storage.tar` | Folder `storage/`: media yang diunggah, file produk digital, dokumen RAG, `installed.lock` | Data di database menunjuk ke file-file ini |
| `env` | Salinan `.env` | `APP_KEY` membuka kredensial payment gateway, LLM, dan OAuth yang tersimpan, juga secret 2FA. Database yang di-restore dengan `APP_KEY` lain tidak bisa memakainya. Juga berisi password database yang dipakai restore |

**Tidak termasuk** — simpan dengan cara lain:

- kode aplikasi (ambil dari git dengan versi yang sama atau lebih baru);
- konfigurasi server: Nginx (`deploy/ubuntu/nginx-fpdp.conf`), pengaturan PHP, `/etc/cron.d/fpdp`, sertifikat TLS (Certbot bisa menerbitkannya lagi);
- log di `/var/log/fpdp/`;
- isi `storage/archive/` justru **ikut** (karena berada di bawah `storage/`), jadi hapus arsip CV lama di sana setelah disalin ke luar server.

## 3. Cara kerja backup

`backup.sh` berjalan sebagai root dan:

1. membaca host, port, nama, user, dan password database dari `.env` (tanpa mengeksekusi file itu);
2. menulis password ke file opsi sementara yang hanya bisa dibaca root, sehingga tidak pernah muncul di command line (`ps`);
3. men-dump database dengan `mysqldump --single-transaction --routines`: snapshot InnoDB yang konsisten **tanpa mengunci situs** — pengunjung dan owner tetap bisa bekerja selama backup berjalan;
4. menyalin `.env` dan mengemas `storage/` menjadi `storage.tar`;
5. mengemas ketiganya menjadi `/var/backups/fpdp/fpdp-<waktu UTC>.tar.gz` dengan mode `600` (`umask 077` sejak awal);
6. menghapus arsip yang lebih tua dari 14 hari;
7. menghapus folder sementara, termasuk file password, walaupun ada langkah yang gagal.

Pengaturan, lewat environment variable: `FPDP_DIR` (default `/var/www/fpdp`), `BACKUP_DIR` (default `/var/backups/fpdp`), `KEEP_DAYS` (default `14`).

### Persiapan (sekali saja)

```bash
sudo mkdir -p /var/log/fpdp && sudo chown www-data:www-data /var/log/fpdp
sudo cp /var/www/fpdp/deploy/ubuntu/fpdp.cron /etc/cron.d/fpdp && sudo chmod 644 /etc/cron.d/fpdp
```

File cron itu juga menjalankan job latar lainnya (pengiriman federasi, sinkronisasi feed, rekonsiliasi pembayaran). Baris backup-nya:

```text
30 2 * * *   root   FPDP_DIR=$FPDP_DIR /bin/sh $FPDP_DIR/deploy/ubuntu/backup.sh >> /var/log/fpdp/backup.log 2>&1
```

### Menjalankan backup secara manual

Sebelum upgrade, migration yang mengubah data, atau perubahan berisiko:

```bash
sudo FPDP_DIR=/var/www/fpdp sh /var/www/fpdp/deploy/ubuntu/backup.sh
# [2026-09-27T09:15:02Z] backup written: /var/backups/fpdp/fpdp-20260927T091500Z.tar.gz (48M)
```

### Memastikan backup berjalan

```bash
sudo ls -lh /var/backups/fpdp/          # satu arsip per malam, paling banyak 14
sudo tail -n 5 /var/log/fpdp/backup.log # satu baris "backup written" per run
sudo tar -tzf /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz   # database.sql, env, storage.tar
```

Log yang kosong atau tanpa baris baru berarti job tidak berjalan (file cron belum terpasang, `FPDP_DIR` salah) atau gagal (`mysqldump` tidak ada, password database di `.env` salah).

## 4. Menyalin backup ke luar server

Backup yang berada di disk yang sama ikut hilang bersama VPS-nya. Salin arsip secara rutin ke tempat lain — laptop Anda, provider atau region lain.

Arsip hanya bisa dibaca root, jadi salin dulu ke folder home Anda:

```bash
# di VPS
sudo cp /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz ~/
sudo chown $USER: ~/fpdp-20260927T023000Z.tar.gz
gpg -c ~/fpdp-20260927T023000Z.tar.gz          # opsional tapi disarankan: enkripsi dengan passphrase
rm ~/fpdp-20260927T023000Z.tar.gz              # sisakan hanya salinan .gpg di folder home
```

```powershell
# di laptop (Windows PowerShell; scp sudah tersedia di Windows 10/11)
scp user@IP_VPS:~/fpdp-20260927T023000Z.tar.gz.gpg "$HOME\Downloads\"
# port SSH lain: scp -P 2222 ...    file key: scp -i C:\Users\anda\.ssh\id_ed25519 ...
```

```bash
# di VPS, sesudahnya
rm ~/fpdp-20260927T023000Z.tar.gz.gpg
```

WinSCP atau FileZilla (SFTP) juga bisa. Buka enkripsinya nanti dengan `gpg -d file.tar.gz.gpg > file.tar.gz` (Gpg4win di Windows).

## 5. Cara kerja restore

```bash
sudo FPDP_DIR=/var/www/fpdp sh /var/www/fpdp/deploy/ubuntu/restore.sh /var/backups/fpdp/fpdp-20260927T023000Z.tar.gz
```

`restore.sh`:

1. membongkar arsip ke folder sementara yang hanya bisa dibaca root, dan memeriksa bahwa `database.sql`, `env`, dan `storage.tar` lengkap;
2. membaca koneksi database dari `env` **milik arsip**;
3. meminta Anda mengetik `RESTORE` — selain itu dibatalkan tanpa perubahan apa pun;
4. menjeda job latar (mengganti nama `/etc/cron.d/fpdp`), agar tidak ada yang menulis saat data ditukar;
5. memuat `database.sql` ke database: setiap tabel yang ada di dump dihapus lalu dibuat ulang dengan isi dari backup;
6. memindahkan `storage/` yang sekarang ke `storage.before-restore.<waktu>` (disimpan, tidak dihapus) lalu membongkar `storage/` dari backup;
7. memasang `.env` dari backup (`600`, milik `www-data`);
8. menjalankan `database/migrate.php`, sehingga backup yang di-restore ke kode yang lebih baru mendapat migration yang lebih baru;
9. menyalakan kembali job latar.

**Semua yang ditulis setelah backup hilang**: post, pesanan, pembayaran, follower, dan unggahan sejak malam itu. Pembayaran yang dikonfirmasi gateway setelah backup bisa dipulihkan dengan job rekonsiliasi pembayaran (`scripts/reconcile-payments.php`, juga ada di cron), untuk gateway yang punya API status.

## 6. Restore di server yang sama

1. Buat backup baru dulu (§3), untuk berjaga-jaga bila perlu kembali.
2. Jalankan `restore.sh` dengan arsip yang diinginkan (§5).
3. Periksa node (§8).
4. Setelah semua baik, hapus salinan storage lama: `sudo rm -rf /var/www/fpdp/storage.before-restore.*`.

## 7. Restore di server baru (pindah server, atau setelah VPS hilang)

1. Siapkan server seperti di [Panduan Deployment](DEPLOYMENT-GUIDE.id.md) §4.1–4.5: paket, Nginx, PHP, TLS, dan kode dengan versi **yang sama atau lebih baru** (`git clone`, `git checkout <tag>`).
2. Buat database dan user **dengan nama, user, dan password yang sama seperti di `env` backup** — restore memakai itu untuk terhubung. Untuk melihatnya: `sudo tar -xzf fpdp-….tar.gz -C /tmp/fpdp-check env && sudo grep ^DB_ /tmp/fpdp-check/env && sudo rm -rf /tmp/fpdp-check` (buat dulu foldernya dengan `sudo mkdir /tmp/fpdp-check`).
3. **Jangan** menjalankan web installer atau mendaftarkan owner baru: restore mengembalikan akun owner.
4. Salin arsip ke server (`scp` dari laptop, kebalikan dari §4), lalu jalankan `restore.sh`.
5. Arahkan DNS domain ke server baru dan pasang file cron (§3 Persiapan).

Pertahankan **domain yang sama** (`NODE_DOMAIN`). Identitas fediverse node — `@handle@domain` dan kunci tanda tangannya — ikut di database; di domain lain, server lain akan melihatnya sebagai akun yang berbeda.

## 8. Pemeriksaan setelah restore

```bash
curl -s https://DOMAIN-ANDA/api/v1/health            # envelope sukses
sudo -u www-data php /var/www/fpdp/scripts/check-requirements.php --http
```

Lalu di browser: login ke dashboard (dengan 2FA bila sebelumnya aktif), buka post bergambar, buka sebuah produk, buka Settings → Payments (gateway masih terkonfigurasi — bukti `APP_KEY` cocok), dan pastikan halaman Federasi menampilkan follower Anda.

## 9. Dokploy / Docker

`backup.sh` dan `restore.sh` untuk VPS biasa. Di Dokploy, data berada di dua named volume, dan nilai `.env` diatur di UI Dokploy:

| Data | Letaknya di Dokploy |
|---|---|
| Database | volume `fpdp_mysql` (service `db`) |
| `storage/` | volume `fpdp_storage` (service `app`) |
| Nilai `.env`, termasuk `APP_KEY` | Dokploy → aplikasi → Environment. Simpan salinan `APP_KEY` di password manager: tanpanya kredensial terenkripsi di database tidak bisa dibaca |

Backup keduanya bersamaan, dari shell server:

```bash
# database
docker compose -f dokploy-compose.yml exec -T db \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers fpdp > fpdp-db-$(date +%F-%H%M).sql

# volume storage (cari dulu nama lengkapnya: docker volume ls | grep fpdp_storage)
docker run --rm -v <project>_fpdp_storage:/data -v "$PWD":/backup alpine tar -czf /backup/fpdp-storage-$(date +%F-%H%M).tar.gz -C /data .
```

Restore (hentikan dulu service `app`, `federation-worker`, dan `scheduler` agar tidak ada yang menulis; `db` tetap berjalan):

```bash
docker compose -f dokploy-compose.yml exec -T db mysql -u root -p"$MYSQL_ROOT_PASSWORD" fpdp < fpdp-db-….sql
docker run --rm -v <project>_fpdp_storage:/data -v "$PWD":/backup alpine sh -c 'rm -rf /data/* && tar -xzf /backup/fpdp-storage-….tar.gz -C /data'
```

Jalankan lagi service-nya; container `app` menjalankan migration yang tertunda saat start. Backup volume bawaan Dokploy (ke storage kompatibel S3) adalah alternatif yang baik; restore kedua volume dari titik waktu yang sama. Lihat juga [Deployment Dokploy](DOKPLOY-DEPLOYMENT.id.md) §10.

## 10. Latihan restore

Backup yang belum pernah di-restore belum bisa disebut backup. Setiap beberapa bulan, dan setelah upgrade besar:

1. siapkan VPS cadangan (atau database dan folder scratch di server uji);
2. restore arsip semalam di sana (§7, tanpa mengubah DNS);
3. login dan jalankan pemeriksaan di §8;
4. hapus server cadangan dan salinan arsip di dalamnya.

Catat tanggal dan lama prosesnya; itulah waktu pemulihan Anda yang sebenarnya.

## 11. Keamanan

Backup berisi database production (nama, email, nomor telepon, dan alamat pembeli, pesanan, pembayaran), kredensial terenkripsi, dan `.env` beserta `APP_KEY` — cukup untuk membaca kredensial itu. Perlakukan setiap salinan seperti server production (ISO/IEC 27001:2022 A.8.13 backup informasi, A.5.34 privasi dan PII):

- simpan arsip hanya untuk root di server; jangan taruh di folder yang disajikan web;
- enkripsi salinan yang keluar dari server (`gpg -c`, BitLocker/FileVault di laptop); jangan pernah kirim tanpa enkripsi lewat chat atau email, atau ke drive bersama;
- simpan hanya selama diperlukan (default 14 hari di server; tetapkan juga masa simpan untuk salinan di luar server), dan hapus salinan lama;
- batasi siapa yang punya akses SSH/sudo ke server;
- uji restore (§10).

## 12. Troubleshooting

| Gejala | Kemungkinan penyebab | Solusi |
|---|---|---|
| Tidak ada arsip baru, tidak ada baris log | File cron belum terpasang, atau `FPDP_DIR` di dalamnya salah | Pasang `/etc/cron.d/fpdp` (§3), periksa `FPDP_DIR` |
| `mysqldump: command not found` | MySQL client belum terpasang | `sudo apt install mysql-client` (atau `mariadb-client`) |
| `Access denied for user` saat backup | Password database di `.env` berubah atau salah | Perbaiki `.env`, jalankan backup manual |
| `Archive is missing database.sql` | Arsip tidak lengkap atau bukan buatan `backup.sh` | Pakai arsip lain; periksa apakah disk sempat penuh |
| Restore: `Access denied` | User/password database di server baru berbeda dari `env` backup | Buat seperti di §7 langkah 2 |
| Setelah restore, payment gateway gagal atau kode 2FA ditolak | `.env` / `APP_KEY` bukan dari backup yang sama | Restore `.env` dari arsip yang sama; jangan pernah mencampur database dengan `APP_KEY` dari backup lain |
| Gambar atau file produk hilang setelah restore | `storage.tar` dari titik waktu berbeda, atau masalah permission | Restore database dan storage dari arsip yang sama; `sudo chown -R www-data:www-data /var/www/fpdp/storage` |
| Disk cepat penuh | Banyak arsip besar, atau folder `storage.before-restore.*` lama | Turunkan `KEEP_DAYS`, hapus `storage.before-restore.*` lama setelah restore terverifikasi |
