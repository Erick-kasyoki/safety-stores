<?php
// Web requests never print PHP errors into pages or JSON replies (they reveal file paths and queries); they go to the Apache error log instead.
if(PHP_SAPI!=='cli'){ini_set('display_errors','0');ini_set('log_errors','1');}
// Database (edit to match your PostgreSQL install)
// Copy secrets.example.php to secrets.php (same folder) and put the real database login there. It overrides the values below.
// Environment variables of the same names also work. FORCE_HTTPS=0 turns off the HTTPS requirement (only for testing on a LAN).
// Non-secret defaults are for local setup only. Set DB_USER and DB_PASS in secrets.php or the environment.
// M-Pesa STK Push (MPESA_*) is set the same way: see MPESA-SETUP.md.
// Optional extras, set the same way: SITE_URL (your public address, for example https://shop.example.com; used in emailed links) and PUBLIC_DIR (the folder visitors can reach, if your host calls it public_html instead of public).
$__p=__DIR__.'/secrets.php'; // private, never served: this whole app/ folder is outside the public web folder
if(is_file($__p))require $__p;
foreach(['DB_HOST'=>'localhost','DB_PORT'=>'5432','DB_NAME'=>'safety_store','DB_USER'=>'store_app','DB_PASS'=>'','ADMIN_SIGNUP_CODE'=>'','FORCE_HTTPS'=>'1','SITE_URL'=>'','PUBLIC_DIR'=>__DIR__.'/../public','MPESA_ENV'=>'sandbox','MPESA_CONSUMER_KEY'=>'','MPESA_CONSUMER_SECRET'=>'','MPESA_SHORTCODE'=>'','MPESA_PASSKEY'=>'','MPESA_TXN_TYPE'=>'CustomerBuyGoodsOnline','MPESA_PARTYB'=>'','MPESA_CALLBACK_SECRET'=>'','MPESA_CALLBACK_URL'=>'','MPESA_BASE_URL'=>''] as $__k=>$__d)
 if(!defined($__k))define($__k,getenv($__k)!==false?getenv($__k):$__d);
// PUBLIC_DIR (defined above) is where product photos are stored: ../public unless you set it in secrets.php.
// Your store details, shown on the site
const SITE_NAME='Top Tack Safety Shop Limited',SITE_PHONE='+254 721 373107',SITE_PHONE2='+254 746 525398',SITE_EMAIL='info@toptacksafetyshop.co.ke',CURRENCY='KSh';
// Admin sign-up. Leave '' to allow ONLY the very first admin to sign up (do it right after installing).
// Set a secret code to let further admins sign up at admin.html by entering it. Share it only with people you trust.
function db():PDO{
 static $pdo;
 if($pdo)return $pdo;
 if(DB_USER===''||DB_PASS==='')throw new RuntimeException('Database credentials are not configured. Set DB_USER and DB_PASS in app/secrets.php or the environment.');
 $pdo=new PDO('pgsql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.";options='--client_encoding=UTF8'",DB_USER,DB_PASS,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 app_apply_timezone($pdo);
 return $pdo;
}
// The timezone chosen in Admin > Settings (BH_TZ) is used for ALL times: PHP dates and every time PostgreSQL prints (orders, logins, stock log, invoices).
function app_apply_timezone(PDO $pdo):void{
 $tz='';
 try{foreach($pdo->query("SELECT k,v FROM settings WHERE k IN('BH_TZ','TIMEZONE')")->fetchAll() as $r){if($r['k']==='BH_TZ'||$tz==='')$tz=(string)$r['v'];}}catch(Throwable $x){}
 if($tz===''&&defined('TIMEZONE'))$tz=(string)TIMEZONE;
 if(!in_array($tz,DateTimeZone::listIdentifiers(),true))$tz='Africa/Nairobi';
 date_default_timezone_set($tz);
 try{$pdo->exec('SET TIME ZONE '.$pdo->quote($tz));}catch(Throwable $x){}
}
// Invoices (all optional; the invoice generator works without them)
// Map branches, one per line: Name | Address | latitude | longitude. Edit in Admin > Settings. Coordinates below are APPROXIMATE: right-click the exact spot in Google Maps, click the numbers to copy them, and paste them here.
const MAP_BRANCHES="Main Branch (Mogadishu Road) | Mogadishu Road, Nairobi | -1.2830 | 36.8300\nNairobi Town Branch | Beaver Mall, Tom Mboya Street, Nairobi | -1.2860 | 36.8250\nSyokimau Branch | Gateway Mall, Mombasa Road, Syokimau | -1.3600 | 36.9330";
const SITE_ADDRESS='Main Branch: Mogadishu Road, Nairobi',INVOICE_PREFIX='INV-',BRAND_COLOR='#ED152A',LOGO='img/logo.png',TAX_NAME='VAT',DEFAULT_TAX=0,DUE_DAYS=14;
// LOGO: a photo path or URL, for example 'img/logo.png'. PAY_INFO is printed under "How to pay" on unpaid invoices.
const PAY_INFO='';

// Quotations: number prefix, days a quotation stays valid, and the terms printed on it (blank = built-in default). Edit in Admin > Settings.
const QUOTE_PREFIX='QUO-',QUOTE_VALID_DAYS=30,QUOTE_TERMS='';
