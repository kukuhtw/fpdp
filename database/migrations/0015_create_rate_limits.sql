CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rate_key VARCHAR(191) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    window_started_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rate_key (rate_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
