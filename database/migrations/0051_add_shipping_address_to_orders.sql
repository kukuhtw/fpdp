ALTER TABLE orders
    ADD COLUMN shipping_address TEXT NULL COMMENT 'Delivery address for physical-goods orders' AFTER notes;
