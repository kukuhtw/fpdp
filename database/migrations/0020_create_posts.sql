CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    user_id INT NOT NULL,
    profile_id INT NOT NULL,
    title VARCHAR(255) NULL,
    content LONGTEXT NOT NULL,
    post_type VARCHAR(32) NOT NULL DEFAULT 'NOTE',
    visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
    published_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post_public_id (public_id),
    KEY idx_posts_public_timeline (visibility, published_at, id),
    KEY idx_posts_user_id (user_id),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_profile FOREIGN KEY (profile_id)
        REFERENCES profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
