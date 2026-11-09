<?php
// Admin: manage administrator accounts.
// One public static method per action. Routes for this module are listed in routes.php.
class AdminController{

 // ---- other admins ----
 /** action: ad_list */
 public static function list(){
  global $in,$act;
admin();
 out(db()->query("SELECT id,email,name,phone,active,to_char(last_login,'YYYY-MM-DD HH24:MI') AS last_login,to_char(created_at,'YYYY-MM-DD') AS created FROM users WHERE role='admin' ORDER BY id")->fetchAll());
 }

 /** action: ad_save */
 public static function save(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$n=mb_substr(str('name'),0,80);$e=strtolower(str('email'));$ph=mb_substr(str('phone'),0,40);$pw=(string)($in['password']??'');
 if($n===''||!filter_var($e,FILTER_VALIDATE_EMAIL))out(['error'=>'Enter a name and a valid email.'],422);
 try{
  if($id){$s=db()->prepare("UPDATE users SET name=?,email=?,phone=? WHERE id=? AND role='admin'");$s->execute([$n,$e,$ph,$id]);if($s->rowCount()!==1)out(['error'=>'Unknown admin.'],404);
   if($pw!==''){new_pw($pw);db()->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='admin'")->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);}}
  else{new_pw($pw);$s=db()->prepare("INSERT INTO users(email,name,phone,password_hash,role) VALUES(?,?,?,?,'admin') RETURNING id");$s->execute([$e,$n,$ph,password_hash($pw,PASSWORD_DEFAULT)]);$id=(int)$s->fetchColumn();}
 }catch(PDOException $x){if($x->getCode()!=='23505')throw $x;out(['error'=>'That email already has an account.'],409);}
 out(['ok'=>true,'id'=>$id]);
 }

 /** action: ad_active */
 public static function active(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$on=!empty($in['active']);
 if(!$on&&$id===uid())out(['error'=>'You can’t switch off your own account.'],409);
 if(!$on&&(int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active")->fetchColumn()<=1)out(['error'=>'At least one admin must stay active.'],409);
 $s=db()->prepare("UPDATE users SET active=? WHERE id=? AND role='admin'");$s->execute([$on?'t':'f',$id]);
 if($s->rowCount()!==1)out(['error'=>'Unknown admin.'],404);out(['ok'=>true]);
 }

 /** action: ad_delete */
 public static function delete(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);if($id===uid())out(['error'=>'You can’t delete your own account.'],409);
 if((int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active")->fetchColumn()<=1)out(['error'=>'At least one admin must stay.'],409);
 $s=db()->prepare("DELETE FROM users WHERE id=? AND role='admin'");$s->execute([$id]);
 if($s->rowCount()!==1)out(['error'=>'Unknown admin.'],404);out(['ok'=>true]);
 }
}
