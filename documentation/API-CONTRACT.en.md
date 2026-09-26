# FPDP API Contract (English)

## 1. Scope and status

This document defines the intended HTTP contract for the FPDP MVP. It is a design contract; the current repository does not yet implement most routes. The machine-readable source is [`openapi.yaml`](openapi.yaml).

Base path: `/api/v1`

Authentication:

- public reads do not require authentication;
- owner/admin operations use `Authorization: Bearer <token>`;
- payment webhooks use provider-specific signatures and must not use user bearer tokens.

Content types: `application/json`, except webhook bodies which may be provider-specific JSON.

## 2. Common conventions

- IDs exposed by the API are UUID strings.
- Timestamps use RFC 3339 UTC values.
- Money is represented as a decimal string plus an ISO 4217 currency code to prevent floating-point errors.
- Collection responses use cursor pagination: `data`, `meta.next_cursor`, and `meta.has_more`.
- Mutable resources return an `ETag` when optimistic concurrency is supported.
- All content records identify `source_type`, `source_provider`, and `canonical_url`.
- Retrying a create-payment request requires the same `Idempotency-Key` header.

Standard success envelope:

```json
{"data": {}, "meta": {"request_id": "req_..."}}
```

Standard error envelope:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The request is invalid.",
    "details": [{"field": "email", "reason": "invalid_format"}],
    "request_id": "req_..."
  }
}
```

## 3. Endpoint list

### System and authentication

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | Public | Liveness and dependency summary |
| POST | `/auth/register` | Public | Register an owner and initial node |
| POST | `/auth/login` | Public | Exchange credentials for access token |
| POST | `/auth/logout` | Bearer | Revoke the current token |
| GET | `/me` | Bearer | Return authenticated user and node context |

### Profiles and content

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/profiles/{handle}` | Public | Read a public profile |
| PATCH | `/me/profile` | Bearer | Update the owner's profile |
| GET | `/posts` | Public | List posts with source/visibility filters |
| POST | `/posts` | Bearer | Create a local post |
| GET | `/posts/{postId}` | Public | Read a visible post |
| PATCH | `/posts/{postId}` | Bearer | Update an owned local post |
| DELETE | `/posts/{postId}` | Bearer | Soft-delete an owned local post |

Post writes accept up to 10 ordered media metadata items (`IMAGE`, `VIDEO`, `AUDIO`, or `FILE`). Media URLs must be absolute HTTPS URLs without embedded credentials; alternative text is limited to 500 characters. Supplying `media` in a `PATCH` replaces the complete media list atomically.
| GET | `/timeline` | Public/optional Bearer | Return normalized local, external, and federated content |

### External sources

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/external-sources` | Bearer | List owner connections and sync health |
| POST | `/external-sources/test` | Bearer | Validate and preview a source without saving it |
| POST | `/external-sources` | Bearer | Save and schedule a source |
| GET | `/external-sources/{sourceId}` | Bearer | Read source configuration and health |
| PATCH | `/external-sources/{sourceId}` | Bearer | Change interval, visibility, or enabled state |
| DELETE | `/external-sources/{sourceId}` | Bearer | Disconnect a source |
| POST | `/external-sources/{sourceId}/sync` | Bearer | Queue an on-demand synchronization |

### Products, orders, and payments

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/products` | Public | List available products |
| POST | `/products` | Bearer | Create a local product |
| GET | `/products/{productId}` | Public | Read product details |
| PATCH | `/products/{productId}` | Bearer | Update an owned product |
| POST | `/orders` | Public/optional Bearer | Create an order and immutable total snapshot |
| GET | `/orders/{orderId}` | Bearer/order token | Read an order |
| POST | `/orders/{orderId}/payments` | Bearer/order token | Create a payment attempt; requires `Idempotency-Key` |
| GET | `/payments/{paymentId}` | Bearer/order token | Read normalized payment state |
| POST | `/payments/{paymentId}/cancel` | Bearer/order token | Cancel a pending payment |
| POST | `/payments/{paymentId}/refunds` | Admin Bearer | Request full or partial refund |
| POST | `/webhooks/payments/{gatewayCode}` | Signature | Receive and normalize provider events |

Owner payment management is implemented today (owner Bearer) at these paths:

| Method | Path | Purpose |
|---|---|---|
| GET | `/me/payments/pending` | List `PENDING` payments for manual confirmation |
| POST | `/me/payments/{uuid}/confirm` | Mark a `PENDING` payment `PAID` and run fulfillment |
| POST | `/me/payments/{uuid}/cancel` | Cancel a `PENDING` payment at the gateway (when it has an API) and locally; closes the related order |
| POST | `/me/payments/{uuid}/refund` | Body `{"amount"?: number, "manual"?: bool}`; no `amount` = full refund; `manual: true` records a refund made outside the gateway; a full refund undoes fulfillment |
| POST | `/me/payments/reconcile` | Check old `PENDING` payments with the provider, and cancelled payments that were paid anyway |
| POST | `/payments/webhook/{gateway}` | Provider webhook (replaces `/webhooks/payments/{gatewayCode}` above) |

### Administration and federation

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/admin/payment-gateways` | Admin Bearer | List gateway capabilities and configuration status |
| PUT | `/admin/payment-gateways/{gatewayCode}` | Admin Bearer | Configure and activate a gateway |
| GET | `/admin/integration-jobs` | Admin Bearer | Inspect synchronization queue and failures |
| POST | `/admin/integration-jobs/{jobId}/retry` | Admin Bearer | Retry a failed job |
| GET | `/.well-known/fpdp` | Public | Discover node identity and capabilities |

## 4. Important behavior

### Source test and connection

`POST /external-sources/test` performs a bounded server-side fetch, blocks private/reserved network targets, applies timeouts and size limits, and returns normalized preview items. Saving a source is a separate explicit action.

### Timeline provenance

Every item contains:

- `source_type`: `LOCAL`, `EXTERNAL`, or `FEDERATED`;
- `source_provider`: e.g. `FPDP`, `RSS`, `ATOM`, `CUSTOM_API`;
- `canonical_url`: authoritative original URL;
- author identity and publication timestamp.

### Payment idempotency and webhooks

- A client reuses one `Idempotency-Key` for retries of the same logical payment request.
- The server returns the original result when the key and payload match.
- A reused key with a different payload returns `409 IDEMPOTENCY_CONFLICT`.
- Webhooks are verified before processing and deduplicated using provider + event ID.
- A valid duplicate webhook returns `200` without applying the transition twice.

### Suggested status codes

| Status | Meaning |
|---|---|
| 200 | Read/update success or accepted duplicate webhook |
| 201 | Resource created |
| 202 | Async synchronization/refund accepted |
| 204 | Logout or deletion completed |
| 400 | Malformed request |
| 401 | Missing/invalid authentication or webhook signature |
| 403 | Authenticated but not authorized |
| 404 | Resource unavailable or not visible |
| 409 | State or idempotency conflict |
| 422 | Semantic validation failure |
| 429 | Rate limit exceeded |
| 502 | Upstream provider failure |
