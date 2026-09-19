CREATE TABLE IF NOT EXISTS node_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    key_type VARCHAR(32) NOT NULL DEFAULT 'ed25519',
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    rotated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_node_key_fingerprint (fingerprint),
    KEY idx_node_keys_current (node_id, is_current),
    CONSTRAINT fk_node_keys_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;