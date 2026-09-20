# Mendapatkan `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET`

Dokumen ini menjelaskan konfigurasi Google OAuth untuk login pengunjung FPDP. Login ini dipakai sebelum pengunjung membeli atau mengunduh CV/resume.

FPDP menggunakan OAuth 2.0 tipe **Web application** dan hanya meminta scope identitas dasar:

- `openid`
- `email`
- `profile`

## 1. Tentukan callback FPDP

Format callback aplikasi:

```text
https://DOMAIN/api/v1/profiles/HANDLE/visitor-auth/google/callback
```

Ganti:

- `DOMAIN` dengan domain publik FPDP;
- `HANDLE` dengan handle owner yang sebenarnya, tanpa karakter `{` dan `}`.

Contoh untuk domain `profil.example.com` dan handle `maya`:

```text
https://profil.example.com/api/v1/profiles/maya/visitor-auth/google/callback
```

Google tidak menerima wildcard pada authorized redirect URI. URI harus didaftarkan secara persis, termasuk `https`, domain, path, huruf besar/kecil, dan trailing slash. Karena callback FPDP mengandung handle, daftarkan satu URI untuk setiap handle yang akan menerima login Google.

Untuk pengembangan lokal, sesuaikan host dan port yang benar, misalnya:

```text
http://localhost:8080/api/v1/profiles/maya/visitor-auth/google/callback
```

Google mengizinkan HTTP untuk localhost, tetapi deployment publik harus memakai HTTPS.

## 2. Buat atau pilih Google Cloud project

1. Buka [Google Cloud Console](https://console.cloud.google.com/).
2. Pilih project yang sudah ada atau buat project baru.
3. Buka **Google Auth Platform**.
4. Jika project belum dikonfigurasi, tekan **Get started**.

## 3. Konfigurasikan consent screen

Pada Google Auth Platform, lengkapi:

1. **Branding**: app name, support email, homepage, dan informasi kontak.
2. **Audience**:
   - pilih **Internal** hanya bila seluruh pengguna berasal dari satu organisasi Google Workspace;
   - pilih **External** untuk akun Google umum.
3. **Data Access**: tambahkan scope `openid`, `email`, dan `profile` bila belum tersedia.
4. Saat aplikasi masih berstatus testing, tambahkan akun yang akan mencoba login pada **Test users**.

Untuk penggunaan publik, siapkan domain terverifikasi, homepage, dan privacy policy sesuai persyaratan Google. Kebutuhan verifikasi bergantung pada audience, status publikasi, dan scope aplikasi.

## 4. Buat OAuth client

1. Buka **Google Auth Platform → Clients**.
2. Klik **Create client**.
3. Pilih application type **Web application**.
4. Isi nama, misalnya `FPDP Production`.
5. Pada **Authorized redirect URIs**, tambahkan callback dari Bagian 1.
6. **Authorized JavaScript origins** tidak diperlukan oleh flow FPDP saat ini karena pertukaran authorization code dilakukan oleh server.
7. Klik **Create**.
8. Salin **Client ID** dan **Client secret** saat ditampilkan.

Simpan client secret dengan aman. Jangan memasukkannya ke repository, JavaScript browser, screenshot publik, atau log aplikasi.

## 5. Isi environment FPDP

Untuk instalasi lokal, isi file `.env`:

```dotenv
GOOGLE_CLIENT_ID=123456789012-abcdefghijklmnopqrstuvwxyz.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-contohSecretDariGoogle
```

Gunakan nilai asli dari Google Cloud Console tanpa tanda kutip dan tanpa spasi di awal atau akhir.

Untuk Dokploy:

1. Buka service aplikasi.
2. Buka menu **Environment**.
3. Tambahkan `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET`.
4. Simpan lalu redeploy aplikasi.

Jangan menaruh secret asli di `.env.example` atau `.env.dokploy.example`; kedua file tersebut hanya template.

## 6. Uji konfigurasi

1. Pastikan profile owner dan CV sudah tersedia.
2. Buka:

   ```text
   https://DOMAIN/@HANDLE/cv
   ```

3. Klik **Masuk dengan Google**.
4. Pilih akun Google dan selesaikan consent.
5. Setelah callback, browser harus kembali ke halaman CV dan tombol pembelian/download akan tersedia sesuai hak akses.

## 7. Troubleshooting

### `redirect_uri_mismatch`

Bandingkan callback yang dikirim FPDP dengan **Authorized redirect URIs** di Google Cloud. Nilainya harus identik. Penyebab umum:

- masih menulis `{handle}` dan bukan handle sebenarnya;
- memakai `http` di Google Cloud tetapi aplikasi mengirim `https`, atau sebaliknya;
- domain `www` dan non-`www` berbeda;
- port localhost berbeda;
- ada trailing slash tambahan;
- reverse proxy tidak meneruskan host atau protokol asli.

Untuk deployment di belakang Dokploy/reverse proxy, pastikan request aplikasi menerima `Host` yang benar dan `X-Forwarded-Proto: https`, karena FPDP membangun callback dari kedua header tersebut.

### `GOOGLE_CLIENT_ID is not configured`

Variabel belum masuk ke proses PHP. Simpan environment lalu restart/redeploy service.

### `Google OAuth is not configured`

Salah satu dari `GOOGLE_CLIENT_ID` atau `GOOGLE_CLIENT_SECRET` kosong.

### Aplikasi belum diverifikasi atau akses ditolak

Jika aplikasi masih dalam mode testing, masukkan akun tersebut sebagai test user. Untuk audience publik, lengkapi konfigurasi consent screen dan proses verifikasi yang diminta Google.

### Perubahan belum langsung berlaku

Perubahan OAuth client dapat memerlukan waktu beberapa menit hingga beberapa jam sebelum aktif di seluruh sistem Google.

## 8. Referensi resmi

- [Google OAuth 2.0 untuk aplikasi web server](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Mengelola OAuth clients](https://support.google.com/cloud/answer/15549257)
- [Memulai Google Auth Platform](https://support.google.com/cloud/answer/15544987)
- [Kebijakan OAuth 2.0 Google](https://developers.google.com/identity/protocols/oauth2/policies)

