# FPDP Entity Relationship Diagram (English)

## 1. Purpose and scope

This document describes both:

1. the **current physical schema** in [`database/schema.sql`](../database/schema.sql); and
2. the **target logical data model** required by the product requirements, OpenAPI contract, and development roadmap.

The distinction is important: the target entities are a design proposal and do not yet exist in the application. Fields shown in target diagrams are the minimum relationship fields, not a complete migration specification.

## 2. Legend and conventions

| Marker | Meaning |
|---|---|
| Current | Table exists in `database/schema.sql` |
| Planned | Table is required by the target product but not yet implemented |
| `PK` | Primary key |
| `FK` | Foreign key |
| `UK` | Unique key or unique constraint |
| `||` | Exactly one |
| `o|` | Zero or one |
| `o{` | Zero or many |
| `|{` | One or many |

Recommended database conventions for new migrations:

- Use one ID strategy consistently. The API exposes UUIDs; either use UUIDs as database primary keys or keep internal numeric keys plus a unique public UUID.
- Store timestamps in UTC and render them in the user's timezone.
- Use fixed-precision `DECIMAL`, never floating point, for money.
- Encrypt provider credentials and tokens at rest.
- Use soft deletion only when retention or recovery requires it.
- Add foreign keys, unique constraints, and indexes through ordered migrations.
- Keep canonical source data immutable where it is required for attribution or order history.

## 3. Current physical ERD

The current SQL file creates nine tables. Relationships below are inferred from column names because the schema does not currently declare foreign-key constraints.

```mermaid
erDiagram
    PAYMENT_GATEWAYS ||--o{ PAYMENT_GATEWAY_CONFIGS : "has configuration"
    PAYMENT_GATEWAYS ||--o{ PAYMENTS : "processes by code (inferred)"
    PAYMENTS ||--o{ PAYMENT_TRANSACTIONS : "records events"

    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_FEED_SOURCES : "authorizes"
    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_POSTS : "owns remote identity"
    EXTERNAL_FEED_SOURCES ||--o{ EXTERNAL_POSTS : "imports (inferred)"

    PAYMENT_GATEWAYS {
        int id PK
        varchar code
        varchar name
        varchar adapter_class
        varchar status
        boolean supports_refund
        boolean supports_recurring
        boolean supports_qris
        boolean supports_va
        boolean supports_credit_card
        boolean supports_ewallet
    }

    PAYMENT_GATEWAY_CONFIGS {
        int id PK
        int gateway_id FK
        varchar config_key
        text encrypted_value
        varchar environment
        boolean is_active
    }

    PAYMENTS {
        int id PK
        char uuid
        varchar order_id
        varchar gateway_code
        varchar external_transaction_id
        varchar payment_method
        char currency
        decimal amount
        decimal fee
        varchar status
        varchar payment_url
        timestamp expired_at
        timestamp paid_at
    }

    PAYMENT_TRANSACTIONS {
        int id PK
        int payment_id FK
        varchar provider
        varchar external_id
        varchar event_type
        varchar status
        json payload
    }

    EXTERNAL_ACCOUNTS {
        int id PK
        int user_id FK
        varchar provider
        varchar external_account_id
        varchar external_username
        varchar display_name
        varchar profile_url
        text access_token
        text refresh_token
        timestamp token_expires_at
        json permissions
        varchar connection_status
        timestamp last_sync_at
    }

    EXTERNAL_FEED_SOURCES {
        int id PK
        int user_id FK
        varchar provider
        varchar source_type
        varchar source_url
        int external_account_id FK
        boolean sync_enabled
        int sync_interval
        timestamp last_sync_at
        timestamp next_sync_at
        varchar status
    }

    EXTERNAL_POSTS {
        int id PK
        int user_id FK
        varchar provider
        varchar external_post_id
        int external_account_id FK
        varchar post_type
        varchar canonical_url
        varchar title
        longtext content
        json media_json
        varchar author_name
        timestamp published_at
        timestamp fetched_at
        json raw_payload
        varchar status
    }

    CONNECTOR_DEFINITIONS {
        int id PK
        varchar code
        varchar name
        varchar adapter_class
        varchar auth_type
        boolean supports_sync
        boolean supports_webhook
        boolean supports_profile
        boolean supports_posts
        boolean supports_products
        varchar status
    }

    INTEGRATION_QUEUE {
        int id PK
        int user_id FK
        varchar provider
        varchar job_type
        json payload
        varchar status
        int retry_count
        timestamp next_retry_at
        text last_error
    }
```

### Current schema gaps

- `users` and `orders` do not exist even though `user_id` and `order_id` are used.
- No explicit foreign-key constraint is declared.
- Gateway and connector codes are not unique.
- The existing external-post uniqueness rule is composite: `(provider, external_post_id)`.
- `external_posts` has no `external_feed_source_id`, so the exact importing source cannot be enforced.
- Payment records use `gateway_code` instead of a constrained gateway foreign key.
- Payment event idempotency is not guaranteed by a unique provider event key.
- Credentials exist as generic text columns; application-level encryption is not yet implemented.
- Queue locking, attempt history, and dead-letter state are not modeled.
- Several operational lookup indexes are missing.

## 4. Target domain model overview

| Domain | Planned entities | Existing entities reused or migrated |
|---|---|---|
| Identity and node | `nodes`, `users`, `profiles`, `auth_tokens`, `audit_events` | None |
| Local content | `posts`, `post_media` | None |
| External integration | `external_accounts`, `external_feed_sources`, `external_posts`, `integration_jobs`, `integration_job_attempts`, `connector_definitions` | Existing integration tables |
| Commerce | `products`, `orders`, `order_items` | None |
| Payments | `payment_gateways`, `payment_gateway_configs`, `payments`, `payment_events`, `refunds`, `idempotency_keys` | Existing payment tables |
| Federation | `remote_nodes`, `remote_actors`, `federated_objects`, `federation_activities`, `follows`, `moderation_rules`, `reports` | None |

## 5. Target ERD — identity and local content

```mermaid
erDiagram
    NODES ||--|{ USERS : "has members"
    NODES ||--o{ POSTS : "hosts"
    USERS ||--|| PROFILES : "owns"
    USERS ||--o{ AUTH_TOKENS : "authenticates with"
    USERS ||--o{ POSTS : "authors"
    POSTS ||--o{ POST_MEDIA : "contains"
    USERS ||--o{ AUDIT_EVENTS : "acts in"
    NODES ||--o{ AUDIT_EVENTS : "records"

    NODES {
        bigint id PK
        uuid public_id UK
        varchar domain UK
        varchar name
        varchar default_locale
        varchar timezone
        varchar status
        timestamp created_at
    }

    USERS {
        bigint id PK
        uuid public_id UK
        bigint node_id FK
        varchar email UK
        varchar password_hash
        varchar role
        varchar status
        timestamp created_at
    }

    PROFILES {
        bigint id PK
        uuid public_id UK
        bigint user_id FK,UK
        varchar handle
        varchar display_name
        text bio
        varchar avatar_url
        varchar visibility
        json links
        timestamp updated_at
    }

    AUTH_TOKENS {
        bigint id PK
        bigint user_id FK
        char token_hash UK
        varchar token_type
        json scopes
        timestamp expires_at
        timestamp revoked_at
    }

    POSTS {
        bigint id PK
        uuid public_id UK
        bigint node_id FK
        bigint author_user_id FK
        varchar slug
        varchar post_type
        varchar title
        longtext content
        varchar status
        varchar visibility
        varchar canonical_url UK
        timestamp published_at
        timestamp deleted_at
    }

    POST_MEDIA {
        bigint id PK
        bigint post_id FK
        varchar media_type
        varchar storage_key
        varchar public_url
        varchar alt_text
        int sort_order
    }

    AUDIT_EVENTS {
        bigint id PK
        bigint node_id FK
        bigint actor_user_id FK
        varchar action
        varchar subject_type
        varchar subject_public_id
        json metadata
        timestamp created_at
    }
```

Important constraints:

- `profiles.user_id` is unique: one current profile per user.
- `(node_id, handle)` and `(node_id, slug)` are unique.
- Passwords and bearer tokens are never stored in plaintext.
- A post's local canonical URL must remain stable after publication.
- Private or soft-deleted posts must be excluded by repository-level visibility rules.

## 6. Target ERD — external integrations

```mermaid
erDiagram
    USERS ||--o{ EXTERNAL_ACCOUNTS : "connects"
    USERS ||--o{ EXTERNAL_FEED_SOURCES : "configures"
    CONNECTOR_DEFINITIONS ||--o{ EXTERNAL_ACCOUNTS : "defines adapter"
    CONNECTOR_DEFINITIONS ||--o{ EXTERNAL_FEED_SOURCES : "defines adapter"
    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_FEED_SOURCES : "authorizes"
    EXTERNAL_FEED_SOURCES ||--o{ EXTERNAL_POSTS : "imports"
    EXTERNAL_FEED_SOURCES ||--o{ INTEGRATION_JOBS : "schedules"
    INTEGRATION_JOBS ||--o{ INTEGRATION_JOB_ATTEMPTS : "attempts"

    CONNECTOR_DEFINITIONS {
        bigint id PK
        varchar code UK
        varchar adapter_class
        varchar auth_type
        json capabilities
        varchar status
    }

    EXTERNAL_ACCOUNTS {
        bigint id PK
        uuid public_id UK
        bigint user_id FK
        bigint connector_id FK
        varchar provider_account_id
        varchar username
        text encrypted_access_token
        text encrypted_refresh_token
        timestamp token_expires_at
        varchar status
    }

    EXTERNAL_FEED_SOURCES {
        bigint id PK
        uuid public_id UK
        bigint user_id FK
        bigint connector_id FK
        bigint external_account_id FK
        varchar source_url
        char source_url_hash
        int sync_interval_seconds
        varchar default_visibility
        boolean sync_enabled
        varchar status
        timestamp last_sync_at
        timestamp next_sync_at
    }

    EXTERNAL_POSTS {
        bigint id PK
        uuid public_id UK
        bigint source_id FK
        varchar provider_post_id
        varchar post_type
        varchar title
        longtext content
        varchar canonical_url
        json media
        json author
        timestamp published_at
        timestamp fetched_at
        char content_hash
        varchar status
    }

    INTEGRATION_JOBS {
        bigint id PK
        uuid public_id UK
        bigint source_id FK
        varchar job_type
        varchar status
        int attempt_count
        timestamp available_at
        timestamp locked_at
        varchar locked_by
        text last_error
    }

    INTEGRATION_JOB_ATTEMPTS {
        bigint id PK
        bigint job_id FK
        int attempt_number
        varchar status
        timestamp started_at
        timestamp finished_at
        text sanitized_error
    }
```

Important constraints:

- `(connector_id, provider_account_id, user_id)` is unique when an account ID exists.
- `(user_id, connector_id, source_url_hash)` prevents duplicate source configuration.
- `(source_id, provider_post_id)` is unique and is the primary import deduplication key.
- Only encrypted credentials are persisted; masked values may be returned to administrators.
- Job claiming must use an atomic lock/update strategy.
- Raw payload retention must be bounded and must not retain secrets.

## 7. Target ERD — commerce and payments

```mermaid
erDiagram
    NODES ||--o{ PRODUCTS : "sells"
    USERS ||--o{ PRODUCTS : "owns"
    NODES ||--o{ ORDERS : "receives"
    USERS o|--o{ ORDERS : "places"
    ORDERS ||--|{ ORDER_ITEMS : "contains"
    PRODUCTS ||--o{ ORDER_ITEMS : "snapshotted in"
    ORDERS ||--o{ PAYMENTS : "has attempts"
    PAYMENT_GATEWAYS ||--o{ PAYMENT_GATEWAY_CONFIGS : "configured by"
    PAYMENT_GATEWAYS ||--o{ PAYMENTS : "processes"
    PAYMENTS ||--o{ PAYMENT_EVENTS : "receives"
    PAYMENTS ||--o{ REFUNDS : "refunds"
    IDEMPOTENCY_KEYS o|--o| PAYMENTS : "protects creation"
    IDEMPOTENCY_KEYS o|--o| REFUNDS : "protects creation"

    PRODUCTS {
        bigint id PK
        uuid public_id UK
        bigint node_id FK
        bigint owner_user_id FK
        varchar slug
        varchar name
        text description
        decimal price_amount
        char currency
        int stock_quantity
        varchar status
        varchar source_type
        varchar canonical_url
    }

    ORDERS {
        bigint id PK
        uuid public_id UK
        bigint node_id FK
        bigint buyer_user_id FK
        char guest_token_hash UK
        varchar customer_email
        varchar customer_name
        decimal total_amount
        char currency
        varchar status
        timestamp created_at
    }

    ORDER_ITEMS {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        varchar product_name_snapshot
        decimal unit_price_amount
        char currency
        int quantity
        decimal subtotal_amount
    }

    PAYMENT_GATEWAYS {
        bigint id PK
        varchar code UK
        varchar adapter_class
        varchar status
        json capabilities
    }

    PAYMENT_GATEWAY_CONFIGS {
        bigint id PK
        bigint gateway_id FK
        bigint node_id FK
        varchar environment
        json encrypted_configuration
        boolean is_active
    }

    PAYMENTS {
        bigint id PK
        uuid public_id UK
        bigint order_id FK
        bigint gateway_id FK
        varchar external_transaction_id
        varchar payment_method
        decimal amount
        decimal fee
        char currency
        varchar status
        varchar payment_url
        timestamp expires_at
        timestamp paid_at
    }

    PAYMENT_EVENTS {
        bigint id PK
        bigint payment_id FK
        bigint gateway_id FK
        varchar provider_event_id
        varchar event_type
        varchar normalized_status
        char payload_hash
        json sanitized_payload
        timestamp processed_at
    }

    REFUNDS {
        bigint id PK
        uuid public_id UK
        bigint payment_id FK
        varchar external_refund_id
        decimal amount
        char currency
        varchar status
        varchar reason
    }

    IDEMPOTENCY_KEYS {
        bigint id PK
        bigint node_id FK
        varchar scope
        varchar key_hash
        char request_hash
        varchar resource_type
        uuid resource_public_id
        timestamp expires_at
    }
```

Important constraints:

- Order items preserve product name and price snapshots; historical orders do not depend on mutable product prices.
- All order items in one order use the order currency unless multi-currency settlement is explicitly designed later.
- `(gateway_id, external_transaction_id)` is unique when the provider supplies an ID.
- `(gateway_id, provider_event_id)` is unique to make webhook handling idempotent.
- `(node_id, scope, key_hash)` is unique for idempotent API operations.
- Payment and order status changes occur in one database transaction where applicable.
- Refund totals may not exceed the captured payment amount.

## 8. Target ERD — federation and moderation

```mermaid
erDiagram
    NODES ||--o{ REMOTE_NODES : "discovers"
    REMOTE_NODES ||--o{ REMOTE_ACTORS : "hosts"
    REMOTE_ACTORS ||--o{ FEDERATED_OBJECTS : "authors"
    REMOTE_NODES ||--o{ FEDERATION_ACTIVITIES : "exchanges"
    USERS ||--o{ FOLLOWS : "initiates"
    REMOTE_ACTORS ||--o{ FOLLOWS : "is followed"
    NODES ||--o{ MODERATION_RULES : "enforces"
    REMOTE_NODES o|--o{ MODERATION_RULES : "is targeted"
    REMOTE_ACTORS o|--o{ MODERATION_RULES : "is targeted"
    USERS ||--o{ REPORTS : "submits"
    REMOTE_ACTORS o|--o{ REPORTS : "is reported"
    FEDERATED_OBJECTS o|--o{ REPORTS : "is reported"

    REMOTE_NODES {
        bigint id PK
        varchar domain UK
        varchar protocol_version
        varchar inbox_url
        text public_key
        json capabilities
        varchar trust_status
        timestamp last_seen_at
    }

    REMOTE_ACTORS {
        bigint id PK
        uuid public_id UK
        bigint remote_node_id FK
        varchar actor_uri UK
        varchar handle
        varchar display_name
        varchar profile_url
        varchar status
    }

    FEDERATED_OBJECTS {
        bigint id PK
        uuid public_id UK
        bigint remote_actor_id FK
        varchar object_uri UK
        varchar object_type
        json normalized_content
        varchar canonical_url
        timestamp published_at
        timestamp deleted_at
    }

    FEDERATION_ACTIVITIES {
        bigint id PK
        uuid public_id UK
        bigint remote_node_id FK
        varchar direction
        varchar activity_uri
        varchar activity_type
        char payload_hash
        varchar status
        int attempt_count
        timestamp processed_at
    }

    FOLLOWS {
        bigint id PK
        bigint local_user_id FK
        bigint remote_actor_id FK
        varchar direction
        varchar status
        timestamp created_at
    }

    MODERATION_RULES {
        bigint id PK
        bigint node_id FK
        bigint remote_node_id FK
        bigint remote_actor_id FK
        varchar action
        varchar reason
        timestamp expires_at
    }

    REPORTS {
        bigint id PK
        uuid public_id UK
        bigint reporter_user_id FK
        bigint remote_actor_id FK
        bigint federated_object_id FK
        varchar reason_code
        text details
        varchar status
    }
```

Important constraints:

- Remote URIs are globally unique and are never treated as trusted solely because they are syntactically valid.
- Incoming activities are deduplicated by stable activity URI or a documented sender + payload hash strategy.
- Exactly one moderation target is required when a rule targets a remote node or actor.
- Tombstones preserve enough identity to prevent deleted remote objects from being re-imported accidentally.
- Raw signed activity payload retention and personal-data retention require explicit policies.

## 9. Relationship and deletion policy

| Relationship | Recommended deletion behavior |
|---|---|
| Node → users/profiles/posts/products/orders | Restrict node deletion; use a controlled export and retirement workflow |
| User → profile | Cascade only during a verified hard-delete workflow |
| User → posts | Preserve or anonymize based on ownership/export policy |
| External source → external posts | Default soft disconnect; optional explicit purge |
| Order → order items/payments | Restrict hard deletion; retain for financial/audit policy |
| Payment → events/refunds | Restrict hard deletion |
| Remote node → actors/objects | Prefer trust-state change or tombstone over deletion |
| Token/session | Hard delete or revoke after retention window |
| Integration job → attempts | Cascade after operational retention expires |

## 10. Index and constraint checklist

Minimum indexes should support:

- login by normalized email;
- public profile by `(node_id, handle)`;
- public post by `(node_id, slug)` and timeline by `(visibility, published_at, id)`;
- due feed sources by `(sync_enabled, status, next_sync_at)`;
- external post deduplication by `(source_id, provider_post_id)`;
- available jobs by `(status, available_at)` and stale locks by `locked_at`;
- products by `(node_id, status)`;
- orders by `(node_id, status, created_at)`;
- payments by `order_id`, status, and unique external transaction ID;
- payment events by unique provider event ID;
- remote actors and objects by unique URI;
- federation activity delivery by `(direction, status, available_at)` when delivery scheduling is added.

## 11. Recommended migration order

1. Migration framework and baseline current tables.
2. `nodes`, `users`, `profiles`, `auth_tokens`, and `audit_events`.
3. `posts` and `post_media`.
4. Refactor external tables to reference users, connectors, and feed sources explicitly.
5. Replace `integration_queue` with robust jobs and attempt history, or migrate it compatibly.
6. Add products, orders, and immutable order items.
7. Refactor payments, add events, refunds, and idempotency keys.
8. Add federation and moderation entities only after protocol selection.
9. Backfill data, validate constraints, then enable foreign-key enforcement.

Every migration must include a forward test, rollback strategy, data-backfill plan when required, and an update to this ERD.
