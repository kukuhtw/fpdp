CREATE TABLE IF NOT EXISTS follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    profile_id INT NOT NULL COMMENT 'Local profile that follows',
    remote_actor_id INT NULL COMMENT 'Remote actor being followed (NULL for local-to-local)',
    target_actor_uri VARCHAR(2048) NOT NULL COMMENT 'Canonical URI of followed actor',
    target_federated_address VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    activity_public_id CHAR(36) NULL COMMENT 'ID of the Follow activity',
    accepted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_follow_public_id (public_id),
    UNIQUE KEY unique_follow_pair (profile_id, target_actor_uri(255)),
    KEY idx_follow_profile (profile_id, status),
    KEY idx_follow_remote (remote_actor_id),
    CONSTRAINT fk_follow_profile FOREIGN KEY (profile_id)
        REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_actor FOREIGN KEY (remote_actor_id)
        REFERENCES remote_actors (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;