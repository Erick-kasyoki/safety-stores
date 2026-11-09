-- For an EXISTING database where store details were already saved in Admin > Settings
-- (saved settings override config.php). Run once:
--   psql -U postgres -d safety_store -f migrate_logo.sql
INSERT INTO settings(k,v) VALUES('SITE_NAME','Top Tack Safety Shop Limited'),('LOGO','img/logo.png'),('BRAND_COLOR','#ED152A'),('SITE_PHONE','+254 721 373107'),('SITE_ADDRESS','Main Branch: Mogadishu Road, Nairobi')
ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v;
