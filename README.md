# Federated Personal Digital Platform (FPDP)

[English](#english) · [Bahasa Indonesia](#bahasa-indonesia) · [Documentation](documentation/README.md) · [OpenAPI](documentation/openapi.yaml)

FPDP is an early-stage, provider-agnostic **personal digital home**. It is designed to let individuals operate their identity, content, external feeds, products, and payment channels from an independent node on a domain they control.

FPDP adalah **personal digital home** tahap awal yang tidak terikat pada provider tertentu. Platform ini dirancang agar individu dapat mengelola identitas, konten, feed eksternal, produk, dan saluran pembayaran dari node independen pada domain yang mereka kuasai.

> **Project status / Status proyek:** architecture prototype and API design. The repository is not yet a complete end-user application. / Prototipe arsitektur dan desain API. Repository ini belum menjadi aplikasi end-user yang lengkap.

---

## English

### Why FPDP exists

Most online identities, audiences, content, and transactions live inside centralized platforms. FPDP explores a different model: the user's own domain becomes the primary digital identity and content destination, while external networks remain connected through attributed aggregation and federation.

The intended product combines:

- a personal website and public profile;
- local publishing and a unified timeline;
- attributed RSS, Atom, and custom API content;
- federation between independently operated nodes;
- products, orders, and checkout;
- payment gateway abstraction controlled by the node operator.

### Product principles

1. **User ownership** — identity and local content originate from a user-controlled domain.
2. **Independent nodes** — every node can be operated without a mandatory central platform.
3. **Clear provenance** — local, external, and federated content retain source, provider, author, and canonical URL.
4. **Provider independence** — connectors and payment gateways sit behind common interfaces.
5. **Revocable connections** — users can connect and disconnect external sources themselves.
6. **Secure asynchronous processing** — synchronization and webhooks are designed for queues, retries, verification, and idempotency.

### Current implementation

| Area | Current state |
|---|---|
| PHP structure | Lightweight PHP 8.2+ modular structure with PSR-4 autoloading |
| MVC example | `HomeController`, view renderer, and static landing view |
| Payments | Interface, service, factory, and functional dummy gateway |
| Payment lifecycle | Dummy create, status, cancel, refund, and webhook normalization |
| External content | RSS, Atom, and Custom API connector adapters |
| Data model | Initial MySQL tables for gateways, payments, external sources/posts, and integration queue |
| Tests | MVP smoke test and MVC rendering test |
| API design | Bilingual API contract and OpenAPI 3.1 specification |

The following areas are **designed but not yet implemented end-to-end**:

- HTTP router/front controller and REST handlers;
- registration, login, sessions, bearer tokens, roles, and permissions;
- user, node, profile, and local-post persistence;
- public profile and timeline user interfaces;
- scheduler, workers, retries, and normalized feed persistence;
- federation protocol, discovery processing, and remote actors;
- products, orders, checkout, and buyer experience;
- production payment adapters, signature verification, and webhook idempotency;
- administration dashboard and operational monitoring.

### Architecture

FPDP currently uses a framework-light modular monolith approach:

```text
User or API Client
        │
        ▼
Controller / future REST handlers
        │
        ▼
Service layer
        │
        ├── PaymentGatewayInterface
        │      └── Gateway adapters
        │
        └── ExternalContentProviderInterface
               └── RSS / Atom / Custom API adapters
        │
        ▼
MySQL persistence and future async queue
```

The two main extension points are:

- `PaymentGatewayInterface`, which prevents checkout logic from depending on one gateway;
- `ExternalContentProviderInterface`, which normalizes authentication, profiles, posts, and disconnect behavior across content providers.

Factories currently select adapters by provider code. Only the dummy payment gateway is implemented in this repository; several production gateway names are reserved by the factory for future adapters.

### Repository structure

```text
app/
├── Contracts/          Shared payment and external-provider interfaces
├── Controllers/        MVC controllers
├── Core/               Minimal view rendering
├── Services/
│   ├── External/       RSS, Atom, and Custom API adapters
│   └── Payment/        Payment service, factory, and dummy adapter
└── Views/              PHP views

database/
└── schema.sql          Initial MySQL schema

documentation/
├── README.md           Bilingual documentation index
├── BRD*.md             Business requirements
├── PRD*.md             Product requirements
├── USER-JOURNEY*.md    User journeys
├── API-CONTRACT*.md    Human-readable API contracts
├── WBS-TASK*.md        Work breakdown and delivery plan
└── openapi.yaml        OpenAPI 3.1 source of truth

tests/
├── MvpSmokeTest.php    Payment and connector smoke test
└── MvcHomeTest.php     Landing-view rendering test
```

### Requirements

- PHP 8.2 or newer
- Composer
- PHP SimpleXML extension for RSS and Atom parsing
- MySQL 8 or newer when using the provided database schema
- Network access when testing a real external feed

### Quick start

1. Install PHP dependencies and refresh the autoloader:

   ```bash
   composer install
   composer dump-autoload
   ```

2. Run the available tests:

   ```bash
   php tests/MvpSmokeTest.php
   php tests/MvcHomeTest.php
   ```

3. Optionally create a MySQL database and import the initial schema:

   ```bash
   mysql -u root -p fpdp < database/schema.sql
   ```

The repository does not currently contain a public web entry point or router. `MvcHomeTest.php` demonstrates rendering the landing page directly through `HomeController`.

### Using the current services

Create a dummy payment through the provider-independent service:

```php
<?php

use App\Services\Payment\PaymentService;

$service = new PaymentService();
$payment = $service->createPayment('DUMMY', [
    'order_id' => 'ORD-1001',
    'amount' => 250000,
    'currency' => 'IDR',
    'payment_method' => 'BANK_TRANSFER',
]);
```

Fetch and normalize an RSS feed:

```php
<?php

use App\Services\External\ExternalConnectorFactory;

$connector = ExternalConnectorFactory::create('RSS', [
    'source_url' => 'https://example.com/feed.xml',
]);

$result = $connector->fetchPosts([
    'source_url' => 'https://example.com/feed.xml',
]);
```

These examples exercise adapters directly. They do not yet persist results or expose HTTP endpoints.

### API contract

The target REST API uses `/api/v1` and defines 36 operations across authentication, profiles, posts, timeline, external sources, commerce, payments, administration, and federation discovery.

- [API contract — English](documentation/API-CONTRACT.en.md)
- [API contract — Bahasa Indonesia](documentation/API-CONTRACT.id.md)
- [OpenAPI 3.1 YAML](documentation/openapi.yaml)

The OpenAPI document is a **target contract**, not a statement that every route is already available.

### Security considerations

Before production use, the implementation must include at least:

- password hashing, session hardening, token expiration, and role authorization;
- encrypted credential storage and masked configuration responses;
- outbound URL validation and SSRF protection for feed connectors;
- connection timeouts, response-size limits, safe XML parsing, and rate limits;
- payment webhook signature verification and replay protection;
- idempotency for payment creation and webhook processing;
- sanitized logs, audit trails, CSRF protection, and input validation;
- secure production secrets outside source control.

The current dummy webhook verifier intentionally returns `true` and must never be treated as production security behavior.

### Recommended implementation order

1. **Personal node foundation:** users, authentication, node/profile setup, local posts, public profile.
2. **External aggregation:** source testing, persistence, normalization, queue, retry, attribution, disconnect.
3. **Commerce:** products, immutable order totals, one production payment adapter, verified idempotent webhooks.
4. **Federation:** discovery, remote actors, activities, moderation, and failure recovery.
5. **Operations:** administration UI, health monitoring, auditing, deployment, and security hardening.

The first practical end-to-end journey is:

```text
Owner login → Complete profile → Create local post → Connect RSS
→ Preview and sync → View attributed timeline → Open public profile
```

### Documentation

Start with the [documentation index](documentation/README.md). Product requirements, user journeys, the development roadmap, the WBS, and API contracts are available in English and Bahasa Indonesia.

- [Development roadmap and strategy — English](documentation/ROADMAP.en.md)
- [Roadmap dan strategi pengembangan — Bahasa Indonesia](documentation/ROADMAP.id.md)
- [Interactive dashboard and public-profile mockup](documentation/mockup/README.md)

---

## Bahasa Indonesia

### Mengapa FPDP dibuat

Sebagian besar identitas online, audience, konten, dan transaksi saat ini berada di dalam platform terpusat. FPDP mengeksplorasi model berbeda: domain milik pengguna menjadi identitas digital dan tujuan konten utama, sementara jaringan eksternal tetap terhubung melalui agregasi beratribusi dan federasi.

Produk yang dituju menggabungkan:

- website personal dan profil publik;
- publikasi konten lokal dan timeline terpadu;
- konten RSS, Atom, dan Custom API dengan atribusi;
- federasi antar-node yang dikelola secara independen;
- produk, order, dan checkout;
- abstraksi payment gateway yang dikendalikan operator node.

### Prinsip produk

1. **Kepemilikan pengguna** — identitas dan konten lokal berasal dari domain yang dikuasai pengguna.
2. **Node independen** — setiap node dapat beroperasi tanpa platform pusat yang diwajibkan.
3. **Asal konten yang jelas** — konten lokal, eksternal, dan federasi mempertahankan sumber, provider, author, dan canonical URL.
4. **Tidak terikat provider** — connector dan payment gateway berada di balik interface bersama.
5. **Koneksi dapat dicabut** — pengguna dapat menghubungkan dan memutus sumber eksternal secara mandiri.
6. **Pemrosesan async yang aman** — sinkronisasi dan webhook dirancang untuk queue, retry, verifikasi, dan idempotency.

### Implementasi saat ini

| Area | Kondisi saat ini |
|---|---|
| Struktur PHP | Struktur modular ringan berbasis PHP 8.2+ dengan autoload PSR-4 |
| Contoh MVC | `HomeController`, view renderer, dan landing view statis |
| Pembayaran | Interface, service, factory, dan dummy gateway yang berfungsi |
| Siklus pembayaran | Dummy create, status, cancel, refund, dan normalisasi webhook |
| Konten eksternal | Adapter connector RSS, Atom, dan Custom API |
| Model data | Tabel MySQL awal untuk gateway, payment, sumber/post eksternal, dan integration queue |
| Pengujian | MVP smoke test dan test render MVC |
| Desain API | Kontrak API bilingual dan spesifikasi OpenAPI 3.1 |

Area berikut **sudah dirancang tetapi belum diimplementasikan secara end-to-end**:

- router/front controller HTTP dan handler REST;
- registrasi, login, session, bearer token, role, dan permission;
- persistence user, node, profil, dan post lokal;
- UI profil publik dan timeline;
- scheduler, worker, retry, dan penyimpanan feed yang sudah dinormalisasi;
- protokol federasi, pemrosesan discovery, dan remote actor;
- produk, order, checkout, dan pengalaman buyer;
- adapter pembayaran production, verifikasi signature, dan idempotency webhook;
- dashboard administrasi dan monitoring operasional.

### Arsitektur

FPDP saat ini memakai pendekatan modular monolith tanpa framework berat:

```text
User atau API Client
        │
        ▼
Controller / REST handler mendatang
        │
        ▼
Service layer
        │
        ├── PaymentGatewayInterface
        │      └── Adapter gateway
        │
        └── ExternalContentProviderInterface
               └── Adapter RSS / Atom / Custom API
        │
        ▼
Persistence MySQL dan async queue mendatang
```

Dua extension point utamanya adalah:

- `PaymentGatewayInterface`, agar logika checkout tidak bergantung pada satu gateway;
- `ExternalContentProviderInterface`, untuk menormalisasi autentikasi, profil, post, dan proses disconnect antar-provider konten.

Factory saat ini memilih adapter berdasarkan kode provider. Hanya dummy payment gateway yang tersedia dalam repository ini; beberapa nama gateway production sudah dicadangkan oleh factory untuk adapter mendatang.

### Struktur repository

```text
app/
├── Contracts/          Interface payment dan external provider
├── Controllers/        Controller MVC
├── Core/               View renderer minimal
├── Services/
│   ├── External/       Adapter RSS, Atom, dan Custom API
│   └── Payment/        Payment service, factory, dan dummy adapter
└── Views/              View PHP

database/
└── schema.sql          Skema awal MySQL

documentation/
├── README.md           Indeks dokumentasi bilingual
├── BRD*.md             Business requirements
├── PRD*.md             Product requirements
├── USER-JOURNEY*.md    User journey
├── API-CONTRACT*.md    Kontrak API untuk pembaca
├── WBS-TASK*.md        Work breakdown dan rencana delivery
└── openapi.yaml        Sumber utama OpenAPI 3.1

tests/
├── MvpSmokeTest.php    Smoke test payment dan connector
└── MvcHomeTest.php     Test render landing view
```

### Kebutuhan sistem

- PHP 8.2 atau lebih baru
- Composer
- Ekstensi PHP SimpleXML untuk parsing RSS dan Atom
- MySQL 8 atau lebih baru jika memakai skema database yang tersedia
- Akses jaringan saat menguji feed eksternal nyata

### Menjalankan proyek

1. Instal dependency PHP dan perbarui autoloader:

   ```bash
   composer install
   composer dump-autoload
   ```

2. Jalankan test yang tersedia:

   ```bash
   php tests/MvpSmokeTest.php
   php tests/MvcHomeTest.php
   ```

3. Opsional: buat database MySQL dan impor skema awal:

   ```bash
   mysql -u root -p fpdp < database/schema.sql
   ```

Repository saat ini belum memiliki public web entry point atau router. `MvcHomeTest.php` menunjukkan proses render landing page secara langsung melalui `HomeController`.

### Menggunakan service yang tersedia

Membuat dummy payment melalui service yang tidak terikat provider:

```php
<?php

use App\Services\Payment\PaymentService;

$service = new PaymentService();
$payment = $service->createPayment('DUMMY', [
    'order_id' => 'ORD-1001',
    'amount' => 250000,
    'currency' => 'IDR',
    'payment_method' => 'BANK_TRANSFER',
]);
```

Mengambil dan menormalisasi RSS feed:

```php
<?php

use App\Services\External\ExternalConnectorFactory;

$connector = ExternalConnectorFactory::create('RSS', [
    'source_url' => 'https://example.com/feed.xml',
]);

$result = $connector->fetchPosts([
    'source_url' => 'https://example.com/feed.xml',
]);
```

Contoh tersebut menggunakan adapter secara langsung. Hasilnya belum disimpan ke database atau diekspos melalui endpoint HTTP.

### Kontrak API

Target REST API menggunakan `/api/v1` dan mendefinisikan 36 operasi untuk autentikasi, profil, post, timeline, sumber eksternal, commerce, pembayaran, administrasi, dan federation discovery.

- [Kontrak API — Bahasa Indonesia](documentation/API-CONTRACT.id.md)
- [API contract — English](documentation/API-CONTRACT.en.md)
- [OpenAPI 3.1 YAML](documentation/openapi.yaml)

Dokumen OpenAPI adalah **kontrak target**, bukan pernyataan bahwa seluruh route sudah tersedia.

### Pertimbangan keamanan

Sebelum dipakai pada production, implementasi minimal harus memiliki:

- password hashing, session hardening, kedaluwarsa token, dan otorisasi berbasis role;
- penyimpanan credential terenkripsi dan respons konfigurasi yang dimasking;
- validasi URL keluar dan proteksi SSRF untuk feed connector;
- connection timeout, batas ukuran response, parsing XML aman, dan rate limit;
- verifikasi signature webhook pembayaran dan replay protection;
- idempotency untuk pembuatan pembayaran dan pemrosesan webhook;
- log tersanitasi, audit trail, perlindungan CSRF, dan validasi input;
- secret production yang disimpan di luar source control.

Verifier webhook pada dummy gateway saat ini sengaja selalu menghasilkan `true` dan tidak boleh dianggap sebagai perilaku keamanan untuk production.

### Urutan implementasi yang disarankan

1. **Fondasi personal node:** user, autentikasi, setup node/profil, post lokal, profil publik.
2. **Agregasi eksternal:** pengujian sumber, persistence, normalisasi, queue, retry, atribusi, disconnect.
3. **Commerce:** produk, total order immutable, satu adapter payment production, webhook terverifikasi dan idempotent.
4. **Federasi:** discovery, remote actor, activity, moderasi, dan pemulihan kegagalan.
5. **Operasional:** UI administrasi, health monitoring, audit, deployment, dan security hardening.

Journey end-to-end pertama yang paling praktis:

```text
Owner login → Lengkapi profil → Buat post lokal → Hubungkan RSS
→ Preview dan sync → Lihat timeline beratribusi → Buka profil publik
```

### Dokumentasi

Mulai dari [indeks dokumentasi](documentation/README.md). Product requirements, user journey, roadmap pengembangan, WBS, dan kontrak API tersedia dalam English dan Bahasa Indonesia.

- [Development roadmap and strategy — English](documentation/ROADMAP.en.md)
- [Roadmap dan strategi pengembangan — Bahasa Indonesia](documentation/ROADMAP.id.md)
- [Mockup interaktif dashboard dan profil publik](documentation/mockup/README.md)

---

## License / Lisensi

No license file is currently included. Add an explicit license before distributing or accepting external contributions.

Belum ada file lisensi dalam repository ini. Tambahkan lisensi eksplisit sebelum melakukan distribusi atau menerima kontribusi eksternal.
