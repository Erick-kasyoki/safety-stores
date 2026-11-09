-- M-Pesa (Daraja STK Push) payments. Run once as the postgres user, never as store_app:
--   psql -U postgres -d safety_store -f database/migrate_mpesa.sql
-- Needs migrate_payments.sql (orders.paid_at) to have been run first.
CREATE TABLE IF NOT EXISTS mpesa_payments(
  id SERIAL PRIMARY KEY,
  order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  user_id INT REFERENCES users(id) ON DELETE SET NULL,
  phone TEXT NOT NULL,                       -- 2547XXXXXXXX
  amount INT NOT NULL CHECK(amount>0),       -- whole shillings, what was asked on the phone
  merchant_request_id TEXT NOT NULL DEFAULT '',
  checkout_request_id TEXT UNIQUE,           -- Daraja's id for this prompt; the callback is matched on it
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN('pending','success','failed','cancelled','timeout')),
  result_code INT,
  result_desc TEXT NOT NULL DEFAULT '',
  receipt TEXT,                              -- e.g. SGL4X9ABCD (M-Pesa confirmation code)
  paid_amount NUMERIC(10,2),                 -- amount reported by Safaricom in the callback
  paid_phone TEXT NOT NULL DEFAULT '',
  txn_date TEXT NOT NULL DEFAULT '',
  needs_review BOOLEAN NOT NULL DEFAULT false, -- money arrived but the order could not take it (cancelled, already paid, amount short)
  callback_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS mpesa_payments_order_idx ON mpesa_payments(order_id);
CREATE UNIQUE INDEX IF NOT EXISTS mpesa_payments_receipt_uq ON mpesa_payments(receipt) WHERE receipt IS NOT NULL;
-- If the website already runs as the limited store_app user, give it the same rights as the other tables:
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='store_app') THEN
    GRANT SELECT,INSERT,UPDATE,DELETE ON mpesa_payments TO store_app;
    GRANT USAGE,SELECT ON SEQUENCE mpesa_payments_id_seq TO store_app;
  END IF;
END $$;
