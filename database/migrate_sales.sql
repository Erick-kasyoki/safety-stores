-- Adds the Sales Management module to an EXISTING safety_store database (safe to run more than once).
-- Run once as the postgres user:  psql -U postgres -d safety_store -f database/migrate_sales.sql
-- New installs: schema.sql already ends with this same block.
SET client_encoding='UTF8';
CREATE SEQUENCE IF NOT EXISTS sale_seq;
-- status: completed | returned (everything sent back) | cancelled. Sales are never deleted.
-- total = amount charged (incl. tax); returned = value of goods sent back (incl. tax); paid = money kept (payments minus refunds paid out).
CREATE TABLE IF NOT EXISTS sales(
 id SERIAL PRIMARY KEY,receipt_no TEXT NOT NULL UNIQUE,idem_key TEXT NOT NULL UNIQUE,
 cashier_id INT REFERENCES users(id) ON DELETE SET NULL,
 customer_name TEXT NOT NULL DEFAULT '',customer_phone TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'completed' CHECK(status IN('completed','returned','cancelled')),
 subtotal NUMERIC(12,2) NOT NULL CHECK(subtotal>=0),discount NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(discount>=0),
 tax_rate NUMERIC(5,2) NOT NULL DEFAULT 0,tax NUMERIC(12,2) NOT NULL DEFAULT 0,total NUMERIC(12,2) NOT NULL CHECK(total>=0),
 returned NUMERIC(12,2) NOT NULL DEFAULT 0,paid NUMERIC(12,2) NOT NULL DEFAULT 0,
 method TEXT NOT NULL DEFAULT '',note TEXT NOT NULL DEFAULT '',oversold BOOLEAN NOT NULL DEFAULT false,
 cancel_reason TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sales_created_idx ON sales(created_at DESC);
CREATE INDEX IF NOT EXISTS sales_cashier_idx ON sales(cashier_id,created_at DESC);
CREATE TABLE IF NOT EXISTS sale_items(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 product_id INT REFERENCES products(id) ON DELETE SET NULL,name TEXT NOT NULL,sku TEXT NOT NULL DEFAULT '',
 unit_price NUMERIC(10,2) NOT NULL CHECK(unit_price>=0),qty INT NOT NULL CHECK(qty>0),
 discount NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(discount>=0),net NUMERIC(12,2) NOT NULL CHECK(net>=0),
 stock_taken INT NOT NULL DEFAULT 0,restocked INT NOT NULL DEFAULT 0,returned_qty INT NOT NULL DEFAULT 0,returned_net NUMERIC(12,2) NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS sale_items_sale_idx ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS sale_items_product_idx ON sale_items(product_id);
CREATE TABLE IF NOT EXISTS sale_payments(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL,reference TEXT NOT NULL DEFAULT '',
 user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_payments_sale_idx ON sale_payments(sale_id);
CREATE TABLE IF NOT EXISTS sale_returns(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 value NUMERIC(12,2) NOT NULL,refund NUMERIC(12,2) NOT NULL DEFAULT 0,refund_method TEXT NOT NULL DEFAULT '',
 restock BOOLEAN NOT NULL DEFAULT true,cancel BOOLEAN NOT NULL DEFAULT false,reason TEXT NOT NULL DEFAULT '',
 user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_returns_sale_idx ON sale_returns(sale_id);
CREATE TABLE IF NOT EXISTS sale_return_items(
 id SERIAL PRIMARY KEY,return_id INT NOT NULL REFERENCES sale_returns(id) ON DELETE CASCADE,
 sale_item_id INT NOT NULL REFERENCES sale_items(id) ON DELETE CASCADE,qty INT NOT NULL CHECK(qty>0),restocked INT NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS sale_audit(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 user_id INT REFERENCES users(id) ON DELETE SET NULL,action TEXT NOT NULL,detail TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_audit_sale_idx ON sale_audit(sale_id);
-- Give the limited website login (see restrict_postgres.sql) rights on the new tables, when that login exists.
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='store_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;
  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
 END IF;
END $$;
