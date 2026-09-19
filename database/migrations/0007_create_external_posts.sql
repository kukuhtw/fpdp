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
    UNIQUE KEY unique_provider_post (provider, external_post_id),
    KEY idx_external_posts_user_id (user_id),
    KEY idx_external_posts_external_account_id (external_account_id),
    KEY idx_external_posts_published_at (published_at),
    CONSTRAINT fk_external_posts_account FOREIGN KEY (external_account_id)
        REFERENCES external_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
