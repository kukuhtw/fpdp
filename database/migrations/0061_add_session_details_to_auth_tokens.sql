-- Session management: each owner login token gets a public id (safe to
-- show and reference — never the token hash), the device's user agent, a
-- truncated IP ("203.0.113.x" / IPv6 /48 — enough to recognise a network,
-- not to identify a person), and when it was last used.
ALTER TABLE auth_tokens
    ADD COLUMN public_id CHAR(36) NULL AFTER id,
    ADD COLUMN user_agent VARCHAR(255) NULL AFTER scopes,
    ADD COLUMN ip_hint VARCHAR(64) NULL AFTER user_agent,
    ADD COLUMN last_used_at TIMESTAMP NULL AFTER revoked_at,
    ADD UNIQUE KEY unique_auth_token_public_id (public_id);

UPDATE auth_tokens SET public_id = UUID() WHERE public_id IS NULL;
