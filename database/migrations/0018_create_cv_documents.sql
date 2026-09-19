CREATE TABLE IF NOT EXISTS cv_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    title VARCHAR(128) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    content_type VARCHAR(128) NOT NULL DEFAULT 'application/pdf',
    price_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    price_currency CHAR(3) NOT NULL DEFAULT 'IDR',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cv_document_public_id (public_id),
    UNIQUE KEY unique_cv_document_node (node_id),
    CONSTRAINT fk_cv_documents_node FOREIGN KEY (node_id)
        REFERENCES nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
