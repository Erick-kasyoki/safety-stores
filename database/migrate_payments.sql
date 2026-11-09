-- Adds till-payment confirmation to orders on an EXISTING database. Run once as the postgres user:
-- psql -U postgres -d safety_store -f migrate_payments.sql
ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_by INT REFERENCES users(id) ON DELETE SET NULL;
-- Orders already dispatched or delivered before this update count as paid.
UPDATE orders SET paid_at=COALESCE(updated_at,created_at) WHERE paid_at IS NULL AND status IN('dispatched','delivered');
