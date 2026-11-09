-- Adds the notification bell feed to an EXISTING safety_store database (safe to run more than once):
--   psql -U postgres -d safety_store -f database/migrate_notifications.sql
-- New installs do not need this file: schema.sql already includes it. The app also creates the table itself when its database user is allowed to.
CREATE TABLE IF NOT EXISTS notifications(id SERIAL PRIMARY KEY,aud TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE CASCADE,
 kind TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL DEFAULT '',link TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS notifications_aud_idx ON notifications(aud,id DESC);
CREATE INDEX IF NOT EXISTS notifications_user_idx ON notifications(user_id,id DESC);
