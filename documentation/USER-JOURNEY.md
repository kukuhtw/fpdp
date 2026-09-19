# User Journey — Federated Personal Digital Platform (FPDP)

## 1. Ringkasan Produk

FPDP adalah **personal digital home** yang berjalan sebagai node independen pada domain milik pengguna. Satu node dirancang untuk menyatukan identitas, profil, konten lokal, feed eksternal, relasi federasi, produk, dan pembayaran tanpa mengunci pengguna pada satu platform atau provider.

Nilai utama produk:

- pengguna memiliki pusat identitas digital di domainnya sendiri;
- konten lokal, eksternal, dan federasi dapat tampil dalam satu timeline dengan sumber yang jelas;
- koneksi eksternal dapat dipasang dan dilepas secara mandiri;
- operator node dapat memilih provider pembayaran dan konektor tanpa mengubah business logic;
- pengunjung dapat mengenal, mengikuti konten, dan bertransaksi dengan pemilik node.

## 2. Kondisi Produk Saat Ini

| Area | Kondisi saat ini | Target produk |
|---|---|---|
| Landing page | Sudah ada halaman pengenalan statis | Menjadi pintu masuk profil dan konten publik |
| Profil dan autentikasi | Belum diimplementasikan | Registrasi, login, profil, role, dan API token |
| Konten lokal dan timeline | Belum diimplementasikan | Post CRUD dan timeline gabungan |
| Feed eksternal | Adapter RSS, Atom, dan Custom API sudah ada | UI koneksi, penyimpanan, sinkronisasi async, retry, dan atribusi |
| Federasi | Belum diimplementasikan | Discovery, remote actor, activity, dan remote object |
| Marketplace | Belum diimplementasikan | Katalog, detail produk, checkout, dan order |
| Pembayaran | Interface, factory, service, dan dummy adapter sudah ada | Konfigurasi admin, gateway riil, webhook, idempotency, refund |
| Admin dashboard | Belum diimplementasikan | Pengaturan node, konektor, payment, queue, dan keamanan |

Dokumen ini karena itu menggambarkan **target journey**, dengan penanda status agar dapat digunakan sebagai dasar desain dan roadmap.

## 3. Persona Utama

### A. Pemilik Node / Creator

Individu, creator, freelancer, atau professional yang ingin menjadikan domain pribadi sebagai pusat identitas dan karya.

- Tujuan: membangun profil, menerbitkan konten, menggabungkan feed, dan memiliki audience sendiri.
- Kebutuhan: setup sederhana, kontrol privasi, atribusi yang benar, dan transparansi status sinkronisasi.
- Kekhawatiran: konfigurasi teknis terlalu rumit, konten gagal tersinkron, atau kredensial bocor.

### B. Pengunjung / Audience

Orang yang membuka domain milik pengguna untuk mengenal pemilik, membaca konten, atau menemukan produk.

- Tujuan: menemukan informasi relevan dan memahami asal setiap konten.
- Kebutuhan: navigasi jelas, tampilan sumber konten, tautan canonical, dan pengalaman mobile yang baik.
- Kekhawatiran: sulit membedakan konten asli, federasi, dan hasil agregasi.

### C. Buyer / Customer

Pengunjung yang ingin membeli produk atau jasa milik pemilik node.

- Tujuan: memilih produk, membayar dengan aman, dan memperoleh konfirmasi.
- Kebutuhan: harga dan status transaksi yang jelas, metode pembayaran sesuai node, serta bukti transaksi.
- Kekhawatiran: pembayaran gagal, status tidak terbarui, atau order ganda.

### D. Admin / Operator Node

Orang yang memasang, mengoperasikan, dan menjaga node tetap aman dan sehat.

- Tujuan: mengelola domain, fitur, integrasi, payment gateway, queue, dan keamanan.
- Kebutuhan: konfigurasi yang tervalidasi, health status, audit log, dan pemisahan sandbox/production.
- Kekhawatiran: credential salah, webhook palsu, job gagal, dan ketergantungan provider.

## 4. Journey Utama — Pemilik Node dari Onboarding hingga Publish

**Skenario:** seorang creator membuat personal digital home, melengkapi profil, menghubungkan RSS, lalu menerbitkan konten lokal.

| Tahap | Tujuan pengguna | Aksi pengguna | Touchpoint / respons sistem | Emosi | Risiko / pain point | Peluang desain | Status |
|---|---|---|---|---|---|---|---|
| 1. Discover | Memahami manfaat FPDP | Membuka landing page dan melihat contoh node | Landing menjelaskan ownership, agregasi, federasi, dan commerce | Penasaran | Konsep “node” dan “federasi” terasa teknis | Gunakan bahasa manfaat dan demo personal home | Parsial |
| 2. Create node | Memiliki akun dan domain | Registrasi, verifikasi identitas, pilih domain/subdomain | Sistem membuat user, node, dan konfigurasi awal | Antusias | Setup domain dapat membingungkan | Wizard dengan pilihan subdomain cepat atau custom domain | Belum ada |
| 3. Build profile | Membentuk identitas publik | Isi nama, bio, avatar, tautan, portfolio, dan visibility | Preview profil publik secara langsung | Memegang kendali | Takut data belum lengkap tetapi terpublikasi | Draft, checklist kelengkapan, dan tombol publish eksplisit | Belum ada |
| 4. Connect sources | Menggabungkan konten lama | Pilih RSS/Atom/Custom API, isi URL, lalu uji koneksi | Sistem memvalidasi URL, menampilkan preview, dan menjadwalkan sync | Produktif | URL tidak valid atau feed tidak kompatibel | Test connection, pesan error spesifik, dan contoh format | Fondasi backend ada |
| 5. Curate timeline | Mengontrol apa yang tampil | Pilih sumber, visibility, urutan, atau sembunyikan item | Timeline memberi label Local, Federated, atau External dan canonical URL | Percaya | Duplikasi dan asal konten tidak jelas | Filter, deduplication, badge sumber, dan atribusi permanen | Belum ada |
| 6. Create local post | Menerbitkan dari domain sendiri | Tulis post, pilih visibility, preview, lalu publish | Post tersimpan sebagai konten lokal dan masuk timeline | Mandiri | Risiko salah publikasi | Autosave draft, preview, schedule, dan undo/unpublish | Belum ada |
| 7. Share and grow | Membawa audience ke node | Membagikan URL profil/post dan mengaktifkan federasi | Sistem menyediakan canonical URL dan capability discovery | Bangga | Distribusi awal terbatas | Share metadata, follow CTA, dan status federasi | Belum ada |
| 8. Maintain | Menjaga node sehat | Memeriksa koneksi dan memperbarui profil | Dashboard menampilkan last sync, error, dan rekomendasi tindakan | Tenang | Kegagalan async tidak terlihat | Health center dan notifikasi yang actionable | Belum ada |

### Alur ideal

`Landing → Daftar/Login → Buat node → Lengkapi profil → Hubungkan feed → Preview → Atur visibility → Publish → Bagikan domain → Pantau kesehatan node`

### Momen kunci

- **Time to value:** pengguna melihat profil publik dan minimal satu konten secepat mungkin.
- **Trust moment:** sebelum menyimpan koneksi, sistem menjelaskan data yang diambil dan cara memutus koneksi.
- **Ownership moment:** setiap post memiliki URL di domain pengguna dan dapat dibedakan dari konten eksternal.

## 5. Journey Pengunjung — Menjelajah Personal Digital Home

| Tahap | Tujuan | Aksi | Respons sistem yang diharapkan | Risiko | Status |
|---|---|---|---|---|---|
| Masuk | Memahami siapa pemilik node | Membuka domain dari search/share | Hero profil, bio singkat, CTA, dan navigasi utama | Landing sekarang belum menampilkan data pengguna | Parsial |
| Eksplorasi | Menemukan karya dan aktivitas | Membuka profil, portfolio, timeline, atau produk | Konten dikelompokkan dan dapat difilter | Campuran sumber terasa membingungkan | Belum ada |
| Verifikasi sumber | Menilai keaslian konten | Melihat badge dan membuka sumber asli | Label Local/Federated/External, provider, dan canonical link | Atribusi hilang atau misleading | Model data tersedia sebagian |
| Engagement | Berinteraksi atau mengikuti | Membuka detail, membagikan, mengikuti, atau menghubungi | Sistem menjaga konteks node dan privacy | Belum ada mekanisme engagement | Belum ada |
| Kembali | Mengikuti update terbaru | Menyimpan URL atau mengikuti via federasi/feed | Update dapat diterima tanpa platform pusat | Discovery dan follow terlalu teknis | Belum ada |

Alur ideal: `Domain publik → Kenali pemilik → Jelajah timeline/portfolio → Periksa sumber → Baca detail → Follow/share/contact`.

## 6. Journey Buyer — Menemukan Produk hingga Pembayaran

| Tahap | Tujuan | Aksi buyer | Respons sistem yang diharapkan | Risiko / recovery | Status |
|---|---|---|---|---|---|
| Discover | Menemukan penawaran | Membuka tab produk atau CTA dari profil/post | Katalog menampilkan produk lokal dan label produk eksternal/federasi | Asal seller tidak jelas | Belum ada |
| Evaluate | Memastikan produk sesuai | Membuka detail produk, harga, seller, dan kebijakan | Informasi kepemilikan, stok, total, dan currency jelas | Informasi lintas node tidak konsisten | Belum ada |
| Checkout | Membuat order | Memilih item, identitas, alamat bila perlu, dan metode bayar | Sistem membuat order dan memilih gateway aktif | Double submit membuat order ganda | Belum ada |
| Pay | Menyelesaikan pembayaran | Dialihkan ke payment URL atau mengikuti instruksi bayar | Status awal `PENDING`, nominal dan expiry ditampilkan | Gateway gagal atau redirect terputus | Dummy flow tersedia |
| Confirm | Mendapat kepastian | Kembali ke node atau menunggu update | Webhook terverifikasi mengubah status secara idempotent | Callback duplikat/palsu | Kontrak ada, pipeline belum ada |
| After-sales | Melacak atau meminta refund | Membuka detail order dan mengajukan bantuan/refund | Timeline status order dan hasil refund terlihat | Status gateway dan lokal berbeda | Dummy refund saja |

Alur ideal: `Profil/timeline → Produk → Detail → Checkout → Pilih metode → Bayar → Verifikasi webhook → Konfirmasi order → After-sales`.

State pembayaran minimum yang perlu terlihat oleh buyer:

`CREATED → PENDING → PAID | FAILED | EXPIRED | CANCELLED → REFUNDED (opsional)`

## 7. Journey Admin — Menyiapkan dan Mengoperasikan Node

| Tahap | Tujuan admin | Aksi admin | Respons sistem yang diharapkan | Risiko / kontrol | Status |
|---|---|---|---|---|---|
| Install | Menjalankan node | Menyiapkan PHP, database, domain, dan environment | Preflight check memastikan kebutuhan terpenuhi | Setup masih manual dan README terbatas | Parsial |
| Bootstrap | Membuat konfigurasi awal | Membuat admin, nama node, locale, dan kebijakan | Setup wizard mengunci akses sebelum konfigurasi selesai | Default tidak aman | Belum ada |
| Configure connector | Mengaktifkan sumber eksternal | Pilih adapter, interval, credential, dan test | Credential terenkripsi; test dan capability ditampilkan | Secret bocor atau source melakukan SSRF | Backend dasar ada |
| Configure payment | Menentukan gateway aktif | Pilih provider, sandbox/production, credential, metode | Sistem memvalidasi adapter dan test transaction | Factory mereferensikan adapter yang belum tersedia | Dummy saja |
| Operate | Menjaga sync dan transaksi | Memantau queue, retry, webhook, dan transaksi | Dashboard health, log tersanitasi, retry terkontrol | Error diam-diam dan duplicate event | Skema dasar ada |
| Secure | Mengurangi risiko | Rotasi secret, kelola role/token, audit aktivitas | Secret tidak pernah ditampilkan penuh; audit trail tersedia | Belum ada auth/authorization | Belum ada |
| Extend | Menambah provider | Memasang adapter baru sesuai interface | Factory/registry mengenali provider tanpa mengubah domain logic | Daftar provider tidak sesuai class yang tersedia | Arsitektur dasar ada |

Alur ideal: `Install → Preflight → Buat admin → Konfigurasi node → Test connector → Test payment sandbox → Go live → Monitor → Recover/rotate`.

## 8. Service Blueprint Ringkas

| Journey pengguna | Frontstage | Backstage / service | Data utama |
|---|---|---|---|
| Hubungkan feed | Form URL, test, preview, tombol connect | Validasi URL, fetch aman, normalisasi, enqueue sync | `external_accounts`, `external_feed_sources`, `integration_queue` |
| Lihat timeline | Filter dan badge sumber | Query local + federated + external, dedupe, visibility | local posts (belum ada), `external_posts` |
| Checkout | Ringkasan order dan metode bayar | Buat order, pilih adapter, create payment | orders (belum ada), `payments` |
| Konfirmasi bayar | Halaman status dan receipt | Verify webhook, idempotency, update payment/order | `payment_transactions`, `payments` |
| Pantau node | Health cards dan error detail | Scheduler, retry policy, sanitized logs | queue, sync state, audit/log (sebagian belum ada) |

## 9. Prinsip UX yang Wajib Dijaga

1. **Ownership terlihat:** domain pemilik dan konten lokal menjadi identitas utama.
2. **Provenance tidak boleh ambigu:** selalu tampilkan tipe sumber, provider, author, dan canonical URL.
3. **Kontrol sebelum otomatisasi:** pengguna memilih sumber, visibility, dan apakah konten masuk profil/timeline.
4. **Koneksi dapat dicabut:** disconnect harus mudah, dengan penjelasan dampak pada konten yang sudah tersimpan.
5. **Status async transparan:** tampilkan `last_sync_at`, `next_sync_at`, status, dan tindakan retry.
6. **Pembayaran tidak boleh samar:** nominal, currency, expiry, provider, dan state harus konsisten.
7. **Progressive disclosure:** istilah teknis seperti federation, capability, webhook, dan queue ditempatkan pada konteks admin atau dijelaskan dengan bahasa awam.

## 10. Prioritas Journey untuk MVP

### P0 — Membuktikan personal digital home

- registrasi/login dan setup satu node;
- profil publik yang dapat diedit;
- CRUD post lokal;
- timeline lokal;
- visibility dan canonical URL.

### P1 — Membuktikan agregasi eksternal

- form koneksi RSS/Atom/Custom API;
- test connection dan preview;
- normalisasi serta penyimpanan external post;
- atribusi sumber pada timeline;
- sync queue, retry, disconnect, dan health status.

### P2 — Membuktikan commerce

- produk dan order minimal;
- satu gateway nyata selain dummy;
- checkout, webhook verification, idempotency, dan receipt;
- halaman admin untuk konfigurasi sandbox/production.

### P3 — Membuktikan federasi

- node discovery dan remote actor;
- follow/receive activity;
- timeline federasi dengan source identity;
- moderation dan failure handling lintas node.

## 11. Metrik Keberhasilan Journey

| Sasaran | Metrik awal yang disarankan |
|---|---|
| Aktivasi pemilik node | Persentase user yang mempublikasikan profil + satu post/feed dalam satu sesi |
| Time to value | Waktu median dari registrasi sampai profil publik pertama |
| Integrasi berhasil | Persentase test connection dan sync pertama yang sukses |
| Kualitas timeline | Persentase item dengan source, provider, author, dan canonical URL lengkap |
| Reliability | Success rate sync, retry recovery rate, dan usia job tertua |
| Checkout | Checkout completion rate dan payment success rate |
| Konsistensi payment | Persentase webhook terverifikasi, duplicate event yang tertahan, dan mismatch status |
| Retention | Pemilik node aktif menerbitkan/menyinkronkan kembali dalam 7 dan 30 hari |

## 12. Gap Penting Sebelum Journey Dapat Dijalankan End-to-End

- Belum ada entry point/router web yang memperlihatkan alur selain render controller pada test.
- Belum ada model/tabel users, profiles, local posts, nodes, products, orders, maupun role/permission.
- Belum ada UI untuk autentikasi, profil, timeline, integrasi, checkout, atau admin.
- Factory pembayaran menyebut beberapa adapter riil yang class-nya belum tersedia; hanya dummy yang dapat digunakan saat ini.
- External connector melakukan fetch langsung; validasi URL, proteksi SSRF, timeout, retry, dan scheduler belum tersedia.
- Foreign key, index operasional, enkripsi token, audit log, dan data-retention policy belum terdefinisi lengkap.
- Verifikasi webhook dan idempotency transaksi belum diimplementasikan end-to-end.

Journey MVP yang paling realistis untuk dibangun terlebih dahulu adalah:

`Owner login → lengkapi profil → buat post lokal → hubungkan RSS → preview dan sync → lihat timeline beratribusi → buka profil publik`.

