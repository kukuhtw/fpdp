CREATE TABLE IF NOT EXISTS federated_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    profile_id INT NOT NULL,
    remote_actor_id INT NOT NULL,
    relationship_status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    show_on_profile TINYINT(1) NOT NULL DEFAULT 1,
    accepted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fed_conn_public_id (public_id),
    UNIQUE KEY unique_profile_actor (profile_id, remote_actor_id),
    KEY idx_fed_conn_public_query (profile_id, show_on_profile, relationship_status, id),
    KEY idx_fed_conn_actor (remote_actor_id),
    CONSTRAINT fk_fed_conn_profile FOREIGN KEY (profile_id)
        REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_fed_conn_actor FOREIGN KEY (remote_actor_id)
        REFERENCES remote_actors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;