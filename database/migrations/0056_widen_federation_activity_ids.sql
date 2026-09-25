-- Both columns were CHAR(36), sized only for our own internally-generated
-- UUIDs. A real ActivityPub activity id (e.g. any Follow/Accept from
-- Mastodon) is a full dereferenceable URI, routinely well over 36
-- characters — every inbound activity from a real server was silently
-- crashing the whole inbox request with a MySQL "Data too long" error.
-- VARCHAR(512) stays comfortably under InnoDB's 3072-byte index limit
-- (512 * 4 bytes utf8mb4 = 2048 bytes) so the existing unique key on
-- federation_activities.public_id does not need to change to a prefix index.
ALTER TABLE federation_activities
    MODIFY COLUMN public_id VARCHAR(512) NOT NULL;

ALTER TABLE follows
    MODIFY COLUMN activity_public_id VARCHAR(512) NULL COMMENT 'ID of the Follow activity';
