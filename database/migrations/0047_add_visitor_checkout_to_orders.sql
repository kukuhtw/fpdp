ALTER TABLE orders
    ADD COLUMN visitor_id INT NULL COMMENT 'Buyer, when the order came from visitor checkout; NULL for owner-entered orders' AFTER node_id,
    ADD COLUMN payment_reference VARCHAR(191) NULL COMMENT 'payments.order_id (or gateway payment id) that paid this order' AFTER notes,
    ADD KEY idx_orders_visitor_id (visitor_id),
    ADD CONSTRAINT fk_orders_visitor FOREIGN KEY (visitor_id)
        REFERENCES visitor_accounts (id) ON DELETE SET NULL;
