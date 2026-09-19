CREATE TABLE IF NOT EXISTS payment_gateway_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT NOT NULL,
    config_key VARCHAR(128) NOT NULL,
    encrypted_value TEXT NOT NULL,
    environment VARCHAR(32) NOT NULL DEFAULT 'SANDBOX',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_gateway_config_key (gateway_id, config_key, environment),
    KEY idx_gateway_configs_gateway_id (gateway_id),
    CONSTRAINT fk_gateway_configs_gateway FOREIGN KEY (gateway_id)
        REFERENCES payment_gateways (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
