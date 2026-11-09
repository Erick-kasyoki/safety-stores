<?php
// Admin: manage worker accounts.
// One public static method per action. Routes for this module are listed in routes.php.
class WorkerController{

 // ---- workers (admin only): the admin creates, switches off, resets and removes worker accounts ----
 /** action: w_list */
 public static function list(){
  global $in,$act;
admin();
 out(db()->query("SELECT u.id,u.email,u.name,u.phone,u.active,u.must_change,(u.password_hash='') AS pending,to_char(u.last_login,'YYYY-MM-DD HH24:MI') AS last_login,to_char(u.created_at,'YYYY-MM-DD') AS created,
  (SELECT COUNT(*)::int FROM orders o WHERE o.assigned_to=u.id AND o.status IN('pending','confirmed','dispatched')) AS open_orders,
  (SELECT COUNT(*)::int FROM orders o WHERE o.assigned_to=u.id AND o.status='delivered') AS delivered FROM users u WHERE u.role='worker' ORDER BY u.id")->fetchAll());
 }

 /** action: w_save */
 public static function save(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$n=mb_substr(str('name'),0,80);$e=strtolower(str('email'));$ph=mb_substr(str('phone'),0,40);$pw=(string)($in['password']??'');
 if($n===''||!filter_var($e,FILTER_VALIDATE_EMAIL))out(['error'=>'Enter the worker’s name and a valid email.'],422);
 try{
  if($id){
   $s=db()->prepare("UPDATE users SET name=?,email=?,phone=? WHERE id=? AND role='worker'");$s->execute([$n,$e,$ph,$id]);
   if($s->rowCount()!==1)out(['error'=>'Unknown worker.'],404);
  }else{
   // No password here: the worker signs up themselves at worker.html with this email (empty hash = waiting to sign up).
   $s=db()->prepare("INSERT INTO users(email,name,phone,password_hash,role,must_change) VALUES(?,?,?,'','worker',false) RETURNING id");
   $s->execute([$e,$n,$ph]);$id=(int)$s->fetchColumn();
  }
 }catch(PDOException $x){if($x->getCode()!=='23505')throw $x;out(['error'=>'That email already has an account.'],409);}
 out(['ok'=>true,'id'=>$id]);
 }

 /** action: w_active */
 public static function active(){
  global $in,$act;
post();admin();
 $s=db()->prepare("UPDATE users SET active=? WHERE id=? AND role='worker'");$s->execute([!empty($in['active'])?'t':'f',(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown worker.'],404);out(['ok'=>true]);
 }

 /** action: w_reset */
 public static function reset(){
  global $in,$act;
post();admin();
 $pw=(string)($in['password']??'');new_pw($pw);
 $s=db()->prepare("UPDATE users SET password_hash=?,must_change=true WHERE id=? AND role='worker'");$s->execute([password_hash($pw,PASSWORD_DEFAULT),(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown worker.'],404);out(['ok'=>true]);
 }

 /** action: w_delete */
 public static function delete(){
  global $in,$act;
post();admin();
 $s=db()->prepare("DELETE FROM users WHERE id=? AND role='worker'");$s->execute([(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown worker.'],404);out(['ok'=>true]);
 }
}
