# Federated Personal Digital Platform (FPDP)

[English](#english) · [Bahasa Indonesia](#bahasa-indonesia) · [Documentation](documentation/README.md) · [OpenAPI](documentation/openapi.yaml)

FPDP is an early-stage, provider-agnostic **personal digital home**. It is designed to let individuals operate their identity, content, external feeds, products, and payment channels from an independent node on a domain they control.

FPDP adalah **personal digital home** tahap awal yang tidak terikat pada provider tertentu. Platform ini dirancang agar individu dapat mengelola identitas, konten, feed eksternal, produk, dan saluran pembayaran dari node independen pada domain yang mereka kuasai.

> **Project status / Status proyek:** architecture prototype and API design. The repository is not yet a complete end-user application. / Prototipe arsitektur dan desain API. Repository ini belum menjadi aplikasi end-user yang lengkap.

---

## English

### Overview

**FPDP, Federated Personal Digital Platform, is an open architecture for building a personal digital home that you own and control.**

Instead of placing identity, content, audience, commerce, and digital activity inside a single centralized platform, FPDP allows each person to operate their own independent node on a domain and hosting environment they control.

A personal FPDP node can function as a website, social profile, professional identity, publishing platform, portfolio, marketplace, content aggregator, and payment-enabled digital presence.

The key idea is simple:

**your domain becomes your digital home.**

Your profile, posts, products, media, and local application data remain under your control, while federation allows independent FPDP nodes to discover and interact with each other across the internet.

A user on one FPDP node can eventually follow, communicate, exchange content, and interact with users hosted on completely different servers without requiring all users to belong to the same central platform.

```text
alice.id
   │
   │ Federation
   ▼
bob.social
   │
   │ Federation
   ▼
charlie.me
```

Each node maintains its own application and database.

```text
alice.id
App + Database A

bob.social
App + Database B

charlie.me
App + Database C
```

There is no requirement for a single central user database or central content database.

FPDP is designed around the principle that **federation should connect independent digital identities rather than centralize them.**

#### A Personal Digital Home

FPDP is not intended to be only another social networking application.

It is designed as a foundation for a broader personal digital presence.

A single FPDP installation can progressively provide:

```text
Personal Website
+
Social Profile
+
Professional Profile
+
Blog and Publishing
+
Photo and Video Content
+
Portfolio
+
Federated Social Network
+
Marketplace
+
Payment-enabled Commerce
+
External Content Aggregation
+
Digital Identity
```

For example, a user could operate:

```text
https://andi.id
```

and use it as the primary destination for:

```text
/@andi
/posts
/articles
/photos
/videos
/portfolio
/products
/connections
```

Instead of treating third-party platforms as the permanent home of a user's digital identity, FPDP treats them as connected channels.

A user may connect content from services such as social networks, marketplaces, blogs, RSS feeds, or external APIs while preserving clear attribution and canonical links to the original source.

Conceptually:

```text
Instagram
LinkedIn
TikTok
X
Threads
YouTube
RSS
External APIs
Marketplace Platforms
       │
       ▼
 External Connectors
       │
       ▼
      FPDP
       │
       ▼
 Personal Digital Home
```

#### Federation by Design

The long-term goal of FPDP is to allow independently operated personal nodes to participate in a shared network.

Instead of:

```text
Millions of Users
       │
       ▼
One Platform
       │
       ▼
One Central Database
```

FPDP explores a different model:

```text
Person A Node
      ↕
Person B Node
      ↕
Person C Node
      ↕
Person D Node
```

Every node remains independently controlled while federation provides interoperability.

This model allows local content to remain authoritative on the node where it was created.

Remote nodes may cache, reference, or synchronize permitted information, but they do not become the authoritative owner of that data.

#### Provider Independent by Default

FPDP is designed to avoid unnecessary provider lock-in.

Payment processing uses a provider-independent abstraction so a node operator can eventually choose services such as:

```text
Midtrans
Xendit
DOKU
iPaymu
Nicepay
Paywuz
Stripe
PayPal
or another compatible provider
```

The application communicates through a common payment interface rather than embedding one provider directly into marketplace logic.

The same principle applies to external content integrations.

Connectors are designed behind a common abstraction so new sources can be added without rewriting the core application.

Examples may include:

```text
RSS
Atom
Custom REST APIs
Instagram
Facebook
LinkedIn
TikTok
X
Threads
YouTube
Shopee
other external platforms
```

#### Local, Federated, and External Content

FPDP distinguishes content by origin.

Content may be:

```text
LOCAL
created and owned by the current node

FEDERATED
originating from another independent FPDP node

EXTERNAL
retrieved from a third-party platform or feed
```

This distinction is important because ownership and provenance must remain clear.

External and federated content should retain information such as:

```text
source
provider
original author
canonical URL
publication time
origin domain
```

This allows FPDP to create a unified digital experience without pretending that all content originated locally.

#### Commerce Without Payment Lock-in

FPDP also explores decentralized commerce.

A node may publish its own products and accept orders while selecting its preferred payment infrastructure.

Conceptually:

```text
Product
   ↓
Order
   ↓
Checkout
   ↓
Payment Service
   ↓
Payment Gateway Interface
   ↓
Selected Payment Provider
```

The marketplace layer should not need to know whether payment is processed by Midtrans, Xendit, DOKU, Paywuz, or another provider.

This makes payment infrastructure a configurable component of the user's digital node rather than a permanent dependency of the platform.

#### What "marketplace" means in FPDP

In FPDP, "marketplace" does **not** mean a multi-seller platform like Tokopedia or Shopee. Each node has a **personal online shop owned by the website owner**: one seller per node, selling their own products to visitors.

What makes it different from an ordinary web shop is federation:

- **Visitors buy directly on the node.** They browse `/shop`, check out, and pay through whichever gateway the owner activated.
- **Product posts spread to the fediverse.** A product marked as promoted is published as an ActivityPub post, so followers on Mastodon and other FPDP nodes see it in their timelines.
- **Federated commerce (cross-node orders) is the key differentiator.** The goal is for a buyer on another node or fediverse server to order and pay for a product they saw in their timeline. The seller node stays the source of truth for price, stock, and order status, and payment always happens on the seller node. This is planned as its own roadmap phase (Phase 7) — see the [roadmap](documentation/ROADMAP.en.md).

What is implemented today:

| Part | Status | Where |
|---|---|---|
| Products (physical and digital, with photos) and a public shop | Done | `/shop`, `/shop/{id}`, `/dashboard/products` |
| Public visitor checkout with buyer details and shipping address | Done | `POST /api/v1/profiles/{handle}/orders` |
| Payment through the owner's active gateway (Dummy, Paywuz, Midtrans, PayPal, iPaymu, Manual Transfer) | Done | Settings → Payments |
| Payment confirmation page, manual confirmation by the owner, webhooks | Done | `/payment/thank-you`, `/dashboard/payments` |
| Digital-product download after payment | Done | `GET /api/v1/products/{id}/download` |
| Owner cancel and refund (full or partial, through the gateway or recorded manually), with the effect undone on a full refund | Done | `/dashboard/payments`, `POST /api/v1/me/payments/{uuid}/cancel` and `/refund` |
| Payment reconciliation against the provider (missed webhooks, paid-after-cancel) | Done | `scripts/reconcile-payments.php`, `POST /api/v1/me/payments/reconcile` |
| Promoted products federated to the fediverse | Done | `is_promoted` on a product |
| Cross-node orders, payment, and order status sent back to the buyer node | Not started | Roadmap Phase 7 |

The word "marketplace" also appears in three other senses in the documentation, none of which is a multi-seller shop: **external marketplaces** as content sources (for example a future Shopee connector), the **ad marketplace** (owners selling banner slots on their node), and the **plugin marketplace** (a future registry of adapters).

#### Open Architecture

FPDP currently follows a framework-light modular monolith approach using PHP and MySQL.

The architecture emphasizes:

```text
independent deployment

clear service boundaries

provider abstractions

federation readiness

asynchronous integration

data provenance

extensibility

security

operational simplicity
```

The project intentionally starts with a modular monolith rather than microservices so that a personal node can remain relatively simple to deploy on:

```text
shared hosting
VPS
Docker
cloud infrastructure
```

while still allowing individual modules to evolve over time.

#### Project Direction

FPDP is currently an evolving architecture and implementation prototype.

The initial goal is not to recreate every feature of existing social networks or marketplaces.

The first important milestone is proving that independent nodes can operate successfully while retaining ownership of their own identity and data.

A practical end-to-end vision is:

```text
Install FPDP on your own domain
        ↓
Create your personal identity
        ↓
Publish local content
        ↓
Connect external feeds
        ↓
Discover another FPDP node
        ↓
Follow a remote identity
        ↓
Exchange signed federated activities
        ↓
Display local, federated, and external content
        ↓
Publish products
        ↓
Accept orders
        ↓
Use the payment gateway of your choice
```

If this model works reliably across independent domains and independent databases, FPDP can grow from a personal website platform into an interoperable digital network.

#### Vision

The broader vision of FPDP is to make a person's own domain the center of their digital presence.

Not:

```text
Your profile belongs to a platform.
```

But:

```text
Your profile lives on your domain.
```

Not:

```text
Your audience belongs to a platform.
```

But:

```text
Your relationships can exist across an open network.
```

Not:

```text
One company owns the network.
```

But:

```text
Independent nodes form the network.
```

FPDP explores a future where:

> **Your domain is your digital home.
> Your database contains your primary data.
> External platforms become connected channels.
> Payment providers remain your choice.
> Federation connects independent identities.
> The internet becomes the network.**

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

> **This table is a quick orientation snapshot, not the source of truth.** For the current, verified state of every workstream (identity, content, external aggregation, marketplace, payments, federation, dashboard, analytics, deployment) — what is done, partial, or not started, with evidence from the actual routes/controllers/services/tests — see the [progress report](documentation/PROGRESS-REPORT.en.md) / [laporan progres](documentation/PROGRESS-REPORT.id.md), refreshed each time a workstream materially changes.

| Area | Current state |
|---|---|
| PHP structure | Lightweight PHP 8.2+ modular structure with PSR-4 autoloading |
| Web UI | Home page (`/`, a live personal digital home for the node owner once one exists), local timeline, public profile (`/@{handle}`), public post page, post editor with direct media upload (`/dashboard/posts`), and an owner dashboard Overview (`/dashboard`) |
| Personal online shop | Products, public shop, visitor checkout, digital downloads, and promoted products federated to the fediverse — see [What "marketplace" means in FPDP](#what-marketplace-means-in-fpdp) |
| Payments | `PaymentGatewayInterface` with Dummy, Paywuz, Midtrans, and PayPal (Orders API v2) adapters plus iPaymu and Manual Transfer plugins; active-gateway selection in Settings; owner confirm, cancel, and refund; reconciliation against the provider |
| Federation | Real, interoperable ActivityPub: WebFinger, RSA keys, HTTP Signatures (signed inbound verification and outbound delivery), content-negotiated Actor/outbox/followers/following documents, Follow/Accept/Reject/Undo/Block, and bidirectional post and promoted-product syndication merged into a unified local timeline — live-verified against real Mastodon instances (`mastodon.social`, `mastodon.world`) |
| Tests | 49 test scripts covering identity, content, payments (every gateway, refund, cancel, reconciliation), media upload, federation (ActivityPub discovery, signatures, inbox, outbox, mutual follows), marketplace, analytics, deployment config, and the installer — see `tests/` |
| API design | Bilingual API contract and OpenAPI 3.1 specification |

For everything else — REST handler coverage, federation protocol depth, commerce/checkout completeness, dashboard panels beyond Overview, and known gaps — see the progress report linked above rather than this table.

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
4. **Federation:** ActivityPub-compatible discovery, remote actors, signed activity delivery, and bidirectional Follow/post/product syndication are implemented and interoperate with real Mastodon instances; block/mute/report moderation tooling and deeper failure-recovery hardening are still in progress.
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
- [Value proposition and professional copywriting — English](documentation/VALUE-PROPOSITION.en.md)
- [Narasi manfaat dan copywriting profesional — Bahasa Indonesia](documentation/VALUE-PROPOSITION.id.md)
- [Deployment guide (VPS & shared hosting, install wizard) — English](documentation/DEPLOYMENT-GUIDE.en.md)
- [Panduan deployment (VPS & shared hosting, install wizard) — Bahasa Indonesia](documentation/DEPLOYMENT-GUIDE.id.md)
- [Dokploy deployment — English](documentation/DOKPLOY-DEPLOYMENT.en.md)
- [Deployment Dokploy — Bahasa Indonesia](documentation/DOKPLOY-DEPLOYMENT.id.md)

---

## Bahasa Indonesia

### Ringkasan

**FPDP, Federated Personal Digital Platform (Platform Digital Personal Terfederasi), adalah arsitektur terbuka untuk membangun rumah digital personal yang sepenuhnya Anda miliki dan kendalikan.**

Alih-alih menempatkan identitas, konten, audience, komersial, dan aktivitas digital di dalam satu platform terpusat, FPDP memungkinkan setiap orang menjalankan node independen miliknya sendiri pada domain dan lingkungan hosting yang mereka kendalikan.

Sebuah node FPDP personal dapat berfungsi sebagai website, profil sosial, identitas profesional, platform publikasi, portofolio, marketplace, agregator konten, dan kehadiran digital yang mendukung pembayaran.

Gagasan utamanya sederhana:

**domain Anda menjadi rumah digital Anda.**

Profil, post, produk, media, dan data aplikasi lokal Anda tetap berada di bawah kendali Anda, sementara federasi memungkinkan node-node FPDP yang independen untuk saling menemukan dan berinteraksi lintas internet.

Pengguna pada satu node FPDP pada akhirnya dapat mem-follow, berkomunikasi, bertukar konten, dan berinteraksi dengan pengguna yang di-hosting di server yang sepenuhnya berbeda, tanpa mengharuskan semua pengguna berada dalam platform pusat yang sama.

```text
alice.id
   │
   │ Federasi
   ▼
bob.social
   │
   │ Federasi
   ▼
charlie.me
```

Setiap node menjalankan aplikasi dan database miliknya sendiri.

```text
alice.id
Aplikasi + Database A

bob.social
Aplikasi + Database B

charlie.me
Aplikasi + Database C
```

Tidak ada keharusan untuk memiliki satu database pengguna pusat atau database konten pusat.

FPDP dirancang berdasarkan prinsip bahwa **federasi seharusnya menghubungkan identitas digital yang independen, bukan memusatkannya.**

#### Rumah Digital Personal

FPDP tidak dimaksudkan hanya sebagai aplikasi jejaring sosial lainnya.

FPDP dirancang sebagai fondasi untuk kehadiran digital personal yang lebih luas.

Satu instalasi FPDP secara bertahap dapat menyediakan:

```text
Website Personal
+
Profil Sosial
+
Profil Profesional
+
Blog dan Publikasi
+
Konten Foto dan Video
+
Portofolio
+
Jejaring Sosial Terfederasi
+
Marketplace
+
Komersial dengan Pembayaran
+
Agregasi Konten Eksternal
+
Identitas Digital
```

Sebagai contoh, seorang pengguna dapat menjalankan:

```text
https://andi.id
```

dan menggunakannya sebagai tujuan utama untuk:

```text
/@andi
/posts
/articles
/photos
/videos
/portfolio
/products
/connections
```

Alih-alih memperlakukan platform pihak ketiga sebagai rumah permanen identitas digital pengguna, FPDP memperlakukannya sebagai kanal yang terhubung.

Pengguna dapat menghubungkan konten dari layanan seperti jejaring sosial, marketplace, blog, RSS feed, atau API eksternal sembari tetap menjaga atribusi yang jelas dan tautan kanonik ke sumber aslinya.

Secara konsep:

```text
Instagram
LinkedIn
TikTok
X
Threads
YouTube
RSS
API Eksternal
Platform Marketplace
       │
       ▼
 Connector Eksternal
       │
       ▼
      FPDP
       │
       ▼
 Rumah Digital Personal
```

#### Federasi Sejak Awal Desain

Tujuan jangka panjang FPDP adalah memungkinkan node personal yang dioperasikan secara independen untuk berpartisipasi dalam satu jaringan bersama.

Alih-alih:

```text
Jutaan Pengguna
       │
       ▼
Satu Platform
       │
       ▼
Satu Database Pusat
```

FPDP mengeksplorasi model yang berbeda:

```text
Node Orang A
      ↕
Node Orang B
      ↕
Node Orang C
      ↕
Node Orang D
```

Setiap node tetap dikendalikan secara independen, sementara federasi menyediakan interoperabilitas.

Model ini memungkinkan konten lokal tetap otoritatif pada node tempat konten itu dibuat.

Node remote dapat meng-cache, mereferensikan, atau menyinkronkan informasi yang diizinkan, tetapi mereka tidak menjadi pemilik otoritatif atas data tersebut.

#### Independen dari Provider secara Default

FPDP dirancang untuk menghindari ketergantungan yang tidak perlu pada satu provider (provider lock-in).

Pemrosesan pembayaran menggunakan abstraksi yang independen terhadap provider, sehingga operator node pada akhirnya dapat memilih layanan seperti:

```text
Midtrans
Xendit
DOKU
iPaymu
Nicepay
Paywuz
Stripe
PayPal
atau provider lain yang kompatibel
```

Aplikasi berkomunikasi melalui satu interface pembayaran yang umum, alih-alih menanamkan satu provider secara langsung ke dalam logika marketplace.

Prinsip yang sama berlaku untuk integrasi konten eksternal.

Connector dirancang di balik satu abstraksi umum sehingga sumber baru dapat ditambahkan tanpa menulis ulang aplikasi inti.

Contohnya dapat mencakup:

```text
RSS
Atom
Custom REST API
Instagram
Facebook
LinkedIn
TikTok
X
Threads
YouTube
Shopee
platform eksternal lainnya
```

#### Konten Lokal, Terfederasi, dan Eksternal

FPDP membedakan konten berdasarkan asalnya.

Konten dapat berupa:

```text
LOCAL
dibuat dan dimiliki oleh node saat ini

FEDERATED
berasal dari node FPDP independen lainnya

EXTERNAL
diambil dari platform pihak ketiga atau feed
```

Perbedaan ini penting karena kepemilikan dan asal-usul (provenance) harus tetap jelas.

Konten eksternal dan terfederasi harus tetap menyimpan informasi seperti:

```text
sumber
provider
penulis asli
URL kanonik
waktu publikasi
domain asal
```

Hal ini memungkinkan FPDP menciptakan pengalaman digital yang terpadu tanpa berpura-pura bahwa semua konten berasal dari lokal.

#### Komersial Tanpa Terkunci pada Satu Payment Provider

FPDP juga mengeksplorasi komersial (commerce) yang terdesentralisasi.

Sebuah node dapat memublikasikan produknya sendiri dan menerima order sembari memilih infrastruktur pembayaran yang disukainya.

Secara konsep:

```text
Produk
   ↓
Order
   ↓
Checkout
   ↓
Payment Service
   ↓
Payment Gateway Interface
   ↓
Provider Pembayaran Terpilih
```

Lapisan marketplace tidak perlu tahu apakah pembayaran diproses oleh Midtrans, Xendit, DOKU, Paywuz, atau provider lainnya.

Hal ini menjadikan infrastruktur pembayaran sebagai komponen yang dapat dikonfigurasi pada node digital pengguna, alih-alih menjadi ketergantungan permanen dari platform.

#### Arti "marketplace" di FPDP

Di FPDP, "marketplace" **bukan** platform multi-penjual seperti Tokopedia atau Shopee. Setiap node memiliki **toko online pribadi milik pemilik website**: satu penjual per node, yang menjual produknya sendiri kepada pengunjung.

Yang membedakannya dari toko web biasa adalah federasi:

- **Pengunjung membeli langsung di node.** Mereka melihat `/shop`, checkout, lalu membayar lewat gateway yang diaktifkan owner.
- **Posting produk tersebar ke fediverse.** Produk yang ditandai promosi dipublikasikan sebagai post ActivityPub, sehingga follower di Mastodon dan node FPDP lain melihatnya di timeline mereka.
- **Federated commerce (order lintas node) adalah keunggulan utama.** Tujuannya, pembeli di node atau server fediverse lain dapat memesan dan membayar produk yang mereka lihat di timeline. Node penjual tetap menjadi sumber kebenaran untuk harga, stok, dan status order, dan pembayaran selalu terjadi di node penjual. Ini direncanakan sebagai fase roadmap tersendiri (Fase 7) — lihat [roadmap](documentation/ROADMAP.id.md).

Yang sudah diimplementasikan:

| Bagian | Status | Lokasi |
|---|---|---|
| Produk (fisik dan digital, dengan foto) dan toko publik | Selesai | `/shop`, `/shop/{id}`, `/dashboard/products` |
| Checkout visitor publik dengan data pembeli dan alamat pengiriman | Selesai | `POST /api/v1/profiles/{handle}/orders` |
| Pembayaran lewat gateway aktif owner (Dummy, Paywuz, Midtrans, PayPal, iPaymu, Transfer Manual) | Selesai | Settings → Payments |
| Halaman konfirmasi pembayaran, konfirmasi manual oleh owner, webhook | Selesai | `/payment/thank-you`, `/dashboard/payments` |
| Download produk digital setelah pembayaran | Selesai | `GET /api/v1/products/{id}/download` |
| Pembatalan dan refund oleh owner (penuh atau sebagian, lewat gateway atau dicatat manual), dengan efeknya dibatalkan saat refund penuh | Selesai | `/dashboard/payments`, `POST /api/v1/me/payments/{uuid}/cancel` dan `/refund` |
| Rekonsiliasi pembayaran dengan provider (webhook terlewat, dibayar setelah dibatalkan) | Selesai | `scripts/reconcile-payments.php`, `POST /api/v1/me/payments/reconcile` |
| Produk promosi difederasikan ke fediverse | Selesai | `is_promoted` pada produk |
| Order lintas node, pembayaran, dan status order dikirim balik ke node pembeli | Belum dimulai | Roadmap Fase 7 |

Kata "marketplace" juga muncul dengan tiga arti lain di dokumentasi, dan tidak satu pun berarti toko multi-penjual: **marketplace eksternal** sebagai sumber konten (misalnya connector Shopee di masa depan), **marketplace iklan** (owner menjual slot banner di node-nya), dan **marketplace plugin** (registry adapter di masa depan).

#### Arsitektur Terbuka

FPDP saat ini mengikuti pendekatan modular monolith tanpa framework berat, menggunakan PHP dan MySQL.

Arsitektur ini menekankan pada:

```text
deployment independen

batas service yang jelas

abstraksi provider

kesiapan federasi

integrasi asinkron

provenance data

extensibility

keamanan

kesederhanaan operasional
```

Proyek ini sengaja dimulai dengan modular monolith, bukan microservices, agar node personal tetap relatif sederhana untuk di-deploy pada:

```text
shared hosting
VPS
Docker
infrastruktur cloud
```

sembari tetap memungkinkan setiap modul berkembang seiring waktu.

#### Arah Proyek

FPDP saat ini merupakan arsitektur dan prototipe implementasi yang terus berkembang.

Tujuan awalnya bukan untuk menduplikasi seluruh fitur jejaring sosial atau marketplace yang sudah ada.

Milestone penting pertama adalah membuktikan bahwa node-node independen dapat beroperasi dengan sukses sembari tetap mempertahankan kepemilikan atas identitas dan datanya sendiri.

Visi end-to-end yang praktis adalah:

```text
Instal FPDP pada domain Anda sendiri
        ↓
Buat identitas personal Anda
        ↓
Publikasikan konten lokal
        ↓
Hubungkan feed eksternal
        ↓
Temukan node FPDP lain
        ↓
Follow identitas remote
        ↓
Bertukar signed federated activity
        ↓
Tampilkan konten lokal, terfederasi, dan eksternal
        ↓
Publikasikan produk
        ↓
Terima order
        ↓
Gunakan payment gateway pilihan Anda
```

Jika model ini bekerja secara andal lintas domain independen dan database independen, FPDP dapat berkembang dari platform website personal menjadi jaringan digital yang interoperable.

#### Visi

Visi besar FPDP adalah menjadikan domain milik seseorang sebagai pusat dari kehadiran digitalnya.

Bukan:

```text
Profil Anda dimiliki oleh sebuah platform.
```

Melainkan:

```text
Profil Anda hidup di domain Anda sendiri.
```

Bukan:

```text
Audience Anda dimiliki oleh sebuah platform.
```

Melainkan:

```text
Relasi Anda dapat eksis lintas jaringan terbuka.
```

Bukan:

```text
Satu perusahaan memiliki jaringannya.
```

Melainkan:

```text
Node-node independen membentuk jaringan.
```

FPDP mengeksplorasi masa depan di mana:

> **Domain Anda adalah rumah digital Anda.
> Database Anda berisi data utama Anda.
> Platform eksternal menjadi kanal yang terhubung.
> Provider pembayaran tetap menjadi pilihan Anda.
> Federasi menghubungkan identitas-identitas independen.
> Internet menjadi jaringannya.**

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

> **Tabel ini snapshot orientasi cepat, bukan sumber kebenaran.** Untuk kondisi terkini dan terverifikasi dari setiap workstream (identity, konten, agregasi eksternal, marketplace, payment, federasi, dashboard, analytics, deployment) — mana yang selesai, sebagian, atau belum dimulai, dengan bukti dari route/controller/service/test sungguhan — lihat [laporan progres](documentation/PROGRESS-REPORT.id.md) / [progress report](documentation/PROGRESS-REPORT.en.md), yang diperbarui setiap kali ada workstream yang berubah signifikan.

| Area | Kondisi saat ini |
|---|---|
| Struktur PHP | Struktur modular ringan berbasis PHP 8.2+ dengan autoload PSR-4 |
| Web UI | Halaman utama (`/`, personal digital home live untuk owner node begitu ada), timeline lokal, profil publik (`/@{handle}`), halaman post publik, editor post dengan upload media langsung (`/dashboard/posts`), dan dashboard Overview owner (`/dashboard`) |
| Toko online pribadi | Produk, toko publik, checkout visitor, download produk digital, dan produk promosi yang difederasikan ke fediverse — lihat [Arti "marketplace" di FPDP](#arti-marketplace-di-fpdp) |
| Pembayaran | `PaymentGatewayInterface` dengan adapter Dummy, Paywuz, Midtrans, dan PayPal (Orders API v2) plus plugin iPaymu dan Transfer Manual; pemilihan gateway aktif di Settings; konfirmasi, pembatalan, dan refund oleh owner; rekonsiliasi dengan provider |
| Federasi | ActivityPub sungguhan dan interoperable: WebFinger, RSA key, HTTP Signatures (verifikasi inbound dan pengiriman outbound bertanda tangan), dokumen Actor/outbox/followers/following dengan content negotiation, Follow/Accept/Reject/Undo/Block, serta sinkronisasi post dan produk yang dipromosikan dua arah, tergabung dalam satu timeline lokal — sudah diverifikasi langsung terhadap instance Mastodon sungguhan (`mastodon.social`, `mastodon.world`) |
| Pengujian | 49 test script mencakup identity, konten, payment (semua gateway, refund, pembatalan, rekonsiliasi), upload media, federasi (discovery ActivityPub, signature, inbox, outbox, mutual follow), marketplace, analytics, config deployment, dan installer — lihat `tests/` |
| Desain API | Kontrak API bilingual dan spesifikasi OpenAPI 3.1 |

Untuk hal lainnya — cakupan REST handler, kedalaman protokol federasi, kelengkapan commerce/checkout, panel dashboard di luar Overview, dan gap yang diketahui — lihat laporan progres yang ditautkan di atas, bukan tabel ini.

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
4. **Federasi:** discovery, remote actor, dan pengiriman activity bertanda tangan yang kompatibel dengan ActivityPub, serta sinkronisasi Follow/post/produk dua arah sudah diimplementasikan dan terbukti interoperable dengan instance Mastodon sungguhan; tooling moderasi block/mute/report dan penguatan pemulihan kegagalan masih berjalan.
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
- [Value proposition and professional copywriting — English](documentation/VALUE-PROPOSITION.en.md)
- [Narasi manfaat dan copywriting profesional — Bahasa Indonesia](documentation/VALUE-PROPOSITION.id.md)
- [Deployment guide (VPS & shared hosting, install wizard) — English](documentation/DEPLOYMENT-GUIDE.en.md)
- [Panduan deployment (VPS & shared hosting, install wizard) — Bahasa Indonesia](documentation/DEPLOYMENT-GUIDE.id.md)
- [Dokploy deployment — English](documentation/DOKPLOY-DEPLOYMENT.en.md)
- [Deployment Dokploy — Bahasa Indonesia](documentation/DOKPLOY-DEPLOYMENT.id.md)

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
