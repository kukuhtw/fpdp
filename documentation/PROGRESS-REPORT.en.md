# FPDP Development Progress Report

## 1. Snapshot

**Verification date:** September 19, 2026
**Evidence:** repository routes, controllers, services, repositories, migrations, UI, tests, and deployment configuration—not planning documents alone.

FPDP has moved beyond a basic prototype. Identity, local publishing, external aggregation, a basic marketplace, payment abstraction, analytics, and most federation foundations exist as testable code. The next stage is to connect commercial flows end to end, build the real dashboard, validate external integrations against real sandboxes/servers, and harden production operations.

Repository snapshot:

- **39 MySQL migrations** (`0001`–`0039`);
- **29 test scripts**;
- REST APIs for identity, profiles, posts, timeline, external feeds, CV, payments, marketplace, analytics, and federation;
- real UI for the landing page, local timeline, public profile, post page, and post editor;
- a Dockerfile and Dokploy-specific Compose deployment;
- bilingual product and technical documentation.

## 2. Phase status

```mermaid
flowchart LR
    P0["Phase 0<br/>Engineering foundation"] --> P1["Phase 1<br/>Identity & personal node"]
    P1 --> P2["Phase 2<br/>Local content & timeline"]
    P2 --> P3["Phase 3<br/>External aggregation"]
    P3 --> P4["Phase 4<br/>MVP hardening"]
    P4 --> P5["Phase 5<br/>Marketplace & payments"]
    P5 --> P6["Phase 6<br/>Federation"]
    P6 --> P7["Phase 7+<br/>Ecosystem, AI, scale"]

    classDef done fill:#e3efe9,stroke:#185f48,color:#17211b;
    classDef partial fill:#f2e8d6,stroke:#93631e,color:#17211b;
    classDef planned fill:#f1e3e1,stroke:#a13d37,color:#17211b;
    class P0,P1,P2,P3 done;
    class P4,P5,P6 partial;
    class P7 planned;
```

| Workstream | Status | Summary |
|---|---|---|
| Engineering foundation | **Done** | PSR-4, router, PDO, migration runner, config, envelopes, exception mapping, CI |
| Identity & profile | **MVP complete** | Register/login/logout/`me`, hashed tokens, rate limiting, profile visibility, Google visitor OAuth |
| Local content | **MVP complete** | CRUD, draft/publish, visibility, soft delete, media, canonical URLs, cursor timeline, UI |
| External aggregation | **MVP complete** | RSS/Atom/Custom API, SSRF-safe HTTP, sync worker, deduplication, persistence, timeline merge |
| Operations & hardening | **Partial** | CI, base audit exist; Dokploy staging is live and validated (migrations, health check, owner bootstrap); backup/restore recovery exercise remains |
| Marketplace | **Mostly done** | Products and orders exist; public checkout and payment are not connected end to end |
| Payments | **Mostly done** | Dummy, Paywuz, Midtrans, encrypted config, webhook/idempotency; real sandbox and reconciliation remain |
| Federation | **Backend mostly done** | Keys, discovery, signed inbox/outbox, follow lifecycle, moderation, delivery retry; cross-server/UI remain |
| Dashboard | **Partial** | Overview, Payments, Analytics, and Federation have APIs; the mockup is not a live dashboard |
| AI & advertising | **Planned** | Strategy is documented; LLM chat and advertising marketplace are not implemented |

## 3. Delivered capabilities

### 3.1 Platform and baseline security

- Front controller and router with static, segment-parameter, and canonical `/@handle` routes.
- Configuration from defaults, `.env`, and native container environment variables.
- Password hashing, hashed/revocable bearer tokens, and authentication rate limits.
- SSRF-aware outbound HTTP with private-target blocking, redirect, timeout, and response-size limits.
- Consistent JSON success/error envelopes and automated CI checks.

### 3.2 Identity, profiles, and visitors

- Registration provisions a node, owner user, and profile in one flow.
- Login, logout, `/api/v1/me`, public profile reads, and owner profile updates.
- `PUBLIC`, `UNLISTED`, and `PRIVATE` profile visibility.
- Google visitor OAuth with signed state and hashed visitor tokens.

### 3.3 Local content and media

- Post create/read/update/soft delete and ownership enforcement.
- Draft, publish, and unpublish through `published_at`.
- Stable profile/post canonical URLs and cursor pagination.
- Up to ten ordered `IMAGE`, `VIDEO`, `AUDIO`, or `FILE` metadata items with HTTPS/credential/alt-text validation.
- Live local timeline, profile, post, and post-editor pages.

### 3.4 External content

- RSS, Atom, and Custom API connectors.
- YouTube normalization from official feeds and privacy-enhanced embed support.
- Feed-source management, synchronization, deduplication, persistence, statistics, and attributed timeline merge.

### 3.5 CV and visitor-monetization foundation

- CV storage outside the webroot, public metadata, payment-aware access grants, and gated downloads.
- Replacing a CV invalidates previous grants.
- Visitor identity is distinct from owner identity.

### 3.6 Marketplace and payments

- Product CRUD and orders with immutable product snapshots.
- Order lifecycle and ownership validation.
- `PaymentGatewayInterface`, Dummy, Paywuz, and Midtrans adapters.
- Payment/transaction persistence, webhook verification, and duplicate-event handling.
- AES-256-GCM encrypted gateway configuration through API without returning secrets.
- Payment dashboard summary API.

### 3.7 Federation

- Ed25519 node identity and signing keys.
- Public capability discovery and cached remote keys.
- Signed public inbox, authenticated outbox, Follow/Accept/Reject/Undo/Block processing.
- Incoming/outgoing follow direction, federated connections, and public latest-post previews.
- Remote-node trust states, delivery retries, exponential backoff, deduplication, and timestamp replay reduction.
- Federation summary and capability-settings APIs.

### 3.8 Analytics and dashboard APIs

- Privacy-conscious daily visitor HMACs; raw IP addresses are not stored.
- Profile view, post view, outbound click, and shop-conversion events.
- Seven-day summary, unique visitors, traffic chart, and top content.
- Dashboard overview aggregating content, marketplace, payment, federation, analytics, and audit activity.

### 3.9 Deployment

- Web installer for VPS/shared hosting.
- PHP 8.3 + Apache image.
- Dokploy Compose with MySQL 8.4, health checks, persistent volumes, automatic migrations, and owner bootstrap.
- Production secret validation and web-installer lock.
- English and Indonesian deployment guides.

## 4. Partially complete

### 4.1 Production validation

- The Docker image was not built in this development workspace because Docker CLI is unavailable here, but the image build and Dokploy staging deployment were run on the server and confirmed successful by the project owner (2026-09-19): every migration ran cleanly, the health check passed, owner bootstrap created the first account, and the domain/TLS are live.
- The full migration set is not exercised against disposable MySQL 8 by the repository test suite (CI); migration validation so far comes from the staging run above, not an automated CI job.
- No recovery exercise (backup/restore/rollback) has been run.
- `sodium` availability must be verified explicitly in the built production image.

### 4.2 Checkout and marketplace

- Orders are not connected to gateway payments in a public visitor checkout.
- Checkout UI, receipt, status polling, cancellation, and refund experiences remain.
- `SHOP_CONVERSION` still reflects owner-created orders rather than true visitor checkout.
- External-product labels and federated order requests remain.

### 4.3 Production payments

- Paywuz and Midtrans use fake HTTP requesters in tests; real sandbox validation remains.
- Midtrans refund events after `PAID` do not always reconcile the payment to `REFUNDED`.
- No settlement/payout ledger, fee accounting, or reconciliation job exists.
- `APP_KEY` rotation cannot automatically re-encrypt gateway credentials.

### 4.4 Dashboard and settings

- The dashboard mockup is not wired to live APIs.
- Content, Timeline, Integrations, Products, and Orders have domain APIs but no integrated dashboard panels.
- Theme, layout, custom CSS, default/enabled languages, node configuration, 2FA, and session management remain.

### 4.5 Production federation

- No two-instance, cross-domain FPDP interoperability test has run.
- No live federation dashboard for follow/trust/block/moderation/capability actions exists.
- Replay protection lacks a nonce cache.
- Stored capabilities do not enforce feature access.
- Historical follow records may require incoming/outgoing direction backfill.

### 4.6 Authorization, audit, and analytics hardening

- No centralized role/permission middleware distinguishes owner/admin actions.
- Payment-credential and remote-trust changes are not fully audited.
- The public outbound-click endpoint lacks dedicated rate limiting.
- `analytics_events` has no retention/cleanup job.

## 5. Not started

- Production OAuth connectors for Instagram/Meta, LinkedIn, X, Threads, TikTok, and Shopee.
- LLM provider configuration, profile/CV-grounded chat, paid sessions, and AI cost controls.
- Advertising marketplace: slots, pricing, booking, approval, and delivery windows.
- Production plugin/adapter marketplace and federated commerce.
- Multi-node administration, shared/object storage, and horizontal scaling.
- License file and formal contribution policy.

## 6. Recommended priority

```mermaid
flowchart LR
    A["1. Dokploy staging<br/>+ MySQL migration test"] --> B["2. Payment sandbox<br/>+ public checkout"]
    B --> C["3. Live dashboard<br/>+ node settings"]
    C --> D["4. Federation<br/>cross-server test"]
    D --> E["5. Authorization,<br/>audit, retention"]
    E --> F["6. OAuth, AI,<br/>ads & ecosystem"]
```

1. ~~Deploy a staging node through Dokploy and validate image build, health checks, volumes, bootstrap, and every migration on MySQL 8.~~ **Done** — Dokploy staging is live and validated (2026-09-19).
2. Validate Paywuz and Midtrans against real sandboxes.
3. Connect public checkout → order → payment → webhook → fulfillment/refund.
4. Turn the dashboard mockup into a live UI, starting with APIs already available.
5. Implement node appearance/language and security settings.
6. Run cross-server federation interoperability tests.
7. Add RBAC middleware, sensitive-action audit coverage, analytics rate limiting, and retention.
8. Continue with social OAuth, AI chat, advertising, and ecosystem work afterward.

## 7. Operational decisions and risks

- The current deployment assumes **one owner and one app replica per node**.
- Do not scale horizontally before migration locking and shared/object storage exist.
- MySQL and `storage/` must be restored from a consistent recovery point.
- Code rollback does not reverse forward migrations.
- `APP_KEY` protects OAuth state, analytics HMACs, and encrypted gateway credentials; rotation requires a dedicated procedure.
- Production policy must decide whether public registration remains open.
- Payment and federation require real remote-system testing before being called production-ready.

## 8. Latest validation

- **29/29 test scripts passed** in the latest development run.
- PHP syntax checks passed for configuration and owner bootstrap.
- Owner bootstrap passed its SQLite integration test.
- `git diff --check` passed.
- Docker build/Compose rendering did not run in this development workspace because Docker CLI is unavailable here; this acceptance step was run directly on the Dokploy server and confirmed successful by the project owner.
- `ExternalContentTest` passed but Windows emitted a temporary SQLite cleanup warning; functionality passed, while cleanup can be improved.

## 9. References

- [Roadmap](ROADMAP.en.md)
- [WBS](WBS-TASK.en.md)
- [PRD](PRD.en.md)
- [ERD](ERD.en.md)
- [API Contract](API-CONTRACT.en.md) and [OpenAPI](openapi.yaml)
- [Federation Concept](FEDERATION-CONCEPT.en.md)
- [Dokploy Guide](DOKPLOY-DEPLOYMENT.en.md)
- [Mockup](mockup/README.md)
