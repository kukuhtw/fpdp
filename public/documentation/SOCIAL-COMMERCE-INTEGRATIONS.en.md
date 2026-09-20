# Facebook, LinkedIn, TikTok, Shopee, Instagram, X, and Threads Integration Guide

## 1. Purpose and verification date

This document explains whether the named platforms provide first-party RSS, identity login, delegated API authorization, and usable content or commerce APIs—and how those capabilities should be integrated into FPDP.

Information was verified against official developer documentation on **19 September 2026**. Platform products, permissions, pricing, review requirements, and endpoint versions change frequently. Re-verify official documentation before implementation and before every production release.

## 2. Three capabilities that must not be confused

| Capability | Meaning | FPDP use |
|---|---|---|
| RSS/Atom | A public feed URL that can be polled without OAuth | Existing `RSSConnector` or `AtomConnector` |
| Identity login | Lets a person authenticate to FPDP using an external identity | Optional FPDP login provider |
| API authorization | Lets FPDP access permitted data or act for an account/shop | External content or commerce connector |

Completing OAuth does **not** automatically mean FPDP may read all posts, products, followers, or analytics. Every API endpoint has its own scopes, app-review rules, account-type rules, access tier, rate limits, and data-retention policy.

## 3. Capability matrix

“No RSS” means no supported first-party RSS/Atom account feed was found in the official developer documentation reviewed. It does not include unofficial converters or scraping services.

| Platform | Official RSS/Atom | Identity login | Delegated API authorization | Practical FPDP use |
|---|---|---|---|---|
| Facebook | No documented account/Page RSS | Yes, Facebook Login | Yes, Meta Graph API | Optional login; approved Page/content connector |
| LinkedIn | No documented member/company RSS | Yes, OpenID Connect over OAuth 2.0 | Yes, but content-read permissions are restricted | Login is practical; aggregation requires approved permissions |
| TikTok | No documented profile/video RSS | Yes, Login Kit | Yes, OAuth 2.0 + Display API | Login and authorized video/profile connector |
| Shopee | No documented shop/product RSS | No general consumer identity login | Yes, seller/shop authorization through Open Platform | Commerce connector for authorized shops |
| Instagram | No documented profile/media RSS | Connector authorization through Instagram Login; not recommended as universal FPDP identity login | Yes, Instagram API for eligible professional accounts | Business/Creator profile and media connector |
| X | No documented user-post RSS | OAuth can authenticate a user | Yes, OAuth 2.0 PKCE or OAuth 1.0a depending on endpoint | User/post connector, subject to access and pricing |
| Threads | No documented profile/thread RSS | API authorization exists; not a general identity-login product | Yes, Threads API authorization | Profile/thread connector and publishing if approved |

## 4. Platform details

### 4.1 Facebook

#### Availability

- **RSS:** no supported first-party RSS feed for personal profiles or Pages is documented.
- **Login:** Facebook Login provides an OAuth-based user authentication flow.
- **Content API:** the Meta Graph API can expose permitted Page/account data. Availability depends on the resource, Page role, access token, permission, app mode, business verification, and App Review.

#### Recommended use in FPDP

Use two separate products and consent records:

1. `FACEBOOK_LOGIN` for optional FPDP account authentication; and
2. `FACEBOOK_PAGE` for importing approved Page content.

Do not assume a token issued for basic login can read Page posts. Request only the permissions required for the selected connector and show the requested capability before redirecting the owner.

Suggested flow:

```text
Owner selects Connect Facebook Page
→ FPDP creates state + PKCE where supported
→ redirect to Meta authorization
→ verify callback state
→ exchange code server-side
→ discover Pages/resources allowed by the token
→ owner explicitly selects one Page
→ encrypt token and save external account
→ queue initial sync
```

Normalize imported items with `source_type=EXTERNAL`, `source_provider=FACEBOOK`, the original author/Page, and canonical Facebook URL.

Official references:

- [Facebook Login documentation](https://developers.facebook.com/docs/facebook-login/)
- [Meta Graph API documentation](https://developers.facebook.com/docs/graph-api/)
- [Meta App Review](https://developers.facebook.com/docs/app-review/)

### 4.2 LinkedIn

#### Availability

- **RSS:** no supported first-party RSS feed for a member profile or company Page is documented.
- **Login:** Sign In with LinkedIn uses OpenID Connect (OIDC), an identity layer over OAuth 2.0. Typical scopes are `openid`, `profile`, and optionally `email`.
- **Content API:** LinkedIn Posts API exists, but reading member posts requires restricted `r_member_social` access. Organization content requires organization permissions and an eligible Page role. Access may require LinkedIn approval.

#### Recommended use in FPDP

LinkedIn OIDC is suitable as an optional login provider. Validate the ID token signature, issuer, audience, nonce, and expiry; then link the stable `sub` claim to an FPDP user. Never use email alone as the external-account key.

Treat content aggregation as a separate connector:

- show “Requires LinkedIn approval” until FPDP has the necessary product access;
- do not promise member-post import with only OIDC scopes;
- store the person/organization URN and consented scopes;
- send the version headers required by the current LinkedIn API version;
- preserve the LinkedIn post URL and attribution.

Official references:

- [Sign In with LinkedIn using OpenID Connect](https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2)
- [Getting access to LinkedIn APIs](https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access)
- [LinkedIn Posts API and permissions](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api)

### 4.3 TikTok

#### Availability

- **RSS:** no supported first-party profile/video RSS feed is documented.
- **Login:** TikTok Login Kit is based on OAuth 2.0 and supports web authentication.
- **Content API:** Display API can return authorized profile/video information. `user.info.basic` is the baseline profile scope; `video.list` enables read access to the user's public videos and may require approval.

#### Recommended use in FPDP

Register an app, add Login Kit and the required API product, register an exact HTTPS redirect URI, and request the minimum scopes.

```text
GET /api/v1/integrations/tiktok/authorize
→ generate state
→ redirect to https://www.tiktok.com/v2/auth/authorize/
→ callback receives code and state
→ validate state
→ exchange code server-side
→ encrypt access/refresh tokens
→ call Display API
→ normalize videos into external posts
```

Keep the client secret and refresh token server-side. Handle partial consent because users may decline optional scopes. Refresh before expiry and stop synchronization when consent is revoked.

Official references:

- [TikTok Login Kit overview](https://developers.tiktok.com/doc/login-kit-overview/)
- [TikTok Login Kit for Web](https://developers.tiktok.com/doc/login-kit-web/)
- [Manage TikTok user access tokens](https://developers.tiktok.com/doc/oauth-user-access-token-management/)
- [TikTok Display API](https://developers.tiktok.com/doc/display-api-overview/)

### 4.4 Shopee

#### Availability

- **RSS:** no supported first-party shop, product, or order RSS feed is documented.
- **Login:** Shopee Open Platform authorization is for sellers/shops authorizing an integration. It is not a general “Sign in to FPDP with Shopee” identity product.
- **Commerce API:** authorized partners can access the shop APIs made available to their application, such as product and order capabilities. Access, environments, signatures, and availability vary by market and partner approval.

#### Recommended use in FPDP

Shopee does not fit `ExternalContentProviderInterface` well because its main FPDP value is commerce. Introduce a dedicated interface, for example:

```php
interface CommerceProviderInterface
{
    public function authorize(array $configuration): array;
    public function refreshToken(array $account): array;
    public function fetchProducts(array $account, ?string $cursor = null): array;
    public function fetchOrders(array $account, ?string $cursor = null): array;
    public function disconnect(array $account): bool;
}
```

Suggested flow:

```text
Admin registers an FPDP partner app in Shopee Open Platform
→ FPDP signs the shop-authorization URL with partner credentials
→ seller authorizes a shop
→ callback returns authorization data such as code and shop identity
→ FPDP exchanges it for access/refresh credentials
→ credentials are encrypted per shop
→ product/order sync jobs run with signed API requests
→ Shopee push events are verified and deduplicated
```

Do not expose partner keys in browser code. Keep separate sandbox and production configurations. Preserve `shop_id`, region/market, external product/order IDs, and Shopee canonical URLs.

Official references:

- [Shopee Open Platform developer guide](https://open.shopee.com/developer-guide)
- [Shopee Open Platform API reference](https://open.shopee.com/documents/v2/api-reference)

### 4.5 Instagram

#### Availability

- **RSS:** no supported first-party Instagram profile/media RSS feed is documented.
- **Login/authorization:** Instagram API with Instagram Login provides authorization for supported Instagram professional-account use cases. It should be treated as connector authorization rather than a universal identity provider for every FPDP user.
- **Content API:** eligible Business and Creator accounts can grant approved permissions for profile/media capabilities. Consumer-account and feature availability are intentionally limited.

#### Recommended use in FPDP

Create an `INSTAGRAM` connector and use the current Instagram Login flow rather than legacy/deprecated products. During onboarding:

1. explain that an eligible professional account is required;
2. request only the profile/media permissions actually used;
3. validate callback state and exchange the code server-side;
4. encrypt long-lived credentials and record granted scopes;
5. fetch the authorized account and media;
6. store canonical Instagram permalinks and media attribution;
7. implement revocation and deletion callbacks required by Meta policy.

Never scrape public Instagram HTML as a substitute for API access.

Official references:

- [Instagram Platform overview](https://developers.facebook.com/docs/instagram-platform/)
- [Instagram API with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/)
- [Instagram API permissions](https://developers.facebook.com/docs/permissions/)

### 4.6 X (x.com)

#### Availability

- **RSS:** no supported first-party RSS feed for a user's posts is documented.
- **Login/API authorization:** X supports user-context authorization, including OAuth 2.0 Authorization Code with PKCE and OAuth 1.0a for endpoints that require it.
- **Content API:** X API exposes posts and users according to the app's access, endpoint, scopes, rate limits, and current pay-per-use or enterprise terms.

#### Recommended use in FPDP

Prefer OAuth 2.0 Authorization Code with PKCE for supported v2 read operations. Use an OAuth 1.0a adapter only when a required endpoint explicitly needs it.

The connector should:

- request identity/read scopes only when importing posts;
- obtain the authenticated user ID;
- fetch the owner's posts with cursor pagination;
- map post ID, text, media, author, created time, and canonical `https://x.com/{username}/status/{id}` URL;
- persist rate-limit information and back off on `429`;
- model API cost and usage caps before enabling frequent sync.

Official references:

- [X Developer Platform overview](https://docs.x.com/overview)
- [X API documentation](https://docs.x.com/x-api/)
- [X API authentication guidance](https://developer.x.com/en/docs/authentication/overview)

### 4.7 Threads

#### Availability

- **RSS:** no supported first-party profile/thread RSS feed is documented.
- **Login/API authorization:** Threads API provides a user authorization and token flow for Threads API access. It is best treated as connector authorization, not as FPDP's general identity login.
- **Content API:** the Threads API provides approved profile/thread read and publishing capabilities according to current permissions and App Review.

#### Recommended use in FPDP

Create a separate `THREADS` connector even though Meta operates both Instagram and Threads. Do not reuse an Instagram token unless the official flow explicitly issues a token valid for the Threads endpoints.

Suggested implementation:

- register the Threads use case and redirect URI in the Meta app;
- generate and validate OAuth `state`;
- exchange the callback code server-side;
- obtain the authorized Threads user profile;
- fetch threads with pagination and normalize them as external posts;
- preserve the thread permalink and `THREADS` provider identity;
- refresh/extend tokens only through documented endpoints;
- support deauthorization and data-deletion handling.

Official references:

- [Threads API overview](https://developers.facebook.com/docs/threads/)
- [Threads API getting started](https://developers.facebook.com/docs/threads/get-started/)
- [Threads access tokens and permissions](https://developers.facebook.com/docs/threads/get-started/get-access-tokens-and-permissions/)

## 5. Recommended FPDP architecture

### 5.1 Separate login providers from data connectors

Use separate records even when one platform supports both:

```text
IdentityProviderAccount
  └─ used to authenticate an FPDP user

ExternalContentAccount
  └─ used to import profile/media/posts

CommerceProviderAccount
  └─ used to synchronize shops/products/orders
```

This avoids accidentally expanding a login token into broader content access and lets the user revoke one purpose without breaking another.

### 5.2 Provider adapter recommendation

| Provider code | Adapter family | Initial capability |
|---|---|---|
| `FACEBOOK_LOGIN` | Identity | Login and account linking |
| `FACEBOOK_PAGE` | External content | Approved Page content |
| `LINKEDIN_OIDC` | Identity | Login and account linking |
| `LINKEDIN` | External content | Approved member/organization posts |
| `TIKTOK` | Identity + external content | Login, profile, authorized video list |
| `SHOPEE` | Commerce | Shop, products, orders, push events |
| `INSTAGRAM` | External content | Professional profile and media |
| `X` | Identity + external content | User identity and posts |
| `THREADS` | External content | Profile, threads, optional publishing |

### 5.3 Proposed integration endpoints

These extend the current target API contract:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/integrations/providers` | List provider availability, account requirements, and approval state |
| POST | `/api/v1/integrations/{provider}/authorize` | Create state/PKCE and return the authorization URL |
| GET | `/api/v1/integrations/{provider}/callback` | Validate callback and complete the server-side token exchange |
| GET | `/api/v1/integrations/accounts` | List connected external accounts and granted scopes |
| POST | `/api/v1/integrations/accounts/{id}/sync` | Queue an on-demand synchronization |
| POST | `/api/v1/integrations/accounts/{id}/refresh` | Refresh or re-authorize credentials |
| DELETE | `/api/v1/integrations/accounts/{id}` | Revoke/disconnect and apply the selected retention policy |
| POST | `/api/v1/webhooks/{provider}` | Receive verified provider callbacks/push events |

Use POST to initiate authorization so the server can bind state to the authenticated FPDP session. The callback may remain GET where the provider requires it.

## 6. Shared OAuth and connector security

Every provider implementation must:

1. use an exact HTTPS redirect URI in production;
2. generate a cryptographically random, single-use, short-lived `state` value;
3. use PKCE when supported or required;
4. exchange authorization codes only on the server;
5. keep client/partner secrets and refresh tokens off the browser;
6. encrypt access and refresh tokens at rest;
7. store granted scopes, provider account ID, expiry, and revocation state;
8. request least-privilege scopes and support partial consent;
9. redact credentials and authorization codes from logs;
10. refresh with locking so concurrent workers do not rotate the same token;
11. verify webhook signatures against the raw body and deduplicate event IDs;
12. implement disconnect, revocation, data deletion, and retention policy;
13. enforce provider rate limits, backoff, pagination, and API-version monitoring;
14. preserve origin, author, timestamp, provider, and canonical URL on every imported item.

## 7. Why unofficial RSS and scraping are not recommended

Third-party “RSS generators” may scrape public HTML or hold user credentials. They can break without notice, omit deletion/privacy changes, violate platform terms, and introduce an additional data processor. FPDP should therefore:

- use a first-party API when available and approved;
- let users provide a feed they control, such as their blog RSS;
- use manual canonical links or embeds where the platform allows them;
- mark a connector unavailable when permission is not approved;
- never bypass access controls through scraping.

## 8. Implementation priority

1. Keep RSS, Atom, and Custom API as the stable MVP connectors.
2. Build the generic OAuth account/token lifecycle and encrypted storage.
3. Implement TikTok or Instagram as the first social connector only after App Review feasibility is confirmed.
4. Implement LinkedIn OIDC separately as an optional login provider.
5. Implement Shopee behind a commerce-specific interface after products/orders exist.
6. Add Facebook, X, and Threads based on business priority, approval, API cost, and test-account availability.
7. Keep every provider behind a feature flag until production credentials and approval are verified.

## 9. Provider due-diligence checklist

Before marking a provider “available,” record:

- official app/product name and developer account owner;
- supported markets and account types;
- approved scopes and use case;
- sandbox/test-user availability;
- current API version and retirement date;
- pricing, quota, and rate-limit assumptions;
- redirect, webhook, deauthorization, and data-deletion URLs;
- token lifetime and refresh behavior;
- data retention/deletion requirements;
- review evidence and next re-verification date.

