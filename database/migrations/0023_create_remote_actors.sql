CREATE TABLE IF NOT EXISTS remote_actors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    remote_node_id INT NOT NULL,
    actor_uri VARCHAR(2048) NOT NULL,
    federated_address VARCHAR(255) NOT NULL,
    display_name VARCHAR(128) NULL,
    avatar_url VARCHAR(2048) NULL,
    canonical_url VARCHAR(2048) NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_actor_public_id (public_id),
    UNIQUE KEY unique_remote_actor_uri (actor_uri(255)),
    UNIQUE KEY unique_federated_address (federated_address),
    KEY idx_remote_actors_node (remote_node_id),
    CONSTRAINT fk_remote_actors_node FOREIGN KEY (remote_node_id)
        REFERENCES remote_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;