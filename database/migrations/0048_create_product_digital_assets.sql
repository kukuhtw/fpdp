CREATE TABLE product_digital_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    kind VARCHAR(32) NOT NULL COMMENT 'PDF, SOURCE_CODE',
    storage_key VARCHAR(128) NOT NULL,
    original_filename VARCHAR(255) NULL,
    content_type VARCHAR(128) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_digital_assets_kind (product_id, kind),
    CONSTRAINT fk_product_digital_assets_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
