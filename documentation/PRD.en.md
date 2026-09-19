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

- As a user, I want a profile page on my own domain.
- As a user, I want to publish posts from my node.

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

- PHP session authentication for the web application
- Bearer tokens for the REST API
- OAuth 2.0 for external providers

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

- A user can create a profile on their node.
- A user can add an RSS or custom feed.
- An administrator can activate a default gateway.
- Payment flows depend on the common interface, not a specific provider.
- Timeline items display a clear source identity.

## 9. Out of scope

- Complete public plugin marketplace in the MVP
- Advanced multi-vendor settlement
- Complete OAuth social connector coverage in the initial phase

## 10. Product phases

1. Personal website, local profile, and posts
2. Social feed, timeline, and external aggregation
3. Federation and remote actors
4. Marketplace and payment abstraction
5. Advanced connectors and plugin ecosystem

