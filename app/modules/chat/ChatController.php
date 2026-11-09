<?php
// Staff live chat. Only signed-in admins and workers can use it, and only with other active staff accounts.
// Every query is limited to conversations the signed-in person is part of, so one worker can never read another worker's chats.
// "Clear chat" hides the older messages from the person who cleared it only; the other person keeps their copy.
// Replies (reply_to), clear-chat (chat_clears) and file attachments (file_* columns) need database/migrate_chat.sql; until it is run the chat still sends and receives normally.
// Attachments are stored in app/chat_files (never served directly) and only the sender and the recipient can download them through ch_file.
class ChatController{
 private static ?array $feat=null;
 /** which optional parts of the chat schema exist (checked once per request) */
 private static function feat():array{
  if(self::$feat===null){
   try{$r=db()->query("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='chat_messages' AND column_name='reply_to') AS reply, (to_regclass('chat_clears') IS NOT NULL) AS clears, EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='chat_messages' AND column_name='file_path') AS file")->fetch();
    self::$feat=['reply'=>(bool)$r['reply'],'clears'=>(bool)$r['clears'],'file'=>(bool)$r['file']];}
   catch(Throwable $x){self::$feat=['reply'=>false,'clears'=>false,'file'=>false];}
  }
  return self::$feat;
 }
 private static function peer(int $id):array{
  $s=db()->prepare("SELECT id,name,role FROM users WHERE id=? AND role IN('admin','worker') AND active AND password_hash<>'' AND id<>?");$s->execute([$id,(int)uid()]);
  $r=$s->fetch();if(!$r)out(['error'=>'Choose someone from the list.'],404);return $r;
 }
 /** id of the newest message this person has cleared in the conversation with $peer (0 = nothing cleared) */
 private static function cleared(int $me,int $peer):int{
  if(!self::feat()['clears'])return 0;
  $s=db()->prepare('SELECT upto FROM chat_clears WHERE user_id=? AND peer_id=?');$s->execute([$me,$peer]);return (int)$s->fetchColumn();
 }
 /** SQL for "the newest message id I cleared with the person in column $col" (ints are cast, so it is safe to inline) */
 private static function clrSql(int $me,string $col):string{
  return self::feat()['clears']?"COALESCE((SELECT upto FROM chat_clears c WHERE c.user_id=$me AND c.peer_id=$col),0)":"0";
 }

 /** action: ch_users (everyone you can chat with, with unread counts and the last message) */
 public static function users(){
  staff();$me=(int)uid();$cl=self::clrSql($me,'u.id');$lf=self::feat()['file']?"(SELECT (m.file_path IS NOT NULL) FROM chat_messages m WHERE ((m.sender_id=u.id AND m.recipient_id=$me) OR (m.sender_id=$me AND m.recipient_id=u.id)) AND m.id>$cl ORDER BY m.id DESC LIMIT 1)":"false";
  $s=db()->prepare("SELECT u.id,u.name,u.role,
   (SELECT COUNT(*)::int FROM chat_messages m WHERE m.sender_id=u.id AND m.recipient_id=$me AND m.read_at IS NULL AND m.id>$cl) AS unread,
   (SELECT m.body FROM chat_messages m WHERE ((m.sender_id=u.id AND m.recipient_id=$me) OR (m.sender_id=$me AND m.recipient_id=u.id)) AND m.id>$cl ORDER BY m.id DESC LIMIT 1) AS last,
   (SELECT m.id FROM chat_messages m WHERE ((m.sender_id=u.id AND m.recipient_id=$me) OR (m.sender_id=$me AND m.recipient_id=u.id)) AND m.id>$cl ORDER BY m.id DESC LIMIT 1) AS last_id,
   $lf AS last_file
   FROM users u WHERE u.role IN('admin','worker') AND u.active AND u.password_hash<>'' AND u.id<>$me ORDER BY last_id DESC NULLS LAST,u.name");
  $s->execute();
  $r=$s->fetchAll();foreach($r as &$x){$x['id']=(int)$x['id'];$x['unread']=(int)$x['unread'];$x['last']=mb_substr((string)$x['last'],0,60);$x['last_file']=(bool)$x['last_file'];unset($x['last_id']);}unset($x);
  out(['me'=>$me,'me_name'=>(string)(me_row()['name']??''),'users'=>$r]);
 }

 /** action: ch_msgs (the conversation with ?with=ID; ?after=ID returns only newer messages; incoming messages are marked read).
  *  Each message may carry the message it replies to; read_upto = newest of MY messages the other person has read (for the double ticks). */
 public static function msgs(){
  staff();$me=(int)uid();$p=self::peer((int)($_GET['with']??0));$after=(int)($_GET['after']??0);
  $pdo=db();$clr=self::cleared($me,(int)$p['id']);$from=max($after,$clr);$rp=self::feat()['reply'];
  $sel=$rp?"m.reply_to,r.body AS rbody,r.sender_id AS rsender":"NULL AS reply_to,NULL AS rbody,NULL AS rsender";
  $sel.=self::feat()['file']?",m.file_name,m.file_type,m.file_size":",NULL AS file_name,NULL AS file_type,NULL AS file_size";
  $join=$rp?"LEFT JOIN chat_messages r ON r.id=m.reply_to":"";
  $s=$pdo->prepare("SELECT m.id,m.sender_id,m.body,$sel,to_char(m.created_at,'YYYY-MM-DD HH24:MI') AS at FROM chat_messages m $join
   WHERE m.id>? AND ((m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?)) ORDER BY m.id DESC LIMIT 200");
  $s->execute([$from,$me,$p['id'],$p['id'],$me]);
  $r=array_reverse($s->fetchAll());
  foreach($r as &$x){
   $x['id']=(int)$x['id'];$x['mine']=((int)$x['sender_id']===$me);
   $rid=(int)$x['reply_to'];
   $x['reply']=($rid&&$rid>$clr&&$x['rbody']!==null)?['id'=>$rid,'body'=>mb_substr((string)$x['rbody'],0,140),'mine'=>((int)$x['rsender']===$me)]:null;
   $x['file']=$x['file_name']!==null?['name'=>$x['file_name'],'size'=>(int)$x['file_size'],'img'=>strpos((string)$x['file_type'],'image/')===0]:null;
   unset($x['sender_id'],$x['reply_to'],$x['rbody'],$x['rsender'],$x['file_name'],$x['file_type'],$x['file_size']);
  }unset($x);
  // bubble colour: worker -> admin = red, admin -> worker = green; between two people of the same role the one who started the chat is orange and the other one's replies are green
  $myRole=(string)((me_row()['role'])??'');$pRole=(string)$p['role'];
  $f=$pdo->prepare('SELECT sender_id FROM chat_messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?) ORDER BY id ASC LIMIT 1');$f->execute([$me,$p['id'],$p['id'],$me]);$starter=(int)$f->fetchColumn();
  foreach($r as &$x){
   $sRole=$x['mine']?$myRole:$pRole;$rRole=$x['mine']?$pRole:$myRole;
   if($sRole==='worker'&&$rRole==='admin')$x['c']='red';
   elseif($sRole==='admin'&&$rRole==='worker')$x['c']='green';
   else{$sid=$x['mine']?$me:(int)$p['id'];$x['c']=($sid===$starter)?'orange':'green';}
  }unset($x);
  $pdo->prepare('UPDATE chat_messages SET read_at=now() WHERE sender_id=? AND recipient_id=? AND read_at IS NULL')->execute([$p['id'],$me]);
  $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE sender_id=? AND recipient_id=? AND read_at IS NOT NULL');$q->execute([$me,$p['id']]);
  out(['with'=>$p,'me'=>['id'=>$me,'name'=>(string)(me_row()['name']??''),'role'=>$myRole],'messages'=>$r,'read_upto'=>(int)$q->fetchColumn()]);
 }

 /** Attachment rules: extension => stored content type. Images are checked with getimagesize; everything else is download-only. */
 private const FILES=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv','txt'=>'text/plain'];
 private const MAXFILE=5*1024*1024;
 private static function dir():string{return dirname(__DIR__,2).'/chat_files';}

 /** validates and stores an uploaded attachment; returns [path,name,type,size] */
 private static function saveFile(array $f):array{
  if(!self::feat()['file'])out(['error'=>'Attachments need a database update: run database/migrate_chat.sql as the postgres user.'],503);
  if($f['error']!==UPLOAD_ERR_OK){
   $m=[UPLOAD_ERR_INI_SIZE=>'That file is bigger than the server allows.',UPLOAD_ERR_FORM_SIZE=>'That file is too big.',UPLOAD_ERR_NO_FILE=>'Choose a file first.'];
   out(['error'=>$m[$f['error']]??'The upload failed. Try again.'],422);
  }
  if($f['size']>self::MAXFILE)out(['error'=>'Files can be up to 5 MB.'],422);
  $name=trim(preg_replace('#[\x00-\x1f\x7f\\/:*?"<>|]+#u','_',basename((string)$f['name'])));
  if($name===''||$name==='.')$name='file';
  if(mb_strlen($name)>100){$e=pathinfo($name,PATHINFO_EXTENSION);$name=mb_substr(pathinfo($name,PATHINFO_FILENAME),0,90).($e?'.'.$e:'');}
  $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$type=self::FILES[$ext]??null;
  if(!$type)out(['error'=>'That file type is not allowed. Use an image, PDF, Word, Excel, CSV or text file.'],422);
  if(strpos($type,'image/')===0){$i=@getimagesize($f['tmp_name']);if(!$i||!in_array($i[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true))out(['error'=>'That image could not be read.'],422);}
  $dir=self::dir();
  if(!is_dir($dir)){
   if(!@mkdir($dir,0755,true))out(['error'=>'Could not create the app/chat_files folder. Create it by hand and allow Apache to write to it.'],500);
   @file_put_contents($dir.'/.htaccess',"Require all denied\n");
  }
  $path=bin2hex(random_bytes(16)).'.'.$ext;
  if(!@move_uploaded_file($f['tmp_name'],$dir.'/'.$path))out(['error'=>'Could not save the file. Allow Apache to write to the app/chat_files folder.'],500);
  return [$path,$name,$type,(int)$f['size']];
 }

 /** action: ch_send (JSON {to,body,reply_to}, or multipart with the same fields plus a "file" attachment) */
 public static function send(){
  global $in;staff();$me=(int)uid();$pdo=db();
  $multi=stripos($_SERVER['CONTENT_TYPE']??'','multipart/form-data')===0;
  if($multi){
   if($_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='fetch')out(['error'=>'Upload with POST required.'],405);
   $in=$_POST;
   // a file over post_max_size arrives with empty $_POST and $_FILES
   if(!$_POST&&!$_FILES&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)out(['error'=>'That file is bigger than the server allows.'],422);
  }else post();
  $p=self::peer((int)($in['to']??0));
  $b=trim((string)($in['body']??''));$f=$multi?($_FILES['file']??null):null;
  if($f&&$f['error']===UPLOAD_ERR_NO_FILE)$f=null;
  if($b===''&&!$f)out(['error'=>'Type a message first.'],422);
  if(mb_strlen($b)>2000)out(['error'=>'Messages can be up to 2000 characters.'],422);
  if(!$f){
   // the same text sent twice within 3 seconds (double click) is stored once
   $d=$pdo->prepare("SELECT id FROM chat_messages WHERE sender_id=? AND recipient_id=? AND body=? AND created_at>now()-interval '3 seconds' ORDER BY id DESC LIMIT 1");$d->execute([$me,$p['id'],$b]);
   if($dup=$d->fetchColumn())out(['id'=>(int)$dup,'duplicate'=>true]);
  }
  $rt=(int)($in['reply_to']??0);
  if($rt&&self::feat()['reply']){
   $q=$pdo->prepare('SELECT id FROM chat_messages WHERE id=? AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))');$q->execute([$rt,$me,$p['id'],$p['id'],$me]);
   if(!$q->fetchColumn())$rt=0;
  }else $rt=0;
  $cols='sender_id,recipient_id,body';$vals=[$me,$p['id'],$b];
  if($f){[$fp,$fn,$ft,$fs]=self::saveFile($f);if($b==='')$vals[2]=$fn;$cols.=',file_path,file_name,file_type,file_size';array_push($vals,$fp,$fn,$ft,$fs);}
  if(self::feat()['reply']){$cols.=',reply_to';$vals[]=$rt?:null;}
  $s=$pdo->prepare("INSERT INTO chat_messages($cols) VALUES(".implode(',',array_fill(0,count($vals),'?')).") RETURNING id");$s->execute($vals);
  out(['id'=>(int)$s->fetchColumn()]);
 }

 /** action: ch_file (?id=MESSAGE_ID, optional &dl=1) streams an attachment, only to the two people in that conversation */
 public static function file(){
  staff();$me=(int)uid();
  if(!self::feat()['file'])out(['error'=>'Not found.'],404);
  $s=db()->prepare('SELECT file_path,file_name,file_type FROM chat_messages WHERE id=? AND file_path IS NOT NULL AND (sender_id=? OR recipient_id=?)');
  $s->execute([(int)($_GET['id']??0),$me,$me]);$m=$s->fetch();
  $path=$m?self::dir().'/'.basename((string)$m['file_path']):'';
  if(!$m||!is_file($path))out(['error'=>'File not found.'],404);
  $img=strpos((string)$m['file_type'],'image/')===0&&empty($_GET['dl']);
  header_remove('Content-Type');
  header('Content-Type: '.($img?$m['file_type']:'application/octet-stream'));
  header('Content-Length: '.filesize($path));
  header('Content-Disposition: '.($img?'inline':'attachment').'; filename="'.addcslashes(preg_replace('/[^\x20-\x7e]/','_',$m['file_name']),'"\\').'"; filename*=UTF-8\'\''.rawurlencode($m['file_name']));
  header('Cache-Control: private, max-age=86400');
  header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
  readfile($path);exit;
 }

 /** action: ch_clear (clear the conversation with {to:ID} from MY side only) */
 public static function clear(){
  global $in;post();staff();$me=(int)uid();$p=self::peer((int)($in['to']??0));$pdo=db();
  if(!self::feat()['clears'])out(['error'=>'Clear chat needs a database update: run database/migrate_chat.sql as the postgres user.'],503);
  $s=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)');
  $s->execute([$me,$p['id'],$p['id'],$me]);$max=(int)$s->fetchColumn();
  $pdo->prepare('INSERT INTO chat_clears(user_id,peer_id,upto) VALUES(?,?,?) ON CONFLICT(user_id,peer_id) DO UPDATE SET upto=GREATEST(chat_clears.upto,EXCLUDED.upto)')->execute([$me,$p['id'],$max]);
  $pdo->prepare('UPDATE chat_messages SET read_at=now() WHERE sender_id=? AND recipient_id=? AND read_at IS NULL')->execute([$p['id'],$me]);
  out(['ok'=>true]);
 }

 /** action: ch_unread (total unread, for the badge on the chat button) */
 public static function unread(){
  staff();$me=(int)uid();$cl=self::clrSql($me,'m.sender_id');
  $s=db()->prepare("SELECT COUNT(*)::int FROM chat_messages m WHERE m.recipient_id=$me AND m.read_at IS NULL AND m.id>$cl");$s->execute();
  out(['unread'=>(int)$s->fetchColumn()]);
 }
}
