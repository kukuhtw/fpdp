CREATE TABLE chatbot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    price_per_question DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'IDR',
    status VARCHAR(16) NOT NULL DEFAULT 'DISABLED' COMMENT 'ENABLED, DISABLED',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chatbot_settings_node (node_id),
    CONSTRAINT fk_chatbot_settings_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
);

CREATE TABLE visitor_wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visitor_id INT NOT NULL,
    balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'IDR',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_visitor_wallets_visitor (visitor_id),
    CONSTRAINT fk_visitor_wallets_visitor FOREIGN KEY (visitor_id) REFERENCES visitor_accounts(id) ON DELETE CASCADE
);

CREATE TABLE visitor_wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    wallet_id INT NOT NULL,
    type VARCHAR(16) NOT NULL COMMENT 'TOPUP, CHAT_COST, OWNER_GRANT, REFUND',
    amount DECIMAL(14,2) NOT NULL COMMENT 'Positive = credit, negative = debit',
    payment_id INT NULL COMMENT 'Set for TOPUP rows confirmed via PaymentGatewayInterface',
    note VARCHAR(255) NULL COMMENT 'e.g. reason for an OWNER_GRANT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_visitor_wallet_tx_public_id (public_id),
    KEY idx_visitor_wallet_tx_wallet (wallet_id),
    CONSTRAINT fk_visitor_wallet_tx_wallet FOREIGN KEY (wallet_id) REFERENCES visitor_wallets(id) ON DELETE CASCADE
);

CREATE TABLE chat_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    node_id INT NOT NULL,
    visitor_id INT NOT NULL,
    message_count INT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE, CLOSED',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP NULL,
    UNIQUE KEY uniq_chat_sessions_public_id (public_id),
    KEY idx_chat_sessions_visitor (visitor_id),
    CONSTRAINT fk_chat_sessions_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_sessions_visitor FOREIGN KEY (visitor_id) REFERENCES visitor_accounts(id) ON DELETE CASCADE
);

CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    role VARCHAR(16) NOT NULL COMMENT 'VISITOR, ASSISTANT',
    content TEXT NOT NULL,
    cost_amount DECIMAL(14,2) NULL COMMENT 'Set on the VISITOR message that triggered a charge',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_messages_session (session_id),
    CONSTRAINT fk_chat_messages_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
);
