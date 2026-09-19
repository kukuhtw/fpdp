CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'OWNER',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_public_id (public_id),
    UNIQUE KEY unique_user_email (email),
    KEY idx_users_node_id (node_id),
    CONSTRAINT fk_users_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
