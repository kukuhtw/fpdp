# FPDP Development Roadmap and Strategy

## 1. Purpose

This document turns the FPDP product vision into an executable delivery plan. It defines what should be built first, why that order matters, what each milestone must prove, and which quality gates must be passed before the next phase starts.

This roadmap is based on the repository's current state:

- a lightweight PHP 8.2 modular structure exists;
- a static MVC landing view can be rendered;
- payment and external-content interfaces and factories exist;
- only the dummy payment gateway is functional;
- RSS, Atom, and Custom API adapters can normalize remote records in memory;
- an initial payment and integration schema exists;
- most HTTP, authentication, persistence, UI, queue, commerce, and federation capabilities are not implemented.

## 2. Strategic outcome

The first meaningful product release must let a node owner complete this journey:

```text
Install node → Create owner account → Complete public profile
→ Publish local post → Connect RSS → Sync and review attributed content
→ View the public personal digital home
```

Payment, marketplace, and federation should not be placed on the critical path until this ownership-and-content loop is reliable.

## 3. Development strategy

### 3.1 Build vertical slices

Deliver small end-to-end capabilities that include route, authorization, validation, service, persistence, response contract, UI where required, and tests. Avoid building every database table first or every UI screen first without an executable journey.

Recommended first slice:

```text
Register → Persist user/node/profile → Authenticate → Read /me
```

Recommended second slice:

```text
Create local post → Persist → Read public post → Display on profile
```

### 3.2 Contract-first API development

Use [`openapi.yaml`](openapi.yaml) as the target API contract.

For each endpoint:

1. confirm its request, response, authorization, and error behavior;
2. add contract tests or request-level tests;
3. implement the smallest service and persistence path;
4. keep the OpenAPI document synchronized with implementation;
5. mark implementation status in release notes or an endpoint matrix.

The contract may be revised when implementation reveals a design problem, but code and documentation must change together.

### 3.3 Preserve provider boundaries

Business logic must depend on `PaymentGatewayInterface` and `ExternalContentProviderInterface`, not concrete adapters. Unknown provider codes must fail explicitly; silently falling back to a dummy or RSS adapter is unsafe for production behavior.

### 3.4 Establish security boundaries early

Security work is part of each feature, not a final hardening phase. Authentication, authorization, ownership checks, secret encryption, SSRF protection, webhook verification, log sanitization, and idempotency must be implemented with the capability that needs them.

### 3.5 Keep asynchronous work observable

Feed synchronization and webhook processing need explicit states, retry policy, attempt counts, timestamps, sanitized errors, and operational visibility. A queued capability is incomplete when operators cannot see or recover failed work.

### 3.6 Use migration-owned persistence

Replace the schema stub with ordered, repeatable migrations. Add foreign keys, unique constraints, indexes, timestamps, and retention rules deliberately. Avoid relying on manually edited production schemas.

### 3.7 Delay federation until local semantics are stable

Federation amplifies identity, moderation, delivery, trust, and consistency problems. Stabilize local profiles, posts, visibility, canonical URLs, and source attribution before exchanging remote activities.

## 4. Priority model

| Priority | Meaning | Rule |
|---|---|---|
| P0 | Release blocker | Required for a secure personal-node content loop |
| P1 | MVP completion | Required for reliable external aggregation and operation |
| P2 | Commercial capability | Products, orders, and real payments |
| P3 | Network expansion | Federation and advanced ecosystem capabilities |

Within a priority, complete foundational risks before convenience features. Security and data-integrity tasks inherit the priority of the feature they protect.

## 5. Delivery roadmap

### Phase 0 — Engineering foundation

**Objective:** create a safe, testable application shell before adding product features.

Expected duration: 1–2 weeks.

Tasks, in order:

1. Add environment configuration with validation and an example file containing no secrets.
2. Add a public front controller and explicit web/API routing.
3. Add centralized JSON responses, exception mapping, request IDs, and sanitized logging.
4. Introduce database connection management and ordered migrations.
5. Convert the existing schema into migrations; add foreign keys and operational indexes.
6. Add automated unit, integration, and HTTP test entry points.
7. Add static analysis, code style checks, and a repeatable CI command.
8. Make factories reject unsupported provider codes instead of silently choosing a fallback.

Exit criteria:

- `/api/v1/health` returns the documented envelope;
- migrations run on an empty database and can be repeated safely;
- test, lint, and static-analysis commands pass from a clean checkout;
- runtime errors return sanitized responses with request IDs;
- no application secret is committed or printed in logs.

### Phase 1 — Identity and personal node

**Objective:** establish ownership, authentication, and the public profile.

Expected duration: 2–3 weeks.

Tasks, in order:

1. Add `nodes`, `users`, `profiles`, sessions/API tokens, and audit-event migrations.
2. Implement password hashing and secure owner registration.
3. Implement login, logout, token expiration/revocation, and session protection.
4. Add authentication and role/ownership middleware.
5. Implement `/auth/register`, `/auth/login`, `/auth/logout`, and `/me`.
6. Implement profile update and public-profile read endpoints.
7. Build a minimal profile editor and public profile page.
8. Add rate limiting for authentication endpoints.

Exit criteria:

- one owner can securely create and access a node;
- unauthenticated, owner, and admin permissions are tested;
- profile visibility rules work at API and UI levels;
- credentials and tokens never appear in API output or logs;
- registration-to-public-profile flow passes an end-to-end test.

### Phase 2 — Local content and timeline

**Objective:** make the user's domain useful without any external provider.

Expected duration: 2–3 weeks.

Tasks, in order:

1. Add local post and media metadata migrations.
2. Implement post create, read, update, soft delete, draft, and visibility rules.
3. Generate stable canonical URLs on the user's node.
4. Implement public post and timeline queries with cursor pagination.
5. Build the post editor, post page, and local timeline.
6. Add output escaping, content sanitization, and media validation.
7. Add ownership, visibility, pagination, and soft-delete tests.

Exit criteria:

- an owner can draft, publish, edit, unpublish, and delete a local post;
- visitors see only content allowed by visibility rules;
- every published item has stable authorship, source metadata, and canonical URL;
- timeline queries remain indexed and paginated.

### Phase 3 — External feed aggregation

**Objective:** connect external sources safely and display normalized content with attribution.

Expected duration: 3–4 weeks.

Tasks, in order:

1. Add strict connector configuration validation and explicit provider errors.
2. Implement outbound HTTP with DNS/IP checks, SSRF protection, timeouts, size limits, and safe XML handling.
3. Implement source test/preview without persistence.
4. Persist external sources and encrypted credentials.
5. Define the normalized external-post mapping and deterministic deduplication keys.
6. Implement queue claiming, locking, retries with backoff, and dead-letter handling.
7. Persist normalized posts and update sync state atomically.
8. Merge external items into the timeline with provider and canonical-link labels.
9. Implement manual sync, disable, disconnect, and optional imported-content purge.
10. Add an integration-health screen with actionable error states.

Exit criteria:

- RSS, Atom, and Custom API test/preview paths are safe and bounded;
- repeated synchronization does not duplicate posts;
- failed jobs can retry and can be inspected without exposing secrets;
- disconnect behavior and data retention are explicit and tested;
- all external timeline items show source type, provider, author, and canonical URL.

### Phase 4 — Operations and MVP hardening

**Objective:** make the personal content node deployable and supportable.

Expected duration: 2 weeks.

Tasks, in order:

1. Add an admin health dashboard for dependencies, jobs, and recent failures.
2. Add audit events for authentication, source configuration, and administrative actions.
3. Add backup, restore, migration, scheduler, and worker runbooks.
4. Add rate limits, security headers, CSRF protection, and production configuration checks.
5. Add metrics for activation, synchronization success, job age, and error rates.
6. Run dependency, credential, logging, authorization, and connector security reviews.
7. Execute recovery and rollback exercises in a staging environment.

Exit criteria:

- the first practical user journey passes in staging;
- operators can detect and recover common failure modes;
- deployment, backup, restore, and rollback are documented and tested;
- no critical security finding remains open.

**Milestone:** Personal Digital Home MVP.

### Phase 5 — Marketplace and payments

**Objective:** support a trustworthy local product-to-payment journey.

Expected duration: 4–6 weeks.

Tasks, in order:

1. Define product, inventory, order, order-line, payment-attempt, refund, and idempotency models.
2. Build local product CRUD and public catalog/detail pages.
3. Create immutable order price snapshots and explicit order-state transitions.
4. Harden the dummy gateway contract with service and state-machine tests.
5. Implement one production gateway based on target-market priority.
6. Implement idempotent payment creation.
7. Implement signed webhook verification, replay prevention, event deduplication, and transactional state updates.
8. Build checkout, payment status, receipt, cancellation, and refund experiences.
9. Add gateway configuration with encrypted secrets and sandbox/production separation.
10. Add reconciliation and alerting for mismatched local/provider states.

Exit criteria:

- duplicate checkout submission cannot create duplicate logical payments;
- forged and replayed webhook events are rejected;
- valid duplicate webhook delivery is acknowledged without repeated transition;
- order totals cannot change after checkout creation;
- sandbox end-to-end checkout, payment, refund, and reconciliation pass.

### Phase 6 — Federation

**Objective:** connect independent nodes without weakening local ownership or moderation.

Expected duration: 5–8 weeks after protocol selection.

Tasks, in order:

1. Select and document the federation protocol and interoperability target.
2. Implement node identity, key management, and capability discovery.
3. Add remote actor/object models with provenance and trust state.
4. Implement signed inbox/outbox delivery and verification.
5. Add delivery queue, retries, deduplication, tombstones, and update/delete semantics.
6. Add follow/block/mute/report and moderation controls.
7. Display federated content with unmistakable remote identity and canonical URLs.
8. Run interoperability, abuse, key-rotation, replay, and failure tests.

Exit criteria:

- two test nodes can exchange supported activities securely;
- duplicate and replayed activities are harmless;
- remote updates/deletions follow documented semantics;
- administrators and users can block abusive nodes or actors;
- local, external, and federated ownership remain distinguishable.

### Phase 7 — Ecosystem and scale

**Objective:** broaden integrations and operational capacity only after core semantics are stable.

Candidate work:

- OAuth social connectors;
- plugin/adapter registry and compatibility policy;
- federated commerce experiments;
- advanced search, media processing, and caching;
- multi-node operational tooling;
- accessibility, localization, import/export, and data portability improvements.

## 6. Tasks to start first

The next development cycle should execute these tasks in this exact order:

| Order | Task | Why it comes first | Deliverable |
|---:|---|---|---|
| 1 | Define configuration and environment validation | Every route, database, secret, and job depends on predictable configuration | Config loader, validation, safe example environment file |
| 2 | Add front controller and router | There is currently no executable HTTP application surface | Public entry point, `/api/v1/health`, route tests |
| 3 | Add error/response/request-ID layer | All endpoints need one stable API envelope and safe failures | JSON responder, exception mapping, sanitized logs |
| 4 | Add database layer and migrations | Identity and content cannot be implemented safely on a schema stub | Migration runner and converted baseline schema |
| 5 | Fix factory fallback behavior | Silent fallback can select the wrong connector or payment behavior | Explicit unsupported-provider exception and tests |
| 6 | Add users, nodes, profiles, and token schema | This is the foundation of ownership | Indexed migrations with constraints |
| 7 | Implement registration and login slice | Proves routing, validation, persistence, and auth together | Register/login/logout/me endpoints and tests |
| 8 | Implement public profile slice | Produces the first visible product value | Profile update/read API and minimal UI |
| 9 | Implement local post slice | Makes the node useful independently | Post CRUD, visibility, canonical URLs, timeline |
| 10 | Secure external HTTP fetching | Must precede exposing connector URLs to users | SSRF-safe client, timeouts, limits, parser tests |

Do not start a production payment gateway or federation implementation before tasks 1–9 are stable. They depend on identity, authorization, persistence, error handling, auditability, and state semantics established there.

## 7. Dependency map

```text
Configuration
  └─ Router + error handling
      └─ Database + migrations
          ├─ Identity + authorization
          │   ├─ Public profile
          │   ├─ Local posts + timeline
          │   │   └─ External aggregation
          │   │       └─ Federation
          │   └─ Admin operations
          └─ Products + orders
              └─ Payments + webhooks
```

Operations, security tests, observability, and documentation run across every branch rather than appearing only at the end.

## 8. Testing strategy

| Test level | Primary purpose | Examples |
|---|---|---|
| Unit | Domain rules and adapters in isolation | Money, status transitions, normalization, factory selection |
| Integration | Database and infrastructure behavior | Migrations, repositories, queue locking, idempotency |
| Contract/API | OpenAPI-aligned HTTP behavior | Auth, status codes, envelopes, validation, pagination |
| End-to-end | Critical user journeys | Register-to-profile, publish-to-timeline, connect-to-sync, checkout-to-paid |
| Security | Abuse and boundary failures | Authorization, SSRF, XSS, CSRF, webhook replay, secret leakage |
| Operational | Recovery behavior | Failed job retry, backup/restore, rollback, provider outage |

Every bug fix should add a regression test at the lowest useful level.

## 9. Definition of Done

A task is complete only when all applicable conditions are met:

- acceptance criteria are demonstrated;
- authorization and ownership rules are enforced;
- input validation and predictable error responses exist;
- unit/integration/HTTP tests cover success and important failure paths;
- migrations and indexes support the data path;
- logs are useful and contain no secrets or sensitive payloads;
- metrics or operational state exist for asynchronous behavior;
- English and Indonesian documentation are updated;
- OpenAPI is updated when the HTTP contract changes;
- deployment and rollback impact is understood.

## 10. Release gates and metrics

### MVP release gates

- registration-to-public-profile and publish-to-timeline journeys pass;
- external sync succeeds repeatedly without duplication;
- authentication, authorization, SSRF, and output-sanitization reviews pass;
- migrations, backup, restore, worker, and scheduler procedures are tested;
- no critical or high-severity unresolved defect affects data ownership or exposure.

### Initial product metrics

- activation rate: owner publishes a profile and one post/source;
- median time from registration to public profile;
- first-sync and recurring-sync success rates;
- percentage of timeline items with complete provenance;
- oldest queued job and retry recovery rate;
- API error rate and p95 response latency;
- payment completion and reconciliation rates after Phase 5.

## 11. Major risks and mitigations

| Risk | Mitigation |
|---|---|
| Scope spreads across identity, social, commerce, and federation | Enforce phase gates and protect the first content-ownership loop |
| External URLs create SSRF and parser risk | Use a hardened outbound client before user-configurable connectors |
| Provider differences leak into business logic | Keep adapters behind interfaces and normalized models |
| Async failures remain invisible | Persist job state, attempts, next retry, sanitized errors, and metrics |
| Payment duplicates or forged callbacks corrupt orders | Idempotency keys, signed webhooks, deduplication, transactional transitions |
| Federation creates abuse and trust problems | Delay until local moderation/identity semantics are stable; add block/report controls |
| Documentation diverges from implementation | Treat OpenAPI and bilingual docs as part of Definition of Done |

## 12. Review cadence

- Review delivery progress weekly against exit criteria, not percentage-complete estimates.
- Demonstrate one working vertical slice at the end of each iteration.
- Reassess roadmap order at every milestone, but do not bypass security or data-integrity dependencies.
- Update this roadmap when scope, protocol choice, provider priority, or team capacity changes materially.

