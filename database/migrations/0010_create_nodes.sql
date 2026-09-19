CREATE TABLE IF NOT EXISTS nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    name VARCHAR(128) NOT NULL,
    default_locale VARCHAR(8) NOT NULL DEFAULT 'id',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_node_public_id (public_id),
    UNIQUE KEY unique_node_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
