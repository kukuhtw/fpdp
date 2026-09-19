CREATE TABLE IF NOT EXISTS visitor_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visitor_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_visitor_token_hash (token_hash),
    KEY idx_visitor_tokens_visitor_id (visitor_id),
    CONSTRAINT fk_visitor_tokens_visitor FOREIGN KEY (visitor_id)
        REFERENCES visitor_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
