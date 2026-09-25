-- Incoming Create/Update activities can carry image/video attachments
-- (AS2 `attachment`), but nothing was ever captured or stored — federated
-- posts on /timeline never showed images even when the source post had one.
ALTER TABLE federated_posts
    ADD COLUMN attachments JSON NULL AFTER content;
