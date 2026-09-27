-- Two-factor authentication (TOTP, RFC 6238) for the owner account.
-- The secrets are encrypted with APP_KEY (App\Core\Crypto) like gateway
-- credentials; scripts/rotate-app-key.php re-encrypts them.
ALTER TABLE users
    ADD COLUMN totp_secret TEXT NULL,
    ADD COLUMN totp_pending_secret TEXT NULL COMMENT 'set while the owner is scanning the QR code, before confirming',
    ADD COLUMN totp_enabled_at TIMESTAMP NULL,
    ADD COLUMN totp_last_step BIGINT NULL COMMENT 'last accepted 30-second step, so a code cannot be used twice';

-- One-time recovery codes, stored as password hashes.
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_recovery_codes_user (user_id),
    CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The step between a correct password and the second factor: a short-lived
-- token that can only be exchanged for a session together with a valid code.
CREATE TABLE IF NOT EXISTS mfa_challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mfa_challenge_token (token_hash),
    KEY idx_mfa_challenges_user (user_id),
    CONSTRAINT fk_mfa_challenges_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
