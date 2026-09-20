# Mendapatkan credential PayPal untuk FPDP

Panduan ini menjelaskan cara mendapatkan dan mengisi:

```dotenv
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_ENVIRONMENT=SANDBOX
```

FPDP memakai PayPal REST **Orders API v2** dengan intent `CAPTURE`. Mulailah dari Sandbox dan pindah ke Live hanya setelah checkout serta webhook berhasil diuji.

## 1. Perbedaan Sandbox dan Live

Credential Sandbox dan Live berbeda dan tidak dapat dicampur.

| Environment FPDP | Credential PayPal | API PayPal |
|---|---|---|
| `SANDBOX` | Sandbox Client ID, Secret, dan Webhook ID | `https://api-m.sandbox.paypal.com` |
| `PRODUCTION` atau `LIVE` | Live Client ID, Secret, dan Webhook ID | `https://api-m.paypal.com` |

Untuk pengembangan gunakan:

```dotenv
PAYPAL_ENVIRONMENT=SANDBOX
```

## 2. Buat akun dan aplikasi Sandbox

1. Buka [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/).
2. Login menggunakan akun PayPal.
3. Pilih environment **Sandbox**.
4. Buka **Apps & Credentials**.
5. Jika belum memiliki sandbox business account, buat atau pilih akun merchant/business pada bagian Sandbox Accounts.
6. Klik **Create App**.
7. Isi nama, misalnya `FPDP Sandbox`.
8. Kaitkan aplikasi dengan sandbox business account yang akan menerima pembayaran.
9. Selesaikan pembuatan aplikasi.

## 3. Ambil Client ID dan Client Secret

Buka detail REST app yang baru dibuat. Pada bagian API credentials:

1. Salin **Client ID** ke `PAYPAL_CLIENT_ID`.
2. Tampilkan lalu salin **Secret** ke `PAYPAL_CLIENT_SECRET`.
3. Jangan memakai email/password sandbox sebagai API credential.

Contoh struktur `.env`:

```dotenv
PAYPAL_CLIENT_ID=Acxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
PAYPAL_CLIENT_SECRET=ELxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
PAYPAL_ENVIRONMENT=SANDBOX
```

Client Secret harus diperlakukan seperti password. Jangan memasukkannya ke Git, JavaScript browser, screenshot, atau log.

## 4. Daftarkan webhook FPDP

Webhook production harus dapat diakses melalui HTTPS publik. Format endpoint FPDP:

```text
https://DOMAIN/api/v1/payments/webhook/PAYPAL
```

Contoh:

```text
https://profil.example.com/api/v1/payments/webhook/PAYPAL
```

Pada detail REST app di PayPal Developer Dashboard:

1. Cari bagian **Webhooks** untuk environment yang sedang digunakan.
2. Klik **Add Webhook**.
3. Masukkan URL webhook FPDP.
4. Pilih event berikut:
   - `CHECKOUT.ORDER.APPROVED`
   - `CHECKOUT.ORDER.COMPLETED`
   - `PAYMENT.CAPTURE.PENDING`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`
5. Simpan webhook.
6. Salin **Webhook ID** yang dihasilkan ke `PAYPAL_WEBHOOK_ID`.

Webhook ID bukan event ID (`WH-...`) dari satu delivery. Gunakan ID milik konfigurasi/listener webhook yang ditampilkan pada detail aplikasi. FPDP memerlukannya untuk meminta PayPal memverifikasi signature setiap webhook.

```dotenv
PAYPAL_WEBHOOK_ID=contohWebhookIdDariPayPal
```

Webhook melekat pada REST app tertentu. Transaksi dari app lain tidak akan dikirim ke webhook app ini.

## 5. Konfigurasi lengkap Sandbox

Isi file `.env` lokal atau environment service Dokploy:

```dotenv
PAYPAL_CLIENT_ID=CLIENT_ID_SANDBOX_ANDA
PAYPAL_CLIENT_SECRET=CLIENT_SECRET_SANDBOX_ANDA
PAYPAL_WEBHOOK_ID=WEBHOOK_ID_SANDBOX_ANDA
PAYPAL_ENVIRONMENT=SANDBOX
CV_PAYMENT_GATEWAY=PAYPAL
```

`CV_PAYMENT_GATEWAY=PAYPAL` diperlukan bila pembayaran CV/resume ingin diarahkan melalui PayPal. Tanpa pengaturan ini, default aplikasi untuk CV adalah `DUMMY`.

Untuk Dokploy:

1. Buka service aplikasi.
2. Buka **Environment**.
3. Tambahkan atau ubah kelima variabel di atas.
4. Simpan lalu redeploy aplikasi.

FPDP juga menyediakan API pengaturan gateway yang menyimpan credential terenkripsi di database. Config aktif dari database memiliki prioritas terhadap fallback `.env`:

```text
PATCH /api/v1/me/payment-gateways/PAYPAL
```

Contoh body untuk Sandbox:

```json
{
  "environment": "SANDBOX",
  "config": {
    "client_id": "CLIENT_ID_SANDBOX_ANDA",
    "client_secret": "CLIENT_SECRET_SANDBOX_ANDA",
    "webhook_id": "WEBHOOK_ID_SANDBOX_ANDA"
  }
}
```

Endpoint memerlukan bearer token owner. Secret disimpan terenkripsi dan tidak dikembalikan oleh endpoint daftar gateway.

## 6. Uji Sandbox

1. Pastikan CV berbayar menggunakan mata uang `USD`.
2. Buka halaman publik `/@HANDLE/cv`.
3. Login sebagai visitor lalu pilih pembelian akses.
4. Pada halaman PayPal Sandbox, login memakai akun **Personal/Buyer Sandbox**, bukan akun PayPal asli.
5. Selesaikan pembayaran.
6. Periksa webhook delivery pada PayPal Developer Dashboard.
7. Pastikan endpoint FPDP membalas HTTP `2xx` dan event `PAYMENT.CAPTURE.COMPLETED` diterima sebelum akses dianggap lunas.

PayPal pada implementasi FPDP tidak mendukung penerimaan `IDR`. Gunakan salah satu mata uang yang didukung adapter, misalnya `USD`, `SGD`, `EUR`, atau `AUD`. Jangan hanya mengganti label IDR menjadi USD tanpa melakukan konversi harga yang benar.

## 7. Beralih ke Live

1. Pastikan akun PayPal Business siap menerima pembayaran dan semua persyaratan akun telah diselesaikan.
2. Pada Developer Dashboard pilih environment **Live**.
3. Buat atau buka Live REST app.
4. Salin **Live Client ID** dan **Live Client Secret**.
5. Buat webhook Live dengan URL production serta event yang sama.
6. Salin **Live Webhook ID**.
7. Ganti environment FPDP:

   ```dotenv
   PAYPAL_CLIENT_ID=LIVE_CLIENT_ID
   PAYPAL_CLIENT_SECRET=LIVE_CLIENT_SECRET
   PAYPAL_WEBHOOK_ID=LIVE_WEBHOOK_ID
   PAYPAL_ENVIRONMENT=PRODUCTION
   CV_PAYMENT_GATEWAY=PAYPAL
   ```

8. Redeploy aplikasi dan lakukan transaksi Live bernilai kecil.

Jangan memakai Sandbox Client ID dengan Live Secret, atau Live Webhook ID pada environment Sandbox.

## 8. Troubleshooting

### `PAYPAL_CLIENT_ID is not configured`

Variabel belum masuk ke proses PHP. Simpan environment lalu restart/redeploy service.

### `PAYPAL_CLIENT_SECRET is not configured`

Client Secret kosong atau nama variabel salah. Pastikan tidak ada spasi di sekitar tanda `=`.

### `PAYPAL_WEBHOOK_ID is not configured`

Webhook belum dibuat atau Webhook ID belum dimasukkan. Client ID bukan Webhook ID.

### `401 Unauthorized` dari PayPal

Penyebab umum:

- Client ID dan Secret tidak berasal dari app yang sama;
- credential Sandbox dipakai pada environment Live atau sebaliknya;
- secret telah diganti tetapi deployment masih memakai nilai lama;
- config terenkripsi di database masih aktif dan menimpa `.env`.

### Webhook gagal diverifikasi

Pastikan Webhook ID berasal dari app dan environment yang mengirim event. Jangan gunakan ID event delivery. Pastikan reverse proxy meneruskan header `paypal-*` dan body JSON asli tanpa perubahan.

### Webhook tidak diterima

Pastikan URL memakai HTTPS publik, tidak dilindungi basic auth/firewall, dapat menerima `POST`, dan tidak mengarah ke localhost. Periksa delivery log pada Developer Dashboard. PayPal akan mencoba ulang delivery yang tidak mendapat respons `2xx`.

### Pembayaran CV gagal karena mata uang

PayPal tidak mendukung penerimaan IDR pada adapter FPDP. Atur harga CV dalam USD sebelum memakai `CV_PAYMENT_GATEWAY=PAYPAL`.

## 9. Referensi resmi

- [Autentikasi REST API PayPal](https://developer.paypal.com/api/rest/authentication)
- [Webhook PayPal](https://developer.paypal.com/api/rest/webhooks)
- [Integrasi dan verifikasi webhook](https://developer.paypal.com/api/rest/webhooks/rest/)
- [Event webhook untuk checkout](https://developer.paypal.com/payment-methods/webhooks/)

