-- Real ActivityPub actors carry their own inbox URL and RSA public key
-- (per-actor, not per-domain — unlike the old FPDP-only protocol's
-- domain-level remote_node_keys cache, a Mastodon server hosts many
-- actors each with their own keypair).
ALTER TABLE remote_actors
    ADD COLUMN inbox_url VARCHAR(2048) NULL AFTER canonical_url,
    ADD COLUMN shared_inbox_url VARCHAR(2048) NULL AFTER inbox_url,
    ADD COLUMN public_key_id VARCHAR(2048) NULL COMMENT 'e.g. https://mastodon.social/users/alice#main-key' AFTER shared_inbox_url,
    ADD COLUMN public_key_pem TEXT NULL AFTER public_key_id;
