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
