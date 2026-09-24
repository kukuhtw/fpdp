CREATE TABLE rag_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    content LONGTEXT NOT NULL COMMENT 'Plain text/markdown content, used directly for FAQ generation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rag_documents_public_id (public_id),
    KEY idx_rag_documents_node (node_id),
    CONSTRAINT fk_rag_documents_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
);

CREATE TABLE rag_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    document_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    embedding LONGTEXT NULL COMMENT 'JSON-encoded float array; NULL until (re-)generated',
    embedding_model VARCHAR(128) NULL,
    embedding_generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rag_faqs_public_id (public_id),
    KEY idx_rag_faqs_document (document_id),
    CONSTRAINT fk_rag_faqs_document FOREIGN KEY (document_id) REFERENCES rag_documents(id) ON DELETE CASCADE
);
