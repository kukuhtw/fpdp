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
- Local products and marketplace
- Administration dashboard

### Outside the MVP

- Complete implementation of every payment gateway
- Complete OAuth integration for every social provider
- End-to-end federated commerce in the initial phase
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

### Seller / merchant

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

FPDP places the user at the center of digital identity and data ownership. It is not merely another social network; it is a personal internet node connecting websites, social activity, federation, commerce, and payments.

