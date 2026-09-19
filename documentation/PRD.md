# Product Requirements Document (PRD)

## 1. Ringkasan Produk
FPDP adalah platform personal digital home yang memungkinkan setiap pengguna memiliki domain pribadi, profil, feed sosial, marketplace, dan payment endpoint. Produk ini dirancang untuk bekerja di node-node independen yang saling berhubungan melalui federasi.

## 2. Problem Statement
Saat ini, banyak individu mengandalkan platform besar untuk identitas digital, konten, dan transaksi. Kondisi ini membuat data, audience, dan komunikasi bergantung pada satu entitas. FPDP mengatasi masalah ini dengan membuat domain pengguna sebagai pusat digital identity, sambil tetap terhubung ke network lain melalui federasi dan integrasi eksternal.

## 3. Goal
- Mengembangkan platform yang dapat beroperasi sebagai independent node.
- Menyediakan personal website + social feed + portfolio + marketplace dalam satu pengalaman.
- Mengintegrasikan konten dari berbagai platform tanpa kehilangan atribusi.
- Menyediakan abstraction payment agar node bebas memilih gateway.

## 4. Target Pengguna
1. Individual personal brand
2. Creator / content producer
3. Freelancer / professional
4. Seller / merchant
5. Admin node / operator

## 5. User Stories
### 5.1 Personal Website
- Sebagai pengguna, saya ingin mendaftar dan langsung mendapatkan node, akun owner, dan profil saya dalam satu langkah.
- Sebagai pengguna, saya ingin memiliki halaman profil saya sendiri di domain saya.
- Sebagai pengguna, saya ingin menulis blog atau post di node saya.

### 5.2 Personalisasi
- Sebagai owner, saya ingin memilih tema dan layout untuk tampilan publik saya.
- Sebagai owner, saya ingin mengganti styling dengan custom CSS saya sendiri, tanpa membahayakan keamanan pengunjung.
- Sebagai owner, saya ingin mengatur bahasa default node saya dan memilih bahasa apa saja yang tersedia untuk pengunjung.

### 5.3 External Feed Integration
- Sebagai pengguna, saya ingin menghubungkan Instagram, LinkedIn, RSS, dan X ke timeline saya.
- Sebagai pengguna, saya ingin melihat sumber asli dari setiap konten.

### 5.4 Payment
- Sebagai admin, saya ingin memilih gateway pembayaran yang aktif di node saya.
- Sebagai pengguna, saya ingin checkout menggunakan gateway yang telah disetujui node saya.

### 5.5 Federation
- Sebagai node, saya ingin mengenali kemampuan node lain.
- Sebagai pengguna, saya ingin melihat konten federasi dengan jelas sumbernya.
- Sebagai visitor, saya ingin menemukan koneksi federasi suatu profil dan melihat post publik terbaru mereka.
- Sebagai owner, saya ingin memilih koneksi federasi mana yang ditampilkan pada profil publik saya.

## 6. Functional Requirements
### 6.1 Identity dan Autentikasi
- Registrasi owner langsung menyediakan node, user owner, dan profil publik dalam satu langkah; password di-hash, tidak pernah disimpan atau di-log dalam bentuk plain text.
- Bearer token mengautentikasi REST API. Token di-hash saat disimpan, punya masa berlaku, dan dapat dicabut eksplisit saat logout.
- Registrasi dan login dibatasi rate-nya per IP client untuk menahan credential stuffing dan pembuatan akun spam.
- Session-based auth untuk owner dashboard berbasis browser masih direncanakan, belum diimplementasikan; API saat ini hanya bearer-token.
- OAuth 2.0 masih direncanakan untuk menghubungkan content provider eksternal (Instagram, LinkedIn, dll), bukan untuk login owner.

### 6.2 Profiles
- Setiap user punya tepat satu profil: handle, display name, bio, avatar, links, dan canonical URL di domain node-nya.
- Visibility profil adalah `PUBLIC`, `UNLISTED`, atau `PRIVATE`; profil private tidak disajikan lewat endpoint baca publik.
- Owner meng-update profilnya sendiri; field tidak dikenal dan nilai tidak valid ditolak dengan error validasi per field.

### 6.3 Personalization
- Setiap owner node dapat mengatur tema, pilihan layout, dan override custom CSS untuk tampilan publiknya.
- Custom CSS disanitasi di sisi server sebelum pernah dirender balik ke pengunjung (tidak boleh `@import`, injeksi script, atau panjang tak terbatas).
- Setiap node punya locale default dan daftar bahasa yang aktif; language switcher di UI mengikuti daftar ini.
- Tema dan locale bawaan yang aman selalu jadi fallback saat setting-nya kosong atau tidak valid.

### 6.4 Content
- Local post, federated post, dan external post harus dibedakan.
- Setiap item harus memiliki source_type, source_provider, dan canonical_url.
- User bisa memilih visibility dan tampilkan di profile atau timeline.

### 6.5 Payment
- Payment adapter sesuai interface
- Factory memilih provider dinamai
- Webhook generic dengan normalized event
- Idempotency untuk webhook duplicate

### 6.6 External Connector
- Connector harus menggunakan abstraction layer
- RSS/Atom/custom API MVP didukung
- OAuth connector dapat ditambahkan pada fase berikutnya

## 7. Non-Functional Requirements
- API versioning dengan prefix /api/v1/
- Secure secret storage
- Async queue and cron for sync tasks
- Webhook verification and replay prevention
- Monitoring logs for payment and integration only without leaking credentials

## 8. Acceptance Criteria
- User dapat registrasi akun, autentikasi dengan bearer token, dan membaca context-nya sendiri lewat `/me`.
- User dapat membuat dan meng-update profile di node sendiri, dengan aturan visibility ditegakkan pada pembacaan publik.
- Registrasi dan login menolak percobaan berlebihan dari client yang sama dengan error rate-limit.
- User dapat mengustomisasi tema, layout, custom CSS, dan bahasa default/aktif node-nya (direncanakan; belum diimplementasikan).
- User dapat menambahkan RSS atau feed custom.
- Admin dapat mengaktifkan gateway default.
- Payment flow hanya bergantung pada interface, bukan provider tertentu.
- Timeline dapat menampilkan sumber dengan identitas jelas.

## 9. Out of Scope
- Full plugin marketplace pada MVP
- E-commerce advanced multi-vendor settlement
- Full OAuth social connectors di fase awal

## 10. Prioritas Produk
### Phase 1
- Personal website
- Identity, registrasi, autentikasi, profil, local post CRUD, timeline lokal, editor post, dan UI profil publik sudah tersedia; personalisasi node masih direncanakan.

### Phase 2
- Social feed and timeline
- External aggregation

### Phase 3
- Federation and remote actors

### Phase 4
- Marketplace and payment abstraction

### Phase 5
- Advanced connectors and plugin ecosystem

## 11. Addendum: Interaksi AI dan Monetisasi (2026-09-19)

Addendum ini menambahkan pengalaman visitor berbasis AI yang dapat dimonetisasi di atas personal digital home inti. Lihat [`AI-MONETIZATION-STRATEGY.id.md`](AI-MONETIZATION-STRATEGY.id.md) untuk data model detail dan rencana implementasi bertahap.

### 11.1 Target Pengguna Tambahan

6. Visitor yang ingin membaca CV owner atau chat dengan asisten profilnya
7. Advertiser yang ingin menyewa ruang iklan di sebuah node

### 11.2 User Stories Tambahan

**Konfigurasi AI**

- Sebagai owner, saya ingin menghubungkan akun OpenAI atau Anthropic saya sendiri agar asisten profil saya memakai model pilihan saya.
- Sebagai owner, saya ingin credential LLM saya disimpan secara aman dan tidak pernah ditampilkan penuh kembali ke saya atau siapa pun.

**Akses CV berbayar**

- Sebagai owner, saya ingin mengunggah CV saya dan mengenakan fee pilihan saya ke visitor untuk melihatnya, termasuk gratis.
- Sebagai visitor, saya ingin membayar sekali dan bisa melihat CV tanpa membayar lagi di kunjungan berikutnya.

**Chatbot berbayar**

- Sebagai owner, saya ingin visitor bisa bertanya ke asisten profil saya soal latar belakang dan layanan saya.
- Sebagai owner, saya ingin mengenakan biaya untuk akses chatbot supaya fitur ini tidak jadi endpoint API gratis tak terbatas untuk siapa saja.
- Sebagai visitor, saya ingin tahu dengan jelas bahwa saya sedang chat dengan asisten otomatis, bukan langsung dengan owner.

**Identity visitor**

- Sebagai visitor, saya ingin login dengan akun Google saya alih-alih membuat password baru hanya untuk membaca CV atau memulai chat.
- Sebagai owner, saya ingin setiap interaksi berbayar terikat ke visitor yang nyata dan teridentifikasi supaya saya bisa menyelesaikan sengketa.

**Analytics**

- Sebagai owner, saya ingin melihat berapa banyak unique visitor dan traffic yang didapat node saya setiap hari.

**Ad marketplace**

- Sebagai owner, saya ingin mendefinisikan ad slot di profil saya dan mengatur harga harian, mingguan, atau bulanan saya sendiri.
- Sebagai advertiser, saya ingin booking dan membayar ad slot untuk periode tertentu dan tahu persis kapan mulai dan berakhirnya.

### 11.3 Functional Requirements Tambahan

**LLM abstraction**

- Definisikan `LLMProviderInterface` yang analog dengan interface payment dan external-content yang sudah ada.
- Dukung minimal adapter OpenAI dan Anthropic saat launch; tolak kode provider yang belum dikonfigurasi atau tidak didukung secara eksplisit.
- Simpan provider, model, dan credential terenkripsi per node.

**Konten dan interaksi berbayar**

- Dokumen CV dan sesi chatbot digerbang oleh payment yang dibuat lewat `PaymentGatewayInterface` yang sudah ada.
- Access grant (untuk CV) dan catatan sesi (untuk chat) mencegah visitor yang sudah bayar dikenakan biaya lagi dalam masa akses yang ditentukan owner.

**Identity visitor**

- Google OAuth 2.0 adalah satu-satunya metode sign-in visitor yang didukung untuk addendum ini.
- Identity visitor berbeda dari akun owner/user dan tidak diberi permission manajemen node apa pun.

**Analytics**

- Catat page view dengan informasi yang cukup untuk menghitung unique visitor harian dan traffic harian, tanpa menyimpan identifier mentah selamanya.

**Ad marketplace**

- Ad slot punya harga harian, mingguan, dan bulanan yang independen, diatur oleh owner.
- Booking punya waktu mulai dan berakhir yang eksplisit serta status approval sebelum creative ditampilkan ke publik.

### 11.4 Acceptance Criteria Tambahan

- Owner dapat memilih dan mengonfigurasi LLM provider dan melihatnya berlaku pada chatbot-nya tanpa perubahan kode.
- Visitor tidak dapat melihat CV berbayar atau mengirim pesan chat tanpa login Google dan menyelesaikan payment terlebih dahulu saat diperlukan.
- Visitor yang sudah pernah bayar tidak dikenakan biaya lagi untuk CV yang sama dalam masa akses yang ditentukan owner.
- Dashboard harian owner menampilkan minimal unique visitor dan page view untuk hari sebelumnya.
- Advertiser dapat booking ad slot yang tersedia untuk periode pilihannya dan melihatnya tayang hanya setelah owner menyetujui creative-nya.

### 11.5 Out of Scope Tambahan

- LLM provider di luar adapter awal OpenAI/Anthropic (pola yang sama extensible, ditambahkan belakangan).
- Real-time ad bidding, programmatic exchange, atau moderasi creative otomatis.
- Metode sign-in visitor selain Google OAuth.
- Billing multi-currency untuk iklan dan chat di luar yang sudah didukung payment abstraction.

## 12. Addendum Produk: Koneksi Federasi pada Profil Publik

### 12.1 Experience Requirements

- Tambahkan bagian **Jaringan federasi** pada profil publik setelah konten terbaru owner.
- Kartu koneksi berisi avatar/fallback, display name, federated address, domain, relationship, waktu sinkronisasi, dan satu post publik terbaru.
- Label `FEDERATED` dan domain asal selalu terlihat; seluruh link keluar memakai canonical URL remote.
- Empty, loading, stale-cache, remote-unavailable, hidden, dan blocked state memiliki perilaku yang jelas.
- Default maksimum enam koneksi; gunakan cursor pagination untuk data berikutnya.

### 12.2 API Requirements

- `GET /api/v1/profiles/{handle}/federated-connections` mengembalikan daftar publik yang sudah difilter moderasi.
- `GET /api/v1/me/federated-connections` mengembalikan daftar owner, termasuk visibility dan health.
- `PATCH /api/v1/me/federated-connections/{connectionId}` mengubah `show_on_profile`, mute, atau block.
- Response publik tidak membocorkan inbox URL, key material, delivery error internal, atau metadata moderasi privat.

### 12.3 Acceptance Criteria

- Hanya koneksi aktif, tidak diblokir, dan `show_on_profile=true` yang tampil.
- Maksimal satu preview post publik terbaru per koneksi pada initial render.
- Cache stale diberi waktu sinkronisasi; timeout remote tidak memblokir render profil.
- Test mencakup ownership, visibility, blocked-node filtering, deduplication, pagination, dan siklus A→B→D→E→A.
