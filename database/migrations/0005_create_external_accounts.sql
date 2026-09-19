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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_external_account (provider, external_account_id),
    KEY idx_external_accounts_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
