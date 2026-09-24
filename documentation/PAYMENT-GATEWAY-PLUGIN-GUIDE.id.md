# Panduan Plugin Payment Gateway FPDP — Bahasa Indonesia

## 1. Ringkasan

Selain empat gateway bawaan (Dummy, Paywuz, Midtrans, PayPal), FPDP mendukung **plugin payment gateway** berbasis folder — mekanisme yang sama seperti sistem Template (`/themes/`), tapi untuk metode pembayaran. Pemilik akun memasang plugin dengan **menyalin folder ke `/gateways/` di server**, lalu mengonfigurasi dan mengaktifkannya dari **Dashboard → Settings** (`/dashboard/settings`) — persis alur yang sama seperti gateway bawaan, karena plugin memakai API dan mesin konfigurasi/enkripsi yang sama.

Begitu aktif, gateway plugin otomatis terpakai di **semua** alur checkout yang sudah ada — termasuk pembelian akses **CV/Resume** (`POST /api/v1/profiles/{handle}/cv/access`) — tanpa perubahan apa pun di kode CV. Alur itu hanya membaca `nodes.active_gateway` dan memanggil gateway apa pun yang sedang aktif.

**Peringatan keamanan — baca sebelum memasang plugin apa pun:**

Tidak seperti Template (yang hanya boleh mengubah tampilan HTML), kelas adapter gateway plugin dieksekusi sebagai kode PHP penuh, dan **bisa mengakses kredensial gateway lain yang sudah didekripsi dalam proses yang sama**. Hanya pasang plugin dari sumber yang benar-benar Anda percaya — sama seperti aturan untuk Template, tapi risikonya lebih tinggi karena menyangkut uang dan kredensial pembayaran. Tidak ada langkah upload lewat browser secara sengaja, dengan alasan yang sama seperti Template: siapa pun yang bisa menaruh folder di `/gateways/` sudah punya akses file-level ke server.

## 2. Struktur folder sebuah plugin

```
gateways/
  slug-gateway-anda/
    gateway.json        (wajib)
    Gateway.php          (wajib)
```

Nama folder (`slug-gateway-anda`) adalah slug: huruf kecil, angka, `-`, `_` saja (regex: `^[a-z0-9][a-z0-9_-]{0,63}$`). Kedua file wajib ada — plugin tanpa salah satunya diabaikan sepenuhnya (tidak error, hanya tidak muncul di daftar).

### 2.1 `gateway.json`

```json
{
    "code": "STRIPE_LIKE",
    "name": "Nama Gateway Anda",
    "description": "Deskripsi singkat yang tampil di dashboard.",
    "author": "Nama Anda",
    "version": "1.0.0",
    "class": "NamaVendor\\StripeLike\\Gateway",
    "config_keys": ["api_key", "api_secret"],
    "capabilities": {
        "supports_refund": true,
        "supports_recurring": false,
        "supports_qris": false,
        "supports_va": false,
        "supports_credit_card": true,
        "supports_ewallet": false
    }
}
```

| Field | Wajib | Keterangan |
|---|---|---|
| `code` | ya | Kode unik, huruf besar (`A-Z0-9_`), mis. `STRIPE_LIKE`. Tidak boleh sama dengan kode bawaan (`DUMMY`, `PAYWUZ`, `MIDTRANS`, `PAYPAL`) — akan ditolak jika bentrok. |
| `name` | tidak | Nama tampilan di dashboard. Fallback ke nama folder. |
| `description`, `author`, `version` | tidak | Metadata tampilan saja. |
| `class` | ya | Nama class lengkap (dengan namespace) yang mengimplementasikan `App\Contracts\PaymentGatewayInterface`. **Tidak boleh** diawali `App\\` (namespace inti FPDP, direservasi). |
| `config_keys` | tidak | Daftar field kredensial yang dibutuhkan constructor adapter Anda. Field inilah yang otomatis muncul sebagai form input di Settings — tidak perlu ubah kode dashboard sama sekali. |
| `capabilities` | tidak | Flag boolean yang sama seperti kolom `supports_*` gateway bawaan; sekadar metadata tampilan, tidak memengaruhi perilaku pembayaran. |

### 2.2 `Gateway.php`

File ini **wajib** mendefinisikan class dengan nama persis sesuai field `class` di atas, dan class itu **wajib** mengimplementasikan `App\Contracts\PaymentGatewayInterface`:

```php
interface PaymentGatewayInterface
{
    public function getName(): string;
    public function createPayment(array $paymentData): array;
    public function getPaymentStatus(string $externalTransactionId): array;
    public function cancelPayment(string $externalTransactionId): array;
    public function refundPayment(string $externalTransactionId, float $amount): array;
    public function verifyWebhook(array $headers, string $payload): bool;
    public function handleWebhook(array $headers, string $payload): array;
}
```

Constructor Anda menerima satu array `$configuration` — persis field-field yang Anda deklarasikan di `config_keys`, sudah didekripsi oleh FPDP:

```php
final class Gateway implements PaymentGatewayInterface
{
    public function __construct(private readonly array $configuration = []) {}
    // ...
}
```

Lihat `gateways/manual-transfer/Gateway.php` di repository ini sebagai contoh kerja lengkap (gateway "transfer bank manual": tidak ada API pihak ketiga, konfirmasi pembayaran dilakukan manual oleh pemilik akun lewat webhook bersegel shared-secret).

## 3. Cara kerja tiap method

- **`createPayment()`** dipanggil saat pengunjung membeli sesuatu berharga (mis. akses CV). Kembalikan array dengan minimal `status` (`PENDING`/`PAID`/dst.), `order_id`, `amount`, `currency`; opsional `payment_url` (jika gateway Anda mengarahkan pengunjung ke halaman checkout eksternal) atau field bebas lain (mis. `instructions`) yang akan diteruskan apa adanya ke response API dan front-end.
- **`verifyWebhook()`** dipanggil sebelum `handleWebhook()` setiap kali ada request masuk ke `POST /api/v1/payments/webhook/{CODE}` (endpoint publik, tanpa bearer token — inilah yang mengautentikasi request, bukan token). Kembalikan `false` untuk menolak (FPDP membalas 401) jika signature/secret tidak cocok.
- **`handleWebhook()`** mengembalikan event yang sudah dinormalisasi: `order_id`, `status` (harus salah satu dari `PAID`/`FAILED`/`CANCELLED` agar status payment lokal ikut berubah), `event_id` (untuk deduplikasi).
- Begitu payment lokal berubah status ke `PAID`, FPDP otomatis menyelesaikan fitur terkait lewat metadata yang disimpan saat `createPayment()` dipanggil (lihat `PaymentController::fulfill()`) — untuk CV, ini berarti akses otomatis diberikan ke pengunjung. **Anda tidak perlu menulis kode apa pun untuk mengintegrasikan ke CV/Resume atau fitur checkout lain** — itu semua sudah generik terhadap kode gateway.

## 4. Cara instalasi plugin (yang dibuat orang lain)

1. Dapatkan folder plugin (biasanya dibagikan sebagai `.zip`).
2. Ekstrak, pastikan strukturnya `<slug>/gateway.json` dan `<slug>/Gateway.php` di root folder tersebut.
3. Salin folder itu ke `/gateways/` di server FPDP Anda — lewat FTP, file manager hosting, atau `scp`/`rsync` via SSH.
4. Buka **Dashboard → Settings** (`/dashboard/settings`), muat ulang halaman — gateway baru otomatis muncul di daftar dengan label **PLUGIN**.
5. Klik **Configure**, isi field kredensial (field-nya otomatis sesuai `config_keys` plugin), simpan.
6. Klik **Set Active** untuk menjadikannya gateway checkout default node Anda — berlaku langsung untuk pembelian CV dan fitur checkout lain, tanpa restart server.

Tidak ada langkah upload lewat browser secara sengaja — lihat bagian 1 untuk alasannya.

## 5. Perbedaan dari gateway bawaan

- **Verifikasi kredensial otomatis**: gateway bawaan (Paywuz/Midtrans/PayPal) melakukan panggilan live ke API penyedia saat disimpan, untuk memastikan kredensial valid. Ini **tidak dilakukan** untuk gateway plugin (tidak ada cara generik untuk memverifikasi API pihak ketiga sembarang) — `provider_check` akan bernilai `NOT_VERIFIED_PLUGIN_GATEWAY`. Pastikan kredensial Anda benar sebelum mengaktifkan.
- **Kolom konfigurasi**: gateway bawaan memakai daftar field yang di-hardcode di `PaymentService::ALLOWED_CONFIG_KEYS`; gateway plugin memakai `config_keys` dari `gateway.json`-nya sendiri.
- **Penghapusan**: menghapus folder plugin dari `/gateways/` membuatnya hilang dari daftar saat halaman dimuat ulang berikutnya, tapi baris `payment_gateways` dan kredensial tersimpannya **tidak otomatis terhapus** dari database. Jika gateway itu sedang aktif saat foldernya dihapus, pembelian berikutnya akan gagal dengan error jelas ("gateway tidak lagi terpasang") alih-alih diam-diam beralih ke gateway lain — nonaktifkan/ganti gateway aktif sebelum menghapus foldernya.

## 6. Keamanan & batasan

- Slug folder, kode gateway, dan nama class divalidasi dengan whitelist ketat (regex), dan kode/namespace yang bentrok dengan gateway bawaan atau namespace inti FPDP (`App\`) ditolak.
- Hanya file `Gateway.php` dengan nama persis itu yang pernah di-`require` — tidak ada path yang bisa diarahkan lewat manifest, sehingga tidak ada celah path traversal.
- Kelas adapter plugin punya akses penuh sama seperti kode inti FPDP (variabel yang dioper, fungsi global PHP, koneksi database lewat kelas inti, dll.), termasuk berjalan di proses yang sama dengan kredensial gateway lain yang sudah didekripsi. **Hanya pasang plugin dari sumber yang Anda percaya sepenuhnya.**
- Endpoint webhook (`POST /api/v1/payments/webhook/{CODE}`) bersifat publik untuk semua gateway (bawaan maupun plugin) — keamanannya sepenuhnya bergantung pada `verifyWebhook()` masing-masing adapter. Pastikan implementasi Anda benar-benar menolak request tanpa signature/secret yang valid.
