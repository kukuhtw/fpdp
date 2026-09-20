# User Journey — Federated Personal Digital Platform (FPDP)

## 1. Product overview

FPDP is a personal digital home running as an independent node on a user-controlled domain. It is designed to combine identity, profile, local content, external feeds, federation, products, and payments without locking the owner into a single platform or provider.

Core value:

- the owner controls a central digital identity on their own domain;
- local, external, and federated content can appear in one timeline with clear provenance;
- external connections can be added and removed independently;
- node operators can change connectors and payment providers without changing domain logic;
- visitors can discover the owner, consume content, and transact from one destination.

## 2. Current product state

| Area | Current state | Product target |
|---|---|---|
| Landing page | Static introduction page exists | Public profile and content entry point |
| Profile and authentication | Not implemented | Registration, login, profile, roles, API tokens |
| Local content and timeline | Not implemented | Post CRUD and unified timeline |
| External feeds | RSS, Atom, and Custom API adapters exist | Connection UI, persistence, async sync, retry, attribution |
| Federation | Not implemented | Discovery, remote actors, activities, remote objects |
| Marketplace | Not implemented | Catalog, product detail, checkout, orders |
| Payments | Interface, factory, service, and dummy adapter exist | Admin setup, real gateways, verified webhooks, idempotency |
| Admin dashboard | Not implemented | Node, integration, payment, queue, and security controls |

This journey therefore describes the **target experience** and explicitly shows implementation status.

## 3. Personas

### Node owner / creator

A creator, freelancer, professional, or individual using a personal domain as the center of their identity and work.

- Goal: build a profile, publish content, aggregate feeds, and own the audience relationship.
- Needs: simple setup, privacy controls, correct attribution, visible synchronization state.
- Concerns: technical configuration, failed synchronization, leaked credentials.

### Visitor / audience

A person visiting the owner's domain to understand their identity, consume content, or discover products.

- Goal: find relevant information and understand the source of each item.
- Needs: clear navigation, source labels, canonical links, good mobile experience.
- Concerns: difficulty distinguishing original, federated, and aggregated content.

### Buyer / customer

A visitor purchasing a product or service from the node owner.

- Goal: select an offer, pay safely, and receive confirmation.
- Needs: transparent totals and transaction state, suitable payment methods, receipt.
- Concerns: payment failure, stale status, duplicate orders.

### Node administrator / operator

The person responsible for installing, configuring, securing, and monitoring a node.

- Goal: manage domains, integrations, payment gateways, queues, and security.
- Needs: validated configuration, health state, audit logs, sandbox/production separation.
- Concerns: invalid credentials, forged webhooks, failed jobs, provider dependency.

## 4. Primary owner journey

**Scenario:** a creator establishes a personal digital home, completes a profile, connects an RSS source, and publishes local content.

| Stage | User goal and action | Expected experience | Main risk | Design opportunity | Status |
|---|---|---|---|---|---|
| Discover | Open the landing page and assess FPDP | Explain ownership, aggregation, federation, and commerce in plain language | “Node” and “federation” feel technical | Lead with benefits and a real personal-home example | Partial |
| Create node | Register and select a domain/subdomain | Create user, node, and safe defaults | Domain setup is confusing | Guided wizard with quick subdomain and custom-domain paths | Not built |
| Build profile | Add name, bio, avatar, links, portfolio, visibility | Live preview and explicit publish control | Incomplete data becomes public | Draft state and completion checklist | Not built |
| Connect sources | Select RSS/Atom/Custom API, enter URL, test | Validate, preview, and schedule synchronization | Invalid or incompatible feed | Test connection and actionable errors | Backend foundation |
| Curate timeline | Choose sources, visibility, order, hidden items | Persistent Local/Federated/External labels and canonical links | Duplicate or ambiguous provenance | Filters, deduplication, source badges | Not built |
| Publish locally | Write, preview, select visibility, publish | Store as local content and add to timeline | Accidental publishing | Autosave, preview, unpublish | Not built |
| Share and grow | Share profile/post URL and enable federation | Stable canonical URLs and node discovery | Limited early distribution | Share metadata and follow CTA | Not built |
| Maintain | Review connection health and update profile | Last sync, next sync, errors, and recovery actions | Silent async failure | Actionable health center and alerts | Not built |

Ideal flow:

`Landing → Register/Login → Create node → Complete profile → Connect feed → Preview → Set visibility → Publish → Share domain → Monitor health`

## 5. Visitor journey

| Stage | Visitor action | Expected system behavior | Status |
|---|---|---|---|
| Arrive | Open the owner's domain | Show identity, concise bio, primary CTA, navigation | Partial |
| Explore | Browse profile, portfolio, timeline, products | Group and filter content clearly | Not built |
| Verify provenance | Inspect badges or open original source | Show content type, provider, author, canonical URL | Data foundation only |
| Engage | Read details, share, follow, or contact | Preserve node context and privacy | Not built |
| Return | Bookmark or follow through federation/feed | Deliver updates without central platform dependence | Not built |

## 6. Buyer journey

| Stage | Buyer action | Expected system behavior | Recovery requirement | Status |
|---|---|---|---|---|
| Discover | Open product list or product CTA | Identify local, federated, or external offers | Keep seller origin explicit | Not built |
| Evaluate | Review product, seller, price, policy | Show ownership, stock, total, currency | Handle inconsistent remote data | Not built |
| Checkout | Supply details and choose payment method | Create one order and use an active gateway | Prevent duplicate submission | Not built |
| Pay | Follow payment URL/instructions | Show `PENDING`, exact total, expiry | Recover from interrupted redirect | Dummy only |
| Confirm | Return or wait for status update | Verified idempotent webhook updates payment/order | Reject forged or duplicate events | Contract only |
| After-sales | Track order or request refund | Show order history and refund result | Reconcile local/provider status | Dummy refund only |

Minimum visible payment lifecycle:

`CREATED → PENDING → PAID | FAILED | EXPIRED | CANCELLED → REFUNDED (optional)`

## 7. Administrator journey

| Stage | Administrator action | Expected system behavior | Status |
|---|---|---|---|
| Install | Configure PHP, database, domain, environment | Run dependency and configuration preflight checks | Partial/manual |
| Bootstrap | Create first admin and node policy | Enforce secure setup before public access | Not built |
| Configure connector | Select adapter, interval, credentials; test | Encrypt secrets and display capabilities/test result | Backend foundation |
| Configure payment | Select provider/environment/methods; test | Validate adapter and sandbox transaction | Dummy only |
| Operate | Monitor queue, retry, webhook, transactions | Health dashboard, sanitized logs, controlled retries | Schema foundation |
| Secure | Rotate secrets, manage roles/tokens, audit | Mask secrets and preserve audit trail | Not built |
| Extend | Install a new interface-compliant adapter | Register it without changing domain logic | Architecture foundation |

## 8. UX principles

1. Ownership must be visible through the user's domain and local content identity.
2. Provenance must always include source type, provider, author, and canonical URL.
3. Users choose sources, visibility, and placement before automation takes effect.
4. Connections must be easy to revoke with clear retention consequences.
5. Async work exposes last sync, next sync, state, and retry actions.
6. Payments expose amount, currency, expiry, provider, and consistent state.
7. Technical concepts are progressively disclosed and translated into plain language.

## 9. Recommended delivery order

- **P0:** authentication, node/profile setup, local post CRUD, public profile, local timeline.
- **P1:** feed connection test/preview, normalized storage, attributed timeline, queue/retry/disconnect.
- **P2:** products, orders, one real gateway, verified idempotent webhooks, receipt, admin payment setup.
- **P3:** discovery, remote actors/activities, federated timeline, moderation and failure handling.

The most practical MVP journey is:

`Owner login → Complete profile → Create local post → Connect RSS → Preview and sync → View attributed timeline → Open public profile`.

