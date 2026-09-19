# Entity Relationship Diagram (ERD) FPDP — Bahasa Indonesia

## 1. Tujuan dan cakupan

Dokumen ini menjelaskan:

1. **skema fisik saat ini** pada [`database/schema.sql`](../database/schema.sql); dan
2. **model data logis target** yang dibutuhkan oleh product requirements, kontrak OpenAPI, dan roadmap pengembangan.

Pembedaan tersebut penting: entity target merupakan rancangan dan belum tersedia pada aplikasi. Field pada diagram target menunjukkan field relasi minimum, bukan spesifikasi migration lengkap.

## 2. Legenda dan konvensi

| Penanda | Arti |
|---|---|
| Saat ini | Tabel tersedia pada `database/schema.sql` |
| Direncanakan | Tabel diperlukan produk target tetapi belum diimplementasikan |
| `PK` | Primary key |
| `FK` | Foreign key |
| `UK` | Unique key atau unique constraint |
| `||` | Tepat satu |
| `o|` | Nol atau satu |
| `o{` | Nol atau banyak |
| `|{` | Satu atau banyak |

Konvensi database yang disarankan untuk migration baru:

- Gunakan satu strategi ID secara konsisten. API mengekspos UUID; gunakan UUID sebagai primary key database atau gunakan numeric key internal dengan UUID publik yang unik.
- Simpan timestamp dalam UTC dan tampilkan sesuai timezone pengguna.
- Gunakan `DECIMAL` fixed-precision, bukan floating point, untuk uang.
- Enkripsi credential dan token provider ketika disimpan.
- Gunakan soft deletion hanya jika dibutuhkan oleh retention atau recovery.
- Tambahkan foreign key, unique constraint, dan index melalui migration berurutan.
- Jaga canonical source data tetap immutable ketika diperlukan untuk atribusi atau histori order.

## 3. ERD fisik saat ini

File SQL saat ini membuat sembilan tabel. Relasi berikut disimpulkan dari nama kolom karena schema belum mendeklarasikan foreign-key constraint.

```mermaid
erDiagram
    PAYMENT_GATEWAYS ||--o{ PAYMENT_GATEWAY_CONFIGS : "memiliki konfigurasi"
    PAYMENT_GATEWAYS ||--o{ PAYMENTS : "memproses berdasarkan kode (asumsi)"
    PAYMENTS ||--o{ PAYMENT_TRANSACTIONS : "mencatat event"

    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_FEED_SOURCES : "mengotorisasi"
    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_POSTS : "memiliki identitas remote"
    EXTERNAL_FEED_SOURCES ||--o{ EXTERNAL_POSTS : "mengimpor (asumsi)"

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

### Gap pada schema saat ini

- `users` dan `orders` belum tersedia walaupun `user_id` dan `order_id` digunakan.
- Belum ada foreign-key constraint eksplisit.
- Kode gateway dan connector belum memiliki unique constraint.
- Unique rule external post saat ini bersifat komposit: `(provider, external_post_id)`.
- `external_posts` tidak memiliki `external_feed_source_id`, sehingga sumber pengimpor tidak dapat dipastikan.
- Payment menggunakan `gateway_code` dan bukan constrained gateway foreign key.
- Idempotency payment event belum dijamin oleh unique provider event key.
- Credential masih berupa kolom text generik; enkripsi pada application layer belum tersedia.
- Queue locking, attempt history, dan dead-letter state belum dimodelkan.
- Beberapa index untuk query operasional belum tersedia.

## 4. Ringkasan model domain target

| Domain | Entity direncanakan | Entity saat ini yang digunakan/migrasi |
|---|---|---|
| Identity dan node | `nodes`, `users`, `profiles`, `auth_tokens`, `audit_events` | Tidak ada |
| Konten lokal | `posts`, `post_media` | Tidak ada |
| Integrasi eksternal | `external_accounts`, `external_feed_sources`, `external_posts`, `integration_jobs`, `integration_job_attempts`, `connector_definitions` | Tabel integrasi yang ada |
| Commerce | `products`, `orders`, `order_items` | Tidak ada |
| Payment | `payment_gateways`, `payment_gateway_configs`, `payments`, `payment_events`, `refunds`, `idempotency_keys` | Tabel payment yang ada |
| Federasi | `remote_nodes`, `remote_actors`, `federated_objects`, `federation_activities`, `follows`, `moderation_rules`, `reports` | Tidak ada |

## 5. ERD target — identity dan konten lokal

```mermaid
erDiagram
    NODES ||--|{ USERS : "memiliki anggota"
    NODES ||--o{ POSTS : "menjadi host"
    USERS ||--|| PROFILES : "memiliki"
    USERS ||--o{ AUTH_TOKENS : "menggunakan"
    USERS ||--o{ POSTS : "menulis"
    POSTS ||--o{ POST_MEDIA : "berisi"
    USERS ||--o{ AUDIT_EVENTS : "melakukan aksi"
    NODES ||--o{ AUDIT_EVENTS : "mencatat"

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

Constraint penting:

- `profiles.user_id` unik: satu profil aktif untuk setiap user.
- `(node_id, handle)` dan `(node_id, slug)` unik.
- Password dan bearer token tidak pernah disimpan sebagai plaintext.
- Canonical URL post lokal harus stabil setelah publikasi.
- Post private atau soft-deleted harus dikecualikan oleh visibility rule pada repository.

## 6. ERD target — integrasi eksternal

```mermaid
erDiagram
    USERS ||--o{ EXTERNAL_ACCOUNTS : "menghubungkan"
    USERS ||--o{ EXTERNAL_FEED_SOURCES : "mengatur"
    CONNECTOR_DEFINITIONS ||--o{ EXTERNAL_ACCOUNTS : "menentukan adapter"
    CONNECTOR_DEFINITIONS ||--o{ EXTERNAL_FEED_SOURCES : "menentukan adapter"
    EXTERNAL_ACCOUNTS o|--o{ EXTERNAL_FEED_SOURCES : "mengotorisasi"
    EXTERNAL_FEED_SOURCES ||--o{ EXTERNAL_POSTS : "mengimpor"
    EXTERNAL_FEED_SOURCES ||--o{ INTEGRATION_JOBS : "menjadwalkan"
    INTEGRATION_JOBS ||--o{ INTEGRATION_JOB_ATTEMPTS : "memiliki percobaan"

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

Constraint penting:

- `(connector_id, provider_account_id, user_id)` unik ketika account ID tersedia.
- `(user_id, connector_id, source_url_hash)` mencegah konfigurasi sumber duplikat.
- `(source_id, provider_post_id)` unik dan menjadi kunci deduplikasi impor utama.
- Hanya credential terenkripsi yang disimpan; admin hanya menerima nilai yang dimasking.
- Job claiming harus memakai strategi lock/update atomik.
- Retention raw payload harus dibatasi dan tidak boleh menyimpan secret.

## 7. ERD target — commerce dan payment

```mermaid
erDiagram
    NODES ||--o{ PRODUCTS : "menjual"
    USERS ||--o{ PRODUCTS : "memiliki"
    NODES ||--o{ ORDERS : "menerima"
    USERS o|--o{ ORDERS : "membuat"
    ORDERS ||--|{ ORDER_ITEMS : "berisi"
    PRODUCTS ||--o{ ORDER_ITEMS : "disalin ke"
    ORDERS ||--o{ PAYMENTS : "memiliki percobaan"
    PAYMENT_GATEWAYS ||--o{ PAYMENT_GATEWAY_CONFIGS : "dikonfigurasi"
    PAYMENT_GATEWAYS ||--o{ PAYMENTS : "memproses"
    PAYMENTS ||--o{ PAYMENT_EVENTS : "menerima"
    PAYMENTS ||--o{ REFUNDS : "memiliki refund"
    IDEMPOTENCY_KEYS o|--o| PAYMENTS : "melindungi pembuatan"
    IDEMPOTENCY_KEYS o|--o| REFUNDS : "melindungi pembuatan"

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

Constraint penting:

- Order item menyimpan snapshot nama dan harga; histori order tidak bergantung pada harga produk yang dapat berubah.
- Seluruh item dalam satu order menggunakan currency order kecuali multi-currency settlement dirancang kemudian.
- `(gateway_id, external_transaction_id)` unik ketika provider memberikan ID.
- `(gateway_id, provider_event_id)` unik agar pemrosesan webhook idempotent.
- `(node_id, scope, key_hash)` unik untuk operasi API idempotent.
- Perubahan status payment dan order dijalankan dalam satu transaksi database jika relevan.
- Total refund tidak boleh melebihi captured payment amount.

## 8. ERD target — federasi dan moderasi

```mermaid
erDiagram
    NODES ||--o{ REMOTE_NODES : "menemukan"
    REMOTE_NODES ||--o{ REMOTE_ACTORS : "menjadi host"
    REMOTE_ACTORS ||--o{ FEDERATED_OBJECTS : "menulis"
    REMOTE_NODES ||--o{ FEDERATION_ACTIVITIES : "bertukar"
    USERS ||--o{ FOLLOWS : "memulai"
    REMOTE_ACTORS ||--o{ FOLLOWS : "diikuti"
    NODES ||--o{ MODERATION_RULES : "menerapkan"
    REMOTE_NODES o|--o{ MODERATION_RULES : "menjadi target"
    REMOTE_ACTORS o|--o{ MODERATION_RULES : "menjadi target"
    USERS ||--o{ REPORTS : "mengirim"
    REMOTE_ACTORS o|--o{ REPORTS : "dilaporkan"
    FEDERATED_OBJECTS o|--o{ REPORTS : "dilaporkan"

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

Constraint penting:

- Remote URI unik secara global dan tidak dipercaya hanya karena formatnya valid.
- Incoming activity dideduplikasi melalui stable activity URI atau strategi sender + payload hash yang terdokumentasi.
- Tepat satu moderation target wajib tersedia jika rule menargetkan remote node atau actor.
- Tombstone menyimpan identitas minimum agar object remote yang sudah dihapus tidak diimpor kembali.
- Retention raw signed activity dan personal data membutuhkan policy eksplisit.

## 9. Kebijakan relasi dan penghapusan

| Relasi | Perilaku penghapusan yang disarankan |
|---|---|
| Node → user/profil/post/produk/order | Batasi penghapusan node; gunakan workflow export dan retirement terkontrol |
| User → profil | Cascade hanya dalam workflow hard-delete yang terverifikasi |
| User → post | Pertahankan atau anonimisasi sesuai policy ownership/export |
| Sumber eksternal → external post | Default soft disconnect; purge hanya melalui aksi eksplisit |
| Order → order item/payment | Batasi hard delete; pertahankan sesuai policy keuangan/audit |
| Payment → event/refund | Batasi hard delete |
| Remote node → actor/object | Gunakan perubahan trust state atau tombstone daripada delete |
| Token/session | Hard delete atau revoke setelah retention window |
| Integration job → attempt | Cascade setelah masa retention operasional berakhir |

## 10. Checklist index dan constraint

Index minimum harus mendukung:

- login melalui email ternormalisasi;
- profil publik melalui `(node_id, handle)`;
- post publik melalui `(node_id, slug)` dan timeline melalui `(visibility, published_at, id)`;
- feed yang jatuh tempo melalui `(sync_enabled, status, next_sync_at)`;
- deduplikasi external post melalui `(source_id, provider_post_id)`;
- job tersedia melalui `(status, available_at)` dan stale lock melalui `locked_at`;
- produk melalui `(node_id, status)`;
- order melalui `(node_id, status, created_at)`;
- payment melalui `order_id`, status, dan external transaction ID yang unik;
- payment event melalui provider event ID yang unik;
- remote actor dan object melalui URI unik;
- delivery federation activity melalui `(direction, status, available_at)` ketika scheduling ditambahkan.

## 11. Urutan migration yang disarankan

1. Migration framework dan baseline tabel saat ini.
2. `nodes`, `users`, `profiles`, `auth_tokens`, dan `audit_events`.
3. `posts` dan `post_media`.
4. Refactor tabel eksternal agar mereferensikan user, connector, dan feed source secara eksplisit.
5. Ganti `integration_queue` dengan job serta attempt history yang kuat, atau lakukan migration kompatibel.
6. Tambahkan product, order, dan order item immutable.
7. Refactor payment, lalu tambahkan event, refund, dan idempotency key.
8. Tambahkan entity federasi dan moderasi hanya setelah pemilihan protokol.
9. Backfill data, validasi constraint, kemudian aktifkan foreign-key enforcement.

Setiap migration wajib memiliki forward test, rollback strategy, data-backfill plan jika diperlukan, dan pembaruan ERD ini.

## 12. Addendum — Koneksi Federasi pada Profil Publik

Model target ini mendukung discovery koneksi publik dan preview post terbaru dari cache. Bagian ini adalah desain target, bukan klaim bahwa persistence federasi sudah diimplementasikan.

```mermaid
erDiagram
    PROFILES ||--o{ FEDERATED_CONNECTIONS : memiliki
    REMOTE_NODES ||--o{ REMOTE_ACTORS : menaungi
    REMOTE_ACTORS ||--o{ FEDERATED_CONNECTIONS : direferensikan
    REMOTE_ACTORS ||--o{ FEDERATED_POSTS : menerbitkan
    FEDERATED_CONNECTIONS }o--o| FEDERATED_POSTS : preview_terbaru

    REMOTE_NODES {
        bigint id PK
        uuid public_id UK
        varchar domain UK
        varchar status
        varchar trust_state
        timestamp last_seen_at
    }
    REMOTE_ACTORS {
        bigint id PK
        bigint remote_node_id FK
        varchar actor_uri UK
        varchar federated_address UK
        varchar display_name
        varchar avatar_url
        varchar canonical_url
        timestamp fetched_at
    }
    FEDERATED_CONNECTIONS {
        bigint id PK
        bigint profile_id FK
        bigint remote_actor_id FK
        varchar relationship_status
        boolean show_on_profile
        timestamp accepted_at
        timestamp updated_at
    }
    FEDERATED_POSTS {
        bigint id PK
        bigint remote_actor_id FK
        varchar object_uri UK
        varchar canonical_url
        text content
        varchar visibility
        timestamp published_at
        timestamp fetched_at
        timestamp deleted_at
    }
```

Constraint dan aturan query:

- Unique `(profile_id, remote_actor_id)` mencegah edge duplikat; edge bersifat directional dan siklus tetap sah.
- `relationship_status`: `PENDING`, `FOLLOWING`, `CONNECTED`, `MUTED`, `BLOCKED`, atau `DISCONNECTED`.
- Query publik mensyaratkan `show_on_profile=true`, status hubungan yang diizinkan, trust state node yang diizinkan, dan post publik yang belum dihapus.
- Preview terbaru dipilih dari cache lokal; render profil publik tidak melakukan remote fetch yang blocking.
- Index `(profile_id, show_on_profile, relationship_status, id)` mendukung cursor pagination; index `(remote_actor_id, published_at, id)` mendukung lookup post terbaru.
