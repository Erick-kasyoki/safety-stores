<?php
require __DIR__.'/config.php';
require __DIR__.'/invoice_lib.php';
require __DIR__.'/mail_lib.php';
require __DIR__.'/session_lib.php';
require __DIR__.'/modules_lib.php'; // Expense Tracker + Employee Leave Management
require __DIR__.'/quotes_lib.php'; // Quotations + best sellers
require __DIR__.'/notify_lib.php'; // Notification bell
require __DIR__.'/mpesa_lib.php'; // M-Pesa STK Push (Daraja)
secure_session();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
set_exception_handler(function($x){error_log((string)$x);out(['error'=>'Server error. See the Apache error log.'],500);});
// JSON-only POSTs: a cross-site form cannot send this content type, which blocks CSRF.
function post(){if($_SERVER['REQUEST_METHOD']!=='POST'||stripos($_SERVER['CONTENT_TYPE']??'','application/json')!==0)out(['error'=>'POST with JSON required.'],405);}
// The signed-in user, re-read from the database on every request, so switching an account off (or changing its role) takes effect at once.
function me_row(){
 static $c=[];$id=$_SESSION['uid']??null;if(!$id)return null;
 if(!array_key_exists($id,$c)){
  $s=db()->prepare('SELECT id,email,name,phone,role,active,must_change'.(photo_ready()?',photo':'').' FROM users WHERE id=?');$s->execute([$id]);$r=$s->fetch();
  if($r&&!$r['active'])$r=false;
  $c[$id]=$r?['id'=>(int)$r['id'],'email'=>$r['email'],'name'=>$r['name'],'phone'=>$r['phone'],'role'=>$r['role'],'must_change'=>(bool)$r['must_change']]:null;
 }
 if(!$c[$id]){$_SESSION=[];}
 return $c[$id];
}
function uid(){$u=me_row();return $u?$u['id']:null;}
function role(){$u=me_row();return $u?$u['role']:null;}
function u_json($u){return['id'=>(int)$u['id'],'email'=>$u['email'],'name'=>$u['name'],'role'=>$u['role'],'is_admin'=>$u['role']==='admin','must_change'=>$u['must_change'],'photo'=>(string)($u['photo']??'')]+(in_array($u['role'],['admin','worker'],true)?['home'=>$u['role']==='admin'?'admin.html':'worker.html']:[]);}
// Only the listed roles may continue. Workers with a temporary password must set their own first.
function need(array $roles,$allowChange=false){
 $u=me_row();
 if(!$u)out(['error'=>'Sign in first.'],401);
 if(!in_array($u['role'],$roles,true))out(['error'=>$roles===['admin']?'Admins only.':'Not allowed for this account.'],403);
 if($u['must_change']&&!$allowChange)out(['error'=>'Set your own password first.','must_change'=>true],403);
}
function admin(){need(['admin']);}
function staff(){need(['admin','worker']);}
// Slows down password guessing: too many recent failures for a key (email or address) block further tries for a while.
function throttle($k,$max=8){
 $s=db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE k=? AND at>now()-interval '15 minutes'");$s->execute([$k]);
 if((int)$s->fetchColumn()>=$max)out(['error'=>'Too many failed attempts. Wait 15 minutes and try again.'],429);
}
function fail_try($k){db()->prepare('INSERT INTO login_attempts(k) VALUES(?)')->execute([$k]);if(random_int(1,20)===1)db()->exec("DELETE FROM login_attempts WHERE at<now()-interval '1 day'");}
function ip(){return $_SERVER['REMOTE_ADDR']??'';}
// Checks email and password, starts the session and returns the account row. Callers check the role.
function sign_in(array $roles,$wrongRole){
 $e=strtolower(str('email'));$k1='e:'.$e;$k2='i:'.ip();throttle($k1);throttle($k2,40);
 $s=db()->prepare('SELECT id,email,name,password_hash,role,active,must_change'.(photo_ready()?',photo':'').' FROM users WHERE email=?');$s->execute([$e]);$u=$s->fetch();
 if(!$u||$u['password_hash']===''||!password_verify((string)($GLOBALS['in']['password']??''),$u['password_hash'])){fail_try($k1);fail_try($k2);out(['error'=>'Incorrect email or password.'],401);}
 if(!in_array($u['role'],$roles,true))out(['error'=>$wrongRole],403);
 if(!$u['active'])out(['error'=>'This account has been switched off. Please contact the store admin.'],403);
 db()->prepare('DELETE FROM login_attempts WHERE k=?')->execute([$k1]);
 db()->prepare('UPDATE users SET last_login=now() WHERE id=?')->execute([$u['id']]);
 session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];remember_session();
 $u['must_change']=(bool)$u['must_change'];return $u;
}
function new_pw($pw){if(strlen($pw)<8)out(['error'=>'The password must be at least 8 characters.'],422);}
function str($k){global $in;return trim((string)($in[$k]??''));}
function prod($r){return['id'=>(int)$r['id'],'name'=>$r['name'],'descr'=>$r['descr'],'price'=>(float)$r['price'],'was'=>(float)$r['was'],'emoji'=>$r['emoji'],'image'=>$r['image'],'dept'=>$r['dept'],'stock'=>(int)$r['stock']];}
// Admin view of a product: adds the inventory fields the public site never sees (cost, SKU, reorder level).
function prodA($r){return prod($r)+['sku'=>$r['sku'],'cost'=>$r['cost']===null?null:(float)$r['cost'],'reorder_level'=>(int)$r['reorder_level']];}
const PCOLS='id,name,descr,price,was,emoji,image,dept,stock,sku,cost,reorder_level';
// Product photos uploaded from admin live in img/uploads/ under random names.
function up_ok($p){return (bool)preg_match('#^img/uploads/[a-f0-9]{16}\.(jpg|png|webp|gif)$#',(string)$p);}
// Delete uploaded photo files that no product uses any more (call AFTER the product change is committed).
function up_gc(array $paths){
 foreach(array_unique($paths) as $p){
  if(!up_ok($p))continue;
  $s=db()->prepare('SELECT 1 FROM products WHERE image=? LIMIT 1');$s->execute([$p]);
  if(!$s->fetchColumn())@unlink(PUBLIC_DIR.'/'.$p);
 }
}
// ---- business hours: working minutes between two moments, using the admin's Business Hours settings ----
function bh_minutes(int $from,int $to):int{
 if($to<=$from)return 0;
 $tz=new DateTimeZone(app_tz());
 $hm=function($v,$d){$p=explode(':',(string)$v);return count($p)==2?((int)$p[0]*60+(int)$p[1]):$d;};
 $st=$hm(cfg('BH_START','08:00'),480);$en=$hm(cfg('BH_END','17:00'),1020);
 $days=array_filter(explode(',',cfg('BH_DAYS','1,2,3,4,5')),'strlen');$hol=array_flip(array_filter(explode(',',cfg('BH_HOLIDAYS',''))));
 $cur=(new DateTime('@'.$from))->setTimezone($tz)->setTime(0,0);$tot=0;
 for($n=0;$cur->getTimestamp()<$to&&$n<800;$n++){
  if(in_array($cur->format('N'),$days,true)&&!isset($hol[$cur->format('Y-m-d')])){
   $a=max($from,$cur->getTimestamp()+$st*60);$b=min($to,$cur->getTimestamp()+$en*60);if($b>$a)$tot+=intdiv($b-$a,60);}
  $cur->modify('+1 day')->setTime(0,0);}
 return $tot;
}
function img_ok($v){return $v!==''&&strpos($v,'..')===false&&preg_match('#^(https?://\S+|[\w./-]+)$#',$v);}
// CSV safety: a text cell that starts with = + - @ would run as a formula in Excel, so it is exported with a leading '
function cx($v){$v=(string)$v;return $v!==''&&strpos("=+-@\t\r",$v[0])!==false?"'".$v:$v;}
// ...and the leading ' is removed again on import.
function ix($v){$v=trim((string)$v);return strlen($v)>1&&$v[0]==="'"&&strpos("=+-@",$v[1])!==false?substr($v,1):$v;}
function inum($v){$v=preg_replace('/[^0-9.\-]/','',(string)$v);return $v===''?null:(is_numeric($v)?(float)$v:false);}
// Every stock change is written to stock_movements (call inside the same transaction as the change).
function mv($pdo,$pid,$name,$kind,$chg,$after,$price=null,$oid=null,$note='',$actor=null){
 $pdo->prepare('INSERT INTO stock_movements(product_id,name,kind,qty_change,qty_after,unit_price,order_id,note,user_id) VALUES(?,?,?,?,?,?,?,?,?)')
  ->execute([$pid,$name,$kind,$chg,$after,$price===null?null:number_format($price,2,'.',''),$oid,mb_substr($note,0,200),$actor]);
}
// The cart lives in the session; names and prices always come from the database.
function cart(){
 $c=$_SESSION['cart']??[];$items=[];$cents=0;$n=0;
 if($c){
  $ids=array_keys($c);
  $s=db()->prepare('SELECT id,name,price,emoji,image FROM products WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id');
  $s->execute($ids);
  foreach($s->fetchAll() as $r){
   $r['id']=(int)$r['id'];$r['qty']=$c[$r['id']];$r['price']=(float)$r['price'];
   $cents+=(int)round($r['price']*100)*$r['qty'];$n+=$r['qty'];$items[]=$r;
  }
 }
 return['items'=>$items,'total'=>$cents/100,'count'=>$n];
}

$act=$_GET['action']??'';
// Older databases may lack the settings table. Create it only when it is really missing: the limited database user cannot create tables, so trying on every request would fail (and log an error) every time.
try{if(!db()->query("SELECT to_regclass('settings')")->fetchColumn())db()->exec("CREATE TABLE settings(k TEXT PRIMARY KEY,v TEXT NOT NULL DEFAULT '')");}catch(Throwable $x){}
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=[];
