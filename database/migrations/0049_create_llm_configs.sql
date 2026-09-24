CREATE TABLE llm_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    provider_code VARCHAR(32) NOT NULL COMMENT 'OPENAI, ANTHROPIC, OPENROUTER',
    model VARCHAR(128) NOT NULL,
    encrypted_api_key TEXT NOT NULL,
    supports_vision TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Owner-confirmed: can this model read images?',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_llm_configs_node (node_id),
    CONSTRAINT fk_llm_configs_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
);
