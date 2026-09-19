CREATE TABLE IF NOT EXISTS federated_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    remote_actor_id INT NOT NULL,
    object_uri VARCHAR(2048) NOT NULL,
    canonical_url VARCHAR(2048) NULL,
    title VARCHAR(255) NULL,
    content LONGTEXT NULL,
    visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
    published_at TIMESTAMP NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY unique_fed_post_public_id (public_id),
    UNIQUE KEY unique_object_uri (object_uri(255)),
    KEY idx_fed_post_actor_latest (remote_actor_id, published_at, id),
    KEY idx_fed_post_visibility (visibility, published_at),
    CONSTRAINT fk_fed_post_actor FOREIGN KEY (remote_actor_id)
        REFERENCES remote_actors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;