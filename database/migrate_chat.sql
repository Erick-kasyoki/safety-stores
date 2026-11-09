-- Adds staff live chat (admin <-> worker, worker <-> worker) to an EXISTING safety_store database (safe to run more than once).
-- Run once as the postgres user:  psql -U postgres -d safety_store -f database/migrate_chat.sql
SET client_encoding='UTF8';
CREATE TABLE IF NOT EXISTS chat_messages(
 id SERIAL PRIMARY KEY,sender_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,recipient_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 body TEXT NOT NULL CHECK(length(body)BETWEEN 1 AND 2000),read_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(sender_id<>recipient_id));
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS reply_to INT REFERENCES chat_messages(id) ON DELETE SET NULL;
-- File attachments (Attach button): the file itself is stored in app/chat_files, only its details are kept here
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS file_path TEXT, ADD COLUMN IF NOT EXISTS file_name TEXT, ADD COLUMN IF NOT EXISTS file_type TEXT, ADD COLUMN IF NOT EXISTS file_size INT;
CREATE INDEX IF NOT EXISTS chat_pair_idx ON chat_messages(sender_id,recipient_id,id);
CREATE INDEX IF NOT EXISTS chat_unread_idx ON chat_messages(recipient_id,read_at);
-- Per-person "clear chat": hides a conversation's older messages from ONE person only (the other person still sees them)
CREATE TABLE IF NOT EXISTS chat_clears(
 user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,peer_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 upto INT NOT NULL DEFAULT 0,PRIMARY KEY(user_id,peer_id));
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='store_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;
  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
 END IF;
END $$;
