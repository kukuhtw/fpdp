CREATE TABLE IF NOT EXISTS remote_node_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    remote_node_id INT NOT NULL,
    key_type VARCHAR(32) NOT NULL DEFAULT 'ed25519',
    public_key TEXT NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_node_key_node (remote_node_id),
    KEY idx_remote_node_keys_fingerprint (fingerprint),
    CONSTRAINT fk_remote_node_keys_node FOREIGN KEY (remote_node_id)
        REFERENCES remote_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
