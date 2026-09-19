# FPDP Development Progress Report — English

## 1. Snapshot

**As of:** 2026-09-19, verified directly against the code, migrations, tests, and routes in this repository (not only against planning documents). The federation section of this report was updated after two consecutive follow-up sessions: (1) end-to-end signature verification, remote node discovery, and inbox→processor wiring, then (2) a `trust_state` moderation endpoint and timestamp-based replay protection (see §3 and §4).

FPDP has a working **engineering foundation** and most product phases implemented or in progress. The repository now includes:

- Full **identity/authentication** flow (Phase 1)
- **Local post CRUD** with media, timeline, and visibility (Phase 2)
- **External content connectors** with SSRF-safe HTTP client and sync worker (Phase 3)
- **Audit trail** wired across all services and **GitHub Actions CI** (Phase 4)
- **Marketplace** with products, orders, and order items (Phase 5)
- **Signed-activity federation** (Phase 6): Ed25519 node identity, capability-document discovery, inbound signature verification, stale-activity rejection (timestamp window), automatic Follow/Accept/Reject/Undo/Block handling through a public inbox, an owner-facing `trust_state` moderation endpoint, and a delivery worker with retry+backoff

What remains is: real payment gateway adapters, advanced marketplace features (checkout with payment), and the administration dashboard backend. Federation still needs a real cross-server test (only exercised within a single process/DB so far) and has no admin UI yet (API only).

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
    class P4,P6 partial;
    class P7 todo;
```

| Workstream (WBS) | Status | Evidence |
|---|---|---|
| 1.0 Project setup | **Done** | `composer.json` PSR-4 autoload, `.env.example`, `Config` loader, `.dockerignore`, `Dockerfile`, `dokploy-compose.yml` |
| 2.0 Core platform architecture | **Done** | `Router`, `Database`, `MigrationRunner`, `HttpClient` (SSRF-safe), JSON envelope, exception mapping, factory pattern for payments/connectors |
| 3.0 Authentication & user management | **Done** | Register/login/logout/`/me`, bcrypt, hashed bearer tokens, rate limiting; profile read/update by handle; visitor Google OAuth |
| 4.0 Content and timeline | **Done** | Posts CRUD, draft/publish/soft-delete, media metadata (image/video/audio/file), canonical URLs, cursor-paginated public timeline, post editor UI |
| 5.0 Federation layer | **Mostly done** | Ed25519 node identity, capability-document discovery, inbound signature verification, stale-activity rejection (timestamp window), automatic Follow/Accept/Reject/Undo/Block via a public inbox, `trust_state` moderation endpoints (`GET`/`PATCH /api/v1/me/federation/remote-nodes`), delivery worker with retry+backoff (9 tests); not yet tested across real servers, no admin UI |
| 6.0 Payment layer | **Partially done** | Interface, factory, dummy gateway, payment tables done; real gateway adapters, webhook verification, idempotency not started |
| 7.0 External content integration | **Done** | RSS/Atom/Custom API connectors with HttpClient (SSRF-safe), SyncWorker, `sync-external.php` CLI for cron, dedup by (provider, external_post_id), timeline merge via `source_type=EXTERNAL` |
| 8.0 Marketplace | **Done** | Products CRUD + Orders with immutable item snapshots + order status flow (14 test cases) |
| 9.0 Administration dashboard | **Not started (mockup only)** | Prototyped as static HTML in [documentation/mockup](mockup/README.md); no real endpoints or views |
| 10.0 Testing, security, deployment | **Partially done** | 22 passing test scripts; GitHub Actions CI workflow; Dokploy deployment guide (EN & ID); audit trail across auth/post/profile services; note: the real migrations under `database/migrations/` have never been validated to run against SQLite (every test hand-writes its own minimal schema — see §7) |

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
- **Signed-activity federation (node identity, discovery, automatic inbox):** `NodeKeyService` generates an Ed25519 keypair per node and signs outgoing activities; `GET /api/v1/federation/capability` is now reachable without a bearer token (falls back to the single locally-hosted node) so remote servers can discover us. The new `NodeDiscoveryService` fetches the sender's capability document over the SSRF-safe HTTP client, caches its public key in `remote_node_keys`, and auto-registers unknown remote actors. `POST /api/v1/federation/inbox` is now a public endpoint (no longer requires the caller's own bearer token) and verifies each activity's signature against the discovered public key before processing — an invalid signature returns 403, and a sender domain marked `BLOCKED` in `remote_nodes` is rejected before any discovery attempt. Inbound `Follow`/`Undo`/`Block` activities are resolved to a local profile from the actor URI embedded in the payload; inbound `Accept`/`Reject` are matched back to the `Follow` we originally sent and update its status plus the resulting `federated_connections` row. `deliver-federation.php` (the delivery cron worker) now honors real exponential backoff (`federation_activities.next_attempt_at`, migration `0033`). Inbound activities whose `published` field falls outside a ±5-minute window of server time are rejected (403) as a basic replay defense on top of the existing activity-id dedup. Owners can now also review and moderate remote nodes via `GET /api/v1/me/federation/remote-nodes` (every remote node seen so far, with `trust_state`/`last_seen_at`) and `PATCH /api/v1/me/federation/remote-nodes/{domain}/trust` (set `UNKNOWN`/`TRUSTED`/`BLOCKED`) — a `BLOCKED` state takes effect on the very next inbox request, before discovery or signature verification is attempted. 9 tests (`FederationInboxTest`): valid signature, tampered signature, blocked domain, unsigned activity, a full send-follow → inbound-Accept round trip, duplicate activity id, a stale-timestamp activity, the moderation list requiring auth, and blocking via the endpoint immediately rejecting the next inbox call.
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
| Federation | Node identity + signing, capability discovery, signature-verified inbox, stale-activity rejection, Follow/Accept/Reject/Undo/Block, `trust_state` moderation endpoints, delivery worker with backoff | Admin UI (API only today), a full anti-replay nonce cache (currently a ±5-minute timestamp window plus activity-id dedup), real cross-server testing (only exercised within a single process/DB so far) |
| Marketplace | Products CRUD, orders with immutable snapshots, status flow | Real checkout flow with payment integration, external-product labels, federated order-request workflow |
| Testing & CI | 22 passing script-style tests, GitHub Actions workflow | No webhook-idempotency or connector-security tests, no performance benchmarks |

## 5. What is not started

- **Real payment gateway adapters** (Midtrans, Stripe, etc.) and the security work that must ship with them (idempotency keys, signed-webhook verification, reconciliation).
- **Advanced marketplace:** checkout flow with real payment, external-product labels, federated order-request workflow.
- **Administration dashboard (real):** the dashboard exists only as a static mockup; no backend endpoints, views, or auth-gated screens for settings, integrations, products, orders, payments, federation, or analytics.
- **LLM/AI monetization:** chatbot, paid CV gating, analytics, ad marketplace — design-only ([AI-MONETIZATION-STRATEGY.en.md](AI-MONETIZATION-STRATEGY.en.md)).
- **OAuth social connectors:** Instagram, LinkedIn, X (Twitter) — only the RSS/Atom/Custom API connectors are implemented.
- **Deployment/operations:** no backup/restore/rollback runbook execution, no license file, no staging recovery exercises.

## 6. Recommended next steps

Per the roadmap, the highest-leverage remaining work is, in order:

1. **Real payment gateway adapters** — integrate Midtrans or Stripe with signed-webhook verification and idempotency. Unlocks the checkout flow.
2. **Checkout flow** — connect marketplace orders with payment gateway, allowing buyers to complete purchases.
3. **Administration dashboard backend** — API endpoints for settings, products, orders, payments, and analytics to replace the static mockup, including a UI for the federation moderation endpoints that already exist in the API.
4. **Authorization middleware** — role/permission model (admin vs owner) and middleware for route protection (the federation moderation endpoints are currently only gated by ordinary bearer auth, with no admin-role distinction).
5. **Real cross-server federation test** — run two actual FPDP instances (e.g. two Dokploy containers) following each other over the internet, to validate discovery/signing beyond the in-process/in-DB test.

## 7. Federation operational notes (from the follow-up sessions)

- **`ext-sodium` dependency:** `NodeKeyService` (Ed25519 generate/sign/verify) requires PHP's `sodium` extension. On the local development environment (XAMPP on Windows) it was **disabled by default** in `php.ini` (`;extension=sodium`) — it has been enabled for this session so the tests can run. The production `Dockerfile` (`docker-php-ext-install pdo_mysql mbstring simplexml`) does not explicitly install/enable `sodium`; verify it is actually present on the target PHP image before relying on signed federation in production (it usually ships built-in since PHP 7.2, but don't assume without checking).
- **Real migrations still unvalidated against SQLite:** all 33 files under `database/migrations/` (including pre-existing ones) fail when run directly through `MigrationRunner` against an in-memory SQLite database (`ALTER TABLE`, `KEY idx(...)`, `... ON UPDATE CURRENT_TIMESTAMP`, and `COMMENT '...'` are not valid SQLite syntax). This is not a regression from this session — every existing test (and the new one) hand-writes its own minimal SQLite schema instead of running the real migration files. Migrations are currently only validated against MySQL/MariaDB in CI; no test in this repo runs `database/migrations/*.sql` end-to-end against a real MySQL instance.
- **Single-local-node assumption:** the public `GET /api/v1/federation/capability` endpoint (used by other nodes for discovery) falls back to `NodeRepository::findFirst()` when called without a bearer token, because the schema supports multiple `nodes` per install but there is no `Host`-header-based resolution. This matches the "one owner per deployment" model described in §3, but would need redesigning if FPDP is ever run as a genuine multi-tenant install.
- **Replay protection is still coarse:** rejecting activities with a `published` timestamp outside a ±5-minute window (`FederationService::MAX_ACTIVITY_SKEW_SECONDS`) narrows the replay window, but it is not a nonce cache — a captured activity with a valid signature and unique `id`, replayed within that window, would still be processed as new (dedup only blocks reusing the exact same `id` twice). Good enough for an MVP, not full anti-replay protection.
- **Moderation actions are not audited yet:** `PATCH /api/v1/me/federation/remote-nodes/{domain}/trust` does not write to `audit_events` (unlike AuthService/PostService/ProfileService, which are already wired to `AuditService`) — worth connecting when the admin dashboard backend is built.

## 8. References

- [Work Breakdown Structure](WBS-TASK.en.md)
- [Development roadmap and strategy](ROADMAP.en.md)
- [Entity Relationship Diagram](ERD.en.md)
- [API contract](API-CONTRACT.en.md) · [OpenAPI 3.1](openapi.yaml)
- [Interactive mockup](mockup/README.md) · [Mockup navigation map](mockup/NAVIGATION-MAP.en.md)
- [Deployment guide (Dokploy)](DOKPLOY-DEPLOYMENT.en.md)
- [Deployment guide (VPS & shared hosting)](DEPLOYMENT-GUIDE.en.md)
- [Repository README](../README.md) — "Current implementation" table, kept in sync with this report
