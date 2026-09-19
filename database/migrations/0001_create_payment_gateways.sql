CREATE TABLE IF NOT EXISTS payment_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    adapter_class VARCHAR(255) NULL,
    description TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    supports_refund TINYINT(1) NOT NULL DEFAULT 1,
    supports_recurring TINYINT(1) NOT NULL DEFAULT 0,
    supports_qris TINYINT(1) NOT NULL DEFAULT 0,
    supports_va TINYINT(1) NOT NULL DEFAULT 1,
    supports_credit_card TINYINT(1) NOT NULL DEFAULT 0,
    supports_ewallet TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_payment_gateway_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
