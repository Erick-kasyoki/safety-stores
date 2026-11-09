<?php
// Order emails: a small SMTP client (no extra software needed) with a fallback to PHP's mail().
// Settings (Admin > Settings > Order emails): ORDER_EMAIL, SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls|ssl|none), SMTP_USER, SMTP_PASS, MAIL_FROM.
function mail_hdr($s){return trim(preg_replace('/[\r\n]+/',' ',(string)$s));}
function mail_list($s){$o=[];foreach(preg_split('/[,;\s]+/',(string)$s,-1,PREG_SPLIT_NO_EMPTY) as $a)if(filter_var($a,FILTER_VALIDATE_EMAIL))$o[]=$a;return array_values(array_unique($o));}
function mail_enc($s){return '=?UTF-8?B?'.base64_encode((string)$s).'?=';}
function smtp_read($f){$r='';while(($l=fgets($f,515))!==false){$r.=$l;if(strlen($l)<4||$l[3]===' ')break;}return $r;}
function smtp_cmd($f,$c,$ok){
 if($c!==null)fwrite($f,$c."\r\n");
 $r=smtp_read($f);
 if(!in_array((int)substr($r,0,3),(array)$ok,true))throw new RuntimeException(trim($r)!==''?trim($r):'No reply from the mail server.');
 return $r;
}
function mail_send(array $to,$subject,$body,$replyTo=''){
 $host=trim((string)cfg('SMTP_HOST',''));$user=trim((string)cfg('SMTP_USER',''));$pass=(string)cfg('SMTP_PASS','');
 $from=(mail_list(cfg('MAIL_FROM',''))[0]??(mail_list($user)[0]??'orders@localhost'));
 $fromName=mail_hdr(cfg('SITE_NAME','Store'));
 $h=['Date: '.date('r'),'From: '.mail_enc($fromName).' <'.$from.'>','To: '.implode(', ',$to),'Subject: '.mail_enc(mail_hdr($subject)),
  'Message-ID: <'.bin2hex(random_bytes(8)).'@'.(preg_replace('/[^a-z0-9.-]/i','',explode('@',$from)[1]??'localhost')?:'localhost').'>',
  'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
 if($replyTo!==''&&filter_var($replyTo,FILTER_VALIDATE_EMAIL))$h[]='Reply-To: '.$replyTo;
 $enc=chunk_split(base64_encode((string)$body),76,"\r\n");
 if($host===''){ // no SMTP details: try the server's own mail()
  $hh=array_values(array_filter($h,fn($x)=>!preg_match('/^(To|Subject):/',$x)));
  if(!@mail(implode(',',$to),mail_enc(mail_hdr($subject)),$enc,implode("\r\n",$hh)))
   throw new RuntimeException('This server cannot send mail by itself. Fill in the SMTP host, user and password in Admin > Settings > Order emails.');
  return;
 }
 $secure=strtolower((string)cfg('SMTP_SECURE','tls'));if(!in_array($secure,['tls','ssl','none'],true))$secure='tls';
 $port=(int)cfg('SMTP_PORT',0)?:($secure==='ssl'?465:587);
 $f=@stream_socket_client(($secure==='ssl'?'ssl://':'tcp://').$host.':'.$port,$en,$es,10,STREAM_CLIENT_CONNECT);
 if(!$f)throw new RuntimeException("Could not connect to $host:$port ($es). Check the host, port and security setting.");
 stream_set_timeout($f,10);
 try{
  smtp_cmd($f,null,220);smtp_cmd($f,'EHLO localhost',250);
  if($secure==='tls'){
   smtp_cmd($f,'STARTTLS',220);
   if(!@stream_enable_crypto_ok($f))throw new RuntimeException('Could not start a secure (TLS) connection. If this is XAMPP on Windows, set openssl.cafile in php.ini to a CA bundle, or try SSL on port 465.');
   smtp_cmd($f,'EHLO localhost',250);
  }
  if($user!==''){smtp_cmd($f,'AUTH LOGIN',334);smtp_cmd($f,base64_encode($user),334);smtp_cmd($f,base64_encode($pass),235);}
  smtp_cmd($f,'MAIL FROM:<'.$from.'>',250);
  foreach($to as $a)smtp_cmd($f,'RCPT TO:<'.$a.'>',[250,251]);
  smtp_cmd($f,'DATA',354);
  fwrite($f,preg_replace('/^\./m','..',implode("\r\n",$h)."\r\n\r\n".$enc)."\r\n.\r\n");
  smtp_cmd($f,null,250);
  @fwrite($f,"QUIT\r\n");
 }finally{@fclose($f);}
}
function stream_enable_crypto_ok($f){return stream_socket_enable_crypto($f,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);}
// Email the store about a new order. Never throws into the checkout.
function notify_order($oid){
 $to=mail_list(cfg('ORDER_EMAIL',''));if(!$to)return;
 $s=db()->prepare('SELECT o.*,u.email AS cemail FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$s->execute([(int)$oid]);$o=$s->fetch();if(!$o)return;
 $s=db()->prepare('SELECT name,unit_price,qty FROM order_items WHERE order_id=? ORDER BY id');$s->execute([(int)$oid]);
 $cur=cfg('CURRENCY','$');$lines=[];
 foreach($s->fetchAll() as $r)$lines[]=sprintf('  %d x %s @ %s%s = %s%s',$r['qty'],$r['name'],$cur,number_format((float)$r['unit_price'],2),$cur,number_format($r['qty']*(float)$r['unit_price'],2));
 $link='';
 if(cfg('SITE_URL','')!=='')$link=rtrim(cfg('SITE_URL',''),'/').'/admin.html'; // best: set SITE_URL in app/secrets.php so the link cannot be forged through the Host header
 else{
  $host=mail_hdr($_SERVER['HTTP_HOST']??'');$dir=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/');
  $tls=function_exists('is_https')?is_https():(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
  if($host!=='')$link=($tls?'https':'http').'://'.$host.$dir.'/admin.html';
 }
 $body="New order #{$o['id']}\r\n\r\nCustomer: {$o['name']}\r\nPhone: {$o['phone']}\r\nEmail: {$o['cemail']}\r\nAddress: {$o['address']}\r\nPayment: {$o['payment']}\r\n"
  .($o['note']!==''?"Note: {$o['note']}\r\n":'')."\r\nItems:\r\n".implode("\r\n",$lines)."\r\n\r\nTotal: {$cur}".number_format((float)$o['total'],2)."\r\n"
  .($link?"\r\nManage this order: $link\r\n":'');
 mail_send($to,'New order #'.$o['id'].' - '.$o['name'].' - '.$cur.number_format((float)$o['total'],2),$body,(string)$o['cemail']);
}
