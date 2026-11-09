-- Gives the website its OWN limited database user (not the postgres superuser) and shuts everyone else out.
-- 1) Change the password on the line marked  <<< CHANGE THIS  (16+ characters, random, and no ' character in it).
-- 2) Run it as the postgres user:
--      psql -U postgres -d safety_store -f database/restrict_postgres.sql
--    (pgAdmin works too: open the safety_store database > Query Tool > paste this file > run.)
-- 3) Put the same user and password in app/secrets.php (DB_USER / DB_PASS), then delete the password from this file.
-- Safe to run again: it updates the user and re-applies the rights.
DO $$
DECLARE
  pw text := 'CHANGE-ME-REPLACE-WITH-RANDOM-SECRET';   -- <<< CHANGE THIS
  db text := current_database();
BEGIN
  IF pw LIKE 'CHANGE-ME%' OR length(pw) < 16 THEN
    RAISE EXCEPTION 'Edit the password at the top of restrict_postgres.sql first (16 or more characters).';
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'store_app') THEN
    EXECUTE format('ALTER ROLE store_app WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', pw);
  ELSE
    EXECUTE format('CREATE ROLE store_app WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', pw);
  END IF;
  EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', db);
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO store_app', db);
END
$$;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO store_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
-- tables or sequences you add later (for example from a migrate_*.sql file run as postgres) get the same rights:
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT,INSERT,UPDATE,DELETE ON TABLES TO store_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE,SELECT ON SEQUENCES TO store_app;
-- The app cannot create, alter or drop tables. Run schema.sql and migrate_*.sql as postgres, not as store_app.
