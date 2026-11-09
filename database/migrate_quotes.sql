-- Adds Quotations and admin-chosen Best Sellers to an EXISTING safety_store database (safe to run more than once):
--   psql -U postgres -d safety_store -f database/migrate_quotes.sql
-- New installs do not need this file: schema.sql already includes it.
SET client_encoding='UTF8';
CREATE SEQUENCE IF NOT EXISTS quote_seq;
CREATE TABLE IF NOT EXISTS quotations(id SERIAL PRIMARY KEY,number TEXT NOT NULL UNIQUE,token TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN('request','draft','sent','accepted','declined','void')),
 customer_name TEXT NOT NULL,customer_company TEXT NOT NULL DEFAULT '',customer_email TEXT NOT NULL DEFAULT '',customer_phone TEXT NOT NULL DEFAULT '',customer_address TEXT NOT NULL DEFAULT '',
 subject TEXT NOT NULL DEFAULT '',issue_date DATE NOT NULL,valid_until DATE NOT NULL,
 tax_rate NUMERIC(5,2) NOT NULL DEFAULT 0,discount NUMERIC(12,2) NOT NULL DEFAULT 0,subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,tax NUMERIC(12,2) NOT NULL DEFAULT 0,total NUMERIC(12,2) NOT NULL DEFAULT 0,
 notes TEXT NOT NULL DEFAULT '',terms TEXT NOT NULL DEFAULT '',request_text TEXT NOT NULL DEFAULT '',
 invoice_id INT REFERENCES invoices(id) ON DELETE SET NULL,created_by INT REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),updated_at TIMESTAMPTZ,responded_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS quotation_items(id SERIAL PRIMARY KEY,quotation_id INT NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,product_id INT REFERENCES products(id) ON DELETE SET NULL,
 description TEXT NOT NULL,qty NUMERIC(10,2) NOT NULL CHECK(qty>0),unit_price NUMERIC(10,2) NOT NULL CHECK(unit_price>=0),position INT NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS quotations_status_idx ON quotations(status,id DESC);
-- Best sellers the admin picks (shown on the store front page)
ALTER TABLE products ADD COLUMN IF NOT EXISTS best_seller BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE products ADD COLUMN IF NOT EXISTS best_pos INT NOT NULL DEFAULT 0;
-- With the limited store_app user, the default privileges from restrict_postgres.sql cover the new tables. Otherwise run:
--   GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
