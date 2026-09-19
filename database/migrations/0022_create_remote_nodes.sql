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
    UNIQUE KEY unique_remote_node_domain (domain),
    KEY idx_remote_nodes_trust (trust_state, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;