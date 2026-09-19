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
- Sebagai pengguna, saya ingin memiliki halaman profil saya sendiri di domain saya.
- Sebagai pengguna, saya ingin menulis blog atau post di node saya.

### 5.2 External Feed Integration
- Sebagai pengguna, saya ingin menghubungkan Instagram, LinkedIn, RSS, dan X ke timeline saya.
- Sebagai pengguna, saya ingin melihat sumber asli dari setiap konten.

### 5.3 Payment
- Sebagai admin, saya ingin memilih gateway pembayaran yang aktif di node saya.
- Sebagai pengguna, saya ingin checkout menggunakan gateway yang telah disetujui node saya.

### 5.4 Federation
- Sebagai node, saya ingin mengenali kemampuan node lain.
- Sebagai pengguna, saya ingin melihat konten federasi dengan jelas sumbernya.

## 6. Functional Requirements
### 6.1 Authentication
- PHP Session-based auth untuk web app
- Bearer token untuk REST API
- OAuth 2.0 untuk provider eksternal

### 6.2 Content
- Local post, federated post, dan external post harus dibedakan.
- Setiap item harus memiliki source_type, source_provider, dan canonical_url.
- User bisa memilih visibility dan tampilkan di profile atau timeline.

### 6.3 Payment
- Payment adapter sesuai interface
- Factory memilih provider dinamai
- Webhook generic dengan normalized event
- Idempotency untuk webhook duplicate

### 6.4 External Connector
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
- User dapat membuat profile di node sendiri.
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
- Local profile and posts

### Phase 2
- Social feed and timeline
- External aggregation

### Phase 3
- Federation and remote actors

### Phase 4
- Marketplace and payment abstraction

### Phase 5
- Advanced connectors and plugin ecosystem
