-- The paid CV/resume download was removed from the application (September
-- 27, 2026). Its tables hold buyer identity, so they are NOT dropped here:
-- migrations run automatically on every deploy, before anyone could take an
-- archive. They are only renamed out of the application's way, data intact.
--
-- To archive and then delete them (and the files in storage/cv/), run:
--   php scripts/archive-cv-data.php            (summary, changes nothing)
--   php scripts/archive-cv-data.php --archive
--   php scripts/archive-cv-data.php --delete --archive-file=... --confirm
RENAME TABLE cv_access_grants TO archived_cv_access_grants,
             cv_documents TO archived_cv_documents;
