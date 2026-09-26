# Business Requirements Document (BRD)

## 1. Background

The Federated Personal Digital Platform (FPDP) is a personal digital home that gives each individual their own domain, digital identity, content, and social channels connected to external platforms. Its main purpose is to give users control over their data, identity, content, and payment choices.

## 2. Vision

Create a personal internet node for every individual so that identity, content, community, and payments do not depend on a single large platform.

## 3. Business objectives

- Give every user a personal domain as the center of their digital identity.
- Let domain owners manage their website, profile, and content.
- Provide federation between independent nodes without a central platform.
- Integrate attributed content from RSS, Instagram, LinkedIn, X, YouTube, and marketplaces.
- Provide a payment abstraction so users or administrators can select a suitable gateway.
- Combine profile, blog, portfolio, products, social presence, and payments in one digital home.

## 4. Scope

### In scope

- Personal website and profile
- Social feed aggregation
- Federated posting and inter-node communication
- Payment gateway abstraction
- External content integration
- A personal online shop owned by the node owner (one seller per node) and local products
- Product distribution to the fediverse as the foundation for federated commerce
- Administration dashboard

### Outside the MVP

- Complete implementation of every payment gateway
- Complete OAuth integration for every social provider
- End-to-end federated commerce (cross-node orders) in the initial phase — it remains a key differentiator and a next-phase goal
- Public plugin marketplace

## 5. Personas

### Domain owner / individual

- Wants an independently owned digital identity.
- Wants control over data and content.
- Wants external feeds without losing attribution.

### Node administrator / operator

- Configures payments, domains, and system settings.
- Manages external connections.
- Enables or disables features.

### Seller / merchant (owner who sells)

- Sells local or federated products.
- Uses a preferred payment gateway supported by the node.

## 6. Primary business requirements

- Every node is technically and operationally independent.
- No mandatory dependency exists on one payment gateway or social provider.
- Federation is a network core, not a central platform.
- External data always preserves its origin and attribution.
- Users can independently select, connect, and disconnect external services.

## 7. Functional requirements

### Identity and access

- Every domain owner can register an account, which provisions their node, owner user, and public profile together.
- Passwords are hashed; API access uses bearer tokens that are hashed at rest, expire, and can be explicitly revoked on logout.
- Registration and login are rate-limited per client to resist credential stuffing and spam account creation.
- Owners control their public profile (display name, bio, avatar, links) and its visibility (public, unlisted, or private).
- Each node owner can personalize their node's presentation: theme, layout, custom CSS, and default/available languages, with a safe built-in fallback when a setting is missing or invalid.

### Payments

- Select gateways dynamically.
- Let administrators add, enable, and disable gateways.
- Keep business logic independent through a common interface.
- Verify and process webhooks idempotently.

### External integration

- Add RSS, Atom, or custom API feeds.
- Normalize all external content.
- Display provider origin and canonical URL.
- Run synchronization asynchronously.

### Social and timeline

- Display local, federated, and external content.
- Let users choose visible sources.
- Apply display and privacy controls to external content.

### Federation

- Expose node capability discovery.
- Process activities and remote objects.
- Keep local, federated, and external ownership distinct in federated commerce.

## 8. Non-functional requirements

- PHP 8.2+
- MySQL 8+
- Versioned REST JSON API
- Modular monolith with service layer
- Encrypted tokens, hidden secrets, and sanitized logs
- Queue and cron support for asynchronous work
- Adapter, factory, strategy, and repository patterns for maintainability

## 9. Success criteria

- The system operates as an independent node.
- A user can independently register, authenticate, and manage a public profile with visibility controls.
- A user has a Personal Digital Home on their domain.
- A node can select its payment gateway.
- External feeds connect and display with attribution.
- Federation can grow without a central platform.

## 10. Business risks

- Excessive dependency on third-party platforms.
- Inconsistent provider APIs.
- Poorly managed asynchronous synchronization.
- Payment configuration and credential-security mistakes.

## 11. Conclusion

FPDP places the user at the center of digital identity and data ownership. It is not merely another social network; it is a personal internet node connecting websites, social activity, federation, a personal online shop, federated commerce, and payments.

## 12. Addendum: AI interaction and monetization (2026-09-19)

This addendum extends the BRD with owner-configurable AI chat, paid content access, mandatory visitor identity for interactive features, traffic analytics, and an advertising marketplace. It adds a revenue and engagement layer on top of the personal digital home defined in Sections 1–11; see [`AI-MONETIZATION-STRATEGY.en.md`](AI-MONETIZATION-STRATEGY.en.md) for the detailed data model and delivery plan.

### 12.1 Business rationale

- Owners can monetize their expertise and attention directly, without a third-party platform's cut or algorithm.
- An AI-powered profile assistant increases visitor engagement and gives owners a scalable way to answer repetitive questions (about their CV, services, or availability) without their own time.
- Node-level advertising turns visitor traffic into a revenue stream the owner controls end to end.

### 12.2 New and extended personas

- **Visitor (new):** an unauthenticated browser session that becomes an identified visitor only when it wants to interact — viewing paid content, chatting, or booking an ad. Visitors sign in with Google; FPDP never stores a separate visitor password.
- **Domain owner (extended):** also configures their own LLM provider and model, sets prices for CV access and chatbot sessions, and manages ad slots and pricing.
- **Advertiser (new):** a person or business that rents an ad slot from a node owner for a fixed period.

### 12.3 Business objectives

- Let owners plug in their preferred LLM provider (OpenAI, Anthropic, or others) without FPDP depending on any single one.
- Let owners charge for premium content (their CV/resume) and premium interaction (a chatbot conversation), with the fee fully owner-controlled, including free.
- Require verified visitor identity (Google OAuth) before any paid or interactive action, so payments and chat history can be attributed and disputes resolved.
- Give owners visibility into their own traffic (unique visitors and page views per day) without depending on third-party analytics.
- Let owners sell their own ad inventory (daily, weekly, or monthly) directly to advertisers.

### 12.4 Scope additions

In scope for this addendum:

- LLM provider configuration per node (provider, model, credentials).
- Paid CV/resume access.
- Paid chatbot sessions grounded in the owner's own profile and CV content.
- Google OAuth as the mandatory visitor identity for any paid or interactive action.
- Daily traffic and unique-visitor reporting for the owner.
- Ad slot definition, pricing (daily/weekly/monthly), and booking by advertisers.

Outside this addendum's MVP:

- LLM providers beyond an initial adapter set (start with OpenAI and Anthropic; others follow later behind the same interface).
- Real-time ad bidding or programmatic ad exchanges.
- Automated moderation of chatbot answers or ad creatives beyond a manual owner-approval step.
- Multi-currency billing beyond what the existing payment gateway abstraction already supports.

### 12.5 Functional requirements

**AI provider configuration**

- An owner can select an LLM provider (OpenAI, Anthropic, or another supported adapter), supply their own API credentials, and choose a model.
- Credentials are encrypted at rest and never exposed in API responses or logs, consistent with existing payment-gateway credential handling.
- Unsupported provider codes are rejected explicitly, consistent with the factory pattern already used for payments and external connectors.

**Paid CV/resume access**

- An owner can upload a CV/resume and set its access fee, including free.
- A visitor must authenticate with Google and complete payment before the document is served.
- Access grants are recorded so a visitor who already paid is not charged again for the same document.

**Paid chatbot interaction**

- An owner can enable a chatbot on their public profile that answers questions grounded in their own profile and CV content.
- An owner sets the fee model for chatbot access; a visitor must authenticate with Google and pay before chatting.
- Conversations are logged per visitor for support, audit, and abuse review.

**Visitor authentication**

- Passive profile viewing remains open to anonymous visitors.
- Any interactive or paid action (viewing a paid CV, starting a chat, booking an ad) requires the visitor to sign in with Google first.
- FPDP stores only the minimum visitor profile data needed (identifier, email, display name) to attribute payments and interactions.

**Traffic analytics**

- An owner can see daily unique-visitor counts and daily page-view counts for their node.
- Analytics are aggregated without retaining raw visitor identifiers longer than needed for the daily rollup.

**Ad banner marketplace**

- An owner can define one or more ad slots on their profile, each with an independent daily (24-hour), weekly, or monthly price.
- An advertiser books and pays for a slot for a chosen period; the owner can review the creative before it goes live.
- A booking's active window is enforced automatically; expired bookings stop displaying without manual owner action.

### 12.6 Non-functional requirements

- LLM API usage is rate-limited and cost-bounded per node to prevent runaway billing from misconfiguration or abuse.
- All monetized flows reuse the existing `PaymentGatewayInterface` abstraction; no feature hard-codes a specific payment provider.
- Visitor personal data (from Google OAuth) is handled under the same secret-hygiene and audit-logging principles already required for owner credentials.
- Chatbot responses are clearly attributed as automated and never presented as the owner's own real-time reply.

### 12.7 Risks

- Uncontrolled LLM API cost if an owner's chatbot is spammed or scraped.
- Reputational risk if a chatbot gives an inaccurate or inappropriate answer while representing the owner.
- Payment disputes for intangible goods (a chat session, a CV view) are harder to resolve than physical/product orders.
- Ad content moderation gaps could expose an owner's page to inappropriate creatives if the manual review step is skipped.
- Visitor PII from Google OAuth increases the platform's privacy and compliance surface.

## 13. Addendum: Federated Connection Discovery on Public Profiles (2026-09-19)

### 13.1 Business requirement

A public profile is also a discovery surface for independent people and nodes the owner trusts or follows. Visitors must be able to see federated users connected to that profile and previews of their latest public activity without losing provenance.

### 13.2 Functional requirements

- The public profile displays federated connections: remote identity, node/domain, relationship status, and canonical link.
- Each connection may show its latest public remote post when available and permitted by local moderation policy.
- Previews preserve `source_type=FEDERATED`, remote actor/node, original canonical URL, publication time, and synchronization state.
- Owners can show or hide a connection on their public profile without disconnecting it.
- `BLOCKED`, `MUTED`, rejected, or node-blocked relationships never appear publicly.
- Remote-node failure must not make the local profile unavailable; use valid cached data or omit the preview.
- Cycles such as A→B→D→E→A are valid. Graph traversal requires cycle detection and depth limits.

### 13.3 Acceptance criteria

- Visitors can distinguish owner-local posts from posts by federated connections.
- Actor/post links open the correct remote canonical URL.
- Owners can control public connection visibility.
- Blocked actors/nodes disappear from lists and previews.
- The profile remains available when a remote node times out or is offline.
