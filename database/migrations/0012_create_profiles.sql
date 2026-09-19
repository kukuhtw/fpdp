CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    user_id INT NOT NULL,
    handle VARCHAR(64) NOT NULL,
    display_name VARCHAR(128) NOT NULL,
    bio TEXT NULL,
    avatar_url VARCHAR(2048) NULL,
    visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
    links JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_profile_public_id (public_id),
    UNIQUE KEY unique_profile_user_id (user_id),
    UNIQUE KEY unique_profile_handle (handle),
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
