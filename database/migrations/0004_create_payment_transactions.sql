CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    provider VARCHAR(64) NOT NULL,
    external_id VARCHAR(128) NULL,
    event_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_transactions_payment_id (payment_id),
    KEY idx_payment_transactions_external_id (external_id),
    CONSTRAINT fk_payment_transactions_payment FOREIGN KEY (payment_id)
        REFERENCES payments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
