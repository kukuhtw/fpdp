# FPDP Development Progress Report — English

## 1. Snapshot

**As of:** 2026-09-19, verified directly against the code, migrations, tests, and routes in this repository (not only against planning documents).

FPDP has a working **engineering foundation** and most product phases implemented or in progress. The repository now includes:

- Full **identity/authentication** flow (Phase 1)
- **Local post CRUD** with media, timeline, and visibility (Phase 2)
- **External content connectors** with SSRF-safe HTTP client and sync worker (Phase 3)
- **Audit trail** wired across all services and **GitHub Actions CI** (Phase 4)
- **Marketplace** with products, orders, and order items (Phase 5)
- **Federated connections** API on public profiles (Phase 6)

What remains is: real payment gateway adapters, full federation layer (node discovery, activity relay), advanced marketplace features (checkout with payment), and the administration dashboard backend.

This report cross-references the [Work Breakdown Structure](WBS-TASK.en.md) (workstreams 1.0–10.0) and the [Roadmap](ROADMAP.en.md) (phases 0–7) so progress can be read against either plan.

## 2. Status at a glance

```mermaid
flowchart LR
    P0["Phase 0<br/>Engineering foundation"] --> P1["Phase 1<br/>Identity & personal node"]
    P1 --> P2["Phase 2<br/>Local content & timeline"]
    P2 --> P3["Phase 3<br/>External feed aggregation"]
    P3 --> P4["Phase 4<br/>Operations & MVP hardening"]
    P4 --> P5["Phase 5<br/>Marketplace & payments"]
    P5 --> P6["Phase 6<br/>Federation"]
    P6 --> P7["Phase 7<br/>Ecosystem & scale"]

    classDef done fill:#e3efe9,stroke:#185f48,color:#17211b;
    classDef partial fill:#f2e8d6,stroke:#93631e,color:#17211b;
    classDef todo fill:#f1e3e1,stroke:#a13d37,color:#17211b;
    class P0,P1,P2,P3,P5 done;
    class P4 partial;
    class P6,P7 todo;
```

| Workstream (WBS) | Status | Evidence |
|---|---|---|
| 1.0 Project setup | **Done** | `composer.json` PSR-4 autoload, `.env.example`, `Config` loader, `.dockerignore`, `Dockerfile`, `dokploy-compose.yml` |
| 2.0 Core platform architecture | **Done** | `Router`, `Database`, `MigrationRunner`, `HttpClient` (SSRF-safe), JSON envelope, exception mapping, factory pattern for payments/connectors |
| 3.0 Authentication & user management | **Done** | Register/login/logout/`/me`, bcrypt, hashed bearer tokens, rate limiting; profile read/update by handle; visitor Google OAuth |
| 4.0 Content and timeline | **Done** | Posts CRUD, draft/publish/soft-delete, media metadata (image/video/audio/file), canonical URLs, cursor-paginated public timeline, post editor UI |
| 5.0 Federation layer | **Partially done** | Federated connections API (3 endpoints), `remote_nodes`/`remote_actors`/`federated_connections`/`federated_posts` tables; full node discovery & activity relay not started |
| 6.0 Payment layer | **Partially done** | Interface, factory, dummy gateway, payment tables done; real gateway adapters, webhook verification, idempotency not started |
| 7.0 External content integration | **Done** | RSS/Atom/Custom API connectors with HttpClient (SSRF-safe), SyncWorker, `sync-external.php` CLI for cron, dedup by (provider, external_post_id), timeline merge via `source_type=EXTERNAL` |
| 8.0 Marketplace | **Done** | Products CRUD + Orders with immutable item snapshots + order status flow (14 test cases) |
| 9.0 Administration dashboard | **Not started (mockup only)** | Prototyped as static HTML in [documentation/mockup](mockup/README.md); no real endpoints or views |
| 10.0 Testing, security, deployment | **Partially done** | 22 passing test scripts; GitHub Actions CI workflow; Dokploy deployment guide (EN & ID); audit trail across auth/post/profile services; security audit not executed |

## 3. What is done

- **HTTP foundation:** public front controller (`public/index.php`), `Router` with static/`{param}`/`@{handle}` routes, JSON success/error envelopes, sanitized exception-to-500 mapping.
- **Configuration:** `Config` loader with defaults, `.env` override, fail-fast validation.
- **Database layer:** PDO connection manager and `MigrationRunner`; **28** ordered, repeatable migrations under `database/migrations/` covering payments, external content, nodes, users, profiles, auth, audit, rate limits, visitors, CV, posts, remote nodes/actors, federated connections/posts, products, orders, and order_items.
- **SSRF-safe HTTP Client:** URL validation blocking private IP ranges (10.x, 172.16-31.x, 192.168.x, 169.254.x, localhost), configurable timeouts, response size limits, max 5 redirects.
- **Identity & authentication:** Owner registration (creates node+user+profile in one step), login/logout, bcrypt password hashing, bearer tokens hashed at rest, rate limiting on register/login.
- **Profiles:** Public profile read by handle (`GET /api/v1/profiles/{handle}`), authenticated update (`PATCH /api/v1/me/profile`), visibility rules (PUBLIC/UNLISTED/PRIVATE).
- **Visitor identity:** Google OAuth 2.0 sign-in scoped per node/profile, with state signer and callback.
- **Local content:** Draft/publish/update/soft-delete post flow, media metadata validation (type, HTTPS-only URL, max 10 items), post-type (NOTE/ARTICLE/MEDIA), visibility enforcement, canonical URLs, cursor-paginated public timeline, post editor UI.
- **CV management:** Upload, show with gated access, grant access, and download endpoints with file size limits.
- **External content sync:** RSS, Atom, Custom API connectors refactored to use SSRF-safe HttpClient. `SyncWorker` fetches feed sources due for sync, deduplicates by (provider, external_post_id), persists to `external_posts`, updates sync status. CLI entry (`sync-external.php`) for cron jobs. Timeline supports `source_type=EXTERNAL`.
- **Audit trail:** `AuditService` wired into AuthService (`user.registered/login/logout`), PostService (`post.created/deleted`), ProfileService (`profile.updated`). Null-safe integration.
- **Federated connections:** 3 endpoints — public list (filtered), owner list (all), PATCH to update show_on_profile/mute/block. Cursor pagination. 13 test cases.
- **Marketplace:** Full product CRUD. Orders with immutable `product_snapshot` (preserves price & title at order time), auto-calculated totals, 6 statuses (PENDING→...→COMPLETED/CANCELLED/REFUNDED). Ownership validation. 14 test cases.
- **Payment interfaces:** `PaymentGatewayInterface`, `PaymentGatewayFactory`, `PaymentService`, and `DummyPaymentGateway` (create/status/cancel/refund/webhook normalization). Factory only recognizes `DUMMY` and explicitly rejects any other code.
- **Web UI (MVC):** Landing page, timeline (`/timeline`), public profile (`/@{handle}`), public post page, authenticated post editor (`/dashboard/posts`).
- **Install wizard:** `public/install.php` with environment check, `.env` writer, migration runner, and owner registration step.
- **Docker deployment:** `Dockerfile` (PHP 8.3 Apache), `dokploy-compose.yml` (app + MySQL 8.4), `docker/entrypoint.sh`, `.dockerignore`, Dokploy deployment guide (EN & ID).
- **CI workflow:** GitHub Actions (`.github/workflows/test.yml`) — PHP 8.2 & 8.3 syntax check on push to main/develop, full test suite on push/PR.
- **Tests (22, all passing):** AuthEndpoints, Config, ContentPages, CvEndpoints, Database, ExternalContent, FactoryFallback, FederatedConnections, FrontController, Installer, Marketplace, MigrationRunner, MvcHome, MvpSmoke, OAuthStateSigner, PostEndpoints, RateLimit, RateLimitEndpoint, Router, VisitorAuthEndpoints, YouTubeEmbedResolver.
- **Documentation:** BRD, PRD, WBS, Roadmap, ERD, API contract, OpenAPI 3.1, Federation concept, Content-aggregation guide, Social/commerce integrations, Problem statement, Value proposition, User journey, Deployment (VPS, shared hosting, Dokploy), AI monetization strategy, Progress report, mockup navigation map — all bilingual (English/Indonesian).
- **Identity & authentication:** owner registration, login, logout, `/api/v1/me`; bcrypt password hashing; bearer tokens hashed at rest; per-IP rate limiting on register/login (`429 RATE_LIMITED`).
- **Profiles:** public profile read by handle (`GET /api/v1/profiles/{handle}`) and authenticated update (`PATCH /api/v1/me/profile`), with visibility rules.
- **Payments (scaffolding):** `PaymentGatewayInterface`, `PaymentGatewayFactory`, `PaymentService`, and a functional `DummyPaymentGateway` (create/status/cancel/refund/webhook normalization). The factory only recognizes `DUMMY` and explicitly rejects any other code.
- **External content connectors:** `ExternalContentProviderInterface`, `ExternalConnectorFactory`, and working `RSSConnector`/`AtomConnector`/`CustomApiConnector` adapters. RSS/Atom adapters now also detect YouTube video links (including the `yt:videoId`/`media:group` elements in a channel's official Atom feed) and normalize them into an embeddable descriptor (`YouTubeEmbedResolver`).
- **Interactive UI mockup:** dependency-free HTML/CSS/JS prototype of the owner dashboard and public profile under [documentation/mockup](mockup/README.md), including a working, embeddable YouTube "Video" section on the public profile — useful for design review, not wired to the backend.
- **Tests (12, all passing):** `MvpSmokeTest`, `MvcHomeTest`, `RouterTest`, `FrontControllerTest`, `ConfigTest`, `MigrationRunnerTest`, `DatabaseTest`, `FactoryFallbackTest`, `AuthEndpointsTest` (full HTTP flow), `RateLimitTest`, `RateLimitEndpointTest`, `YouTubeEmbedResolverTest`.
- **Documentation:** BRD, PRD, WBS, Roadmap, ERD, API contract, OpenAPI 3.1, Federation concept, Content-aggregation guide, Social/commerce integrations guide, Problem statement, User journey, and the mockup navigation map — all bilingual (English/Indonesian).

## 4. What is partially done

| Area | What exists | What is missing |
|---|---|---|
| Authentication & authorization | Bearer-token auth, ownership of the caller's own account | Role/permission model (admin vs owner), general-purpose authorization middleware, session-based auth for dashboard |
| Audit trail | `AuditService` wired into 3 services (auth, post, profile) | Not yet wired into federation, marketplace, CV, and visitor services |
| Payment lifecycle | Dummy create/status/cancel/refund/webhook normalization, factory pattern | Any real gateway adapter (Midtrans/Stripe), signed-webhook verification, replay/duplicate-event protection, gateway admin settings UI |
| Federation | Federated connections API with actor/node/post tables, cursor pagination | Full federation: node discovery, remote actor model, signed activity delivery, inbox/outbox queue, follow/block/report controls |
| Marketplace | Products CRUD, orders with immutable snapshots, status flow | Real checkout flow with payment integration, external-product labels, federated order-request workflow |
| Testing & CI | 22 passing script-style tests, GitHub Actions workflow | No webhook-idempotency or connector-security tests, no performance benchmarks |

## 5. What is not started

- **Real payment gateway adapters** (Midtrans, Stripe, etc.) and the security work that must ship with them (idempotency keys, signed-webhook verification, reconciliation).
- **Full federation layer:** node discovery, remote actor model, signed activity delivery, inbox/outbox relay, moderation — design-only ([FEDERATION-CONCEPT.en.md](FEDERATION-CONCEPT.en.md)).
- **Advanced marketplace:** checkout flow with real payment, external-product labels, federated order-request workflow.
- **Administration dashboard (real):** the dashboard exists only as a static mockup; no backend endpoints, views, or auth-gated screens for settings, integrations, products, orders, payments, federation, or analytics.
- **LLM/AI monetization:** chatbot, paid CV gating, analytics, ad marketplace — design-only ([AI-MONETIZATION-STRATEGY.en.md](AI-MONETIZATION-STRATEGY.en.md)).
- **OAuth social connectors:** Instagram, LinkedIn, X (Twitter) — only the RSS/Atom/Custom API connectors are implemented.
- **Deployment/operations:** no backup/restore/rollback runbook execution, no license file, no staging recovery exercises.

## 6. Recommended next steps

Per the roadmap, the highest-leverage remaining work is, in order:

1. **Real payment gateway adapters** — integrate Midtrans or Stripe with signed-webhook verification and idempotency. Unlocks the checkout flow.
2. **Checkout flow** — connect marketplace orders with payment gateway, allowing buyers to complete purchases.
3. **Full federation layer** — node key management, remote actor discovery, signed activity delivery, inbox/outbox queue, and moderation controls.
4. **Administration dashboard backend** — API endpoints for settings, integrations, products, orders, payments, and analytics to replace the static mockup.
5. **Authorization middleware** — role/permission model (admin vs owner) and middleware for route protection.

## 7. References

- [Work Breakdown Structure](WBS-TASK.en.md)
- [Development roadmap and strategy](ROADMAP.en.md)
- [Entity Relationship Diagram](ERD.en.md)
- [API contract](API-CONTRACT.en.md) · [OpenAPI 3.1](openapi.yaml)
- [Interactive mockup](mockup/README.md) · [Mockup navigation map](mockup/NAVIGATION-MAP.en.md)
- [Deployment guide (Dokploy)](DOKPLOY-DEPLOYMENT.en.md)
- [Deployment guide (VPS & shared hosting)](DEPLOYMENT-GUIDE.en.md)
- [Repository README](../README.md) — "Current implementation" table, kept in sync with this report
