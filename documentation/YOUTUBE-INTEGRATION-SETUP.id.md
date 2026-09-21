# Integrasi Channel YouTube di FPDP

Dokumen ini menjelaskan arti integrasi YouTube pada FPDP, cara menghubungkan channel, cara sinkronisasi bekerja, data yang disimpan, dan batas implementasi saat ini.

## 1. Apa yang dimaksud integrasi YouTube?

Integrasi YouTube saat ini adalah **agregasi upload publik dari sebuah channel** melalui Atom feed resmi YouTube. Integrasi ini bukan login dengan Google, bukan akses YouTube Studio, dan bukan fitur untuk mengunggah video ke YouTube.

FPDP menggunakan URL feed berikut:

```text
https://www.youtube.com/feeds/videos.xml?channel_id=CHANNEL_ID
```

Karena feed tersebut bersifat publik:

- tidak memerlukan `GOOGLE_CLIENT_ID`;
- tidak memerlukan API key YouTube;
- tidak memerlukan OAuth atau consent screen;
- dapat digunakan untuk channel publik milik sendiri maupun channel publik lain;
- hanya mengambil metadata upload yang tersedia dalam feed publik.

Google OAuth yang digunakan untuk login visitor CV adalah fitur berbeda dan tidak berhubungan dengan integrasi channel YouTube ini.

## 2. Apa yang dilakukan FPDP?

Alurnya sebagai berikut:

```text
Channel ID
  → dinormalisasi menjadi official Atom feed
  → feed diambil oleh Atom connector
  → setiap video dinormalisasi sebagai external post
  → external post disimpan dan dideduplikasi
  → metadata tersedia melalui external-content API
```

Untuk setiap video, FPDP dapat menyimpan:

- ID entry/video eksternal;
- judul atau ringkasan dari feed;
- nama author/channel;
- waktu publikasi;
- canonical URL menuju halaman video YouTube;
- Video ID;
- URL thumbnail;
- URL embed privacy-enhanced `youtube-nocookie.com`.

FPDP tidak mengunduh atau menyalin file video. Pemutaran video tetap berasal dari infrastruktur YouTube.

## 3. Channel yang dapat digunakan

Implementasi saat ini menerima:

```text
UC_xxxxxxxxxxxxxxxxxxxxxx
```

atau URL channel dengan bentuk:

```text
https://www.youtube.com/channel/UC_xxxxxxxxxxxxxxxxxxxxxx
```

URL feed lengkap juga dapat digunakan:

```text
https://www.youtube.com/feeds/videos.xml?channel_id=UC_xxxxxxxxxxxxxxxxxxxxxx
```

Yang belum diterima oleh form saat ini:

```text
https://www.youtube.com/@nama-handle
https://www.youtube.com/c/nama-channel
https://www.youtube.com/user/nama-user
https://www.youtube.com/playlist?list=...
```

Jika channel memakai URL `@handle`, cari Channel ID aslinya terlebih dahulu. Channel ID YouTube biasanya diawali `UC`.

## 4. Cara mendapatkan Channel ID

Gunakan salah satu cara berikut:

### Dari URL channel

Jika alamat browser sudah berbentuk:

```text
https://www.youtube.com/channel/UCabc123...
```

salin bagian yang dimulai dengan `UC`.

### Dari YouTube Studio milik sendiri

1. Masuk ke YouTube Studio.
2. Buka **Settings**.
3. Buka **Channel** lalu **Advanced settings**.
4. Cari dan salin **Channel ID**.

Nama menu YouTube dapat berubah, tetapi nilai yang diperlukan FPDP tetap Channel ID yang berawalan `UC`.

### Verifikasi feed secara manual

Buka URL berikut di browser dengan Channel ID yang ditemukan:

```text
https://www.youtube.com/feeds/videos.xml?channel_id=CHANNEL_ID_ANDA
```

Jika benar, browser akan menampilkan XML/Atom berisi informasi channel dan entry video. Jika kosong atau menampilkan error, periksa kembali Channel ID dan status publik channel/video.

## 5. Cara menghubungkan channel dari dashboard

1. Masuk sebagai owner FPDP.
2. Buka `/dashboard/integrations`.
3. Pada bagian **Hubungkan sumber**, pilih **YouTube channel**.
4. Isi **Channel ID atau URL channel YouTube**.
5. Pilih interval sinkronisasi:
   - setiap jam (`3600` detik);
   - setiap 6 jam (`21600` detik);
   - setiap hari (`86400` detik).
6. Klik **Hubungkan sumber**.
7. Klik **Sinkronkan sekarang** untuk memaksa sinkronisasi semua source aktif milik owner saat itu juga.

Setelah disimpan, FPDP mengubah input menjadi URL feed resmi. Pada daftar sumber, URL yang terlihat akan berbentuk:

```text
https://www.youtube.com/feeds/videos.xml?channel_id=UC...
```

## 6. Cara sinkronisasi bekerja

Source baru memiliki `next_sync_at` kosong sehingga dapat diproses pada siklus sinkronisasi pertama. Setelah berhasil:

- `last_sync_at` diperbarui;
- `next_sync_at` dihitung berdasarkan interval;
- status source menjadi `ACTIVE`;
- video baru dimasukkan ke `external_posts`;
- video yang sudah pernah tersimpan dilewati berdasarkan kombinasi provider dan external post ID.

Tombol **Sinkronkan sekarang** memaksa semua source aktif milik owner untuk diproses tanpa menunggu `next_sync_at`. Worker cron tetap hanya memproses source yang sudah jatuh tempo.

Untuk memaksa satu source tertentu dari server:

```bash
php sync-external.php --source-id=ID_SOURCE
```

Contoh:

```bash
php sync-external.php --source-id=3
```

Untuk memproses maksimal 10 source yang jatuh tempo:

```bash
php sync-external.php --max=10
```

Contoh cron setiap 15 menit:

```cron
*/15 * * * * cd /var/www/fpdp && /usr/bin/php sync-external.php >> /var/log/fpdp-sync.log 2>&1
```

Sesuaikan path aplikasi, binary PHP, dan log dengan server. Cron boleh berjalan lebih sering daripada interval source karena worker hanya mengambil source yang sudah jatuh tempo.

## 7. API yang digunakan

Semua endpoint pengelolaan source memerlukan bearer token owner.

### Menambahkan channel

```http
POST /api/v1/me/feed-sources
Authorization: Bearer OWNER_TOKEN
Content-Type: application/json

{
  "provider": "YOUTUBE",
  "source_type": "youtube_channel",
  "source_url": "UC_xxxxxxxxxxxxxxxxxxxxxx",
  "sync_interval": 3600
}
```

Respons sukses menggunakan HTTP `201` dan mengembalikan ID source.

### Melihat source

```http
GET /api/v1/me/feed-sources
Authorization: Bearer OWNER_TOKEN
```

Field penting:

- `source_url`: feed yang sudah dinormalisasi;
- `status`: `ACTIVE` atau `ERROR`;
- `last_sync_at`: waktu sinkronisasi terakhir;
- `next_sync_at`: jadwal berikutnya;
- `last_error`: penyebab kegagalan terakhir;
- `sync_interval`: interval dalam detik.

### Menjalankan sinkronisasi source yang jatuh tempo

```http
POST /api/v1/me/sync
Authorization: Bearer OWNER_TOKEN
```

Respons menampilkan:

- `processed`: jumlah source yang diproses;
- `inserted`: jumlah external post baru;
- `errors`: jumlah source yang gagal.

### Membaca external post

```http
GET /api/v1/external/posts
```

Endpoint tersebut bersifat publik dan mendukung `limit` serta cursor pagination.

## 8. Penyimpanan database

### `external_feed_sources`

Source YouTube disimpan dengan nilai utama:

| Field | Nilai/contoh |
|---|---|
| `user_id` | Owner source |
| `provider` | `YOUTUBE` |
| `source_type` | `youtube_channel` |
| `source_url` | URL Atom feed yang sudah dinormalisasi |
| `sync_enabled` | Menentukan apakah source ikut worker |
| `sync_interval` | Interval sinkronisasi dalam detik |
| `last_sync_at` | Sinkronisasi terakhir |
| `next_sync_at` | Jadwal sinkronisasi berikutnya |
| `status` | `ACTIVE` atau `ERROR` |
| `last_error` | Error pengambilan/parsing terakhir |

### `external_posts`

Setiap entry video disimpan sebagai external post dengan:

- `provider = YOUTUBE`;
- `external_post_id` dari ID entry feed;
- `external_account_id` menunjuk ke ID `external_feed_sources`;
- `post_type = MEDIA` bila Video ID dikenali;
- `canonical_url` menuju video asli;
- `media_json` berisi descriptor embed dan thumbnail;
- `published_at` mengikuti waktu publikasi dari YouTube.

## 9. Tampilan konten saat ini

Data hasil sinkronisasi tersedia pada `GET /api/v1/external/posts` dan repository external content. Metadata embed `youtube-nocookie.com` juga sudah dihasilkan oleh backend.

Namun, UI timeline utama saat ini secara default membuka `source_type=LOCAL`, dan komponen kartu post server-side belum menampilkan descriptor embed external YouTube secara penuh. Jadi keberhasilan sinkronisasi harus diperiksa melalui endpoint external posts/database sampai panel external timeline dan renderer video disempurnakan.

Ini berarti:

- integrasi dan penyimpanan YouTube sudah bekerja;
- API external post sudah tersedia;
- rendering video hasil agregasi pada UI publik masih merupakan pekerjaan lanjutan.

Fitur menempelkan URL YouTube pada post lokal melalui Post Editor adalah alur yang berbeda. Editor membuat iframe embed pada isi post lokal dan tidak menghubungkan seluruh channel.

## 10. Batasan

- Hanya upload publik yang muncul dalam official feed.
- Video private dan unlisted tidak tersedia.
- Feed hanya menyediakan kumpulan upload terbaru, bukan seluruh arsip channel.
- Playlist belum didukung oleh normalisasi source dashboard saat ini.
- URL `@handle`, `/c/`, dan `/user/` belum otomatis diubah menjadi Channel ID.
- Tidak mengambil analytics, subscriber count, komentar, caption, atau data YouTube Studio.
- Tidak dapat mengunggah, mengedit, atau menghapus video YouTube.
- Source dapat dihapus dari dashboard. Penghapusan juga menghapus external post yang diimpor dari source tersebut.
- Ketersediaan embed tetap mengikuti izin embedding, pembatasan usia, wilayah, dan kebijakan YouTube pada video tersebut.

Untuk metadata lanjutan diperlukan integrasi YouTube Data API v3 yang terpisah, termasuk API key/OAuth, quota handling, consent, dan kebijakan penyimpanan data.

## 11. Troubleshooting

### Pesan “Gunakan channel ID YouTube atau URL /channel/UC…”

Input bukan Channel ID yang valid. Gunakan ID berawalan `UC`, URL `/channel/UC...`, atau URL official feed dengan query `channel_id`.

### Source tersimpan tetapi `inserted: 0`

Kemungkinan:

- semua video dari feed sudah pernah disimpan;
- channel belum memiliki upload publik terbaru;
- feed publik tidak mengembalikan entry.

Gunakan `php sync-external.php --source-id=ID_SOURCE` untuk memaksa source tertentu dan melihat hasil CLI.

### Status source menjadi `ERROR`

Periksa `last_error` melalui `GET /api/v1/me/feed-sources` atau database. Penyebab umum:

- Channel ID salah;
- YouTube tidak dapat dijangkau dari server;
- DNS, firewall, TLS, atau proxy bermasalah;
- respons bukan XML yang valid;
- ekstensi PHP XML/SimpleXML tidak tersedia.

### Video tidak terlihat di timeline

Periksa terlebih dahulu `GET /api/v1/external/posts`. Jika data ada, sinkronisasi berhasil dan masalah berada pada renderer/UI external content, bukan pada koneksi channel.

## 12. Checklist penggunaan

- Channel ID valid dan berawalan `UC`.
- Official feed dapat dibuka dari browser/server.
- Source muncul pada `/dashboard/integrations`.
- Sinkronisasi pertama menghasilkan `processed > 0`.
- `status` source tetap `ACTIVE`.
- External post tersedia melalui `/api/v1/external/posts`.
- Cron `sync-external.php` aktif untuk sinkronisasi otomatis.
- Canonical URL tetap mengarah ke video asli YouTube.
