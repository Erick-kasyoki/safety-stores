-- Adds profile photos (dp) to an EXISTING safety_store database (safe to run more than once):
--   psql -U postgres -d safety_store -f database/migrate_photos.sql
-- New installs do not need this file: schema.sql already includes it. The app also adds the column itself when its database user is allowed to.
ALTER TABLE users ADD COLUMN IF NOT EXISTS photo TEXT NOT NULL DEFAULT '';
