# Roadmap dan Strategi Pengembangan FPDP

## 1. Tujuan

Dokumen ini mengubah visi produk FPDP menjadi rencana delivery yang dapat dieksekusi. Isinya menjelaskan apa yang harus dibangun lebih dahulu, alasan urutannya, hasil yang harus dibuktikan pada setiap milestone, dan quality gate sebelum masuk ke fase berikutnya.

Roadmap ini didasarkan pada kondisi repository saat Phase 0 dimulai, dan tetap disimpan di sini sebagai baseline perencanaan:

- struktur modular ringan berbasis PHP 8.2 sudah tersedia;
- landing view MVC statis dapat dirender;
- interface dan factory payment serta external content sudah tersedia;
- hanya dummy payment gateway yang berfungsi;
- adapter RSS, Atom, dan Custom API dapat menormalisasi record remote di memory;
- skema awal payment dan integration tersedia;
- sebagian besar HTTP, autentikasi, persistence, UI, queue, commerce, dan federasi belum diimplementasikan.

Phase 0 dan sebagian besar Phase 1 kini sudah selesai. Lihat [laporan progres pengembangan](PROGRESS-REPORT.id.md) untuk kondisi terkini dan terverifikasi dari setiap phase dan workstream.

## 2. Hasil strategis

Rilis produk bermakna pertama harus memungkinkan pemilik node menjalankan journey berikut:

```text
Instal node → Buat akun owner → Lengkapi profil publik
→ Terbitkan post lokal → Hubungkan RSS → Sinkronisasi dan tinjau konten beratribusi
→ Lihat personal digital home publik
```

Payment, toko online pribadi, dan federasi tidak ditempatkan pada critical path sampai siklus ownership dan content tersebut stabil.

## 3. Strategi pengembangan

### 3.1 Bangun vertical slice

Kirim capability end-to-end berukuran kecil yang meliputi route, authorization, validation, service, persistence, response contract, UI bila diperlukan, dan test. Hindari membangun seluruh tabel terlebih dahulu atau seluruh layar UI tanpa journey yang dapat dijalankan.

Slice pertama yang disarankan:

```text
Register → Simpan user/node/profile → Authenticate → Baca /me
```

Slice kedua yang disarankan:

```text
Buat post lokal → Simpan → Baca post publik → Tampilkan di profil
```

### 3.2 Pengembangan API contract-first

Gunakan [`openapi.yaml`](openapi.yaml) sebagai target kontrak API.

Untuk setiap endpoint:

1. konfirmasi request, response, authorization, dan perilaku error;
2. tambahkan contract test atau request-level test;
3. implementasikan service dan persistence paling kecil yang diperlukan;
4. jaga agar OpenAPI selalu sinkron dengan implementasi;
5. catat status implementasi pada release note atau endpoint matrix.

Kontrak dapat direvisi ketika implementasi menemukan masalah desain, tetapi kode dan dokumentasi harus berubah bersamaan.

### 3.3 Pertahankan batas antar-provider

Business logic harus bergantung pada `PaymentGatewayInterface` dan `ExternalContentProviderInterface`, bukan adapter konkret. Kode provider yang tidak dikenal harus gagal secara eksplisit; fallback diam-diam ke dummy atau RSS tidak aman untuk production.

### 3.4 Tetapkan security boundary sejak awal

Security merupakan bagian dari setiap fitur, bukan fase hardening terakhir. Authentication, authorization, ownership check, enkripsi secret, proteksi SSRF, verifikasi webhook, sanitasi log, dan idempotency harus dibangun bersama capability yang membutuhkannya.

### 3.5 Pastikan proses async dapat dipantau

Sinkronisasi feed dan pemrosesan webhook membutuhkan status eksplisit, retry policy, attempt count, timestamp, error yang disanitasi, dan operational visibility. Capability berbasis queue belum lengkap jika operator tidak dapat melihat atau memulihkan job gagal.

### 3.6 Kelola persistence melalui migration

Ganti schema stub dengan migration berurutan dan repeatable. Tambahkan foreign key, unique constraint, index, timestamp, dan retention rule secara sengaja. Hindari perubahan manual pada skema production.

### 3.7 Tunda federasi sampai semantic lokal stabil

Federasi memperbesar masalah identity, moderation, delivery, trust, dan consistency. Stabilkan profil lokal, post, visibility, canonical URL, dan source attribution sebelum bertukar remote activity.

### 3.8 Biarkan setiap node menentukan tampilan dan bahasanya sendiri

Personal digital home semestinya tidak terlihat sama untuk setiap owner. Presentasi di level node (tema, layout, custom CSS) dan bahasa (locale default, daftar bahasa yang aktif) adalah pengaturan milik owner, bukan konfigurasi global aplikasi. Simpan per node agar tidak ikut berubah saat ada deploy kode, validasi custom CSS secara defensif (lihat Fase 2 task 8), dan selalu sediakan tema/locale bawaan yang aman sebagai fallback saat setting-nya kosong atau tidak valid.

## 4. Model prioritas

| Prioritas | Arti | Aturan |
|---|---|---|
| P0 | Release blocker | Wajib untuk siklus konten personal node yang aman |
| P1 | Penyelesaian MVP | Wajib untuk agregasi eksternal dan operasi yang andal |
| P2 | Capability komersial | Produk, order, dan pembayaran nyata |
| P3 | Perluasan jaringan | Federasi dan capability ekosistem lanjutan |

Dalam satu prioritas, selesaikan risiko fondasi sebelum fitur kenyamanan. Task keamanan dan integritas data mengikuti prioritas fitur yang dilindunginya.

## 5. Roadmap delivery

### Fase 0 — Fondasi engineering

**Tujuan:** membuat application shell yang aman dan dapat diuji sebelum menambahkan fitur produk.

Estimasi: 1–2 minggu.

Task secara berurutan:

1. Tambahkan environment configuration dengan validasi dan file contoh tanpa secret.
2. Tambahkan public front controller dan routing web/API yang eksplisit.
3. Tambahkan JSON response, exception mapping, request ID, dan logging tersanitasi yang terpusat.
4. Buat database connection management dan migration berurutan.
5. Ubah skema yang ada menjadi migration; tambahkan foreign key dan index operasional.
6. Buat entry point test unit, integration, dan HTTP otomatis.
7. Tambahkan static analysis, pemeriksaan code style, dan perintah CI yang repeatable.
8. Ubah factory agar menolak provider yang tidak didukung dan tidak melakukan fallback diam-diam.

Exit criteria:

- `/api/v1/health` mengembalikan envelope sesuai kontrak;
- migration berjalan pada database kosong dan aman dijalankan ulang;
- test, lint, dan static analysis lulus dari clean checkout;
- runtime error menghasilkan response tersanitasi dengan request ID;
- tidak ada secret aplikasi yang disimpan atau dicetak ke log.

### Fase 1 — Identity dan personal node

**Tujuan:** membentuk ownership, authentication, dan profil publik.

Estimasi: 2–3 minggu.

Task secara berurutan:

1. Tambahkan migration `nodes`, `users`, `profiles`, session/API token, dan audit event.
2. Implementasikan password hashing dan registrasi owner yang aman.
3. Implementasikan login, logout, token expiration/revocation, dan session protection.
4. Tambahkan middleware authentication dan role/ownership.
5. Implementasikan `/auth/register`, `/auth/login`, `/auth/logout`, dan `/me`.
6. Implementasikan update profil dan endpoint pembacaan profil publik.
7. Buat profile editor minimal dan halaman profil publik.
8. Tambahkan rate limiting pada endpoint autentikasi.

Exit criteria:

- satu owner dapat membuat dan mengakses node secara aman;
- permission unauthenticated, owner, dan admin telah diuji;
- aturan visibility profil berjalan pada API dan UI;
- credential dan token tidak muncul pada output API atau log;
- alur registration-to-public-profile lulus end-to-end test.

### Fase 2 — Konten lokal dan timeline

**Tujuan:** membuat domain pengguna berguna tanpa provider eksternal.

Estimasi: 2–3 minggu.

Task secara berurutan:

1. Tambahkan migration post lokal dan metadata media.
2. Implementasikan create, read, update, soft delete, draft, dan visibility post.
3. Buat canonical URL yang stabil pada node pengguna.
4. Implementasikan query post publik dan timeline dengan cursor pagination.
5. Buat post editor, halaman post, dan timeline lokal.
6. Tambahkan output escaping, sanitasi konten, dan validasi media.
7. Tambahkan test ownership, visibility, pagination, dan soft delete.
8. Tambahkan setting tampilan dan bahasa per node: migration `node_settings` (tema, pilihan layout, custom CSS, locale default, daftar bahasa yang aktif); pasangan endpoint `GET/PATCH /me/settings`; sanitasi custom CSS di sisi server (tolak `@import`, `expression()`, dan `<script>` yang disisipkan; batasi panjangnya) sebelum pernah dirender balik ke pengunjung; dan UI setting yang memakai ulang switcher bahasa/tampilan yang sudah ada di [mockup interaktif](mockup/README.md).

Exit criteria:

- owner dapat membuat draft, publish, edit, unpublish, dan menghapus post lokal;
- pengunjung hanya melihat konten yang diizinkan visibility rule;
- setiap item published memiliki author, source metadata, dan canonical URL yang stabil;
- query timeline memakai index dan pagination;
- owner dapat mengatur tema, layout, custom CSS, dan bahasa default/aktif untuk node-nya, dan public profile serta timeline tampil sesuai setting tersebut, dengan fallback aman saat setting kosong atau tidak valid.

### Fase 3 — Agregasi feed eksternal

**Tujuan:** menghubungkan sumber eksternal dengan aman dan menampilkan konten ternormalisasi dengan atribusi.

Estimasi: 3–4 minggu.

Task secara berurutan:

1. Tambahkan validasi konfigurasi connector yang ketat dan error provider eksplisit.
2. Implementasikan outbound HTTP dengan pemeriksaan DNS/IP, proteksi SSRF, timeout, size limit, dan XML handling aman.
3. Implementasikan test/preview sumber tanpa persistence.
4. Simpan sumber eksternal dan credential terenkripsi.
5. Definisikan mapping external post ternormalisasi dan deterministic deduplication key.
6. Implementasikan queue claim, locking, retry dengan backoff, dan dead-letter handling.
7. Simpan post ternormalisasi dan perbarui sync state secara atomik.
8. Gabungkan item eksternal ke timeline dengan label provider dan canonical link.
9. Implementasikan manual sync, disable, disconnect, dan purge konten opsional.
10. Tambahkan integration-health screen dengan error yang actionable.

Exit criteria:

- test/preview RSS, Atom, dan Custom API aman dan memiliki batas;
- sinkronisasi berulang tidak membuat post duplikat;
- job gagal dapat di-retry dan diperiksa tanpa mengekspos secret;
- perilaku disconnect dan data retention eksplisit serta telah diuji;
- seluruh item eksternal menampilkan source type, provider, author, dan canonical URL.

### Fase 4 — Operasional dan hardening MVP

**Tujuan:** membuat personal content node dapat di-deploy dan didukung secara operasional.

Estimasi: 2 minggu.

Task secara berurutan:

1. Tambahkan admin health dashboard untuk dependency, job, dan kegagalan terbaru.
2. Tambahkan audit event untuk autentikasi, konfigurasi sumber, dan aksi administratif.
3. Buat runbook backup, restore, migration, scheduler, dan worker.
4. Tambahkan rate limit, security header, CSRF protection, dan production configuration check.
5. Tambahkan metrik activation, sync success, job age, dan error rate.
6. Jalankan review dependency, credential, logging, authorization, dan connector security.
7. Uji recovery dan rollback di staging environment.

Exit criteria:

- user journey praktis pertama lulus di staging;
- operator dapat mendeteksi dan memulihkan failure mode umum;
- deployment, backup, restore, dan rollback terdokumentasi serta diuji;
- tidak ada temuan security critical yang masih terbuka.

**Milestone:** Personal Digital Home MVP.

### Fase 5 — Toko online pribadi dan payment

**Tujuan:** mendukung journey produk-ke-pembayaran lokal yang dapat dipercaya.

Estimasi: 4–6 minggu.

Task secara berurutan:

1. Definisikan model product, inventory, order, order line, payment attempt, refund, dan idempotency.
2. Buat CRUD produk lokal serta halaman katalog/detail publik.
3. Buat snapshot harga order immutable dan transisi status order eksplisit.
4. Perkuat kontrak dummy gateway dengan service test dan state-machine test.
5. Implementasikan satu production gateway berdasarkan prioritas target market.
6. Implementasikan pembuatan payment yang idempotent.
7. Implementasikan verifikasi signature webhook, replay prevention, event deduplication, dan update state transaksional.
8. Buat pengalaman checkout, status payment, receipt, cancellation, dan refund.
9. Tambahkan konfigurasi gateway dengan secret terenkripsi dan pemisahan sandbox/production.
10. Tambahkan reconciliation dan alert untuk perbedaan state lokal/provider.

Exit criteria:

- submit checkout berulang tidak membuat logical payment duplikat;
- webhook palsu dan replay ditolak;
- webhook valid yang duplikat diterima tanpa transisi berulang;
- total order tidak dapat berubah setelah checkout dibuat;
- checkout, payment, refund, dan reconciliation end-to-end lulus di sandbox.

### Fase 6 — Federasi

**Tujuan:** menghubungkan node independen tanpa melemahkan ownership atau moderasi lokal.

Estimasi: 5–8 minggu setelah pemilihan protokol.

Task secara berurutan:

1. Pilih dan dokumentasikan protokol federasi serta target interoperability.
2. Implementasikan node identity, key management, dan capability discovery.
3. Tambahkan model remote actor/object dengan provenance dan trust state.
4. Implementasikan inbox/outbox delivery yang ditandatangani dan diverifikasi.
5. Tambahkan delivery queue, retry, deduplication, tombstone, dan semantic update/delete.
6. Tambahkan kontrol follow, block, mute, report, dan moderasi.
7. Tampilkan konten federasi dengan remote identity dan canonical URL yang jelas.
8. Tambahkan discovery koneksi pada profil publik dengan flag `show_on_profile` yang dikontrol owner dan satu preview post terbaru dari cache per koneksi.
9. Tambahkan cursor pagination, indikator stale-cache, perilaku non-blocking saat remote gagal, dan traversal graph yang aman terhadap siklus.
10. Jalankan test interoperability, abuse, key rotation, replay, siklus graph, filter privasi, dan kegagalan.

Exit criteria:

- dua test node dapat bertukar activity yang didukung secara aman;
- activity duplikat dan replay tidak menyebabkan efek berulang;
- update/delete remote mengikuti semantic yang terdokumentasi;
- admin dan pengguna dapat memblokir node atau actor yang bermasalah;
- profil publik hanya menampilkan koneksi yang disetujui owner, aman menurut moderasi, dan memiliki preview cache beratribusi;
- graph bersiklus seperti A→B→D→E→A tidak menyebabkan record berulang atau traversal tanpa batas;
- ownership lokal, eksternal, dan federasi tetap dapat dibedakan.

### Fase 7 — Ekosistem dan scale

**Tujuan:** memperluas integrasi dan kapasitas operasional setelah semantic inti stabil.

Kandidat pekerjaan:

- connector sosial OAuth;
- registry adapter/plugin dan compatibility policy;
- federated commerce (order lintas node) — keunggulan utama platform: produk yang tersebar ke fediverse dapat dipesan dan dibayar dari node lain;
- search lanjutan, media processing, dan caching;
- operational tooling multi-node;
- accessibility, localization, import/export, dan data portability.

### Fase 8 — Interaksi AI dan Monetisasi

**Tujuan:** memungkinkan owner memonetisasi node-nya secara langsung lewat AI chat yang dikonfigurasi owner, konten berbayar, dan inventory iklan, digerbang oleh identity visitor yang wajib.

Bergantung pada Fase 1 (identity) dan Fase 5 (payment); independen dari Fase 6 (federasi) dan Fase 7. Lihat [`AI-MONETIZATION-STRATEGY.id.md`](AI-MONETIZATION-STRATEGY.id.md) untuk desain lengkap, data model, dan rasionalnya — entri ini hanya mengurutkan pekerjaannya.

Task secara berurutan (tiap langkah bernomor di bawah cocok dengan Bagian 12 dokumen strategi):

1. Identity visitor lewat Google OAuth (`visitor_accounts`, `visitor_tokens`, endpoint redirect/callback).
2. Abstraksi LLM provider (`LLMProviderInterface`, `LLMProviderFactory` untuk OpenAI/Anthropic, `llm_configs`).
3. Akses CV/resume berbayar (`cv_documents`, `cv_access_grants`).
4. Chatbot berbayar yang grounded pada profil/CV owner sendiri (`chat_sessions`, `chat_messages`).
5. Analitik traffic harian dan unique-visitor (`page_views`, `analytics_daily`).
6. Marketplace ad banner dengan pricing harian/mingguan/bulanan (`ad_slots`, `ad_bookings`).

Exit criteria:

- visitor dapat melihat profil publik tanpa login, tapi diminta login Google begitu mencoba aksi berbayar atau interaktif;
- akses CV dan sesi chatbot ditagih lewat `PaymentGatewayInterface` yang sudah ada, tidak pernah provider yang di-hardcode;
- penggunaan LLM dibatasi rate dan cost per node, dan fail closed (bukan ke default bersama) saat belum dikonfigurasi;
- owner dapat melihat jumlah unique-visitor dan page-view kemarin;
- booking advertiser hanya tayang setelah owner menyetujui creative-nya, dan expired otomatis di akhir periode yang dibayar.

## 6. Task yang harus dikerjakan lebih dahulu

Siklus development berikutnya harus mengerjakan task ini dalam urutan yang sama:

| Urutan | Task | Alasan didahulukan | Deliverable |
|---:|---|---|---|
| 1 | Configuration dan environment validation | Seluruh route, database, secret, dan job membutuhkan konfigurasi yang dapat diprediksi | Config loader, validation, file environment contoh yang aman |
| 2 | Front controller dan router | Belum ada application surface HTTP yang dapat dijalankan | Public entry point, `/api/v1/health`, route test |
| 3 | Error/response/request-ID layer | Seluruh endpoint membutuhkan envelope stabil dan kegagalan yang aman | JSON responder, exception mapping, log tersanitasi |
| 4 | Database layer dan migration | Identity dan content tidak aman dibangun di atas schema stub | Migration runner dan baseline schema hasil konversi |
| 5 | Perbaiki fallback factory | Fallback diam-diam dapat memilih connector atau payment behavior yang salah | Unsupported-provider exception eksplisit dan test |
| 6 | Skema users, nodes, profiles, dan token | Fondasi ownership | Migration ber-index dengan constraint |
| 7 | Slice registration dan login | Membuktikan routing, validation, persistence, dan auth secara terpadu | Endpoint register/login/logout/me dan test |
| 8 | Slice profil publik | Menghasilkan nilai produk pertama yang terlihat | API update/read profil dan UI minimal |
| 9 | Slice post lokal | Membuat node berguna secara independen | Post CRUD, visibility, canonical URL, timeline |
| 10 | Amankan external HTTP fetching | Harus selesai sebelum URL connector dapat diisi pengguna | Client tahan SSRF, timeout, limit, parser test |

Jangan mulai production payment gateway atau implementasi federasi sebelum task 1–9 stabil. Keduanya bergantung pada identity, authorization, persistence, error handling, auditability, dan state semantic yang dibentuk pada task tersebut.

## 7. Peta dependency

```text
Configuration
  └─ Router + error handling
      └─ Database + migration
          ├─ Identity + authorization
          │   ├─ Profil publik
          │   ├─ Post lokal + timeline
          │   │   └─ Agregasi eksternal
          │   │       └─ Federasi
          │   └─ Operasi admin
          └─ Produk + order
              └─ Payment + webhook
```

Operasional, security test, observability, dan dokumentasi berjalan pada setiap cabang dan tidak hanya dikerjakan pada akhir proyek.

## 8. Strategi pengujian

| Level test | Tujuan utama | Contoh |
|---|---|---|
| Unit | Aturan domain dan adapter secara terisolasi | Money, transisi status, normalisasi, factory selection |
| Integration | Perilaku database dan infrastruktur | Migration, repository, queue locking, idempotency |
| Contract/API | Perilaku HTTP sesuai OpenAPI | Auth, status code, envelope, validation, pagination |
| End-to-end | User journey kritis | Register-to-profile, publish-to-timeline, connect-to-sync, checkout-to-paid |
| Security | Abuse dan boundary failure | Authorization, SSRF, XSS, CSRF, webhook replay, kebocoran secret |
| Operasional | Perilaku recovery | Retry job gagal, backup/restore, rollback, provider outage |

Setiap bug fix harus menambahkan regression test pada level terendah yang masih efektif.

## 9. Definition of Done

Sebuah task selesai hanya jika seluruh kondisi yang relevan terpenuhi:

- acceptance criteria dapat didemonstrasikan;
- aturan authorization dan ownership diterapkan;
- input validation dan error response yang dapat diprediksi tersedia;
- unit/integration/HTTP test mencakup jalur sukses dan kegagalan penting;
- migration dan index mendukung data path;
- log berguna tanpa memuat secret atau payload sensitif;
- metrik atau operational state tersedia untuk proses async;
- dokumentasi English dan Bahasa Indonesia diperbarui;
- OpenAPI diperbarui ketika kontrak HTTP berubah;
- dampak deployment dan rollback dipahami.

## 10. Release gate dan metrik

### Release gate MVP

- journey registration-to-public-profile dan publish-to-timeline lulus;
- sinkronisasi eksternal berhasil berulang tanpa duplikasi;
- review authentication, authorization, SSRF, dan output sanitization lulus;
- prosedur migration, backup, restore, worker, dan scheduler telah diuji;
- tidak ada defect critical atau high yang belum selesai terkait ownership atau data exposure.

### Metrik produk awal

- activation rate: owner menerbitkan profil dan satu post/sumber;
- median waktu dari registrasi sampai profil publik;
- success rate first sync dan recurring sync;
- persentase timeline item dengan provenance lengkap;
- job tertua dalam queue dan retry recovery rate;
- API error rate dan p95 response latency;
- payment completion dan reconciliation rate setelah Fase 5.

## 11. Risiko utama dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Scope melebar ke identity, social, commerce, dan federation | Terapkan phase gate dan lindungi siklus content ownership pertama |
| URL eksternal menimbulkan risiko SSRF dan parser | Gunakan hardened outbound client sebelum connector dapat dikonfigurasi user |
| Perbedaan provider bocor ke business logic | Gunakan adapter melalui interface dan normalized model |
| Kegagalan async tidak terlihat | Simpan job state, attempt, next retry, error tersanitasi, dan metrik |
| Payment duplikat atau callback palsu merusak order | Idempotency key, signed webhook, deduplication, transisi transaksional |
| Federasi menimbulkan masalah abuse dan trust | Tunda sampai semantic moderasi/identity lokal stabil; tambahkan block/report |
| Dokumentasi berbeda dari implementasi | Jadikan OpenAPI dan dokumentasi bilingual bagian dari Definition of Done |

## 12. Ritme review

- Tinjau progres delivery setiap minggu berdasarkan exit criteria, bukan estimasi persentase selesai.
- Demonstrasikan satu vertical slice yang berfungsi pada akhir setiap iterasi.
- Evaluasi ulang urutan roadmap pada setiap milestone tanpa melewati dependency keamanan atau integritas data.
- Perbarui roadmap ketika scope, pilihan protokol, prioritas provider, atau kapasitas tim berubah secara material.
