CREATE TABLE IF NOT EXISTS wall_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    visitor_id INT NOT NULL,
    content TEXT NOT NULL,
    admin_reply TEXT NULL,
    admin_reply_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_wall_comment_public_id (public_id),
    KEY idx_wall_comments_node_listing (node_id, deleted_at, id),
    KEY idx_wall_comments_visitor_id (visitor_id),
    CONSTRAINT fk_wall_comments_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE,
    CONSTRAINT fk_wall_comments_visitor FOREIGN KEY (visitor_id)
        REFERENCES visitor_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
