CREATE TABLE IF NOT EXISTS analytics_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    event_type VARCHAR(32) NOT NULL COMMENT 'PROFILE_VIEW, POST_VIEW, OUTBOUND_CLICK, SHOP_CONVERSION',
    subject_type VARCHAR(32) NULL,
    subject_public_id VARCHAR(255) NULL,
    visitor_hash CHAR(64) NULL COMMENT 'HMAC-SHA256(date|ip|ua, APP_KEY); never the raw IP, rotates daily so it cannot track a visitor across days',
    occurred_on DATE NOT NULL COMMENT 'UTC calendar date, precomputed at write time for fast day-bucketed aggregation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_analytics_node_day (node_id, occurred_on),
    KEY idx_analytics_node_type_day (node_id, event_type, occurred_on),
    KEY idx_analytics_subject (node_id, subject_type, subject_public_id),
    CONSTRAINT fk_analytics_events_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
