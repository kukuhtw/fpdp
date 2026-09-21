# Konfigurasi Payment Gateway FPDP

Dokumen ini menjelaskan sumber konfigurasi payment gateway, tabel dan field yang digunakan, arti status sukses pada dashboard, serta cara memilih Sandbox atau Live.

## Ringkasan keputusan

- Gateway yang dipakai checkout dipilih dari database, tepatnya `nodes.active_gateway`.
- Credential yang disimpan melalui dashboard berada di `payment_gateway_configs.encrypted_value`, bukan ditulis ke `.env`.
- Credential di database menjadi sumber utama. `.env` berfungsi sebagai fallback untuk key yang tidak tersedia dari database.
- `APP_KEY` tetap wajib berada di environment server karena dipakai untuk mengenkripsi dan membuka credential database.
- SANDBOX/LIVE yang dipilih di dashboard disimpan pada `payment_gateway_configs.environment` dan `is_active`.
- Khusus implementasi PayPal saat ini, mode endpoint masih dibaca dari `PAYPAL_ENVIRONMENT` apabila repository tidak mengirim key `environment`. Karena itu nilai `.env` harus disamakan dengan pilihan database.

## Cara kerja konfigurasi

Konfigurasi terdiri dari tiga lapisan yang berbeda:

| Tujuan | Lokasi | Field penting |
|---|---|---|
| Daftar gateway yang didukung | `payment_gateways` | `id`, `code`, `name`, `adapter_class`, `status` dan capability |
| Credential dan environment | `payment_gateway_configs` | `gateway_id`, `config_key`, `encrypted_value`, `environment`, `is_active` |
| Gateway default untuk checkout milik node | `nodes` | `active_gateway` |

Tabel `payments` menyimpan transaksi pembayaran, sedangkan `payment_transactions` menyimpan event/webhook. Keduanya bukan tempat konfigurasi credential.

Alur runtime:

1. Checkout membaca `nodes.active_gateway`, misalnya `PAYPAL`.
2. Aplikasi mencari record gateway berdasarkan `payment_gateways.code`.
3. Aplikasi mengambil semua row `payment_gateway_configs` milik gateway dengan `is_active = 1`.
4. `encrypted_value` dibuka menggunakan `APP_KEY`.
5. Adapter gateway memakai nilai database tersebut. Key yang tidak tersedia dapat diambil dari `.env` oleh adapter.
6. Aplikasi menghubungi endpoint provider dan membuat record di `payments` bila pembuatan pembayaran berhasil.
7. Webhook provider masuk ke `/api/v1/payments/webhook/{CODE}` dan direkam di `payment_transactions`.

## Field database secara rinci

### `payment_gateways`

Tabel katalog gateway. Contoh `code`: `DUMMY`, `PAYWUZ`, `MIDTRANS`, dan `PAYPAL`.

- `id`: foreign key yang dipakai oleh tabel konfigurasi.
- `code`: kode gateway yang dipilih di `nodes.active_gateway`.
- `adapter_class`: implementasi PHP gateway.
- `status`: status katalog gateway. Ini bukan penanda credential valid.
- field `supports_*`: capability untuk tampilan/informasi fitur.

### `payment_gateway_configs`

Setiap credential disimpan sebagai satu row per gateway, key, dan environment.

- `gateway_id`: relasi ke `payment_gateways.id`.
- `config_key`: nama key adapter, misalnya `client_id`.
- `encrypted_value`: nilai credential yang sudah dienkripsi; jangan diisi manual dengan plaintext.
- `environment`: hanya `SANDBOX` atau `LIVE` melalui API dashboard.
- `is_active`: menandai kumpulan environment yang sedang dibaca untuk gateway tersebut.
- unique key: `(gateway_id, config_key, environment)`.

Key yang diterima:

| Gateway | `config_key` untuk operasi normal |
|---|---|
| `DUMMY` | Tidak ada |
| `PAYWUZ` | `api_key`, `api_url` |
| `MIDTRANS` | `server_key` |
| `PAYPAL` | `client_id`, `client_secret`, `webhook_id` |

`is_active` bukan berarti gateway dipakai checkout. Ia hanya memilih credential/environment aktif untuk satu gateway.

### `nodes.active_gateway`

Nilainya adalah kode gateway default node, misalnya `PAYPAL`. Field inilah yang menentukan gateway checkout, termasuk pembayaran akses CV/resume. Pemilihan ini dilakukan dengan tombol **Activate** atau endpoint aktivasi, bukan dengan mengubah `.env`.

## Prioritas `.env` dan database

| Konfigurasi | Sumber utama | Fallback/catatan |
|---|---|---|
| Gateway yang dipakai checkout | `nodes.active_gateway` | Tidak menggunakan `CV_PAYMENT_GATEWAY` pada implementasi saat ini |
| Credential gateway | `payment_gateway_configs` aktif | Adapter mengambil key yang hilang dari `.env` |
| Enkripsi credential DB | `.env`: `APP_KEY` | Harus stabil dan tidak boleh diganti sembarangan |
| Mode Midtrans | environment aktif DB | `LIVE` dipetakan menjadi `PRODUCTION`; tanpa config DB gunakan `MIDTRANS_ENVIRONMENT` |
| Mode Paywuz | credential aktif DB | Endpoint Sandbox/Live ditentukan oleh `api_url` |
| Mode PayPal saat ini | `.env`: `PAYPAL_ENVIRONMENT` | Pilihan environment DB belum diteruskan ke adapter PayPal |

Karena adapter dapat memakai `.env` untuk key yang tidak ditemukan, hindari konfigurasi parsial. Konfigurasi parsial bisa mencampurkan Client ID dari database dengan Secret dari `.env`, termasuk mencampur credential Sandbox dan Live.

## Mengapa dashboard mengatakan berhasil, tetapi gateway tidak bekerja?

Respons sukses dashboard saat ini hanya berarti validasi bentuk request lolos, nilai berhasil dienkripsi, dan transaksi database selesai. Aplikasi belum melakukan pemeriksaan ke API provider pada saat tombol **Save configuration** ditekan.

Penyebab yang mungkin:

1. **Credential tidak diverifikasi saat penyimpanan.** Client ID/API key yang salah tetap dapat tersimpan dengan sukses dan baru ditolak ketika checkout atau webhook berjalan.
2. **Konfigurasi parsial diterima.** Backend hanya mewajibkan request berisi minimal satu key. Dashboard menampilkan “Configured” bila minimal satu key tersimpan, bukan bila seluruh key wajib tersedia.
3. **Save dan Activate adalah operasi berbeda.** Menyimpan credential tidak mengubah `nodes.active_gateway`. Sebaliknya, gateway dapat diaktifkan walaupun credential belum lengkap.
4. **Environment dan credential tidak cocok.** Credential Sandbox tidak dapat dipakai pada endpoint Live, dan sebaliknya.
5. **Khusus PayPal, environment database belum mengendalikan endpoint runtime.** Dashboard dapat menampilkan LIVE aktif, tetapi adapter tetap memakai Sandbox jika `PAYPAL_ENVIRONMENT=SANDBOX`.
6. **`APP_KEY` berubah.** Credential lama tidak dapat didekripsi. Row yang gagal didekripsi saat ini dilewati sehingga adapter mungkin jatuh ke `.env`.
7. **Webhook belum dibuat di dashboard provider.** Menyimpan `webhook_id` di FPDP tidak otomatis mendaftarkan URL webhook ke provider.
8. **Constraint provider tidak terpenuhi.** Contohnya adapter PayPal FPDP tidak menerima mata uang `IDR`.

Jadi, pesan yang lebih tepat untuk status sekarang adalah **“configuration saved”**, bukan **“gateway verified”**.

## Mengatur Sandbox dan Live

### Rekomendasi sumber konfigurasi

Untuk deployment yang memakai dashboard:

1. Simpan `APP_KEY` yang kuat dan permanen di `.env`/environment service.
2. Simpan seluruh credential Sandbox dan Live melalui dashboard, bukan sebagian di dashboard dan sebagian di `.env`.
3. Pilih environment yang akan aktif melalui form konfigurasi gateway.
4. Klik **Activate** untuk gateway yang akan dipakai checkout.
5. Samakan `PAYPAL_ENVIRONMENT` di server dengan environment PayPal aktif sampai kekurangan implementasi PayPal diperbaiki.
6. Restart/redeploy service bila `.env` diubah.
7. Jalankan transaksi end-to-end dan periksa delivery webhook sebelum menyatakan integrasi siap.

### Sandbox

Contoh fallback `.env`:

```dotenv
APP_KEY=nilai-rahasia-yang-stabil

PAYWUZ_API_KEY=credential_sandbox
PAYWUZ_API_URL=https://endpoint-sandbox-provider

MIDTRANS_SERVER_KEY=credential_sandbox
MIDTRANS_ENVIRONMENT=SANDBOX

PAYPAL_CLIENT_ID=client_id_sandbox
PAYPAL_CLIENT_SECRET=client_secret_sandbox
PAYPAL_WEBHOOK_ID=webhook_id_sandbox
PAYPAL_ENVIRONMENT=SANDBOX
```

Pada dashboard, pilih environment **Sandbox**, isi semua field gateway, simpan, lalu aktifkan gateway.

### Live

Gunakan credential Live yang berasal dari aplikasi/account Live provider. Jangan sekadar mengubah label environment atas credential Sandbox.

```dotenv
MIDTRANS_ENVIRONMENT=PRODUCTION
PAYPAL_ENVIRONMENT=LIVE
```

- Midtrans: pilihan `LIVE` pada database otomatis dipetakan ke mode `PRODUCTION`.
- Paywuz: simpan `api_url` Live bersama `api_key` Live.
- PayPal: pilih `LIVE` di dashboard **dan** set `PAYPAL_ENVIRONMENT=LIVE` pada server untuk perilaku runtime saat ini.

Lakukan transaksi Live bernilai kecil dan verifikasi webhook sebelum membuka pembayaran untuk pengguna umum.

## API dashboard

Melihat konfigurasi tanpa membuka nilai rahasia:

```http
GET /api/v1/me/payment-gateways
Authorization: Bearer OWNER_TOKEN
```

Menyimpan semua credential PayPal Sandbox:

```http
PATCH /api/v1/me/payment-gateways/PAYPAL
Authorization: Bearer OWNER_TOKEN
Content-Type: application/json

{
  "environment": "SANDBOX",
  "config": {
    "client_id": "...",
    "client_secret": "...",
    "webhook_id": "..."
  }
}
```

Mengaktifkan PayPal sebagai gateway checkout:

```http
PUT /api/v1/me/payment-gateways/PAYPAL/activate
Authorization: Bearer OWNER_TOKEN
```

## Pemeriksaan database yang aman

Query berikut hanya memeriksa metadata dan tidak membuka rahasia:

```sql
SELECT id, code, name, status
FROM payment_gateways
ORDER BY code;

SELECT
    pg.code,
    pgc.config_key,
    pgc.environment,
    pgc.is_active,
    CASE WHEN pgc.encrypted_value <> '' THEN 1 ELSE 0 END AS value_present,
    pgc.updated_at
FROM payment_gateway_configs pgc
JOIN payment_gateways pg ON pg.id = pgc.gateway_id
ORDER BY pg.code, pgc.environment, pgc.config_key;

SELECT id, domain, active_gateway
FROM nodes;
```

Jangan mengubah `encrypted_value` langsung. Gunakan dashboard/API agar nilai dienkripsi dengan format yang benar.

## Catatan implementasi PayPal

Repository konfigurasi saat ini hanya menambahkan key runtime `environment=PRODUCTION` untuk Midtrans ketika environment database adalah `LIVE`. Perlakuan setara belum ada untuk PayPal. Akibatnya:

- `payment_gateway_configs.environment = LIVE` mengaktifkan credential Live di database;
- tetapi pemilihan base URL PayPal masih memakai `PAYPAL_ENVIRONMENT` dari `.env`;
- kondisi keduanya berbeda dapat menyebabkan credential Live dikirim ke endpoint Sandbox atau sebaliknya.

Mitigasi operasional saat ini adalah menyamakan keduanya. Perbaikan kode yang disarankan adalah meneruskan environment database ke konfigurasi adapter PayPal, lalu menambahkan validasi kelengkapan credential dan health check provider sebelum dashboard menampilkan status terverifikasi.

## Checklist sebelum produksi

- `APP_KEY` terpasang, aman, dan tidak berubah sejak credential disimpan.
- Semua key wajib diisi dalam environment yang sama.
- Credential berasal dari account/app provider yang sama.
- Environment database dan endpoint provider cocok.
- Untuk PayPal, `PAYPAL_ENVIRONMENT` cocok dengan pilihan dashboard.
- Gateway yang benar tercatat di `nodes.active_gateway`.
- URL webhook HTTPS publik sudah didaftarkan pada provider.
- Signature webhook berhasil diverifikasi.
- Mata uang dan metode pembayaran didukung adapter/provider.
- Transaksi Sandbox selesai, kemudian transaksi Live bernilai kecil selesai.
