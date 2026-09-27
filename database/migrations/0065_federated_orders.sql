-- Node-to-node orders (federated commerce): the owner of one FPDP node orders
-- from another node's shop from their own dashboard. See
-- documentation/FEDERATION-CONCEPT §11b.

-- Seller side: an order placed by another node's owner is tied to that
-- owner's actor (verified by HTTP Signature) instead of a Google visitor.
-- remote_client_reference is the buyer node's own id for the purchase, so a
-- retried request returns the same order instead of creating a second one.
ALTER TABLE orders
    ADD COLUMN remote_actor_uri VARCHAR(512) NULL,
    ADD COLUMN remote_client_reference CHAR(36) NULL,
    ADD UNIQUE KEY unique_orders_remote_reference (remote_client_reference);

-- Buyer side: what this node's owner ordered from other nodes. The seller's
-- node is the source of truth for status; this is a synced copy.
CREATE TABLE IF NOT EXISTS federated_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    seller_domain VARCHAR(255) NOT NULL,
    seller_actor_uri VARCHAR(512) NOT NULL,
    order_endpoint VARCHAR(512) NOT NULL,
    product_object_uri VARCHAR(2048) NULL,
    remote_order_id VARCHAR(64) NULL,
    items JSON NOT NULL COMMENT 'snapshot: product_id, title, quantity, unit_price, subtotal',
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'IDR',
    status VARCHAR(32) NOT NULL DEFAULT 'SUBMITTING',
    payment_url VARCHAR(2048) NULL,
    downloads JSON NULL COMMENT 'digital products: short-lived links from the seller, refreshed on sync',
    buyer_name VARCHAR(128) NULL,
    buyer_email VARCHAR(254) NULL,
    shipping_address TEXT NULL,
    notes TEXT NULL,
    last_error VARCHAR(500) NULL,
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_federated_purchase_public_id (public_id),
    KEY idx_federated_purchases_node (node_id, created_at),
    KEY idx_federated_purchases_status (status, last_synced_at),
    CONSTRAINT fk_federated_purchases_node FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
