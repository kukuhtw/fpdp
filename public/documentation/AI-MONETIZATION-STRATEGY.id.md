# Strategi Interaksi AI dan Monetisasi FPDP — Bahasa Indonesia

## 1. Tujuan dan Status Implementasi

Dokumen ini mendefinisikan **desain target** untuk enam penambahan yang diminta sebagai addendum BRD/PRD: LLM provider yang dapat dikonfigurasi owner, akses CV/resume berbayar, chatbot profil berbayar, Google OAuth wajib untuk interaksi visitor apa pun, analitik traffic harian, dan marketplace ad banner.

Belum ada satu pun dari ini yang diimplementasikan di repository saat ini. Phase 1 (identity, autentikasi, dan profil owner) sudah selesai dan menjadi fondasi addendum ini: pembuatan node/user/profile, auth bearer-token, dan abstraction `PaymentGatewayInterface` sudah ada dan dipakai ulang, bukan dibangun dari nol lagi.

## 2. Ringkasan Fitur

| # | Fitur | Siapa yang bayar | Apa yang menggerbanginya |
|---|---|---|---|
| 1 | Konfigurasi LLM provider | — | Setting khusus owner, tidak ada gerbang untuk visitor |
| 2 | Akses CV/resume berbayar | Visitor | Login Google + payment |
| 3 | Chatbot berbayar | Visitor | Login Google + payment |
| 4 | Identity visitor | — | Wajib sebelum item 2, 3, atau 6 |
| 5 | Analitik traffic | — | Baca khusus owner, tidak ada gerbang visitor |
| 6 | Marketplace ad banner | Advertiser | Login Google + payment |

Melihat profil secara pasif (membaca profil publik, post-nya, timeline-nya) tetap terbuka untuk visitor anonim. Login Google hanya diwajibkan pada titik saat visitor mencoba melakukan sesuatu yang membebani biaya owner untuk menyediakannya, atau yang memang dikenakan biaya oleh owner.

## 3. Identity Visitor: Google OAuth Wajib

### 3.1 Kenapa Identity Terpisah dari Owner

Owner (`users`) autentikasi untuk **mengelola** node. Visitor autentikasi untuk **mengonsumsi atau membayar** sesuatu di node yang bukan miliknya. Ini adalah level trust dan data yang berbeda: identity visitor tidak punya role, tidak punya permission manajemen node, dan tidak punya password (Google adalah satu-satunya credential).

Karena node dioperasikan secara independen (Prinsip Produk 2), record visitor di sisi FPDP bersifat **node-scoped**, bukan global: akun Google yang sama yang login di dua node berbeda mendapat dua baris `visitor_accounts` independen, satu per node. Hanya identity Google visitor (`sub`) yang dibagikan lintas node; FPDP tidak menyinkronkan state visitor antar node yang di-hosting secara independen.

### 3.2 Alur

```text
Visitor klik "Sign in with Google" pada aksi yang di-gate
  → redirect ke consent screen OAuth 2.0 Google (scope: openid, email, profile)
  → Google redirect balik dengan authorization code
  → node menukar code tersebut dengan ID token, verifikasi signature dan audience-nya
  → node mencari atau membuat baris visitor_accounts dengan key (node_id, google_sub)
  → node menerbitkan visitor bearer token (bentuknya sama seperti auth_tokens owner: di-hash saat
    disimpan, punya masa berlaku, dapat dicabut)
  → aksi yang di-gate (lihat CV / mulai chat / booking iklan) dilanjutkan memakai token tersebut
```

### 3.3 Aturan

- Node tidak pernah meminta password ke visitor; Google adalah satu-satunya credential visitor.
- Token visitor tidak punya akses ke endpoint `/me`, owner, atau admin mana pun; hanya di-scope ke endpoint yang menghadap visitor.
- Mencabut/kedaluwarsanya token visitor tidak menghapus riwayat payment atau access grant yang sudah tercatat di baris `visitor_accounts` tersebut.

## 4. Abstraksi LLM Provider

### 4.1 Interface

Meniru pola `PaymentGatewayInterface` / `ExternalContentProviderInterface` yang sudah ada di `app/Contracts/`:

```php
interface LLMProviderInterface
{
    public function getName(): string;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{content: string, tokens_used: int}
     */
    public function complete(array $messages, array $options = []): array;
}
```

`LLMProviderFactory::create(string $providerCode, array $config)` mendukung `OPENAI` dan `ANTHROPIC` saat launch dan melempar `UnsupportedProviderException` yang sama seperti yang dipakai factory payment dan connector untuk kode lainnya — tidak ada fallback diam-diam ke provider default, dengan alasan yang sama kenapa payment gateway yang salah tidak boleh pernah diganti diam-diam.

### 4.2 Konfigurasi

Satu baris `llm_configs` aktif per node: kode provider, nama model, dan API key terenkripsi (pendekatan enkripsi yang sama seperti `payment_gateway_configs.encrypted_value`). Key tidak pernah dikembalikan penuh di response API mana pun; `GET` config hanya menampilkan provider, model, dan indikator key yang di-mask (mis. `sk-...ab12`).

### 4.3 Kontrol Cost dan Penyalahgunaan

- Setiap panggilan ke provider melewati `RateLimiter` yang sudah ada (Phase 1), dengan key `(node_id, 'llm_call')`, dengan plafon per-node yang tidak bisa dilampaui owner apa pun besar permintaan visitor.
- Panjang token/response dibatasi per request.
- Node tanpa provider yang dikonfigurasi, atau dengan key yang tidak valid/dicabut, membuat fitur chatbot fail closed (visitor melihat "chat tidak tersedia"), tidak pernah fallback ke key default bersama.

## 5. Akses CV/Resume Berbayar

### 5.1 Alur

```text
Owner mengunggah CV → baris cv_documents (price_amount, price_currency; 0 = gratis)
Visitor meminta CV
  → jika price_amount = 0: sajikan langsung, tanpa langkah payment
  → jika price_amount > 0:
      → wajibkan visitor bearer token (Bagian 3); jika tidak ada, kembalikan 401 dan minta login Google
      → cek cv_access_grants untuk (cv_document_id, visitor_id); jika ditemukan, sajikan langsung
      → jika tidak, buat payment lewat PaymentGatewayInterface yang sudah ada untuk price_amount/price_currency
      → setelah payment terkonfirmasi, insert baris cv_access_grants, lalu sajikan dokumennya
```

### 5.2 Aturan

- Grant bersifat permanen secara default (bayar sekali, bisa dilihat kapan saja); owner boleh mengatur grant agar kedaluwarsa (mis. 30 hari) — default-nya tanpa kedaluwarsa supaya mental model tetap sederhana untuk MVP.
- Dokumen itu sendiri disimpan di luar webroot publik; hanya pernah di-stream lewat endpoint yang di-gate, tidak pernah di-link langsung.

## 6. Interaksi Chatbot Berbayar

### 6.1 Grounding

Chatbot hanya menjawab dari konten yang secara eksplisit dipublikasikan owner: bio profil, teks CV (diekstrak saat upload), dan post lokal yang ditandai publik. Chatbot tidak boleh diberi akses ke data privat, catatan payment, atau percakapan visitor lain. System prompt yang dikirim ke `LLMProviderInterface` yang dikonfigurasi menyatakan batasan ini dan mewajibkan model untuk bilang tidak tahu daripada menebak.

### 6.2 Alur

```text
Visitor membuka widget chat di sebuah profil
  → wajibkan visitor bearer token (Bagian 3); jika tidak ada, minta login Google
  → jika owner mengenakan biaya per sesi: wajibkan baris chat_sessions yang aktif dan sudah dibayar
      → tidak ada sesi aktif: buat payment lewat PaymentGatewayInterface; setelah terkonfirmasi, buka baris chat_sessions
  → setiap pesan visitor disimpan di chat_messages, dikirim ke LLMProviderInterface::complete()
    bersama grounding context dan turn terakhir, lalu balasannya disimpan dan dikembalikan
```

### 6.3 Aturan

- Setiap balasan assistant diberi label sebagai otomatis di response payload (`role: "assistant", automated: true`) supaya frontend tidak pernah menampilkannya seolah owner mengetik live.
- Sesi punya batas jumlah pesan dan/atau batas waktu yang diatur owner bersamaan dengan harganya; mencapai batas mengakhiri sesi dan, jika owner mengenakan biaya per sesi, mewajibkan payment baru untuk melanjutkan.

## 7. Analitik Traffic dan Visitor

### 7.1 Apa yang Diukur

- **Page view:** request apa pun ke halaman publik (profil, post, timeline). Dicatat dengan `node_id`, `path`, `viewed_at`, dan `visitor_fingerprint` — baik `visitor_id` terautentikasi jika sudah login, atau hash bergaram dari `(ip_address, user_agent)` untuk traffic anonim. Alamat IP mentah tidak pernah disimpan permanen.
- **Rollup harian:** `analytics_daily` (satu baris per `node_id` + `date`) menyimpan `unique_visitors` (fingerprint unik hari itu) dan `page_views` (total request hari itu), dihitung oleh scheduled job, bukan di setiap request, supaya rendering halaman tetap cepat.

### 7.2 Retensi

Baris `page_views` mentah hanya disimpan cukup lama untuk menghitung rollup secara andal (rolling window, mis. 35 hari) lalu dihapus; baris `analytics_daily` disimpan selamanya karena tidak membawa data identitas visitor.

## 8. Marketplace Ad Banner

### 8.1 Alur

```text
Owner mendefinisikan baris ad_slots: posisi/nama, dimensi, dan harga
harian / mingguan / bulanan yang independen (salah satu dari ketiganya boleh dikosongkan
untuk menonaktifkan tipe periode itu)

Advertiser (terautentikasi lewat login Google visitor yang sama, Bagian 3) melihat slot yang tersedia
  → memilih slot dan tipe periode (DAILY | WEEKLY | MONTHLY) serta tanggal mulai
  → membayar lewat PaymentGatewayInterface sesuai harga periode tersebut
  → baris ad_bookings dibuat dengan approval_status = PENDING dan starts_at/ends_at yang dihitung

Owner mereview creative-nya
  → APPROVED: booking menjadi ACTIVE pada starts_at dan tayang otomatis
  → REJECTED: payment di-refund lewat PaymentGatewayInterface yang sama, booking dibatalkan

Scheduled job mengubah booking ACTIVE menjadi EXPIRED setelah ends_at terlewati; creative
yang expired berhenti tayang tanpa aksi manual owner.
```

### 8.2 Aturan

- Satu slot maksimal punya satu booking `ACTIVE` dalam satu waktu; booking yang tumpang tindih untuk slot/periode yang sama ditolak saat booking dibuat, sebelum payment diambil.
- Creative yang ditolak atau expired tidak pernah ditampilkan, meski masih tersimpan untuk catatan advertiser.

## 9. Data Entities

```text
llm_configs
    id, node_id (FK, satu baris aktif per node), provider_code, model,
    encrypted_api_key, status, created_at, updated_at

visitor_accounts
    id, public_id (UUID), node_id (FK), google_sub, email, display_name,
    avatar_url, created_at, last_seen_at
    UNIQUE (node_id, google_sub)

visitor_tokens
    id, visitor_id (FK), token_hash (UK), expires_at, revoked_at, created_at

cv_documents
    id, public_id (UUID), node_id (FK), title, storage_key,
    price_amount, price_currency, status, created_at, updated_at

cv_access_grants
    id, cv_document_id (FK), visitor_id (FK), payment_id (FK, nullable jika gratis),
    granted_at
    UNIQUE (cv_document_id, visitor_id)

chat_sessions
    id, public_id (UUID), node_id (FK), visitor_id (FK), payment_id (FK, nullable jika gratis),
    started_at, ended_at, message_count, status

chat_messages
    id, session_id (FK), role (VISITOR | ASSISTANT), content, created_at

page_views
    id, node_id (FK), path, visitor_fingerprint, viewed_at

analytics_daily
    id, node_id (FK), date, unique_visitors, page_views
    UNIQUE (node_id, date)

ad_slots
    id, public_id (UUID), node_id (FK), name, width, height,
    price_daily, price_weekly, price_monthly, currency, status

ad_bookings
    id, public_id (UUID), ad_slot_id (FK), advertiser_visitor_id (FK),
    creative_url, target_url, period_type (DAILY | WEEKLY | MONTHLY),
    starts_at, ends_at, payment_id (FK), approval_status (PENDING | APPROVED | REJECTED),
    status (SCHEDULED | ACTIVE | EXPIRED | CANCELLED)
```

Foreign key ke `nodes`/`users` mengikuti konvensi `ON DELETE CASCADE` / `ON DELETE SET NULL` yang sama seperti yang sudah ditetapkan di `documentation/ERD.en.md` Bagian 9.

## 10. Integrasi Payment

Tidak ada jalur kode payment baru yang diperkenalkan. Akses CV, sesi chatbot, dan booking iklan semuanya membuat payment dengan cara yang sama: `PaymentService::createPayment($gatewayCode, [...])` terhadap gateway mana pun yang dikonfigurasi node (Phase 5 roadmap utama). Payment yang ditolak atau gagal cukup mencegah baris grant/session/booking dibuat — tidak ada konsep "payment monetisasi" terpisah yang perlu dirawat.

## 11. Kebutuhan Keamanan dan Privasi

- Credential LLM dan payment dienkripsi saat disimpan dan dikecualikan dari log, export, dan response API, sesuai security consideration yang sudah ada di `README.md` level atas.
- ID token Google diverifikasi (signature, issuer, audience, expiry) di sisi server sebelum baris `visitor_accounts` dipercaya; node tidak pernah menerima klaim "saya adalah Google user ini" dari client tanpa verifikasi.
- PII visitor (email, display name, avatar URL) dibatasi hanya pada yang dikembalikan scope `openid`/`email`/`profile` Google — tidak ada data visitor tambahan yang diminta.
- Fingerprint analytics diberi garam dan tidak bisa dibalik ke alamat IP tertentu setelah di-hash.
- Semua endpoint yang dimonetisasi dibatasi rate per identity visitor selain plafon cost LLM per-node, untuk mencegah satu visitor menghabiskan budget node sendirian.

## 12. Urutan Implementasi yang Disarankan

1. **Selesai.** **Identity visitor (Google OAuth).** Harus ada sebelum item lain di addendum ini, karena setiap fitur berbayar atau interaktif bergantung padanya. Deliverable: migration `visitor_accounts`, `visitor_tokens`; endpoint redirect/callback OAuth; penerbitan dan verifikasi visitor bearer token meniru pola `AuthService` owner.
2. **Abstraksi LLM provider.** Deliverable: `LLMProviderInterface`, `LLMProviderFactory` (OpenAI, Anthropic), migration `llm_configs`, endpoint konfigurasi untuk owner. Belum ada permukaan yang menghadap visitor.
3. **Selesai.** **Akses CV/resume berbayar.** Deliverable: migration `cv_documents`, `cv_access_grants`; endpoint upload; endpoint baca yang di-gate memakai ulang `PaymentGatewayInterface`. Paywall paling sederhana — bukti end-to-end yang baik untuk identity visitor + payment sebelum chatbot yang lebih kompleks. Catatan implementasi: karena Fase 5 belum membangun persistence payment atau konfirmasi webhook, panggilan `PaymentGatewayInterface::createPayment()` yang berhasil dianggap terkonfirmasi untuk gateway DUMMY (satu-satunya yang diimplementasikan); gateway sungguhan harus beralih ke konfirmasi berbasis webhook. Upload CV mengganti satu-satunya dokumen aktif node tersebut dan mencabut semua grant lama terhadapnya, karena grant adalah pembelian atas konten spesifik itu, bukan langganan tetap.
4. **Chatbot berbayar.** Deliverable: migration `chat_sessions`, `chat_messages`; endpoint chat yang menggabungkan LLM abstraction (langkah 2), identity visitor (langkah 1), dan payment (pola langkah 3); pembangun grounding-context; rate limiting.
5. **Analitik traffic.** Deliverable: migration `page_views`, `analytics_daily`; middleware/hook pencatatan view; job rollup harian; endpoint baca untuk owner. Independen dari langkah 2–4; bisa berjalan paralel begitu langkah 1 ada untuk fingerprint visitor terautentikasi (fingerprint anonim bahkan tidak butuh langkah 1).
6. **Marketplace ad banner.** Deliverable: migration `ad_slots`, `ad_bookings`; endpoint manajemen slot (owner); endpoint booking + payment (advertiser, memakai ulang langkah 1 dan pola payment dari langkah 3); alur approval; job expiry.

Langkah 3–6 masing-masing hanya bergantung pada langkah 1 (dan, untuk chatbot, langkah 2) — tidak saling bergantung satu sama lain dan bisa dibangun paralel oleh kontributor berbeda begitu fondasi identity sudah ada.

## 13. Risiko dan Pertanyaan Terbuka

- **Cost LLM membengkak:** dimitigasi oleh rate limit per-node dan cap panjang response di Bagian 4.3, tapi nilai plafon pastinya butuh keputusan produk sebelum launch.
- **Akurasi/liabilitas chatbot:** pembatasan grounding (Bagian 6.1) mengurangi tapi tidak menghilangkan risiko jawaban yang salah atau memalukan; aksi visitor "laporkan jawaban ini" layak ditambahkan begitu fitur ini rilis.
- **Sengketa payment untuk barang intangible:** sesi chat atau view CV tidak bisa "dikembalikan"; kebijakan refund untuk dua fitur ini butuh setting eksplisit yang menghadap owner (mis. "tidak ada refund setelah pesan pertama terkirim").
- **Moderasi konten iklan:** langkah approval manual (Bagian 8.1) adalah satu-satunya pengaman di MVP; tidak scalable untuk volume iklan tinggi dan akan butuh pre-screening otomatis nantinya.
- **Pengalaman visitor lintas node:** karena identity visitor bersifat node-scoped (Bagian 3.1), visitor yang berinteraksi dengan banyak node akan re-autentikasi dan bayar ulang secara independen di masing-masing node; apakah layanan identity visitor bersama di masa depan layak dibangun adalah pertanyaan produk terbuka, tidak dibahas addendum ini.

## 14. Skenario Penerimaan

```text
Owner mengonfigurasi Anthropic sebagai LLM provider-nya dan mengunggah CV seharga Rp75.000.
Visitor baru membuka profil: profil, bio, dan post terlihat tanpa perlu login.
Visitor klik "Lihat CV": diminta login Google, lalu membayar Rp75.000.
Setelah payment, CV disajikan; kunjungan kedua tidak meminta payment lagi.
Visitor yang sama membuka widget chat, sudah login, membayar fee sesi chatbot,
dan bertanya; balasannya grounded pada profil/CV owner dan ditandai sebagai otomatis.
Dashboard owner menampilkan jumlah unique visitor hari itu termasuk visitor ini.
Terpisah, seorang advertiser login dengan Google, booking ad slot sidebar owner untuk
satu minggu, membayar, dan — setelah owner menyetujui creative-nya — melihatnya tayang
pada waktu mulai booking dan hilang otomatis setelah tujuh hari.
```
