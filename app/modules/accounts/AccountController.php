<?php
// Sign-in, sign-up, passwords and profile photos.
// One public static method per action. Routes for this module are listed in routes.php.
class AccountController{

 /** action: me */
 public static function me(){
  global $in,$act;
 $u=me_row();
 out(['user'=>$u?u_json($u):null,'cart'=>cart()]);
 }

 // ---- customers: anyone can create an account on the store ----
 /** action: register */
 public static function register(){
  global $in,$act;
post();
 $e=strtolower(str('email'));$pw=(string)($in['password']??'');$n=mb_substr(str('name'),0,80);$ph=mb_substr(str('phone'),0,40);
 if(!filter_var($e,FILTER_VALIDATE_EMAIL)||strlen($pw)<8)out(['error'=>'Enter a valid email and a password of at least 8 characters.'],422);
 if($n==='')$n=strstr($e,'@',true);
 try{$s=db()->prepare("INSERT INTO users(email,name,phone,password_hash,role) VALUES(?,?,?,?,'customer') RETURNING id");$s->execute([$e,$n,$ph,password_hash($pw,PASSWORD_DEFAULT)]);}
 catch(PDOException $x){if($x->getCode()!=='23505')throw $x;out(['error'=>'That email is already registered. Try signing in.'],409);}
 session_regenerate_id(true);$_SESSION['uid']=(int)$s->fetchColumn();remember_session();
 nf_add('admin',null,'user_new','New customer: '.$n,$e,'tab:c');
 out(['user'=>u_json(me_row()),'cart'=>cart()]);
 }

 /** action: login */
 public static function login(){
  global $in,$act;
post();
 $u=sign_in(['customer'],'Incorrect email or password.');
 out(['user'=>u_json($u),'cart'=>cart()]);
 }

 /** action: logout */
 public static function logout(){
  global $in,$act;
post();
 $_SESSION=[];session_destroy();out(['ok'=>true]);
 }

 // ---- staff: separate sign-in for admins and workers ----
 /** action: staff_login */
 public static function staffLogin(){
  global $in,$act;
post();
 $portal=str('portal')==='worker'?'worker':'admin';
 $u=sign_in([$portal],$portal==='admin'?'This account is not an admin. Workers sign in at worker.html.':'This account is not a worker. Admins sign in at admin.html.');
 out(['user'=>u_json($u)]);
 }

 // Worker sign-up: only for an email the admin has already added. The worker chooses their own password.
 /** action: worker_signup */
 public static function workerSignup(){
  global $in,$act;
post();
 $e=strtolower(str('email'));$pw=(string)($in['password']??'');$k1='ws:'.$e;$k2='ws:'.ip();throttle($k1,5);throttle($k2,20);
 if(!filter_var($e,FILTER_VALIDATE_EMAIL)||strlen($pw)<8)out(['error'=>'Enter your email and a password of at least 8 characters.'],422);
 $s=db()->prepare("UPDATE users SET password_hash=?,must_change=false,last_login=now() WHERE email=? AND role='worker' AND active AND password_hash='' RETURNING id");
 $s->execute([password_hash($pw,PASSWORD_DEFAULT),$e]);$id=$s->fetchColumn();
 if(!$id){fail_try($k1);fail_try($k2);out(['error'=>'Sign-up is not available for this email. Ask the store admin to add you as a worker first, or sign in if you already signed up.'],403);}
 session_regenerate_id(true);$_SESSION['uid']=(int)$id;remember_session();
 nf_add('admin',null,'worker_joined','Worker signed up: '.$e,'They can now take orders.','tab:w');
 out(['user'=>u_json(me_row())]);
 }

 // Admin sign-up: needs the setup code from config.php. With no code set, only the very first admin can sign up.
 /** action: admin_register */
 public static function adminRegister(){
  global $in,$act;
post();
 $k='a:'.ip();throttle($k,10);
 $code=(string)cfg('ADMIN_SIGNUP_CODE','');
 $has=(bool)db()->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
 if($code!==''){if(!hash_equals($code,(string)($in['code']??''))){fail_try($k);out(['error'=>'That admin setup code is not correct.'],403);}}
 elseif($has)out(['error'=>'Admin sign-up is closed. Ask an existing admin, or set ADMIN_SIGNUP_CODE in config.php.'],403);
 $e=strtolower(str('email'));$pw=(string)($in['password']??'');$n=mb_substr(str('name'),0,80);
 if($n===''||!filter_var($e,FILTER_VALIDATE_EMAIL)||strlen($pw)<8)out(['error'=>'Enter your name, a valid email and a password of at least 8 characters.'],422);
 try{$s=db()->prepare("INSERT INTO users(email,name,password_hash,role) VALUES(?,?,?,'admin') RETURNING id");$s->execute([$e,$n,password_hash($pw,PASSWORD_DEFAULT)]);}
 catch(PDOException $x){if($x->getCode()!=='23505')throw $x;out(['error'=>'That email is already registered.'],409);}
 session_regenerate_id(true);$_SESSION['uid']=(int)$s->fetchColumn();remember_session();
 out(['user'=>u_json(me_row())]);
 }

 /** action: change_password */
 public static function changePassword(){
  global $in,$act;
post();need(['customer','worker','admin'],true);
 $new=(string)($in['new']??'');new_pw($new);
 $s=db()->prepare('SELECT password_hash FROM users WHERE id=?');$s->execute([uid()]);
 if(!password_verify((string)($in['current']??''),(string)$s->fetchColumn()))out(['error'=>'Your current password is not correct.'],403);
 if($new===(string)($in['current']??''))out(['error'=>'Choose a password different from the current one.'],422);
 db()->prepare('UPDATE users SET password_hash=?,must_change=false WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),uid()]);
 out(['ok'=>true]);
 }

 /** action: u_photo */
 public static function uPhoto(){
  global $in,$act;
dp_upload();
 }

 /** action: u_photo_remove */
 public static function uPhotoRemove(){
  global $in,$act;
dp_remove();
 }
}
