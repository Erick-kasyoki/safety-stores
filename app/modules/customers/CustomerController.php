<?php
// Admin: manage customer accounts.
// One public static method per action. Routes for this module are listed in routes.php.
class CustomerController{

 // ---- customers ----
 /** action: c_list */
 public static function list(){
  global $in,$act;
admin();
 out(db()->query("SELECT u.id,u.email,u.name,u.phone,u.active,to_char(u.last_login,'YYYY-MM-DD HH24:MI') AS last_login,to_char(u.created_at,'YYYY-MM-DD') AS created,(SELECT COUNT(*)::int FROM orders o WHERE o.user_id=u.id) AS orders,(SELECT COALESCE(SUM(total),0)::float8 FROM orders o WHERE o.user_id=u.id AND o.status<>'cancelled') AS spent FROM users u WHERE u.role='customer' ORDER BY u.id DESC LIMIT 1000")->fetchAll());
 }

 /** action: c_save */
 public static function save(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$n=mb_substr(str('name'),0,80);$e=strtolower(str('email'));$ph=mb_substr(str('phone'),0,40);
 if($n===''||!filter_var($e,FILTER_VALIDATE_EMAIL))out(['error'=>'Enter a name and a valid email.'],422);
 try{$s=db()->prepare("UPDATE users SET name=?,email=?,phone=? WHERE id=? AND role='customer'");$s->execute([$n,$e,$ph,$id]);}
 catch(PDOException $x){if($x->getCode()!=='23505')throw $x;out(['error'=>'That email already has an account.'],409);}
 if($s->rowCount()!==1)out(['error'=>'Unknown customer.'],404);out(['ok'=>true]);
 }

 /** action: c_active */
 public static function active(){
  global $in,$act;
post();admin();
 $s=db()->prepare("UPDATE users SET active=? WHERE id=? AND role='customer'");$s->execute([!empty($in['active'])?'t':'f',(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown customer.'],404);out(['ok'=>true]);
 }

 /** action: c_reset */
 public static function reset(){
  global $in,$act;
post();admin();
 $pw=(string)($in['password']??'');new_pw($pw);
 $s=db()->prepare("UPDATE users SET password_hash=?,must_change=false WHERE id=? AND role='customer'");$s->execute([password_hash($pw,PASSWORD_DEFAULT),(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown customer.'],404);out(['ok'=>true]);
 }

 /** action: c_delete */
 public static function delete(){
  global $in,$act;
post();admin();
 // orders are removed with the customer (the schema cascades), so make that explicit to the admin in the page
 $s=db()->prepare("DELETE FROM users WHERE id=? AND role='customer'");$s->execute([(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown customer.'],404);out(['ok'=>true]);
 }
}
