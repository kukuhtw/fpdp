CREATE TABLE IF NOT EXISTS payment_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    adapter_class VARCHAR(255) NULL,
    description TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    supports_refund TINYINT(1) NOT NULL DEFAULT 1,
    supports_recurring TINYINT(1) NOT NULL DEFAULT 0,
    supports_qris TINYINT(1) NOT NULL DEFAULT 0,
    supports_va TINYINT(1) NOT NULL DEFAULT 1,
    supports_credit_card TINYINT(1) NOT NULL DEFAULT 0,
    supports_ewallet TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payment_gateway_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT NOT NULL,
    config_key VARCHAR(128) NOT NULL,
    encrypted_value TEXT NOT NULL,
    environment VARCHAR(32) NOT NULL DEFAULT 'SANDBOX',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    order_id VARCHAR(128) NOT NULL,
    gateway_code VARCHAR(64) NOT NULL,
    external_transaction_id VARCHAR(128) NULL,
    payment_method VARCHAR(64) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'IDR',
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    fee DECIMAL(18,2) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    payment_url VARCHAR(255) NULL,
    expired_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    external_id VARCHAR(128) NULL,
    event_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS external_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    external_account_id VARCHAR(128) NOT NULL,
    external_username VARCHAR(128) NULL,
    display_name VARCHAR(128) NULL,
    profile_url VARCHAR(255) NULL,
    access_token TEXT NULL,
    refresh_token TEXT NULL,
    token_expires_at TIMESTAMP NULL,
    permissions JSON NULL,
    connection_status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS external_feed_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    source_type VARCHAR(32) NOT NULL,
    source_url VARCHAR(255) NOT NULL,
    external_account_id INT NULL,
    sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sync_interval INT NOT NULL DEFAULT 3600,
    last_sync_at TIMESTAMP NULL,
    next_sync_at TIMESTAMP NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS external_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    external_post_id VARCHAR(128) NOT NULL,
    external_account_id INT NULL,
    post_type VARCHAR(32) NOT NULL DEFAULT 'ARTICLE',
    canonical_url VARCHAR(255) NULL,
    title VARCHAR(255) NULL,
    content LONGTEXT NULL,
    media_json JSON NULL,
    author_name VARCHAR(128) NULL,
    published_at TIMESTAMP NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    raw_payload JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    UNIQUE KEY unique_provider_post (provider, external_post_id)
);

CREATE TABLE IF NOT EXISTS connector_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    adapter_class VARCHAR(255) NOT NULL,
    auth_type VARCHAR(64) NULL,
    supports_sync TINYINT(1) NOT NULL DEFAULT 1,
    supports_webhook TINYINT(1) NOT NULL DEFAULT 0,
    supports_profile TINYINT(1) NOT NULL DEFAULT 1,
    supports_posts TINYINT(1) NOT NULL DEFAULT 1,
    supports_products TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS integration_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    job_type VARCHAR(64) NOT NULL,
    payload JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'QUEUED',
    retry_count INT NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMP NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Federated connections tables

CREATE TABLE IF NOT EXISTS remote_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    name VARCHAR(128) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    trust_state VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_node_public_id (public_id),
    UNIQUE KEY unique_remote_node_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remote_actors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    remote_node_id INT NOT NULL,
    actor_uri VARCHAR(2048) NOT NULL,
    federated_address VARCHAR(255) NOT NULL,
    display_name VARCHAR(128) NULL,
    avatar_url VARCHAR(2048) NULL,
    canonical_url VARCHAR(2048) NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_actor_public_id (public_id),
    KEY idx_remote_actors_node (remote_node_id),
    CONSTRAINT fk_remote_actors_node FOREIGN KEY (remote_node_id)
        REFERENCES remote_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS federated_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    profile_id INT NOT NULL,
    remote_actor_id INT NOT NULL,
    relationship_status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    show_on_profile TINYINT(1) NOT NULL DEFAULT 1,
    accepted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fed_conn_public_id (public_id),
    UNIQUE KEY unique_profile_actor (profile_id, remote_actor_id),
    KEY idx_fed_conn_public_query (profile_id, show_on_profile, relationship_status, id),
    CONSTRAINT fk_fed_conn_profile FOREIGN KEY (profile_id)
        REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_fed_conn_actor FOREIGN KEY (remote_actor_id)
        REFERENCES remote_actors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS federated_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    remote_actor_id INT NOT NULL,
    object_uri VARCHAR(2048) NOT NULL,
    canonical_url VARCHAR(2048) NULL,
    title VARCHAR(255) NULL,
    content LONGTEXT NULL,
    visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
    published_at TIMESTAMP NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY unique_fed_post_public_id (public_id),
    KEY idx_fed_post_actor_latest (remote_actor_id, published_at, id),
    CONSTRAINT fk_fed_post_actor FOREIGN KEY (remote_actor_id)
        REFERENCES remote_actors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketplace tables

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'IDR',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
    media JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_public_id (public_id),
    KEY idx_products_node (node_id, status, visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    buyer_email VARCHAR(254) NULL,
    buyer_name VARCHAR(128) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'IDR',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_public_id (public_id),
    KEY idx_orders_node (node_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_snapshot JSON NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
