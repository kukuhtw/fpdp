# FPDP Documentation / Dokumentasi FPDP

FPDP is a provider-agnostic personal digital home. The documents below distinguish the **target product contract** from the features currently implemented in the lightweight PHP MVP.

FPDP adalah personal digital home yang tidak terikat pada provider tertentu. Dokumen berikut membedakan **kontrak produk target** dari fitur yang saat ini sudah tersedia pada MVP PHP.

## Product documents / Dokumen produk

| Document | English | Bahasa Indonesia |
|---|---|---|
| Business Requirements | [BRD](BRD.en.md) | [BRD](BRD.md) |
| Product Requirements | [PRD](PRD.en.md) | [PRD](PRD.md) |
| Problem Definition | [English](PROBLEM-STATEMENT.en.md) | [Bahasa Indonesia](PROBLEM-STATEMENT.id.md) |
| Value Proposition & Professional Copywriting | [English](VALUE-PROPOSITION.en.md) | [Bahasa Indonesia](VALUE-PROPOSITION.id.md) |
| Work Breakdown Structure | [WBS](WBS-TASK.en.md) | [WBS](WBS-TASK.md) |
| User Journey | [English](USER-JOURNEY.en.md) | [Bahasa Indonesia](USER-JOURNEY.md) |
| Development Roadmap & Strategy | [English](ROADMAP.en.md) | [Bahasa Indonesia](ROADMAP.id.md) |
| Entity Relationship Diagram | [English](ERD.en.md) | [Bahasa Indonesia](ERD.id.md) |
| Social & Commerce Integrations | [English](SOCIAL-COMMERCE-INTEGRATIONS.en.md) | [Bahasa Indonesia](SOCIAL-COMMERCE-INTEGRATIONS.id.md) |
| Social Content Publishing & Aggregation | [English](CONTENT-AGGREGATION-GUIDE.en.md) | [Bahasa Indonesia](CONTENT-AGGREGATION-GUIDE.id.md) |
| Federation Concept | [English](FEDERATION-CONCEPT.en.md) | [Bahasa Indonesia](FEDERATION-CONCEPT.id.md) |
| AI Interaction & Monetization Strategy | [English](AI-MONETIZATION-STRATEGY.en.md) | [Bahasa Indonesia](AI-MONETIZATION-STRATEGY.id.md) |
| Interactive UI Mockup | [Dashboard](mockup/index.html) · [Public profile](mockup/public-profile.html) | [Petunjuk / Guide](mockup/README.md) |
| Mockup Navigation Map | [English](mockup/NAVIGATION-MAP.en.md) | [Bahasa Indonesia](mockup/NAVIGATION-MAP.id.md) |
| Development Progress Report | [English](PROGRESS-REPORT.en.md) | [Bahasa Indonesia](PROGRESS-REPORT.id.md) |
| Deployment Guide (VPS & shared hosting, install wizard) | [English](DEPLOYMENT-GUIDE.en.md) | [Bahasa Indonesia](DEPLOYMENT-GUIDE.id.md) |
| Dokploy Deployment | [English](DOKPLOY-DEPLOYMENT.en.md) | [Bahasa Indonesia](DOKPLOY-DEPLOYMENT.id.md) |
| Google OAuth Setup | — | [Bahasa Indonesia](GOOGLE-OAUTH-SETUP.id.md) |
| PayPal Setup | — | [Bahasa Indonesia](PAYPAL-SETUP.id.md) |
| Payment Gateway Configuration | — | [Bahasa Indonesia](PAYMENT-GATEWAY-CONFIGURATION.id.md) |
| Facebook Pages Integration | — | [Bahasa Indonesia](FACEBOOK-INTEGRATION-SETUP.id.md) |
| Deployment Guide (Dokploy) | [English](DOKPLOY-DEPLOYMENT.en.md) | [Bahasa Indonesia](DOKPLOY-DEPLOYMENT.id.md) |
| API Contract | [English](API-CONTRACT.en.md) | [Bahasa Indonesia](API-CONTRACT.id.md) |
| OpenAPI 3.1 | [Machine-readable YAML](openapi.yaml) | [Machine-readable YAML](openapi.yaml) |

## Implementation status / Status implementasi

Currently implemented / Saat ini sudah tersedia:

- a static MVC landing page / landing page MVC statis;
- payment interfaces, factory, service, and dummy gateway / interface, factory, service, dan dummy payment gateway;
- RSS, Atom, and Custom API connector adapters / adapter konektor RSS, Atom, dan Custom API;
- initial database tables for payment and external content / tabel database awal untuk pembayaran dan konten eksternal.

The OpenAPI file describes the intended MVP-facing API. Most HTTP routes, persistence services, authentication, federation, marketplace, and production payment adapters still need implementation.

File OpenAPI mendeskripsikan API target untuk MVP. Sebagian besar route HTTP, persistence service, autentikasi, federasi, marketplace, dan adapter pembayaran production masih perlu diimplementasikan.
