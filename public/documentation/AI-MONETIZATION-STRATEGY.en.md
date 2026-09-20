# FPDP AI Interaction and Monetization Strategy (English)

## 1. Purpose and implementation status

This document defines the **target design** for six additions requested as a BRD/PRD addendum: owner-configurable LLM providers, paid CV/resume access, a paid profile chatbot, mandatory Google OAuth for any visitor interaction, daily traffic analytics, and an ad banner marketplace.

None of this is implemented in the current repository. Phase 1 (identity, authentication, and owner profiles) is delivered and is the foundation this addendum builds on: node/user/profile creation, bearer-token auth, and the `PaymentGatewayInterface` abstraction already exist and are reused rather than rebuilt.

## 2. Feature summary

| # | Feature | Who pays | What gates it |
|---|---|---|---|
| 1 | LLM provider configuration | — | Owner-only setting, no visitor-facing gate |
| 2 | Paid CV/resume access | Visitor | Google sign-in + payment |
| 3 | Paid chatbot | Visitor | Google sign-in + payment |
| 4 | Visitor identity | — | Required before 2, 3, or 6 |
| 5 | Traffic analytics | — | Owner-only read, no visitor gate |
| 6 | Ad banner marketplace | Advertiser | Google sign-in + payment |

Passive profile viewing (reading a public profile, its posts, its timeline) stays open to anonymous visitors. Google sign-in is required only at the point a visitor tries to do something that costs the owner money to provide or that the owner is charging for.

## 3. Visitor identity: mandatory Google OAuth

### 3.1 Why a separate identity from owners

Owners (`users`) authenticate to **manage** a node. Visitors authenticate to **consume or pay for** something on a node they do not own. These are different trust levels and different data: a visitor identity carries no role, no node-management permission, and no password (Google is the only credential).

Because nodes are independently operated (Product principle 2), a visitor's FPDP-side record is **node-scoped**, not global: the same Google account signing in on two different nodes gets two independent `visitor_accounts` rows, one per node. Only the visitor's Google identity (`sub`) is shared across nodes; FPDP does not synchronize visitor state between independently hosted nodes.

### 3.2 Flow

```text
Visitor clicks "Sign in with Google" on a gated action
  → redirect to Google's OAuth 2.0 consent screen (scope: openid, email, profile)
  → Google redirects back with an authorization code
  → node exchanges the code for an ID token, verifies its signature and audience
  → node finds or creates a visitor_accounts row keyed by (node_id, google_sub)
  → node issues a visitor bearer token (same shape as owner auth_tokens: hashed at rest, expiring, revocable)
  → the gated action (view CV / start chat / book ad) proceeds using that token
```

### 3.3 Rules

- A node never asks a visitor for a password; Google is the only visitor credential.
- A visitor token has no access to any `/me`, owner, or admin endpoint; it is scoped to visitor-facing endpoints only.
- Revoking/expiring a visitor token does not delete payment history or access grants already recorded against that `visitor_accounts` row.

## 4. LLM provider abstraction

### 4.1 Interface

Mirrors the existing `PaymentGatewayInterface` / `ExternalContentProviderInterface` pattern already in `app/Contracts/`:

```php
interface LLMProviderInterface
{
    public function getName(): string;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{content: string, tokens_used: int}
     */
    public function complete(array $messages, array $options = []): array;
}
```

`LLMProviderFactory::create(string $providerCode, array $config)` supports `OPENAI` and `ANTHROPIC` at launch and throws the same `UnsupportedProviderException` used by the payment and connector factories for any other code — no silent fallback to a default provider, for the same reason a wrong payment gateway must never be silently substituted.

### 4.2 Configuration

One active `llm_configs` row per node: provider code, model name, and an encrypted API key (same encryption approach as `payment_gateway_configs.encrypted_value`). The key is never returned in any API response; a `GET` of the config shows only the provider, model, and a masked key indicator (e.g., `sk-...ab12`).

### 4.3 Cost and abuse control

- Every call to a provider goes through the existing `RateLimiter` (Phase 1), keyed by `(node_id, 'llm_call')`, with a per-node ceiling the owner cannot exceed regardless of visitor demand.
- Token/response length is capped per request.
- A node with no configured provider, or an invalid/revoked key, fails the chatbot feature closed (visitors see "chat is unavailable"), never falls back to a shared default key.

## 5. Paid CV/resume access

### 5.1 Flow

```text
Owner uploads a CV → cv_documents row (price_amount, price_currency; 0 = free)
Visitor requests the CV
  → if price_amount = 0: serve immediately, no payment step
  → if price_amount > 0:
      → require a visitor bearer token (Section 3); if absent, return 401 and prompt Google sign-in
      → check cv_access_grants for (cv_document_id, visitor_id); if found, serve immediately
      → otherwise create a payment via the existing PaymentGatewayInterface for price_amount/price_currency
      → on payment confirmation, insert a cv_access_grants row, then serve the document
```

### 5.2 Rules

- A grant is permanent by default (pay once, view anytime); the owner may configure a grant to expire (e.g., 30 days) — the default is no expiry to keep the mental model simple for the MVP.
- The document itself is stored outside the public webroot; it is only ever streamed through the gated endpoint, never linked directly.

## 6. Paid chatbot interaction

### 6.1 Grounding

The chatbot only answers from content the owner explicitly published: profile bio, CV text (extracted at upload time), and local posts marked public. It must not be given access to private data, payment records, or other visitors' conversations. The system prompt sent to the configured `LLMProviderInterface` states these boundaries and requires the model to say it does not know rather than guess.

### 6.2 Flow

```text
Visitor opens the chat widget on a profile
  → require a visitor bearer token (Section 3); if absent, prompt Google sign-in
  → if the owner charges per session: require an active, paid chat_sessions row
      → no active session: create a payment via PaymentGatewayInterface; on confirmation, open a chat_sessions row
  → each visitor message is stored in chat_messages, sent to LLMProviderInterface::complete()
    together with the grounding context and recent turns, and the reply is stored and returned
```

### 6.3 Rules

- Every assistant reply is labeled as automated in the response payload (`role: "assistant", automated: true`) so the frontend can never present it as the owner typing live.
- A session has a message cap and/or time limit set by the owner alongside its price; hitting the cap ends the session and, if the owner charges per session, requires a new payment to continue.

## 7. Traffic and visitor analytics

### 7.1 What is measured

- **Page view:** any request for a public page (profile, post, timeline). Recorded with `node_id`, `path`, `viewed_at`, and a `visitor_fingerprint` — either the authenticated `visitor_id` if signed in, or a salted hash of `(ip_address, user_agent)` for anonymous traffic. Raw IP addresses are never persisted.
- **Daily rollup:** `analytics_daily` (one row per `node_id` + `date`) stores `unique_visitors` (distinct fingerprints that day) and `page_views` (total requests that day), computed by a scheduled job, not on every request, to keep page rendering fast.

### 7.2 Retention

Raw `page_views` rows are retained only long enough to compute the rollup reliably (a rolling window, e.g. 35 days) and are then purged; `analytics_daily` rows are kept indefinitely since they carry no visitor-identifying data.

## 8. Ad banner marketplace

### 8.1 Flow

```text
Owner defines an ad_slots row: position/name, dimensions, and independent
daily / weekly / monthly prices (any of the three may be left unset to disable that period type)

Advertiser (authenticated via the same visitor Google sign-in, Section 3) browses available slots
  → picks a slot and a period type (DAILY | WEEKLY | MONTHLY) and a start date
  → pays via PaymentGatewayInterface for that period's price
  → an ad_bookings row is created with approval_status = PENDING and the computed starts_at/ends_at

Owner reviews the creative
  → APPROVED: the booking becomes ACTIVE at starts_at and serves automatically
  → REJECTED: the payment is refunded through the same PaymentGatewayInterface, booking is cancelled

A scheduled job flips ACTIVE bookings to EXPIRED once ends_at passes; expired creatives stop
rendering without any manual owner action.
```

### 8.2 Rules

- A slot can have at most one `ACTIVE` booking at a time; overlapping bookings for the same slot/period are rejected at booking time, before payment is taken.
- Rejected or expired creatives are never displayed, even if still stored for the advertiser's record.

## 9. Data entities

```text
llm_configs
    id, node_id (FK, one active row per node), provider_code, model,
    encrypted_api_key, status, created_at, updated_at

visitor_accounts
    id, public_id (UUID), node_id (FK), google_sub, email, display_name,
    avatar_url, created_at, last_seen_at
    UNIQUE (node_id, google_sub)

visitor_tokens
    id, visitor_id (FK), token_hash (UK), expires_at, revoked_at, created_at

cv_documents
    id, public_id (UUID), node_id (FK), title, storage_key,
    price_amount, price_currency, status, created_at, updated_at

cv_access_grants
    id, cv_document_id (FK), visitor_id (FK), payment_id (FK, nullable if free),
    granted_at
    UNIQUE (cv_document_id, visitor_id)

chat_sessions
    id, public_id (UUID), node_id (FK), visitor_id (FK), payment_id (FK, nullable if free),
    started_at, ended_at, message_count, status

chat_messages
    id, session_id (FK), role (VISITOR | ASSISTANT), content, created_at

page_views
    id, node_id (FK), path, visitor_fingerprint, viewed_at

analytics_daily
    id, node_id (FK), date, unique_visitors, page_views
    UNIQUE (node_id, date)

ad_slots
    id, public_id (UUID), node_id (FK), name, width, height,
    price_daily, price_weekly, price_monthly, currency, status

ad_bookings
    id, public_id (UUID), ad_slot_id (FK), advertiser_visitor_id (FK),
    creative_url, target_url, period_type (DAILY | WEEKLY | MONTHLY),
    starts_at, ends_at, payment_id (FK), approval_status (PENDING | APPROVED | REJECTED),
    status (SCHEDULED | ACTIVE | EXPIRED | CANCELLED)
```

Foreign keys to `nodes`/`users` follow the same `ON DELETE CASCADE` / `ON DELETE SET NULL` conventions already established in `documentation/ERD.en.md` Section 9.

## 10. Payment integration

No new payment code path is introduced. CV access, chatbot sessions, and ad bookings all create a payment the same way: `PaymentService::createPayment($gatewayCode, [...])` against whichever gateway the node has configured (Phase 5 of the main roadmap). A rejected or failed payment simply prevents the grant/session/booking row from being created — there is no separate "monetization payment" concept to maintain.

## 11. Security and privacy requirements

- LLM and payment credentials are encrypted at rest and excluded from logs, exports, and API responses, per the existing security considerations in the top-level `README.md`.
- Google ID tokens are verified (signature, issuer, audience, expiry) server-side before a `visitor_accounts` row is trusted; a node never accepts a client-supplied "I am this Google user" claim without verification.
- Visitor PII (email, display name, avatar URL) is limited to what Google's `openid`/`email`/`profile` scopes return — no additional visitor data is requested.
- Analytics fingerprints are salted and non-reversible to a specific IP address once hashed.
- All monetized endpoints are rate-limited per visitor identity in addition to the per-node LLM cost ceiling, to stop one visitor from exhausting a node's budget alone.

## 12. Recommended implementation order

1. **Delivered.** **Visitor identity (Google OAuth).** Must exist before any other item in this addendum, since every paid or interactive feature depends on it. Deliverable: `visitor_accounts`, `visitor_tokens` migrations; OAuth redirect/callback endpoints; visitor bearer-token issuance and verification mirroring the owner `AuthService` pattern.
2. **LLM provider abstraction.** Deliverable: `LLMProviderInterface`, `LLMProviderFactory` (OpenAI, Anthropic), `llm_configs` migration, owner-facing config endpoint. No visitor-facing surface yet.
3. **Delivered.** **Paid CV/resume access.** Deliverable: `cv_documents`, `cv_access_grants` migrations; upload endpoint; gated read endpoint reusing `PaymentGatewayInterface`. The simplest paywall — good end-to-end proof of visitor identity + payment before the more complex chatbot. Implementation note: since Phase 5 has not yet built payment persistence or webhook confirmation, a successful `PaymentGatewayInterface::createPayment()` call is treated as confirmed for the DUMMY gateway (the only one implemented); a real gateway must switch this to webhook-driven confirmation. A CV upload replaces the node's single active document and revokes all prior grants against it, since a grant is a purchase of that specific content, not a standing subscription.
4. **Paid chatbot.** Deliverable: `chat_sessions`, `chat_messages` migrations; chat endpoint combining LLM abstraction (step 2), visitor identity (step 1), and payment (step 3's pattern); grounding-context builder; rate limiting.
5. **Traffic analytics.** Deliverable: `page_views`, `analytics_daily` migrations; view-recording middleware/hook; daily rollup job; owner-facing read endpoint. Independent of steps 2–4; can run in parallel once step 1 exists for authenticated-visitor fingerprints (anonymous fingerprinting does not even need step 1).
6. **Ad banner marketplace.** Deliverable: `ad_slots`, `ad_bookings` migrations; slot management endpoints (owner); booking + payment endpoint (advertiser, reuses step 1 and the payment pattern from step 3); approval workflow; expiry job.

Steps 3–6 each depend only on step 1 (and, for the chatbot, step 2) — they do not depend on each other and can be built in parallel by different contributors once the identity foundation lands.

## 13. Risks and open questions

- **LLM cost overrun:** mitigated by the per-node rate limit and response-length cap in Section 4.3, but the exact ceiling values need a product decision before launch.
- **Chatbot accuracy/liability:** the grounding restriction (Section 6.1) reduces but does not eliminate the risk of a wrong or embarrassing answer; a "report this answer" visitor action is worth adding once the feature ships.
- **Payment disputes on intangible goods:** a chat session or CV view cannot be "returned"; refund policy for these two features needs an explicit owner-facing setting (e.g., "no refunds after first message sent").
- **Ad content moderation:** the manual approval step (Section 8.1) is the only safeguard in the MVP; it does not scale to high ad volume and will need automated pre-screening later.
- **Cross-node visitor experience:** because visitor identity is node-scoped (Section 3.1), a visitor who interacts with many nodes re-authenticates and re-pays independently at each one; whether a future shared visitor-identity service is worth building is an open product question, not addressed by this addendum.

## 14. Acceptance scenario

```text
Owner configures Anthropic as their LLM provider and uploads a CV priced at $5.
A new visitor opens the profile: the profile, bio, and posts are visible with no sign-in.
The visitor clicks "View CV": prompted to sign in with Google, then to pay $5.
After payment, the CV is served; a second visit does not prompt payment again.
The same visitor opens the chat widget, is already signed in, pays the chatbot's session
fee, and asks a question; the reply is grounded in the owner's profile/CV and marked
as automated.
The owner's dashboard shows the day's unique-visitor count including this visitor.
Separately, an advertiser signs in with Google, books the owner's sidebar ad slot for
one week, pays, and — after the owner approves the creative — sees it go live at the
booking's start time and disappear automatically after seven days.
```
