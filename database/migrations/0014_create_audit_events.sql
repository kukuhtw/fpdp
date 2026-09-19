CREATE TABLE IF NOT EXISTS audit_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    actor_user_id INT NULL,
    action VARCHAR(64) NOT NULL,
    subject_type VARCHAR(64) NULL,
    subject_public_id CHAR(36) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_events_node_id (node_id),
    KEY idx_audit_events_actor_user_id (actor_user_id),
    CONSTRAINT fk_audit_events_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE,
    CONSTRAINT fk_audit_events_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
