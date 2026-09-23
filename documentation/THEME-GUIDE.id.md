# Panduan Template Theme FPDP — Bahasa Indonesia

## 1. Ringkasan

FPDP mendukung sistem template ("theme") sederhana berbasis folder. Setiap node memilih satu theme aktif (kolom `nodes.theme`, default `default`), dan pemilik akun dapat menggantinya kapan saja dari **Dashboard → Template** (`/dashboard/themes`) tanpa perlu deploy ulang.

Theme dipasang dengan **menyalin folder ke `/themes/` di server** — bukan lewat upload file dari browser. Ini keputusan sengaja: dashboard tidak pernah menerima atau mengeksekusi kode dari luar, jadi tidak ada permukaan serangan baru untuk remote code execution lewat fitur ini. Siapa pun yang bisa menaruh folder di `/themes/` sudah punya akses file-level ke server (FTP/SSH/hosting file manager) — level kepercayaan yang sama seperti mengedit file PHP lain di instalasi FPDP mereka sendiri.

## 2. Struktur folder sebuah theme

```
themes/
  nama-theme-anda/
    theme.json          (wajib)
    views/               (opsional — file yang tidak disediakan otomatis fallback ke tampilan bawaan)
      profile.php
      about-me.php
      youtube.php
      wall-coretan.php
      post.php
      public-cv.php
    assets/              (opsional)
      theme.css
      (font, gambar, dll — semuanya file statis)
```

Nama folder (`nama-theme-anda`) menjadi **slug** theme: huruf kecil, angka, `-`, `_` saja (regex: `^[a-z0-9][a-z0-9_-]{0,63}$`).

### 2.1 `theme.json`

```json
{
    "name": "Nama Theme Anda",
    "description": "Deskripsi singkat yang tampil di dashboard.",
    "author": "Nama Anda",
    "version": "1.0.0",
    "preview_color": "#185f48"
}
```

Semua field opsional (fallback ke slug/`1.0.0`/warna hijau default), tapi isi semuanya supaya theme mudah dikenali di daftar pemilihan.

### 2.2 View yang boleh di-override

Hanya nama file berikut yang dikenali sebagai override halaman publik. File lain di `views/` diabaikan:

| File | Halaman |
|---|---|
| `profile.php` | Profil publik / beranda (`/`, `/@handle`) |
| `about-me.php` | About Me (`/about-me`, `/@handle/about-me`... via handle) |
| `youtube.php` | Halaman video YouTube |
| `wall-coretan.php` | Halaman coretan/wall publik |
| `post.php` | Halaman satu post |
| `public-cv.php` | Halaman CV publik |

**Anda tidak wajib menyediakan semuanya.** Halaman yang tidak ada file override-nya otomatis memakai template inti (`app/Views/{nama}.php`, theme bawaan "Default"). Ini artinya theme bisa dimulai kecil — misalnya cuma meng-override `profile.php` dulu — lalu ditambah bertahap.

Setiap view menerima variabel yang sama seperti versi intinya (lihat `app/Controllers/ContentPageController.php` untuk daftar variabel per halaman, mis. `$profile`, `$posts`, `$post`, `$title`). View adalah file PHP biasa — Anda menulis HTML langsung seperti file inti di `app/Views/`, dengan `htmlspecialchars(...)` di setiap output data pengguna (wajib, demi keamanan XSS).

**Partial yang dibagikan lintas theme:** gunakan `\App\Core\View::partial('post-card', ['post' => $post])` untuk merender kartu post — ini partial inti yang sama dipakai semua theme (termasuk bawaan), berisi logic escaping & media yang sudah teruji. Theme Anda cukup meng-style ulang class CSS-nya (`.post-card`, `.post-meta`, `.post-content`, `.post-media`, `.media-link`) di `theme.css` sendiri, tanpa perlu menulis ulang logic-nya.

### 2.3 Aset (`assets/`)

Semua isi folder `assets/` bisa diakses publik lewat:

```
/themes/<slug>/assets/<nama-file>
```

Misalnya `themes/nama-theme-anda/assets/theme.css` → `/themes/nama-theme-anda/assets/theme.css`. Ekstensi yang didukung: `css`, `js`, `png`, `jpg`/`jpeg`, `svg`, `webp`, `gif`, `woff`/`woff2`, `ttf`. File PHP di `views/` **tidak pernah** diserve lewat HTTP — hanya file di `assets/` yang publik.

Hubungkan CSS Anda dari dalam view dengan tag biasa:

```html
<link rel="stylesheet" href="/themes/nama-theme-anda/assets/theme.css">
```

## 3. Dua cara membuat theme

**A. Theme minimal (hanya metadata, tanpa override apa pun)**

Cukup buat `themes/nama-theme-anda/theme.json`. Tanpa folder `views/`, theme ini murni memakai tampilan inti untuk semua halaman — berguna sebagai titik awal sebelum Anda menambahkan override, atau sebagai placeholder di daftar pemilihan. Ini persis bagaimana theme bawaan **default** dibuat.

**B. Theme dengan tampilan sendiri**

Salin salah satu view inti (mis. `app/Views/profile.php`) ke `themes/nama-theme-anda/views/profile.php` sebagai titik awal, lalu:

1. Ganti `<link rel="stylesheet" href="/assets/app.css">` menjadi `<link rel="stylesheet" href="/themes/nama-theme-anda/assets/theme.css">`.
2. Ubah markup nav/hero/layout sesuka Anda — variabel (`$profile`, `$posts`, dst.) tetap sama seperti versi inti.
3. Tulis `themes/nama-theme-anda/assets/theme.css` dari nol, atau salin `public/assets/app.css` sebagai titik awal lalu ubah warna/font/spacing-nya.

## 4. Tiga theme bawaan sebagai contoh nyata

Pelajari `themes/default/`, `themes/editorial/`, dan `themes/minimal/` di repository ini sebagai referensi kerja:

- **default** — tidak berisi `views/` sama sekali; selalu fallback ke tampilan inti. Contoh theme paling sederhana yang valid (hanya `theme.json`).
- **editorial** — meng-override `profile.php` dengan gaya majalah: latar krem, judul serif besar, aksen merah marun.
- **minimal** — meng-override `profile.php` dengan gaya monokrom bersih: putih/hitam, sans-serif, garis tipis.

Keduanya (`editorial`, `minimal`) menunjukkan pola lengkap: nav sendiri, hero sendiri, panel "cara follow" federasi, lalu me-reuse partial `post-card` untuk daftar tulisan.

## 5. Cara instalasi theme (yang dibuat orang lain)

1. Dapatkan folder theme (biasanya dibagikan sebagai `.zip`).
2. Ekstrak di komputer Anda, pastikan strukturnya `<slug>/theme.json` di root folder tersebut.
3. Salin folder itu ke `/themes/` di server FPDP Anda — lewat FTP, file manager hosting, atau `scp`/`rsync` via SSH. Hasil akhirnya harus `themes/<slug>/theme.json` bisa diakses di server.
4. Buka **Dashboard → Template** (`/dashboard/themes`), muat ulang halaman — theme baru otomatis muncul di daftar.
5. Klik **Aktifkan** pada theme yang diinginkan. Berlaku langsung, tidak perlu restart server.

Tidak ada langkah upload lewat browser secara sengaja — lihat bagian 1 untuk alasannya.

## 6. Cara memilih theme aktif lewat API

Selain lewat dashboard, pemilik akun bisa memakai API langsung (butuh bearer token):

```
GET  /api/v1/me/themes            → daftar theme terpasang + slug yang sedang aktif
PATCH /api/v1/me/theme            → body: {"slug": "editorial"}
```

## 7. Keamanan & batasan

- Slug theme dan nama file view/asset divalidasi dengan whitelist ketat (path traversal, ekstensi tidak dikenal, dan nama file view di luar 6 nama yang diizinkan ditolak).
- Theme tidak bisa mengubah endpoint API, autentikasi, atau halaman dashboard — hanya 6 halaman publik yang terdaftar di atas.
- Karena view theme adalah file PHP biasa yang di-`require`, theme punya akses penuh sama seperti kode inti FPDP (variabel yang dioper, fungsi global PHP, dll). **Hanya pasang theme dari sumber yang Anda percaya**, sama seperti menginstal plugin/kode pihak ketiga apa pun di server Anda sendiri.
