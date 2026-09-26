# Business Requirements Document (BRD)

## 1. Latar Belakang
Platform Personal Federation Digital Platform (FPDP) merupakan sistem personal digital home yang memungkinkan setiap individu memiliki domain sendiri, identitas digital sendiri, konten sendiri, dan saluran sosial yang dikoneksikan dari platform eksternal. Tujuan utama platform ini adalah memberi kontrol penuh kepada pengguna terhadap data, identitas, konten, dan pilihan pembayaran mereka.

## 2. Visi
Membuat personal internet node untuk setiap individu, sehingga user tidak bergantung pada satu platform besar untuk identitas, konten, komunitas, atau pembayaran.

## 3. Tujuan Bisnis
- Memberikan masing-masing pengguna domain pribadi sebagai pusat digital identity.
- Memungkinkan pemilik domain mengelola website, profil, dan konten sendiri.
- Menyediakan jaringan federasi antar node tanpa platform pusat.
- Mengintegrasikan konten dari platform eksternal seperti RSS, Instagram, LinkedIn, X, YouTube, dan marketplace lain.
- Menyediakan payment abstraction agar pengguna atau admin dapat memilih gateway sesuai kebutuhan.
- Menjadi personal digital home yang menggabungkan profil, blog, portfolio, produk, sosial, dan payment channel.

## 4. Ruang Lingkup
### Dalam Scope
- Personal website dan profile
- Social feed aggregation
- Federated posting dan komunikasi antar node
- Payment gateway abstraction
- Integrasi konten eksternal
- Toko online pribadi milik owner node (satu penjual per node) dan produk lokal
- Distribusi produk ke fediverse sebagai fondasi federated commerce
- Dashboard admin untuk konfigurasi

### Diluar Scope pada MVP
- Implementasi semua gateway pembayaran secara penuh
- Semua connector sosial OAuth secara lengkap
- Federated commerce end-to-end (order lintas node) pada fase awal — tetap menjadi keunggulan utama dan target fase berikutnya
- Plugin marketplace publik

## 5. User Persona
### 5.1 Pemilik domain / individu
- Ingin memiliki identitas digital milik pribadi
- Ingin kontrol penuh atas data dan konten
- Ingin menampilkan feed eksternal tanpa kehilangan atribusi

### 5.2 Admin / operator node
- Mengatur payment gateway, domain, dan konfigurasi sistem
- Mengelola koneksi eksternal
- Menonaktifkan atau mengaktifkan fitur tertentu

### 5.3 Seller / merchant (owner yang berjualan)
- Menjual produk lokal atau federated
- Menggunakan gateway pembayaran sesuai preferensi

## 6. Kebutuhan Bisnis Utama
- Setiap node harus independen secara teknis dan operasional.
- Tidak ada dependency wajib terhadap satu payment gateway atau social media provider.
- Federasi harus menjadi network core, bukan platform pusat.
- Semua data eksternal harus punya origin dan atribusi jelas.
- User harus bisa memilih, menghubungkan, dan melepaskan koneksi eksternal secara mandiri.

## 7. Functional Requirements
### 7.1 Identity dan Akses
- Setiap pemilik domain dapat mendaftar akun, yang langsung menyediakan node, user owner, dan profil publiknya sekaligus.
- Password di-hash; akses API memakai bearer token yang di-hash saat disimpan, punya masa berlaku, dan dapat dicabut eksplisit saat logout.
- Registrasi dan login dibatasi rate-nya per client untuk menahan credential stuffing dan pembuatan akun spam.
- Owner mengontrol profil publiknya (display name, bio, avatar, links) dan visibility-nya (public, unlisted, atau private).
- Setiap owner node dapat mempersonalisasi tampilan node-nya: tema, layout, custom CSS, dan bahasa default/aktif, dengan fallback bawaan yang aman saat setting kosong atau tidak valid.

### 7.2 Payment
- Sistem dapat memilih gateway secara dinamis.
- Admin dapat menambah, mengaktifkan, dan menonaktifkan gateway.
- Sistem memerlukan abstraction interface agar business logic tidak tergantung provider.
- Webhook harus dikonfirmasi dan diolah dengan idempotency.

### 7.3 Integrasi Eksternal
- User dapat menambah feed RSS, Atom, atau API custom.
- Semua konten eksternal harus di-normalisasi.
- Sistem harus menampilkan origin provider dan canonical URL.
- Sync harus dilakukan secara asinkron.

### 7.4 Social & Timeline
- Timeline dapat menampilkan local, federated, dan external content.
- User dapat memilih sumber yang ditampilkan.
- Konten eksternal harus memiliki mode tampilan dan privasi.

### 7.5 Federation
- Node harus dapat mengungkapkan capability discovery.
- Node dapat memproses activity dan remote object.
- Federated commerce harus memisahkan owner data lokal, federated, dan eksternal.

## 8. Non-Functional Requirements
- PHP 8.2+
- MySQL 8+
- REST JSON API
- Modular monolith dengan service layer
- Keamanan: token dienkripsi, secret tidak dipajang, log tidak menampilkan secret
- Skalabilitas: queue dan cron untuk tugas async
- Maintainability: adapter, factory, strategy, dan repository pattern

## 9. Kriteria Keberhasilan
- Sistem dapat berjalan sebagai independent node.
- User dapat mendaftar, autentikasi, dan mengelola profil publiknya secara mandiri dengan kontrol visibility.
- User dapat memiliki domain Personal Digital Home-nya sendiri.
- Payment dapat dipilih sesuai gateway pilihan.
- Feed eksternal dapat terkoneksi dan ditampilkan dengan atribusi.
- Federasi dapat dibangun tanpa platform pusat.

## 10. Risiko Bisnis
- Dependency pada platform pihak ketiga yang terlalu besar.
- Ketidakseragaman API provider.
- Async sync yang belum dikelola dengan baik.
- Kesalahan konfigurasi payment dan credential security.

## 11. Kesimpulan
BRD ini menetapkan arah bisnis yang menempatkan user sebagai pemilik digital identity dan data. Platform bukan sekadar media sosial baru, tetapi personal internet node yang menghubungkan website, sosial, federasi, toko online pribadi, federated commerce, dan pembayaran secara mandiri.

## 12. Addendum: Interaksi AI dan Monetisasi (2026-09-19)

Addendum ini memperluas BRD dengan AI chat yang dapat dikonfigurasi owner, akses konten berbayar, identity visitor wajib untuk fitur interaktif, analitik traffic, dan marketplace iklan. Addendum ini menambah lapisan revenue dan engagement di atas personal digital home yang didefinisikan di Bagian 1–11; lihat [`AI-MONETIZATION-STRATEGY.id.md`](AI-MONETIZATION-STRATEGY.id.md) untuk data model dan rencana delivery yang detail.

### 12.1 Rasional Bisnis

- Owner dapat memonetisasi keahlian dan atensinya secara langsung, tanpa potongan atau algoritma platform pihak ketiga.
- Asisten profil bertenaga AI meningkatkan engagement visitor dan memberi owner cara yang scalable untuk menjawab pertanyaan berulang (soal CV, layanan, atau ketersediaan) tanpa memakai waktu mereka sendiri.
- Iklan di level node mengubah traffic visitor menjadi sumber revenue yang sepenuhnya dikontrol owner.

### 12.2 Persona Baru dan yang Diperluas

- **Visitor (baru):** sesi browser anonim yang menjadi visitor teridentifikasi hanya saat ingin berinteraksi — melihat konten berbayar, chatting, atau booking iklan. Visitor login dengan Google; FPDP tidak pernah menyimpan password terpisah untuk visitor.
- **Pemilik domain (diperluas):** juga mengonfigurasi LLM provider dan model pilihannya sendiri, mengatur harga akses CV dan sesi chatbot, serta mengelola ad slot dan harganya.
- **Advertiser (baru):** perorangan atau bisnis yang menyewa ad slot dari owner node untuk periode tertentu.

### 12.3 Tujuan Bisnis

- Memungkinkan owner memakai LLM provider pilihan mereka (OpenAI, Anthropic, atau lainnya) tanpa FPDP bergantung pada satu provider saja.
- Memungkinkan owner mengenakan biaya untuk konten premium (CV/resume mereka) dan interaksi premium (percakapan chatbot), dengan fee sepenuhnya dikontrol owner, termasuk gratis.
- Mewajibkan identity visitor terverifikasi (Google OAuth) sebelum aksi berbayar atau interaktif apa pun, sehingga payment dan riwayat chat dapat diatribusikan dan sengketa dapat diselesaikan.
- Memberi owner visibilitas atas traffic mereka sendiri (unique visitor dan page view per hari) tanpa bergantung pada analytics pihak ketiga.
- Memungkinkan owner menjual inventory iklan mereka sendiri (harian, mingguan, atau bulanan) langsung ke advertiser.

### 12.4 Penambahan Scope

Dalam scope addendum ini:

- Konfigurasi LLM provider per node (provider, model, credential).
- Akses CV/resume berbayar.
- Sesi chatbot berbayar yang grounded pada profil dan konten CV owner sendiri.
- Google OAuth sebagai identity visitor wajib untuk aksi berbayar atau interaktif apa pun.
- Laporan traffic harian dan unique-visitor untuk owner.
- Definisi ad slot, pricing (harian/mingguan/bulanan), dan booking oleh advertiser.

Di luar MVP addendum ini:

- LLM provider di luar set adapter awal (mulai dari OpenAI dan Anthropic; provider lain menyusul lewat interface yang sama).
- Real-time ad bidding atau programmatic ad exchange.
- Moderasi otomatis untuk jawaban chatbot atau creative iklan di luar langkah approval manual owner.
- Billing multi-currency di luar yang sudah didukung abstraction payment gateway saat ini.

### 12.5 Functional Requirements

**Konfigurasi AI provider**

- Owner dapat memilih LLM provider (OpenAI, Anthropic, atau adapter lain yang didukung), memasukkan credential API miliknya sendiri, dan memilih model.
- Credential dienkripsi saat disimpan dan tidak pernah ditampilkan penuh di response API atau log, konsisten dengan penanganan credential payment gateway yang sudah ada.
- Kode provider yang tidak didukung ditolak secara eksplisit, konsisten dengan pola factory yang sudah dipakai untuk payment dan external connector.

**Akses CV/resume berbayar**

- Owner dapat mengunggah CV/resume dan mengatur fee aksesnya, termasuk gratis.
- Visitor wajib autentikasi dengan Google dan menyelesaikan payment sebelum dokumen disajikan.
- Access grant dicatat sehingga visitor yang sudah bayar tidak dikenakan biaya lagi untuk dokumen yang sama.

**Interaksi chatbot berbayar**

- Owner dapat mengaktifkan chatbot di profil publiknya yang menjawab pertanyaan berdasarkan profil dan konten CV-nya sendiri.
- Owner mengatur model fee untuk akses chatbot; visitor wajib autentikasi dengan Google dan membayar sebelum chatting.
- Percakapan dicatat per visitor untuk keperluan support, audit, dan review penyalahgunaan.

**Autentikasi visitor**

- Melihat profil secara pasif tetap terbuka untuk visitor anonim.
- Aksi interaktif atau berbayar apa pun (melihat CV berbayar, memulai chat, booking iklan) mewajibkan visitor login dengan Google terlebih dahulu.
- FPDP hanya menyimpan data profil visitor minimal yang diperlukan (identifier, email, display name) untuk mengatribusikan payment dan interaksi.

**Analitik traffic**

- Owner dapat melihat jumlah unique visitor harian dan jumlah page view harian untuk node-nya.
- Analytics diagregasi tanpa menyimpan identifier visitor mentah lebih lama dari yang diperlukan untuk rollup harian.

**Marketplace ad banner**

- Owner dapat mendefinisikan satu atau lebih ad slot di profilnya, masing-masing dengan harga harian (24 jam), mingguan, atau bulanan yang independen.
- Advertiser melakukan booking dan membayar slot untuk periode pilihannya; owner dapat mereview creative sebelum tayang.
- Jendela aktif booking ditegakkan otomatis; booking yang sudah expired berhenti tayang tanpa perlu aksi manual owner.

### 12.6 Non-Functional Requirements

- Penggunaan API LLM dibatasi rate dan cost per node untuk mencegah tagihan membengkak akibat misconfiguration atau penyalahgunaan.
- Semua flow monetisasi memakai ulang abstraction `PaymentGatewayInterface` yang sudah ada; tidak ada fitur yang hardcode ke payment provider tertentu.
- Data pribadi visitor (dari Google OAuth) ditangani dengan prinsip secret-hygiene dan audit-logging yang sama seperti yang sudah diwajibkan untuk credential owner.
- Respons chatbot diatribusikan dengan jelas sebagai otomatis dan tidak pernah ditampilkan seolah-olah balasan real-time owner sendiri.

### 12.7 Risiko

- Cost API LLM tidak terkendali jika chatbot owner di-spam atau di-scrape.
- Risiko reputasi jika chatbot memberi jawaban tidak akurat atau tidak pantas saat mewakili owner.
- Sengketa payment untuk barang intangible (sesi chat, view CV) lebih sulit diselesaikan dibanding order produk fisik.
- Celah moderasi konten iklan bisa mengekspos halaman owner ke creative yang tidak pantas jika langkah review manual dilewati.
- PII visitor dari Google OAuth memperbesar permukaan privacy dan compliance platform.

## 13. Addendum: Discovery Koneksi Federasi pada Profil Publik (2026-09-19)

### 13.1 Kebutuhan Bisnis

Profil publik juga menjadi pintu discovery ke jaringan independen yang dipercaya atau diikuti owner. Visitor harus dapat melihat user/node federasi lain yang terkoneksi dengan profil tersebut beserta aktivitas publik terbaru mereka, tanpa kehilangan asal konten.

### 13.2 Functional Requirements

- Profil publik menampilkan koneksi federasi: identitas remote, node/domain, status hubungan, dan tautan canonical.
- Setiap koneksi dapat menampilkan preview post publik terbarunya jika tersedia dan lolos kebijakan moderasi lokal.
- Preview mempertahankan `source_type=FEDERATED`, remote actor/node, canonical URL asli, waktu publikasi, dan status sinkronisasi.
- Owner dapat menampilkan atau menyembunyikan koneksi di profil tanpa memutus hubungan federasi.
- Koneksi `BLOCKED`, `MUTED`, ditolak, atau berasal dari node yang diblokir tidak boleh tampil.
- Kegagalan node remote tidak boleh membuat profil lokal gagal; gunakan cache valid terakhir atau lewati preview.
- Siklus A→B→D→E→A sah. Traversal graph wajib memakai cycle detection dan batas kedalaman.

### 13.3 Acceptance Criteria

- Visitor dapat membedakan post lokal owner dari post koneksi federasi.
- Tautan actor/post mengarah ke canonical URL remote yang benar.
- Owner dapat mengatur visibility koneksi publik.
- Actor/node yang diblokir hilang dari daftar dan preview.
- Profil tetap tersedia saat node remote timeout atau offline.
