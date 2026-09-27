-- Where a visit came from: the referring site's host only (e.g.
-- "mastodon.social"), never the full URL — a referrer path or query can
-- carry personal data. NULL for direct visits and links within this node.
-- Page views of the public HTML pages are recorded as PAGE_VIEW (with
-- subject_type naming the page), alongside the existing PROFILE_VIEW,
-- POST_VIEW, OUTBOUND_CLICK, and SHOP_CONVERSION.
ALTER TABLE analytics_events
    ADD COLUMN referrer_host VARCHAR(255) NULL AFTER visitor_hash,
    ADD KEY idx_analytics_node_referrer (node_id, occurred_on, referrer_host);
