<?php
// Copy this file to secrets.php (same folder) and fill in the real values. secrets.php is never served to browsers.
define('DB_HOST','localhost');
define('DB_PORT','5432');
define('DB_NAME','safety_store');
define('DB_USER','store_app');                 // the limited user made by database/restrict_postgres.sql
define('DB_PASS','PUT-A-LONG-RANDOM-PASSWORD-HERE');
define('ADMIN_SIGNUP_CODE','');                // leave '' once your admin exists
// define('SITE_URL','https://shop.example.com');   // your public address: used for links in order emails (stops a forged Host header from changing them)
// define('PUBLIC_DIR','/home/youruser/public_html'); // only if your host calls the web folder something other than public (photos are saved there)
// define('FORCE_HTTPS','0');                  // only to test over plain http on a LAN; never on a live server

// ---- M-Pesa STK Push (Safaricom Daraja). Full steps in MPESA-SETUP.md ----
// define('MPESA_ENV','sandbox');                    // 'sandbox' to test, 'live' for real money
// define('MPESA_CONSUMER_KEY','...');               // Daraja portal > My Apps
// define('MPESA_CONSUMER_SECRET','...');
// define('MPESA_SHORTCODE','174379');               // Till: your STORE number (head office). Paybill: your paybill. Sandbox: the test shortcode on the Daraja simulator page
// define('MPESA_PASSKEY','...');                    // Lipa na M-Pesa Online passkey (sandbox: Daraja simulator page; live: emailed by Safaricom when you go live)
// define('MPESA_TXN_TYPE','CustomerBuyGoodsOnline'); // till = CustomerBuyGoodsOnline, paybill = CustomerPayBillOnline
// define('MPESA_PARTYB','');                        // the TILL number customers pay (leave '' to use Admin > Settings > M-PESA Till number). Paybill: leave ''
// define('MPESA_CALLBACK_SECRET','');               // 32+ random characters, e.g. run: php -r "echo bin2hex(random_bytes(24));"
