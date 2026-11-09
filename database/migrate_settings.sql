-- Adds the settings table to an EXISTING database. Run it as the postgres user (the website's limited database user cannot create tables):
-- psql -U postgres -d safety_store -f migrate_settings.sql
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY,v TEXT NOT NULL DEFAULT '');
