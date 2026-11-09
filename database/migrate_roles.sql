-- Adds admin / worker / customer roles to an EXISTING safety_store database (safe to run more than once):
-- psql -U postgres -d safety_store -f migrate_roles.sql
-- New installs do not need this file: schema.sql already includes it.
SET client_encoding='UTF8';
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'customer';
ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMPTZ;
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='is_admin') THEN
  UPDATE users SET role='admin' WHERE is_admin;
  ALTER TABLE users DROP COLUMN is_admin;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conname='users_role_check') THEN
  ALTER TABLE users ADD CONSTRAINT users_role_check CHECK(role IN('customer','worker','admin'));
 END IF;
END $$;
CREATE INDEX IF NOT EXISTS users_role_idx ON users(role);
CREATE TABLE IF NOT EXISTS login_attempts(id SERIAL PRIMARY KEY,k TEXT NOT NULL,at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS login_attempts_idx ON login_attempts(k,at);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS assigned_to INT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by INT REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS orders_status_idx ON orders(status);
