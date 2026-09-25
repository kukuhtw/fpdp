-- unique_follow_pair (profile_id, target_actor_uri) didn't account for
-- direction: an OUTGOING follow (we follow X) and an INCOMING follow (X
-- follows us) to the very same actor collide on this constraint, since
-- both store X's actor_uri in target_actor_uri. Live-confirmed: a real
-- inbound Follow from an account we already follow (a mutual-follow
-- relationship, the normal and expected case) threw
-- "Duplicate entry '...' for key follows.unique_follow_pair" and 500'd
-- every delivery attempt, even after the application-level lookup was
-- fixed to be direction-aware (see FollowRepository::findByProfileAndTarget).
-- A profile can only ever have ONE relationship of a given direction to a
-- given actor, but both directions must be able to coexist.
ALTER TABLE follows
    DROP INDEX unique_follow_pair,
    ADD UNIQUE KEY unique_follow_pair (profile_id, target_actor_uri(255), direction);
