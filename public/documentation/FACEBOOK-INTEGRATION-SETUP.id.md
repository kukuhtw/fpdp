# Konfigurasi Integrasi Facebook Pages

FPDP mengambil post dari **Facebook Page yang dikelola owner** melalui Meta Graph API. Integrasi ini tidak mengambil post akun personal dan tidak melakukan scraping.

## 1. Buat Meta App

1. Buka [Meta for Developers](https://developers.facebook.com/apps/).
2. Buat app untuk use case Facebook Login/Business.
3. Tambahkan produk **Facebook Login**.
4. Catat **App ID** dan **App Secret** dari App Settings.
5. Tambahkan domain FPDP ke App Domains dan lengkapi URL privacy policy/data deletion yang diminta Meta.

## 2. Daftarkan callback

Tambahkan URI berikut pada **Valid OAuth Redirect URIs**:

```text
https://DOMAIN/api/v1/integrations/facebook/callback
```

Contoh:

```text
https://kukuhtw.com/api/v1/integrations/facebook/callback
```

URI harus cocok persis dan production wajib HTTPS.

## 3. Permission

FPDP meminta:

- `pages_show_list` untuk menemukan Page yang dikelola user;
- `pages_read_engagement` untuk membaca konten dan engagement Page;

Saat app masih Development Mode, hanya akun dengan role app (admin/developer/tester) yang dapat menguji. Untuk pengguna umum, ajukan Advanced Access/App Review sesuai permintaan dashboard Meta. Page yang berada di Business Portfolio mungkin juga memerlukan konfigurasi asset/business yang sesuai.

## 4. Environment

```dotenv
FACEBOOK_APP_ID=APP_ID_DARI_META
FACEBOOK_APP_SECRET=APP_SECRET_DARI_META
FACEBOOK_GRAPH_VERSION=v26.0
```

Simpan lalu redeploy. `FACEBOOK_GRAPH_VERSION` sengaja dapat diubah karena versi Graph API memiliki masa dukungan terbatas. Validasi versi aktif terhadap dashboard dan changelog Meta sebelum upgrade.

## 5. Hubungkan Page

1. Masuk sebagai owner FPDP.
2. Buka `/dashboard/integrations`.
3. Klik **Hubungkan Facebook**.
4. Login ke Facebook dan setujui Page yang ingin diberikan kepada app.
5. FPDP menghubungkan semua Page yang dikembalikan Meta untuk consent tersebut.
6. Klik **Sinkronkan sekarang** untuk mengambil maksimal 50 post terbaru per Page.

Page access token disimpan terenkripsi menggunakan `APP_KEY`; token tidak dikirim ke browser atau endpoint daftar koneksi. Setiap post mempertahankan permalink Facebook sebagai canonical URL.

## 6. Putuskan dan hubungkan ulang

Gunakan tombol **Putuskan** pada Page untuk menghapus token dan feed source lokal. Post yang sudah diimpor tetap tersimpan. Gunakan **Hubungkan Facebook** lagi jika token kedaluwarsa, permission dicabut, atau status sinkronisasi menjadi error.

## 7. Catatan production

- Jangan mengganti `APP_KEY` tanpa rencana migrasi; token terenkripsi lama tidak dapat dibaca setelah rotasi.
- App Secret tidak boleh dikirim ke browser atau disimpan di repository.
- Integrasi bergantung pada approval, role Page, task Page, dan kebijakan Meta yang berlaku.
- Periksa error sinkronisasi di dashboard setelah perubahan permission atau versi Graph API.

Referensi: [koleksi resmi Facebook API oleh Meta](https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api) dan [Meta for Developers](https://developers.facebook.com/).
