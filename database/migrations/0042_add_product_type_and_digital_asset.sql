ALTER TABLE products
    ADD COLUMN product_type VARCHAR(32) NOT NULL DEFAULT 'PHYSICAL' COMMENT 'PHYSICAL, DIGITAL, SERVICE' AFTER currency,
    ADD COLUMN digital_asset_url VARCHAR(2048) NULL COMMENT 'Download URL for digital goods' AFTER product_type,
    ADD COLUMN digital_asset_metadata JSON NULL COMMENT 'File size, format, preview URL, etc.' AFTER digital_asset_url,
    ADD KEY idx_products_type (product_type);

UPDATE products SET product_type = 'PHYSICAL' WHERE product_type IS NULL;