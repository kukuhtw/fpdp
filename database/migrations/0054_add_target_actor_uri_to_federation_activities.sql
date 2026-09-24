-- Decouples "which actor's inbox this activity must be delivered to" from
-- the AS2 `object` field, since they diverge for Undo (object embeds the
-- original Follow, not the recipient) and would otherwise diverge for any
-- future activity type whose `object` isn't the recipient actor.
ALTER TABLE federation_activities
    ADD COLUMN target_actor_uri VARCHAR(2048) NULL AFTER target_node_domain;
