ALTER TABLE external_feed_sources
    ADD COLUMN last_error TEXT NULL AFTER status;

ALTER TABLE external_posts
    ADD COLUMN feed_source_id INT NULL AFTER external_account_id,
    ADD KEY idx_external_posts_feed_source_id (feed_source_id),
    ADD CONSTRAINT fk_external_posts_feed_source FOREIGN KEY (feed_source_id)
        REFERENCES external_feed_sources (id) ON DELETE SET NULL;
