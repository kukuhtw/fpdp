CREATE TABLE IF NOT EXISTS visitor_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    google_sub VARCHAR(255) NOT NULL,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(128) NULL,
    avatar_url VARCHAR(2048) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_visitor_public_id (public_id),
    UNIQUE KEY unique_visitor_node_google (node_id, google_sub),
    KEY idx_visitor_accounts_node_id (node_id),
    CONSTRAINT fk_visitor_accounts_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
