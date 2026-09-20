<?php

declare(strict_types=1);

/**
 * Migration 0042: Add product_type and digital asset columns to products table.
 *
 * Allows sellers to create digital goods (ebooks, source code, video) alongside
 * physical products, with a download URL and optional metadata (file size,
 * format, preview URL, etc.).
 */

use App\Core\Database;

/** @phpstan-ignore-next-line */
$connection = Database::connection();

$connection->exec("
    ALTER TABLE products
    ADD COLUMN product_type VARCHAR(32) NOT NULL DEFAULT 'PHYSICAL'
    AFTER currency,
    ADD COLUMN digital_asset_url VARCHAR(2048) NULL
    AFTER product_type,
    ADD COLUMN digital_asset_metadata JSON NULL
    AFTER digital_asset_url,
    ADD KEY idx_products_type (product_type)
");

$connection->exec("
    -- Update existing products to have the correct type
    UPDATE products SET product_type = 'PHYSICAL' WHERE product_type IS NULL
");