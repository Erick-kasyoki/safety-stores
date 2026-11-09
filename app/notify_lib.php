<?php
// Notifications (the bell). Events are written by nf_add(); every visitor reads the ones meant for them with api.php?action=notifs.
//  Customers and visitors: new products, and updates on their own orders ("mine_*" kinds, addressed to one user).
//  Workers: every order and staff event (orders, status changes, assignments, quotations, invoices, stock) plus things addressed to them.
//  Admins: everything in the system except the customer-only copies of order updates (the matching staff event already covers those).
// Read/unread is remembered by each browser, so nothing here needs a per-user state table.
const NF_SQL="CREATE TABLE IF NOT EXISTS notifications(id SERIAL PRIMARY KEY,aud TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE CASCADE,kind TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL DEFAULT '',link TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());CREATE INDEX IF NOT EXISTS notifications_aud_idx ON notifications(aud,id DESC);CREATE INDEX IF NOT EXISTS notifications_user_idx ON notifications(user_id,id DESC)";
function nf_ready(){
 static $ok=null;if($ok!==null)return $ok;
 try{$ok=(bool)db()->query("SELECT to_regclass('notifications') IS NOT NULL")->fetchColumn();if(!$ok){db()->exec(NF_SQL);$ok=(bool)db()->query("SELECT to_regclass('notifications') IS NOT NULL")->fetchColumn();}}
 catch(Throwable $x){error_log('notifications table: '.$x->getMessage());$ok=false;}
 return $ok;
}
// $aud: 'customers' | 'staff' | 'admin' for everyone in that group, or '' with $uid for one person. Never lets a failure reach the caller.
// Call it AFTER the database transaction has committed.
function nf_add(string $aud,?int $uid,string $kind,string $title,string $body='',string $link=''):void{
 try{
  if(!nf_ready())return;
  db()->prepare('INSERT INTO notifications(aud,user_id,kind,title,body,link) VALUES(?,?,?,?,?,?)')->execute([$aud,$uid?:null,$kind,mb_substr($title,0,140),mb_substr($body,0,300),mb_substr($link,0,80)]);
  if(random_int(1,60)===1)db()->exec("DELETE FROM notifications WHERE created_at<now()-interval '90 days'");
 }catch(Throwable $x){error_log('notification: '.$x->getMessage());}
}
function nf_money($n){return cfg('CURRENCY','$').number_format((float)$n,2);}
function nf_list(){
 header('Cache-Control: no-store');
 if(!nf_ready())out(['items'=>[],'ready'=>false]);
 $u=(int)uid();$role=$u?role():'guest';$p=[];
 if($role==='admin'){$w="kind NOT LIKE 'mine\\_%' OR user_id=?";$p=[$u];}
 elseif($role==='worker'){$w="aud IN('staff','customers') OR user_id=?";$p=[$u];}
 elseif($role==='customer'){$w="aud='customers' OR user_id=?";$p=[$u];}
 else $w="aud='customers'";
 $s=db()->prepare("SELECT id,kind,title,body,link,extract(epoch from created_at)::bigint AS ts FROM notifications WHERE ($w) ORDER BY id DESC LIMIT 30");$s->execute($p);
 $r=array_map(fn($x)=>['id'=>(int)$x['id'],'kind'=>$x['kind'],'title'=>$x['title'],'body'=>$x['body'],'link'=>$x['link'],'ts'=>(int)$x['ts']],$s->fetchAll());
 out(['items'=>$r,'now'=>time()]);
}

// ---------- Profile photos (dp): customers, workers and admins can add one when they sign up and change it later ----------
function photo_ready(){
 static $ok=null;if($ok!==null)return $ok;
 $chk="SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='photo' AND table_schema=current_schema())";
 try{$ok=(bool)db()->query($chk)->fetchColumn();if(!$ok){db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS photo TEXT NOT NULL DEFAULT ''");$ok=(bool)db()->query($chk)->fetchColumn();}}
 catch(Throwable $x){$ok=false;}
 return $ok;
}
function dp_ok($p){return (bool)preg_match('#^img/uploads/avatars/[a-f0-9]{16}\.(jpg|png|webp|gif)$#',(string)$p);}
function dp_drop($p){if(dp_ok($p))@unlink(PUBLIC_DIR.'/'.$p);}
// Checks the upload, crops it to a square and shrinks it (when PHP's GD is available), saves it, and returns its path.
function dp_save(array $f):string{
 if($f['error']!==UPLOAD_ERR_OK){
  $m=[UPLOAD_ERR_INI_SIZE=>'That photo is bigger than the server allows. Use a smaller one.',UPLOAD_ERR_FORM_SIZE=>'That photo is too big.',UPLOAD_ERR_NO_FILE=>'Choose a photo first.'];
  out(['error'=>$m[$f['error']]??'The upload failed. Try again.'],422);
 }
 if($f['size']>8*1024*1024)out(['error'=>'The photo is bigger than 8 MB.'],422);
 $info=@getimagesize($f['tmp_name']);
 $type=$info[2]??0;$ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_GIF=>'gif',IMAGETYPE_WEBP=>'webp'][$type]??null;
 if(!$ext)out(['error'=>'Use a JPG, PNG, WebP or GIF photo.'],422);
 if(($info[0]??0)*($info[1]??0)>25000000)out(['error'=>'That photo is too large in pixels. Use a smaller one.'],422);
 $base=PUBLIC_DIR.'/img/uploads';$dir=$base.'/avatars';
 if(!is_dir($base)){
  if(!@mkdir($base,0755,true))out(['error'=>'Could not create the img/uploads folder. Create it by hand and allow Apache to write to it.'],500);
  @file_put_contents($base.'/.htaccess',"Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n");
 }
 if(!is_dir($dir)&&!@mkdir($dir,0755,true))out(['error'=>'Could not create the img/uploads/avatars folder. Allow Apache to write to img/uploads.'],500);
 $name=bin2hex(random_bytes(8));
 $make=[IMAGETYPE_JPEG=>'imagecreatefromjpeg',IMAGETYPE_PNG=>'imagecreatefrompng',IMAGETYPE_GIF=>'imagecreatefromgif',IMAGETYPE_WEBP=>'imagecreatefromwebp'][$type];
 if(function_exists($make)&&function_exists('imagecreatetruecolor')&&function_exists('imagejpeg')&&($src=@$make($f['tmp_name']))){
  $w=imagesx($src);$h=imagesy($src);$s=min($w,$h);$out=imagecreatetruecolor(320,320);
  imagefill($out,0,0,imagecolorallocate($out,255,255,255));
  imagecopyresampled($out,$src,0,0,(int)(($w-$s)/2),(int)(($h-$s)/2),320,320,$s,$s);
  $ok=@imagejpeg($out,$dir.'/'.$name.'.jpg',86);imagedestroy($src);imagedestroy($out);
  if($ok)return 'img/uploads/avatars/'.$name.'.jpg';
 }
 if(!@move_uploaded_file($f['tmp_name'],$dir.'/'.$name.'.'.$ext))out(['error'=>'Could not save the photo. Allow Apache to write to the img/uploads folder.'],500);
 return 'img/uploads/avatars/'.$name.'.'.$ext;
}
function dp_upload(){
 need(['customer','worker','admin'],true);
 if($_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='fetch')out(['error'=>'Upload with POST required.'],405);
 if(!photo_ready())out(['error'=>'Profile photos need a database update: run database/migrate_photos.sql once as the postgres user (see README).'],503);
 $uid=(int)uid();$s=db()->prepare('SELECT photo FROM users WHERE id=?');$s->execute([$uid]);$old=(string)$s->fetchColumn();
 $path=dp_save($_FILES['photo']??['error'=>UPLOAD_ERR_NO_FILE]);
 db()->prepare('UPDATE users SET photo=? WHERE id=?')->execute([$path,$uid]);dp_drop($old);
 out(['ok'=>true,'photo'=>$path]);
}
function dp_remove(){
 post();need(['customer','worker','admin'],true);
 if(!photo_ready())out(['ok'=>true]);
 $uid=(int)uid();$s=db()->prepare('SELECT photo FROM users WHERE id=?');$s->execute([$uid]);$old=(string)$s->fetchColumn();
 db()->prepare("UPDATE users SET photo='' WHERE id=?")->execute([$uid]);dp_drop($old);out(['ok'=>true]);
}

// ---------- Till payment: an order must be paid (confirmed by a worker or admin) before it can be dispatched ----------
// Adds orders.paid_at / orders.paid_by automatically when the database user may; otherwise run database/migrate_payments.sql once as postgres.
function pay_ready(){
 static $ok=null;if($ok!==null)return $ok;
 $chk="SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='orders' AND column_name='paid_at' AND table_schema=current_schema())";
 try{$ok=(bool)db()->query($chk)->fetchColumn();if(!$ok){db()->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ, ADD COLUMN IF NOT EXISTS paid_by INT REFERENCES users(id) ON DELETE SET NULL");$ok=(bool)db()->query($chk)->fetchColumn();}}
 catch(Throwable $x){error_log('payment columns: '.$x->getMessage());$ok=false;}
 return $ok;
}
function till_no(){return trim((string)cfg('TILL_NUMBER',''));}
