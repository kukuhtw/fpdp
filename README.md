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
| HTTP entry point | Public front controller (`public/index.php`), `Router` with static and `{param}` routes, JSON success/error envelopes, sanitized exception mapping to a 500 envelope |
| Configuration | `Config` loader with defaults, optional `.env` file, real environment override, and fail-fast validation (`.env.example` provided) |
| REST handlers | `GET /api/v1/health` and the identity/profile endpoints below; other routes not yet implemented |
| Identity & authentication | Owner registration, login, logout, and `/me`; bcrypt password hashing, bearer tokens hashed at rest, node/user/profile creation on register; per-IP rate limiting on register/login (`429 RATE_LIMITED`) |
| Profiles | Public profile read by handle and authenticated profile update (`PATCH /me/profile`), with visibility rules |
| Visitor identity | Google OAuth 2.0 sign-in scoped per node/profile (`GET /profiles/{handle}/visitor-auth/google/redirect` and `.../callback`); node-scoped `visitor_accounts`, hashed-at-rest `visitor_tokens`, HMAC-signed OAuth `state` (no server-side session store) |
| Paid CV/resume access | Owner upload (`POST /me/cv`, JSON + base64 content, stored under `storage/` outside the webroot), public metadata read, visitor-paid unlock via the existing `PaymentGatewayInterface` (`POST /profiles/{handle}/cv/access`), and gated download (`GET .../cv/download`, `402 PAYMENT_REQUIRED` without a grant); replacing a CV invalidates prior grants |
| Payments | Interface, service, factory, and functional dummy gateway |
| Payment lifecycle | Dummy create, status, cancel, refund, and webhook normalization |
| External content | RSS, Atom, and Custom API connector adapters |
| Data model | Initial MySQL tables for gateways, payments, external sources/posts, and integration queue |
| Database layer | `Database` PDO connection manager and `MigrationRunner`; ordered migrations under `database/migrations/` with foreign keys and indexes, run via `database/migrate.php` |
| Deployment | Web install wizard (`public/install.php`): requirements check, `.env` writer with tested DB credentials, migration runner, owner-account creation, and a self-lock (`storage/installed.lock`); `public/.htaccess` front-controller rewrite for Apache; see the [deployment guide](documentation/DEPLOYMENT-GUIDE.en.md) |
| Tests | MVP smoke test, MVC rendering test, router test, front-controller route test, config loader/validation test, migration runner test, database connection test, factory fallback-rejection test, a full auth/profile HTTP flow test, rate limiter unit/endpoint tests, an OAuth state signer test, a full visitor-auth HTTP flow test (fake Google client), a full CV upload/paywall/download HTTP flow test, and an installer test (requirements, `.env` round-trip, connection check, install-lock) |
| API design | Bilingual API contract and OpenAPI 3.1 specification |

The following areas are **designed but not yet implemented end-to-end**:

- remaining REST handlers beyond `/health` and the identity/profile endpoints;
- admin role permissions, rate limiting, and audit-event writes (the `audit_events` table exists but nothing writes to it yet);
- local-post persistence;
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

Factories select adapters by provider code and reject unknown or not-yet-implemented codes with an explicit `UnsupportedProviderException` rather than silently falling back to another adapter. Only the dummy payment gateway and the RSS/Atom/Custom API connectors are implemented in this repository; production gateways are added to the factory's supported-code list only once their adapter class exists.

### Repository structure

```text
app/
├── Contracts/          Payment, external-provider, and Google OAuth client interfaces
├── Controllers/        MVC controllers and REST handlers (Home, Health, Auth, Profile, VisitorAuth)
├── Core/
│   ├── Http/            Request, Response, JsonEnvelope, ResourcePresenter
│   ├── Exceptions/       HttpException and its 401/404/409/422/429 subtypes
│   ├── Router.php, Config.php, Database.php, MigrationRunner.php, Uuid.php
│   ├── Installer/        RequirementsChecker, EnvWriter, InstallLock (used by public/install.php)
│   └── View.php          Minimal view rendering
├── Repositories/        Node, User, Profile, AuthToken, Visitor, VisitorToken, RateLimit data access (PDO)
├── Services/
│   ├── Auth/            AuthService: register, login, logout, token verification
│   ├── Profile/         ProfileService: public read and owner update
│   ├── Visitor/         Google OAuth client, signed-state helper, VisitorAuthService
│   ├── Security/        RateLimiter (fixed-window, per action/identifier)
│   ├── External/        RSS, Atom, and Custom API adapters
│   └── Payment/         Payment service, factory, and dummy adapter
├── Views/              PHP views
└── routes.php          Route table consumed by the front controller

public/
├── index.php           HTTP front controller (serve this directory)
├── install.php         Web install wizard: requirements check, .env, migrations, owner account
└── .htaccess           Apache front-controller rewrite (used by both index.php and install.php)

.env.example             Safe example environment file (no secrets)

database/
├── schema.sql          Reference snapshot of the current schema (see migrations for the authoritative, executable version)
├── migrations/         Ordered, repeatable SQL migrations, including nodes/users/profiles/auth_tokens/audit_events
└── migrate.php         CLI runner: applies pending migrations

documentation/
├── README.md           Bilingual documentation index
├── BRD*.md             Business requirements
├── PRD*.md             Product requirements
├── USER-JOURNEY*.md    User journeys
├── API-CONTRACT*.md    Human-readable API contracts
├── WBS-TASK*.md        Work breakdown and delivery plan
└── openapi.yaml        OpenAPI 3.1 source of truth

tests/
├── MvpSmokeTest.php         Payment and connector smoke test
├── MvcHomeTest.php          Landing-view rendering test
├── RouterTest.php           Router matching and fallback behavior
├── FrontControllerTest.php  Route wiring and health-envelope test
├── ConfigTest.php           Config defaults, .env override, and validation test
├── MigrationRunnerTest.php  Migration ordering, tracking, and idempotency test
├── DatabaseTest.php         Database connection wiring test
├── FactoryFallbackTest.php  Payment/connector factories reject unsupported codes
├── AuthEndpointsTest.php    Full HTTP flow: register, login, /me, profile read/update, logout
├── RateLimitTest.php        RateLimiter unit test: window counting and per-key isolation
├── RateLimitEndpointTest.php Login endpoint returns 429 once the configured limit is exceeded
├── OAuthStateSignerTest.php Signed OAuth state: round trip, tamper/secret/expiry rejection
├── VisitorAuthEndpointsTest.php Full visitor OAuth flow via a fake Google client: redirect, callback, reuse, 401s
├── YouTubeEmbedResolverTest.php Video-ID extraction and RSS/Atom YouTube embed normalization
└── InstallerTest.php        Requirements check, .env round-trip, connection test, and install-lock behavior
```

### Requirements

- PHP 8.2 or newer
- Composer
- PHP SimpleXML extension for RSS and Atom parsing
- PHP pdo_mysql extension (pdo_sqlite is used only by the test suite)
- MySQL 8 or newer when running migrations against a real database
- Network access when testing a real external feed

### Quick start

1. Install PHP dependencies and refresh the autoloader:

   ```bash
   composer install
   composer dump-autoload
   ```

2. Copy the example environment file (optional; sane defaults apply without it):

   ```bash
   cp .env.example .env
   ```

3. Run the available tests:

   ```bash
   php tests/MvpSmokeTest.php
   php tests/MvcHomeTest.php
   php tests/RouterTest.php
   php tests/FrontControllerTest.php
   php tests/ConfigTest.php
   php tests/MigrationRunnerTest.php
   php tests/DatabaseTest.php
   php tests/FactoryFallbackTest.php
   php tests/AuthEndpointsTest.php
   php tests/RateLimitTest.php
   php tests/RateLimitEndpointTest.php
   php tests/OAuthStateSignerTest.php
   php tests/VisitorAuthEndpointsTest.php
   php tests/YouTubeEmbedResolverTest.php
   php tests/InstallerTest.php
   ```

4. Create a MySQL database and set `DB_*` credentials in `.env`, then run migrations:

   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS fpdp"
   php database/migrate.php
   ```

   The runner tracks applied migrations in a `migrations` table, so re-running it is safe and only applies what is still pending.

5. Serve the front controller and try the identity flow with curl:

   ```bash
   php -S localhost:8080 -t public

   curl http://localhost:8080/api/v1/health

   curl -X POST http://localhost:8080/api/v1/auth/register \
     -H "Content-Type: application/json" \
     -d '{"email":"owner@example.com","password":"correct horse battery","handle":"owner","display_name":"Owner"}'

   # copy data.token.access_token from the response above
   curl http://localhost:8080/api/v1/me -H "Authorization: Bearer <token>"
   curl http://localhost:8080/api/v1/profiles/owner
   ```

`public/index.php` is the HTTP front controller; it dispatches requests through `App\Core\Router` using the route table in `app/routes.php`. `GET /`, `GET /api/v1/health`, `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`, `GET /api/v1/profiles/{handle}`, and `PATCH /api/v1/me/profile` are wired up so far — the remaining `/api/v1` operations in the [API contract](documentation/API-CONTRACT.en.md) are still design-only. Each node is created per registration, with its domain derived as `{handle}.{NODE_DOMAIN}`.

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
- [Entity Relationship Diagram — English](documentation/ERD.en.md)
- [Entity Relationship Diagram — Bahasa Indonesia](documentation/ERD.id.md)
- [Social and commerce integrations — English](documentation/SOCIAL-COMMERCE-INTEGRATIONS.en.md)
- [Integrasi sosial dan commerce — Bahasa Indonesia](documentation/SOCIAL-COMMERCE-INTEGRATIONS.id.md)
- [Social content publishing and aggregation — English](documentation/CONTENT-AGGREGATION-GUIDE.en.md)
- [Publikasi dan agregasi konten sosial — Bahasa Indonesia](documentation/CONTENT-AGGREGATION-GUIDE.id.md)
- [Federation concept — English](documentation/FEDERATION-CONCEPT.en.md)
- [Konsep federasi — Bahasa Indonesia](documentation/FEDERATION-CONCEPT.id.md)
- [Problem definition — English](documentation/PROBLEM-STATEMENT.en.md)
- [Definisi masalah — Bahasa Indonesia](documentation/PROBLEM-STATEMENT.id.md)
- [Deployment guide (VPS & shared hosting, install wizard) — English](documentation/DEPLOYMENT-GUIDE.en.md)
- [Panduan deployment (VPS & shared hosting, install wizard) — Bahasa Indonesia](documentation/DEPLOYMENT-GUIDE.id.md)

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
| HTTP entry point | Front controller publik (`public/index.php`), `Router` dengan rute statis dan `{param}`, JSON envelope sukses/error, exception mapping tersanitasi ke envelope 500 |
| Konfigurasi | `Config` loader dengan default, file `.env` opsional, override dari environment asli, dan validasi fail-fast (`.env.example` tersedia) |
| REST handler | `GET /api/v1/health` dan endpoint identity/profile di bawah; rute lain belum diimplementasikan |
| Identity & autentikasi | Registrasi owner, login, logout, dan `/me`; password hashing bcrypt, bearer token di-hash saat disimpan, pembuatan node/user/profile saat register; rate limiting per-IP di register/login (`429 RATE_LIMITED`) |
| Profil | Baca profil publik berdasarkan handle dan update profil terautentikasi (`PATCH /me/profile`), dengan aturan visibility |
| Identity visitor | Login Google OAuth 2.0 per-node/profile (`GET /profiles/{handle}/visitor-auth/google/redirect` dan `.../callback`); `visitor_accounts` yang node-scoped, `visitor_tokens` yang di-hash saat disimpan, OAuth `state` yang ditandatangani HMAC (tanpa server-side session store) |
| Akses CV/resume berbayar | Upload owner (`POST /me/cv`, JSON + konten base64, disimpan di `storage/` di luar webroot), baca metadata publik, unlock berbayar oleh visitor lewat `PaymentGatewayInterface` yang sudah ada (`POST /profiles/{handle}/cv/access`), dan download yang digerbang (`GET .../cv/download`, `402 PAYMENT_REQUIRED` tanpa grant); mengganti CV membatalkan grant lama |
| Pembayaran | Interface, service, factory, dan dummy gateway yang berfungsi |
| Siklus pembayaran | Dummy create, status, cancel, refund, dan normalisasi webhook |
| Konten eksternal | Adapter connector RSS, Atom, dan Custom API |
| Model data | Tabel MySQL awal untuk gateway, payment, sumber/post eksternal, dan integration queue |
| Database layer | `Database` PDO connection manager dan `MigrationRunner`; migration terurut di `database/migrations/` dengan foreign key dan index, dijalankan lewat `database/migrate.php` |
| Deployment | Web install wizard (`public/install.php`): cek requirement, penulis `.env` dengan kredensial DB yang sudah diuji, migration runner, pembuatan akun owner, dan self-lock (`storage/installed.lock`); rewrite front controller Apache `public/.htaccess`; lihat [panduan deployment](documentation/DEPLOYMENT-GUIDE.id.md) |
| Pengujian | MVP smoke test, test render MVC, test router, test rute front controller, test config loader/validasi, test migration runner, test koneksi database, test penolakan fallback factory, test alur auth/profile HTTP lengkap, test unit/endpoint rate limiter, test OAuth state signer, test alur visitor-auth HTTP lengkap (fake Google client), test alur upload/paywall/download CV HTTP lengkap, dan test installer (requirement, round-trip `.env`, cek koneksi, install-lock) |
| Desain API | Kontrak API bilingual dan spesifikasi OpenAPI 3.1 |

Area berikut **sudah dirancang tetapi belum diimplementasikan secara end-to-end**:

- handler REST lain di luar `/health` dan endpoint identity/profile;
- permission role admin, rate limiting, dan penulisan audit event (tabel `audit_events` sudah ada tapi belum ada yang menulis ke situ);
- persistence post lokal;
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

Factory memilih adapter berdasarkan kode provider dan menolak kode yang tidak dikenal atau belum diimplementasikan dengan `UnsupportedProviderException` yang eksplisit, bukan diam-diam fallback ke adapter lain. Hanya dummy payment gateway dan connector RSS/Atom/Custom API yang tersedia dalam repository ini; gateway production baru ditambahkan ke daftar kode yang didukung factory setelah adapter class-nya benar-benar ada.

### Struktur repository

```text
app/
├── Contracts/          Interface payment, external provider, dan Google OAuth client
├── Controllers/        Controller MVC dan REST handler (Home, Health, Auth, Profile, VisitorAuth)
├── Core/
│   ├── Http/            Request, Response, JsonEnvelope, ResourcePresenter
│   ├── Exceptions/       HttpException dan subtype 401/404/409/422/429-nya
│   ├── Router.php, Config.php, Database.php, MigrationRunner.php, Uuid.php
│   ├── Installer/        RequirementsChecker, EnvWriter, InstallLock (dipakai public/install.php)
│   └── View.php          View renderer minimal
├── Repositories/        Akses data (PDO) untuk Node, User, Profile, AuthToken, Visitor, VisitorToken, RateLimit
├── Services/
│   ├── Auth/            AuthService: register, login, logout, verifikasi token
│   ├── Profile/         ProfileService: baca publik dan update oleh owner
│   ├── Visitor/         Google OAuth client, signed-state helper, VisitorAuthService
│   ├── Security/        RateLimiter (fixed-window, per action/identifier)
│   ├── External/        Adapter RSS, Atom, dan Custom API
│   └── Payment/         Payment service, factory, dan dummy adapter
├── Views/              View PHP
└── routes.php          Tabel rute yang dipakai front controller

public/
├── index.php           Front controller HTTP (arahkan web server ke folder ini)
├── install.php         Web install wizard: cek requirement, .env, migration, akun owner
└── .htaccess           Rewrite front controller Apache (dipakai index.php maupun install.php)

.env.example             Contoh file environment yang aman (tanpa secret)

database/
├── schema.sql          Snapshot referensi skema saat ini (lihat migrations untuk versi yang otoritatif dan bisa dieksekusi)
├── migrations/         Migration SQL terurut dan repeatable, termasuk nodes/users/profiles/auth_tokens/audit_events
└── migrate.php         CLI runner: menjalankan migration yang masih pending

documentation/
├── README.md           Indeks dokumentasi bilingual
├── BRD*.md             Business requirements
├── PRD*.md             Product requirements
├── USER-JOURNEY*.md    User journey
├── API-CONTRACT*.md    Kontrak API untuk pembaca
├── WBS-TASK*.md        Work breakdown dan rencana delivery
└── openapi.yaml        Sumber utama OpenAPI 3.1

tests/
├── MvpSmokeTest.php         Smoke test payment dan connector
├── MvcHomeTest.php          Test render landing view
├── RouterTest.php           Test pencocokan rute dan fallback
├── FrontControllerTest.php  Test wiring rute dan envelope health
├── ConfigTest.php           Test default config, override .env, dan validasi
├── MigrationRunnerTest.php  Test urutan, tracking, dan idempotency migration
├── DatabaseTest.php         Test wiring koneksi database
├── FactoryFallbackTest.php  Factory payment/connector menolak kode yang tidak didukung
├── AuthEndpointsTest.php    Alur HTTP lengkap: register, login, /me, baca/update profil, logout
├── RateLimitTest.php        Test unit RateLimiter: penghitungan window dan isolasi per-key
├── RateLimitEndpointTest.php Endpoint login mengembalikan 429 setelah limit terlampaui
├── OAuthStateSignerTest.php Signed OAuth state: round trip, penolakan tamper/secret/expiry
├── VisitorAuthEndpointsTest.php Alur OAuth visitor lengkap via fake Google client: redirect, callback, reuse, 401
├── YouTubeEmbedResolverTest.php Ekstraksi video ID dan normalisasi embed YouTube dari RSS/Atom
└── InstallerTest.php        Cek requirement, round-trip .env, test koneksi, dan perilaku install-lock
```

### Kebutuhan sistem

- PHP 8.2 atau lebih baru
- Composer
- Ekstensi PHP SimpleXML untuk parsing RSS dan Atom
- Ekstensi PHP pdo_mysql (pdo_sqlite hanya dipakai oleh test suite)
- MySQL 8 atau lebih baru saat menjalankan migration ke database sungguhan
- Akses jaringan saat menguji feed eksternal nyata

### Menjalankan proyek

1. Instal dependency PHP dan perbarui autoloader:

   ```bash
   composer install
   composer dump-autoload
   ```

2. Salin contoh file environment (opsional; default yang aman tetap berlaku tanpa file ini):

   ```bash
   cp .env.example .env
   ```

3. Jalankan test yang tersedia:

   ```bash
   php tests/MvpSmokeTest.php
   php tests/MvcHomeTest.php
   php tests/RouterTest.php
   php tests/FrontControllerTest.php
   php tests/ConfigTest.php
   php tests/MigrationRunnerTest.php
   php tests/DatabaseTest.php
   php tests/FactoryFallbackTest.php
   php tests/AuthEndpointsTest.php
   php tests/RateLimitTest.php
   php tests/RateLimitEndpointTest.php
   php tests/OAuthStateSignerTest.php
   php tests/VisitorAuthEndpointsTest.php
   php tests/YouTubeEmbedResolverTest.php
   php tests/InstallerTest.php
   ```

4. Buat database MySQL, isi kredensial `DB_*` di `.env`, lalu jalankan migration:

   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS fpdp"
   php database/migrate.php
   ```

   Runner ini mencatat migration yang sudah diterapkan di tabel `migrations`, jadi menjalankannya berkali-kali aman dan hanya menerapkan yang masih pending.

5. Jalankan front controller dan coba alur identity lewat curl:

   ```bash
   php -S localhost:8080 -t public

   curl http://localhost:8080/api/v1/health

   curl -X POST http://localhost:8080/api/v1/auth/register \
     -H "Content-Type: application/json" \
     -d '{"email":"owner@example.com","password":"correct horse battery","handle":"owner","display_name":"Owner"}'

   # salin data.token.access_token dari respons di atas
   curl http://localhost:8080/api/v1/me -H "Authorization: Bearer <token>"
   curl http://localhost:8080/api/v1/profiles/owner
   ```

`public/index.php` adalah front controller HTTP; request diteruskan melalui `App\Core\Router` menggunakan tabel rute di `app/routes.php`. `GET /`, `GET /api/v1/health`, `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`, `GET /api/v1/profiles/{handle}`, dan `PATCH /api/v1/me/profile` sudah tersambung — operasi `/api/v1` lainnya pada [kontrak API](documentation/API-CONTRACT.id.md) masih sebatas desain. Setiap node dibuat per registrasi, dengan domain diturunkan sebagai `{handle}.{NODE_DOMAIN}`.

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
- [Entity Relationship Diagram — English](documentation/ERD.en.md)
- [Entity Relationship Diagram — Bahasa Indonesia](documentation/ERD.id.md)
- [Social and commerce integrations — English](documentation/SOCIAL-COMMERCE-INTEGRATIONS.en.md)
- [Integrasi sosial dan commerce — Bahasa Indonesia](documentation/SOCIAL-COMMERCE-INTEGRATIONS.id.md)
- [Social content publishing and aggregation — English](documentation/CONTENT-AGGREGATION-GUIDE.en.md)
- [Publikasi dan agregasi konten sosial — Bahasa Indonesia](documentation/CONTENT-AGGREGATION-GUIDE.id.md)
- [Federation concept — English](documentation/FEDERATION-CONCEPT.en.md)
- [Konsep federasi — Bahasa Indonesia](documentation/FEDERATION-CONCEPT.id.md)
- [Problem definition — English](documentation/PROBLEM-STATEMENT.en.md)
- [Definisi masalah — Bahasa Indonesia](documentation/PROBLEM-STATEMENT.id.md)
- [Deployment guide (VPS & shared hosting, install wizard) — English](documentation/DEPLOYMENT-GUIDE.en.md)
- [Panduan deployment (VPS & shared hosting, install wizard) — Bahasa Indonesia](documentation/DEPLOYMENT-GUIDE.id.md)

---

## License / Lisensi

No license file is currently included. Add an explicit license before distributing or accepting external contributions.

---

## Author / Penulis

- **Kukuh TW**
- Email: [kukuhtw@gmail.com](mailto:kukuhtw@gmail.com)
- Phone / WhatsApp: +62 812-9893-706
- LinkedIn: [linkedin.com/in/kukuhtw](https://linkedin.com/in/kukuhtw)

Belum ada file lisensi dalam repository ini. Tambahkan lisensi eksplisit sebelum melakukan distribusi atau menerima kontribusi eksternal.
