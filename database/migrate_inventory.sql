-- Adds inventory management to an EXISTING safety_store database (safe to run more than once):
-- psql -U postgres -d safety_store -f migrate_inventory.sql
-- New installs do not need this file: schema.sql already includes it.
SET client_encoding='UTF8';
ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN IF NOT EXISTS cost NUMERIC(10,2) CHECK(cost>=0);
ALTER TABLE products ADD COLUMN IF NOT EXISTS reorder_level INT NOT NULL DEFAULT 5 CHECK(reorder_level>=0);
CREATE TABLE IF NOT EXISTS stock_movements(id SERIAL PRIMARY KEY,product_id INT REFERENCES products(id) ON DELETE SET NULL,name TEXT NOT NULL,kind TEXT NOT NULL,qty_change INT NOT NULL,qty_after INT NOT NULL,unit_price NUMERIC(10,2),order_id INT REFERENCES orders(id) ON DELETE SET NULL,note TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS stock_movements_product_idx ON stock_movements(product_id,created_at DESC);
CREATE INDEX IF NOT EXISTS stock_movements_created_idx ON stock_movements(created_at DESC);
-- Record today's stock as the opening balance (only if no history exists yet).
INSERT INTO stock_movements(product_id,name,kind,qty_change,qty_after,note)
SELECT id,name,'opening',stock,stock,'Opening stock' FROM products WHERE NOT EXISTS(SELECT 1 FROM stock_movements);
