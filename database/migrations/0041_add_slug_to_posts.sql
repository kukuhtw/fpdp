ALTER TABLE posts ADD COLUMN slug VARCHAR(255) NULL AFTER public_id;
CREATE INDEX idx_posts_slug ON posts (slug);