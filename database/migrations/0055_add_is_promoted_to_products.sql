ALTER TABLE products
    ADD COLUMN is_promoted TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility;
