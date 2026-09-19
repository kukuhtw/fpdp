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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_external_feed_sources_user_id (user_id),
    KEY idx_external_feed_sources_external_account_id (external_account_id),
    KEY idx_external_feed_sources_next_sync_at (next_sync_at),
    CONSTRAINT fk_external_feed_sources_account FOREIGN KEY (external_account_id)
        REFERENCES external_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
