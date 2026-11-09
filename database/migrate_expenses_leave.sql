-- Adds the Expense Tracker and Employee Leave Management to an EXISTING safety_store database (safe to run more than once):
--   psql -U postgres -d safety_store -f database/migrate_expenses_leave.sql
-- New installs do not need this file: schema.sql already includes it.
SET client_encoding='UTF8';
-- Expenses: every cost of running the business. Each category has an optional monthly budget (0 = no budget).
CREATE TABLE IF NOT EXISTS expense_categories(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,budget NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(budget>=0));
CREATE TABLE IF NOT EXISTS expenses(id SERIAL PRIMARY KEY,spent_on DATE NOT NULL,category TEXT NOT NULL REFERENCES expense_categories(name) ON UPDATE CASCADE ON DELETE RESTRICT,description TEXT NOT NULL DEFAULT '',vendor TEXT NOT NULL DEFAULT '',amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL DEFAULT 'Cash',reference TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS expenses_date_idx ON expenses(spent_on DESC);
CREATE INDEX IF NOT EXISTS expenses_cat_idx ON expenses(category);
INSERT INTO expense_categories(name) VALUES('Rent'),('Salaries'),('Utilities'),('Transport'),('Stock purchases'),('Marketing'),('Repairs & maintenance'),('Other') ON CONFLICT(name) DO NOTHING;
-- Leave: types with a yearly allowance (0 = no limit), and the requests workers make. status: pending | approved | rejected | cancelled
CREATE TABLE IF NOT EXISTS leave_types(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,days_per_year INT NOT NULL DEFAULT 0 CHECK(days_per_year>=0),paid BOOLEAN NOT NULL DEFAULT true,active BOOLEAN NOT NULL DEFAULT true);
CREATE TABLE IF NOT EXISTS leave_requests(id SERIAL PRIMARY KEY,user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,type_id INT NOT NULL REFERENCES leave_types(id) ON DELETE RESTRICT,start_date DATE NOT NULL,end_date DATE NOT NULL,days NUMERIC(5,1) NOT NULL CHECK(days>0),reason TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN('pending','approved','rejected','cancelled')),decided_by INT REFERENCES users(id) ON DELETE SET NULL,decided_at TIMESTAMPTZ,decision_note TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(end_date>=start_date));
CREATE INDEX IF NOT EXISTS leave_requests_user_idx ON leave_requests(user_id,start_date);
CREATE INDEX IF NOT EXISTS leave_requests_status_idx ON leave_requests(status,start_date);
INSERT INTO leave_types(name,days_per_year,paid) VALUES('Annual leave',21,true),('Sick leave',14,true),('Compassionate leave',5,true),('Unpaid leave',0,false) ON CONFLICT(name) DO NOTHING;
-- If you use the limited store_app user, the default privileges set by restrict_postgres.sql already cover these tables.
-- Otherwise run:  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
