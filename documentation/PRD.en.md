# Product Requirements Document (PRD)

## 1. Product summary

FPDP is a personal digital home that gives users a private domain, profile, social feed, marketplace, and payment endpoint. Independent nodes can connect through federation.

## 2. Problem statement

Individuals currently depend on large platforms for identity, content, transactions, data, and audience access. FPDP makes the user's domain the center of their digital identity while retaining connections to other networks through federation and external integrations.

## 3. Goals

- Operate as an independent node.
- Combine a personal website, social feed, portfolio, and marketplace.
- Integrate external content without losing attribution.
- Let each node choose payment providers through an abstraction layer.

## 4. Target users

1. Personal brands
2. Creators and content producers
3. Freelancers and professionals
4. Sellers and merchants
5. Node administrators and operators

## 5. User stories

### Personal website

- As a user, I want to register and get my own node, owner account, and profile in one step.
- As a user, I want a profile page on my own domain.
- As a user, I want to publish posts from my node.

### Personalization

- As an owner, I want to choose a theme and layout for my public presentation.
- As an owner, I want to override styling with my own custom CSS, without risking my visitors' security.
- As an owner, I want to set my node's default language and choose which languages are available to visitors.

### External feeds

- As a user, I want to connect Instagram, LinkedIn, RSS, and X to my timeline.
- As a user, I want to see the original source of every item.

### Payments

- As an administrator, I want to select the active node payment gateway.
- As a user, I want to check out through a gateway approved by the node.

### Federation

- As a node, I want to discover another node's capabilities.
- As a user, I want federated content to show its source clearly.

## 6. Functional requirements

### Authentication

- Owner registration provisions a node, owner user, and public profile in one step; passwords are hashed, never stored or logged in plain text.
- Bearer tokens authenticate the REST API. Tokens are hashed at rest, carry an expiration, and can be explicitly revoked on logout.
- Registration and login are rate-limited per client IP to resist credential stuffing and spam account creation.
- Session-based authentication for a browser owner dashboard is planned but not yet implemented; today's API is bearer-token only.
- OAuth 2.0 remains planned for connecting external content providers (Instagram, LinkedIn, etc.), not for owner login.

### Profiles

- Each user has exactly one profile: handle, display name, bio, avatar, links, and a canonical URL on the node's domain.
- Profile visibility is `PUBLIC`, `UNLISTED`, or `PRIVATE`; private profiles are not served by the public read endpoint.
- Owners update their own profile; unknown fields and invalid values are rejected with field-level validation errors.

### Personalization

- Each node owner can set a theme, layout choice, and custom CSS override for their public presentation.
- Custom CSS is sanitized server-side before it is ever rendered back to a visitor (no `@import`, script injection, or unbounded length).
- Each node has a default locale and a list of enabled languages; the UI's language switcher is driven by this list.
- A safe built-in theme and locale are always the fallback when a setting is missing or invalid.

### Content

- Distinguish local, federated, and external posts.
- Include `source_type`, `source_provider`, and `canonical_url`.
- Allow visibility and profile/timeline placement controls.

### Payments

- Implement adapters behind a common interface.
- Select named providers through a factory.
- Normalize generic webhook events.
- Prevent duplicate webhook processing through idempotency.

### External connectors

- Use an abstraction layer.
- Support RSS, Atom, and custom APIs in the MVP.
- Allow OAuth connectors in later phases.

## 7. Non-functional requirements

- `/api/v1/` API versioning
- Secure secret storage
- Async queue and scheduled synchronization
- Webhook verification and replay prevention
- Payment and integration monitoring without credential leakage

## 8. Acceptance criteria

- A user can register an account, authenticate with a bearer token, and read their own context via `/me`.
- A user can create and update a profile on their node, with visibility rules enforced for public reads.
- Registration and login reject excessive attempts from the same client with a rate-limit error.
- A user can customize their node's theme, layout, custom CSS, and default/available languages (planned; not yet implemented).
- A user can add an RSS or custom feed.
- An administrator can activate a default gateway.
- Payment flows depend on the common interface, not a specific provider.
- Timeline items display a clear source identity.

## 9. Out of scope

- Complete public plugin marketplace in the MVP
- Advanced multi-vendor settlement
- Complete OAuth social connector coverage in the initial phase

## 10. Product phases

1. Personal website, local profile, and posts (identity, registration, authentication, and profile management are delivered; posts, personalization, and the public-facing UI are not yet built)
2. Social feed, timeline, and external aggregation
3. Federation and remote actors
4. Marketplace and payment abstraction
5. Advanced connectors and plugin ecosystem

## 11. Addendum: AI interaction and monetization (2026-09-19)

This addendum adds an AI-assisted, monetizable visitor experience on top of the core personal digital home. See [`AI-MONETIZATION-STRATEGY.en.md`](AI-MONETIZATION-STRATEGY.en.md) for the detailed data model and phased implementation plan.

### 11.1 Additional target users

6. Visitors who want to read an owner's CV or chat with their profile assistant
7. Advertisers who want to rent ad space on a node

### 11.2 Additional user stories

**AI configuration**

- As an owner, I want to connect my own OpenAI or Anthropic account so my profile assistant uses the model I choose.
- As an owner, I want my LLM credentials stored securely and never shown back to me or anyone else in full.

**Paid CV access**

- As an owner, I want to upload my CV and charge visitors a fee I choose to view it, including free.
- As a visitor, I want to pay once and view the CV without paying again on a later visit.

**Paid chatbot**

- As an owner, I want visitors to be able to ask my profile assistant questions about my background and services.
- As an owner, I want to charge for chatbot access so the feature doesn't become a free, unlimited API endpoint for anyone.
- As a visitor, I want to know clearly that I'm chatting with an automated assistant, not the owner directly.

**Visitor identity**

- As a visitor, I want to sign in with my Google account instead of creating a new password just to read a CV or start a chat.
- As an owner, I want every paid interaction tied to a real, identifiable visitor so I can resolve disputes.

**Analytics**

- As an owner, I want to see how many unique visitors and how much traffic my node gets each day.

**Ad marketplace**

- As an owner, I want to define ad slots on my profile and set my own daily, weekly, or monthly price.
- As an advertiser, I want to book and pay for an ad slot for a specific period and know exactly when it starts and ends.

### 11.3 Additional functional requirements

**LLM abstraction**

- Define an `LLMProviderInterface` analogous to the existing payment and external-content interfaces.
- Support at least OpenAI and Anthropic adapters at launch; reject unconfigured or unsupported provider codes explicitly.
- Store provider, model, and encrypted credentials per node.

**Monetized content and interaction**

- CV documents and chatbot sessions are gated by a payment created through the existing `PaymentGatewayInterface`.
- Access grants (for CV) and session records (for chat) prevent re-charging an already-paying visitor within the access terms the owner defines.

**Visitor identity**

- Google OAuth 2.0 is the only supported visitor sign-in method for this addendum.
- A visitor identity is distinct from an owner/user account and is not granted any node-management permission.

**Analytics**

- Record page views with enough information to compute daily unique visitors and daily traffic, without retaining raw identifiers indefinitely.

**Ad marketplace**

- Ad slots carry independent daily, weekly, and monthly prices set by the owner.
- Bookings have an explicit start and end time and an approval state before a creative is shown publicly.

### 11.4 Additional acceptance criteria

- An owner can select and configure an LLM provider and see it take effect on their chatbot without code changes.
- A visitor cannot view a paid CV or send a chat message without first signing in with Google and completing payment where required.
- A previously paying visitor is not re-charged for the same CV within the access terms the owner set.
- An owner's daily dashboard shows unique visitors and page views for the previous day at minimum.
- An advertiser can book an available ad slot for a chosen period and see it go live only after the owner approves the creative.

### 11.5 Additional out of scope

- LLM providers beyond the initial OpenAI/Anthropic adapters (same extensible pattern, added later).
- Real-time ad bidding, programmatic exchanges, or automated creative moderation.
- Visitor sign-in methods other than Google OAuth.
- Multi-currency ad and chat billing beyond what the payment abstraction already supports.

