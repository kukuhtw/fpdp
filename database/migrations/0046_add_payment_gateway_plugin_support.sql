ALTER TABLE payment_gateways
    ADD COLUMN is_plugin TINYINT(1) NOT NULL DEFAULT 0 AFTER adapter_class,
    ADD COLUMN config_keys_json TEXT NULL COMMENT 'Plugin-declared config keys (JSON array); ignored for built-in gateways, which use PaymentService::ALLOWED_CONFIG_KEYS instead' AFTER is_plugin;
