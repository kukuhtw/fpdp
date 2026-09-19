CREATE TABLE IF NOT EXISTS cv_access_grants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cv_document_id INT NOT NULL,
    visitor_id INT NOT NULL,
    payment_reference VARCHAR(128) NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cv_grant (cv_document_id, visitor_id),
    KEY idx_cv_access_grants_visitor_id (visitor_id),
    CONSTRAINT fk_cv_access_grants_document FOREIGN KEY (cv_document_id)
        REFERENCES cv_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_cv_access_grants_visitor FOREIGN KEY (visitor_id)
        REFERENCES visitor_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
