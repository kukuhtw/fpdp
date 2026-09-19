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
- Marketplace dan produk local
- Dashboard admin untuk konfigurasi

### Diluar Scope pada MVP
- Implementasi semua gateway pembayaran secara penuh
- Semua connector sosial OAuth secara lengkap
- Federated commerce end-to-end pada fase awal
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

### 5.3 Seller / merchant
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
BRD ini menetapkan arah bisnis yang menempatkan user sebagai pemilik digital identity dan data. Platform bukan sekadar media sosial baru, tetapi personal internet node yang menghubungkan website, sosial, federasi, marketplace, dan pembayaran secara mandiri.
