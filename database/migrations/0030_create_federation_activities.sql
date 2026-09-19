CREATE TABLE IF NOT EXISTS federation_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    direction VARCHAR(16) NOT NULL COMMENT 'OUTGOING or INCOMING',
    activity_type VARCHAR(64) NOT NULL,
    actor_uri VARCHAR(2048) NOT NULL,
    object_uri VARCHAR(2048) NULL,
    target_node_domain VARCHAR(255) NULL,
    payload JSON NOT NULL,
    signature TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    retry_count INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fed_activity_public_id (public_id),
    KEY idx_fed_activity_node (node_id, direction, status),
    KEY idx_fed_activity_type (activity_type, status),
    CONSTRAINT fk_fed_activity_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;