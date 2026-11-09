<?php
// Setup check: open http://localhost/safety-store/public/check.php ON THE SERVER ITSELF, fix anything marked with a cross, then DELETE this file.
require __DIR__.'/config.php';
// This page reveals server details: only the machine itself (or the command line) may open it.
$__h=strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
if(PHP_SAPI!=='cli'&&(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!in_array($__h,['localhost','127.0.0.1','[::1]'],true))){http_response_code(403);exit('Open this page on the server itself (http://localhost/...).');}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
$r=[];
function add($ok,$t,$fix=''){global $r;$r[]=[$ok,$t,$fix];}
add(PHP_VERSION_ID>=70400,'PHP '.PHP_VERSION,'Needs PHP 7.4 or newer. Update XAMPP.');
$ini=php_ini_loaded_file()?:'(no php.ini loaded)';
$ext=extension_loaded('pdo_pgsql');
add($ext,'PHP extension pdo_pgsql is enabled','Edit '.$ini.', remove the ";" in front of extension=pdo_pgsql and extension=pgsql, save, then Stop and Start Apache in the XAMPP control panel.');
if($ext){
 try{
  $pdo=db();
  add(true,'Connected to PostgreSQL ('.DB_USER.'@'.DB_HOST.':'.DB_PORT.', database '.DB_NAME.')');
  try{
   $q=$pdo->query("SELECT (rolsuper OR rolcreaterole OR rolcreatedb)::int AS powerful,has_schema_privilege(current_user,'public','CREATE')::int AS can_create FROM pg_roles WHERE rolname=current_user")->fetch();
   add(!(int)$q['powerful'],'The website logs in to PostgreSQL as a limited user, not as an administrator','The site logs in as '.DB_USER.', which can manage the whole PostgreSQL server. Run database/restrict_postgres.sql as the postgres user, then put the new DB_USER and DB_PASS in app/secrets.php (SECURITY.md, section 3).');
   if(!(int)$q['powerful'])add(!(int)$q['can_create'],'That database user cannot create or change tables','Run database/restrict_postgres.sql as the postgres user: it removes the right to create tables (SECURITY.md, section 3).');
  }catch(PDOException $x){add(false,'Could not read the database user rights: '.$x->getMessage());}
  try{
   $n=(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
   $pdo->query('SELECT id,name,descr,price,was,emoji,image,dept,stock FROM products LIMIT 1');
   add($n>0,"Products table has $n rows",'Run schema.sql on the '.DB_NAME.' database.');
   try{
    $pdo->query('SELECT role,active,must_change FROM users LIMIT 1');$pdo->query('SELECT assigned_to FROM orders LIMIT 1');$pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
    add(true,'Users, roles and workers are ready');
   }catch(PDOException $x){
    add(false,'Roles (admin, worker, customer) are missing: '.$x->getMessage(),'Run migrate_roles.sql on the '.DB_NAME.' database: psql -U postgres -d '.DB_NAME.' -f migrate_roles.sql');
   }
   try{
    $pdo->query('SELECT sku,cost,reorder_level FROM products LIMIT 1');$pdo->query('SELECT id,kind,qty_change,qty_after FROM stock_movements LIMIT 1');
    add(true,'Inventory tables are ready');
   }catch(PDOException $x){
    add(false,'Inventory tables are missing: '.$x->getMessage(),'Run migrate_inventory.sql on the '.DB_NAME.' database: psql -U postgres -d '.DB_NAME.' -f migrate_inventory.sql');
   }
   try{
    $pdo->query('SELECT id,number,token,status,total FROM invoices LIMIT 1');$pdo->query('SELECT id FROM invoice_items LIMIT 1');$pdo->query('SELECT id FROM invoice_payments LIMIT 1');$pdo->query('SELECT id,next_date FROM recurring_invoices LIMIT 1');
    add(true,'Invoice tables are ready');
   }catch(PDOException $x){
    add(false,'Invoice tables are missing: '.$x->getMessage(),'Run migrate_invoices.sql on the '.DB_NAME.' database: psql -U postgres -d '.DB_NAME.' -f migrate_invoices.sql');
   }
   try{
    $pdo->query('SELECT k,v FROM settings LIMIT 1');
    add(true,'Store settings table is ready');
   }catch(PDOException $x){
    add(false,'Store settings table is missing: '.$x->getMessage(),'Run migrate_settings.sql as the postgres user: psql -U postgres -d '.DB_NAME.' -f migrate_settings.sql');
   }
   try{
    $pdo->query('SELECT id,name,budget FROM expense_categories LIMIT 1');$pdo->query('SELECT id,spent_on,category,amount FROM expenses LIMIT 1');$pdo->query('SELECT id,name,days_per_year FROM leave_types LIMIT 1');$pdo->query('SELECT id,user_id,start_date,status FROM leave_requests LIMIT 1');
    add(true,'Expense and leave tables are ready');
   }catch(PDOException $x){
    add(false,'Expense and leave tables are missing: '.$x->getMessage(),'Run migrate_expenses_leave.sql as the postgres user: psql -U postgres -d '.DB_NAME.' -f migrate_expenses_leave.sql');
   }
   try{
    $pdo->query('SELECT id,receipt_no,total,paid,status FROM sales LIMIT 1');$pdo->query('SELECT id,sale_id,qty FROM sale_items LIMIT 1');$pdo->query('SELECT id,sale_id,amount FROM sale_payments LIMIT 1');$pdo->query('SELECT id,sale_id,value FROM sale_returns LIMIT 1');$pdo->query('SELECT id,sale_id,action FROM sale_audit LIMIT 1');
    add(true,'Sales tables are ready');
   }catch(PDOException $x){
    add(false,'Sales tables are missing: '.$x->getMessage(),'Run migrate_sales.sql as the postgres user: psql -U postgres -d '.DB_NAME.' -f database/migrate_sales.sql');
   }
   try{$pdo->query('SELECT id,sender_id,recipient_id,body,reply_to,file_path,file_name,file_type,file_size FROM chat_messages LIMIT 1');$pdo->query('SELECT user_id,peer_id,upto FROM chat_clears LIMIT 1');add(true,'Staff chat table is ready');}
   catch(PDOException $x){add(false,'Staff chat table is missing: '.$x->getMessage(),'Run migrate_chat.sql as the postgres user: psql -U postgres -d '.DB_NAME.' -f database/migrate_chat.sql');}
  }catch(PDOException $x){
   add(false,'Tables: '.$x->getMessage(),'Run schema.sql on the '.DB_NAME.' database. If it already has tables from the earlier Walmart-style project, create a fresh database and run schema.sql there.');
  }
 }catch(PDOException $x){
  $m=$x->getMessage();
  if(stripos($m,'password authentication')!==false)$fix='Wrong password. Set DB_PASS (and DB_USER) in config.php to the ones you chose when installing PostgreSQL.';
  elseif(preg_match('/database ".*" does not exist/i',$m))$fix='Create the database, then run schema.sql on it: psql -U postgres -c "CREATE DATABASE '.DB_NAME.' ENCODING \'UTF8\' TEMPLATE template0;"';
  else $fix='PostgreSQL is not reachable. Start its Windows service (Services app, postgresql-x64-...) and check DB_HOST and DB_PORT in config.php (5432 by default, 5433 if you have several versions installed).';
  add(false,'Connection failed: '.$m,$fix);
 }
}
$ok=!in_array(false,array_column($r,0),true);
?>
<!doctype html><meta charset="utf-8"><link rel="icon" type="image/png" href="img/favicon.png?v=3"><link rel="shortcut icon" href="favicon.ico?v=3"><link rel="apple-touch-icon" href="img/apple-touch-icon.png?v=3"><title>Setup check</title>
<style>body{font:15px/1.5 system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px}li{margin:12px 0}.f{color:#a11;margin-top:2px}</style>
<h1>Setup check</h1>
<ul style="list-style:none;padding:0">
<?php foreach($r as [$o,$t,$f]): ?>
<li><?= $o?'✅':'❌' ?> <?= h($t) ?><?php if(!$o&&$f): ?><div class="f"><b>Fix:</b> <?= h($f) ?></div><?php endif ?></li>
<?php endforeach ?>
</ul>
<h2 style="font-size:18px;margin-top:28px">Private files must not open from the web</h2>
<ul id="pf" style="list-style:none;padding:0"></ul>
<script>
(function(){
 var T=[['../app/config.php','PHP code (app/config.php)'],['../app/secrets.php','Database login (app/secrets.php)'],['../database/schema.sql','Database files (database/schema.sql)'],['../database/restrict_postgres.sql','Database files (database/restrict_postgres.sql)'],['../SECURITY.md','Project notes (SECURITY.md)'],['../docs/apache-https-vhost.conf','Server notes (docs/)'],['.htaccess','Server rules (public/.htaccess)'],['img/','Folder listing (public/img/)']];
 var ul=document.getElementById('pf');
 T.forEach(function(t){
  var li=document.createElement('li');li.style.margin='10px 0';li.textContent='... testing '+t[1];ul.appendChild(li);
  fetch(t[0],{cache:'no-store',credentials:'omit'}).then(function(r){return r.text().then(function(b){
   var open=r.status===200&&(t[0]!=='img/'||/Index of/i.test(b));
   li.textContent=(open?'\u274C ':'\u2705 ')+t[1]+(open?' can be opened from the web. Fix this before going live (SECURITY.md, section 1).':' is not reachable (HTTP '+r.status+')');
  });}).catch(function(){li.textContent='\u26A0\uFE0F '+t[1]+' could not be tested';});
 });
})();
</script>
<?php if($ok): ?>
<p><b>All checks passed.</b> <a href="./">Open the store</a>, then delete check.php.</p>
<?php else: ?>
<p>Fix the ❌ items and reload this page. Apache's own log is at <code>xampp\apache\logs\error.log</code>.</p>
<?php endif ?>
