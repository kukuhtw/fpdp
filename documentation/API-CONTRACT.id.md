# Kontrak API FPDP (Bahasa Indonesia)

## 1. Cakupan dan status

Dokumen ini mendefinisikan kontrak HTTP target untuk MVP FPDP. Ini adalah kontrak desain; repository saat ini belum mengimplementasikan sebagian besar route. Sumber machine-readable tersedia pada [`openapi.yaml`](openapi.yaml).

Base path: `/api/v1`

Autentikasi:

- pembacaan data publik tidak membutuhkan autentikasi;
- operasi owner/admin memakai `Authorization: Bearer <token>`;
- webhook pembayaran memakai signature khusus provider dan tidak memakai bearer token pengguna.

Content type: `application/json`, kecuali body webhook dapat mengikuti format JSON provider.

## 2. Konvensi umum

- ID yang diekspos API berupa UUID string.
- Timestamp memakai RFC 3339 UTC.
- Nilai uang berupa decimal string dan kode currency ISO 4217 untuk mencegah kesalahan floating point.
- Collection memakai cursor pagination: `data`, `meta.next_cursor`, dan `meta.has_more`.
- Resource mutable mengembalikan `ETag` ketika optimistic concurrency tersedia.
- Setiap konten mengidentifikasi `source_type`, `source_provider`, dan `canonical_url`.
- Retry pembuatan pembayaran harus memakai header `Idempotency-Key` yang sama.

Envelope sukses standar:

```json
{"data": {}, "meta": {"request_id": "req_..."}}
```

Envelope error standar:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Permintaan tidak valid.",
    "details": [{"field": "email", "reason": "invalid_format"}],
    "request_id": "req_..."
  }
}
```

## 3. Daftar endpoint

### Sistem dan autentikasi

| Method | Path | Auth | Kegunaan |
|---|---|---|---|
| GET | `/health` | Publik | Status hidup aplikasi dan ringkasan dependency |
| POST | `/auth/register` | Publik | Mendaftarkan owner dan node awal |
| POST | `/auth/login` | Publik | Menukar credential dengan access token |
| POST | `/auth/logout` | Bearer | Mencabut token aktif |
| GET | `/me` | Bearer | Mengambil user dan konteks node aktif |

### Profil dan konten

| Method | Path | Auth | Kegunaan |
|---|---|---|---|
| GET | `/profiles/{handle}` | Publik | Membaca profil publik |
| PATCH | `/me/profile` | Bearer | Memperbarui profil owner |
| GET | `/posts` | Publik | Daftar post dengan filter sumber/visibility |
| POST | `/posts` | Bearer | Membuat post lokal |
| GET | `/posts/{postId}` | Publik | Membaca post yang dapat dilihat |
| PATCH | `/posts/{postId}` | Bearer | Memperbarui post lokal milik user |
| DELETE | `/posts/{postId}` | Bearer | Soft-delete post lokal milik user |

Penulisan post menerima maksimal 10 metadata media terurut (`IMAGE`, `VIDEO`, `AUDIO`, atau `FILE`). URL media wajib berupa URL HTTPS absolut tanpa credential tertanam; alternative text dibatasi 500 karakter. Mengirim `media` melalui `PATCH` mengganti seluruh daftar media secara atomik.
| GET | `/timeline` | Publik/Bearer opsional | Timeline normalisasi local, external, dan federated |

### Sumber eksternal

| Method | Path | Auth | Kegunaan |
|---|---|---|---|
| GET | `/external-sources` | Bearer | Daftar koneksi dan kondisi sinkronisasi |
| POST | `/external-sources/test` | Bearer | Validasi dan preview tanpa menyimpan sumber |
| POST | `/external-sources` | Bearer | Menyimpan dan menjadwalkan sumber |
| GET | `/external-sources/{sourceId}` | Bearer | Membaca konfigurasi dan kondisi sumber |
| PATCH | `/external-sources/{sourceId}` | Bearer | Mengubah interval, visibility, atau status enabled |
| DELETE | `/external-sources/{sourceId}` | Bearer | Memutus sumber |
| POST | `/external-sources/{sourceId}/sync` | Bearer | Memasukkan sinkronisasi manual ke antrean |

### Produk, order, dan pembayaran

| Method | Path | Auth | Kegunaan |
|---|---|---|---|
| GET | `/products` | Publik | Daftar produk tersedia |
| POST | `/products` | Bearer | Membuat produk lokal |
| GET | `/products/{productId}` | Publik | Membaca detail produk |
| PATCH | `/products/{productId}` | Bearer | Memperbarui produk milik user |
| POST | `/orders` | Publik/Bearer opsional | Membuat order dan snapshot total immutable |
| GET | `/orders/{orderId}` | Bearer/order token | Membaca order |
| POST | `/orders/{orderId}/payments` | Bearer/order token | Membuat payment attempt; wajib `Idempotency-Key` |
| GET | `/payments/{paymentId}` | Bearer/order token | Membaca status pembayaran ternormalisasi |
| POST | `/payments/{paymentId}/cancel` | Bearer/order token | Membatalkan pembayaran pending |
| POST | `/payments/{paymentId}/refunds` | Admin Bearer | Meminta refund penuh atau sebagian |
| POST | `/webhooks/payments/{gatewayCode}` | Signature | Menerima dan menormalisasi event provider |

### Administrasi dan federasi

| Method | Path | Auth | Kegunaan |
|---|---|---|---|
| GET | `/admin/payment-gateways` | Admin Bearer | Daftar kapabilitas dan status konfigurasi gateway |
| PUT | `/admin/payment-gateways/{gatewayCode}` | Admin Bearer | Mengatur dan mengaktifkan gateway |
| GET | `/admin/integration-jobs` | Admin Bearer | Memeriksa queue sinkronisasi dan kegagalan |
| POST | `/admin/integration-jobs/{jobId}/retry` | Admin Bearer | Mengulang job gagal |
| GET | `/.well-known/fpdp` | Publik | Discovery identitas dan kapabilitas node |

## 4. Perilaku penting

### Pengujian dan penyimpanan sumber

`POST /external-sources/test` melakukan fetch server-side yang dibatasi, memblokir target network privat/reserved, menerapkan timeout dan batas ukuran, kemudian memberikan preview yang sudah dinormalisasi. Penyimpanan sumber merupakan aksi eksplisit yang terpisah.

### Asal-usul timeline

Setiap item memuat:

- `source_type`: `LOCAL`, `EXTERNAL`, atau `FEDERATED`;
- `source_provider`: misalnya `FPDP`, `RSS`, `ATOM`, `CUSTOM_API`;
- `canonical_url`: URL original yang otoritatif;
- identitas author dan waktu publikasi.

### Idempotency pembayaran dan webhook

- Client menggunakan satu `Idempotency-Key` untuk retry permintaan pembayaran logis yang sama.
- Server mengembalikan hasil pertama ketika key dan payload sama.
- Key yang sama dengan payload berbeda menghasilkan `409 IDEMPOTENCY_CONFLICT`.
- Webhook diverifikasi sebelum diproses dan dideduplikasi berdasarkan provider + event ID.
- Webhook duplikat yang valid menghasilkan `200` tanpa menjalankan transisi dua kali.

### Status code yang disarankan

| Status | Arti |
|---|---|
| 200 | Pembacaan/update berhasil atau webhook duplikat diterima |
| 201 | Resource berhasil dibuat |
| 202 | Sinkronisasi/refund async diterima |
| 204 | Logout atau penghapusan selesai |
| 400 | Request malformed |
| 401 | Autentikasi atau signature webhook tidak ada/tidak valid |
| 403 | Terautentikasi tetapi tidak memiliki izin |
| 404 | Resource tidak ada atau tidak dapat dilihat |
| 409 | Konflik state atau idempotency |
| 422 | Validasi semantik gagal |
| 429 | Rate limit terlampaui |
| 502 | Provider upstream gagal |
