<?php
// Store settings and the test email.
// One public static method per action. Routes for this module are listed in routes.php.
class SettingController{

 // ---- store settings: name, contact details, currency, invoice details, signup code ----
 /** action: a_settings */
 public static function settings(){
  global $in,$act;
admin();
 $keys=['MAP_BRANCHES','SITE_NAME','SITE_PHONE','SITE_PHONE2','SITE_EMAIL','SITE_ADDRESS','CURRENCY','QUOTE_PREFIX','QUOTE_VALID_DAYS','QUOTE_TERMS','TAX_NAME','DEFAULT_TAX','DUE_DAYS','INVOICE_PREFIX','BRAND_COLOR','LOGO','PAY_INFO','TILL_NUMBER','ORDER_EMAIL','SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','MAIL_FROM','BH_TZ','BH_START','BH_END','BH_WARN','BH_TARGET','BH_DAYS','BH_HOLIDAYS'];
 $r=[];foreach($keys as $k)$r[$k]=(string)cfg($k,'');$r['SMTP_PASS_SET']=(string)cfg('SMTP_PASS','')!==''?'1':'';out($r);
 }

 /** action: a_settings_save */
 public static function settingsSave(){
  global $in,$act;
post();admin();
 $oldTill=till_no();
 $rules=['MAP_BRANCHES'=>1500,'SITE_NAME'=>80,'SITE_PHONE'=>40,'SITE_PHONE2'=>40,'SITE_EMAIL'=>120,'SITE_ADDRESS'=>300,'CURRENCY'=>8,'QUOTE_PREFIX'=>12,'QUOTE_VALID_DAYS'=>3,'QUOTE_TERMS'=>1500,'TAX_NAME'=>20,'DEFAULT_TAX'=>8,'DUE_DAYS'=>4,'INVOICE_PREFIX'=>12,'BRAND_COLOR'=>7,'LOGO'=>300,'PAY_INFO'=>600,'TILL_NUMBER'=>12,'ORDER_EMAIL'=>300,'SMTP_HOST'=>120,'SMTP_PORT'=>5,'SMTP_SECURE'=>4,'SMTP_USER'=>120,'SMTP_PASS'=>200,'MAIL_FROM'=>120,'BH_TZ'=>64,'BH_START'=>5,'BH_END'=>5,'BH_WARN'=>3,'BH_TARGET'=>5,'BH_DAYS'=>13,'BH_HOLIDAYS'=>2000];
 if(isset($in['ORDER_EMAIL'])&&trim($in['ORDER_EMAIL'])!==''&&count(mail_list($in['ORDER_EMAIL']))!==count(preg_split('/[,;\s]+/',trim($in['ORDER_EMAIL']),-1,PREG_SPLIT_NO_EMPTY)))out(['error'=>'One of the order email addresses is not valid.'],422);
 if(isset($in['TILL_NUMBER'])&&trim((string)$in['TILL_NUMBER'])!==''&&!preg_match('/^\d{4,10}$/',trim((string)$in['TILL_NUMBER'])))out(['error'=>'The till number must be 4 to 10 digits.'],422);
 if(isset($in['SMTP_SECURE'])&&!in_array($in['SMTP_SECURE'],['tls','ssl','none'],true))out(['error'=>'Security must be TLS, SSL or None.'],422);
 if(isset($in['SMTP_PORT'])&&$in['SMTP_PORT']!==''&&!ctype_digit((string)$in['SMTP_PORT']))out(['error'=>'The SMTP port must be a number.'],422);

 if(isset($in['BH_TZ'])&&!in_array($in['BH_TZ'],timezone_identifiers_list(),true))out(['error'=>'Choose a valid timezone.'],422);
 foreach(['BH_START','BH_END'] as $k)if(isset($in[$k])&&!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$in[$k]))out(['error'=>'Start and end time must look like 08:00.'],422);
 if(isset($in['BH_START'],$in['BH_END'])&&$in['BH_END']<=$in['BH_START'])out(['error'=>'The daily end time must be after the start time.'],422);
 if(isset($in['BH_WARN'])&&(!ctype_digit((string)$in['BH_WARN'])||(int)$in['BH_WARN']<1||(int)$in['BH_WARN']>100))out(['error'=>'Breach warning must be a whole number from 1 to 100.'],422);
 if(isset($in['BH_TARGET'])&&$in['BH_TARGET']!==''&&(!is_numeric($in['BH_TARGET'])||$in['BH_TARGET']<0||$in['BH_TARGET']>720))out(['error'=>'The order target must be 0 to 720 working hours (0 switches it off).'],422);
 if(isset($in['BH_DAYS'])){$d=array_filter(explode(',',(string)$in['BH_DAYS']),'strlen');foreach($d as $x)if(!preg_match('/^[1-7]$/',$x))out(['error'=>'Working days are not valid.'],422);if(!$d)out(['error'=>'Choose at least one working day.'],422);$in['BH_DAYS']=implode(',',array_values(array_unique($d)));}
 if(isset($in['BH_HOLIDAYS'])){$h=[];foreach(preg_split('/[,;\s]+/',trim((string)$in['BH_HOLIDAYS']),-1,PREG_SPLIT_NO_EMPTY) as $x){if(!valid_date($x))out(['error'=>'Holiday “'.$x.'” must look like 2026-12-25.'],422);$h[$x]=1;}ksort($h);$in['BH_HOLIDAYS']=implode(',',array_keys($h));}
 if((!isset($in['BH_TZ']))&&trim((string)($in['SITE_NAME']??''))==='')out(['error'=>'The store name can’t be empty.'],422);
 if(isset($in['MAP_BRANCHES'])&&trim($in['MAP_BRANCHES'])!==''){$n=0;foreach(preg_split('/\R/',trim($in['MAP_BRANCHES'])) as $ln){if(trim($ln)==='')continue;$p=array_map('trim',explode('|',$ln));$n++;if(count($p)<4||$p[0]===''||!is_numeric($p[2])||!is_numeric($p[3])||abs($p[2])>90||abs($p[3])>180)out(['error'=>'Map branch line '.$n.' must look like: Name | Address | -1.2833 | 36.8167'],422);}}
 if(isset($in['BRAND_COLOR'])&&$in['BRAND_COLOR']!==''&&!preg_match('/^#[0-9a-f]{6}$/i',$in['BRAND_COLOR']))out(['error'=>'Colour must look like #12385f.'],422);
 if(isset($in['LOGO'])&&$in['LOGO']!==''&&!preg_match('#^(https?://\S+|[\w./-]+)$#',$in['LOGO']))out(['error'=>'Logo must be a photo path like img/logo.png or a web address.'],422);
 foreach(['DEFAULT_TAX','DUE_DAYS','QUOTE_VALID_DAYS'] as $k)if(isset($in[$k])&&$in[$k]!==''&&!is_numeric($in[$k]))out(['error'=>$k.' must be a number.'],422);
 $pdo=db();$pdo->beginTransaction();$u=$pdo->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v');
 foreach($rules as $k=>$len)if(array_key_exists($k,$in)&&!($k==='SMTP_PASS'&&trim((string)$in[$k])===''))$u->execute([$k,mb_substr(trim((string)$in[$k]),0,$len)]);
 $pdo->commit();// Customers whose order is already confirmed but unpaid are told the new till number as soon as it is set or changed.
 $newTill=trim((string)($in['TILL_NUMBER']??''));
 if($newTill!==''&&$newTill!==$oldTill&&pay_ready()){
  foreach(db()->query("SELECT id,user_id,total FROM orders WHERE status='confirmed' AND paid_at IS NULL AND user_id IS NOT NULL")->fetchAll() as $o)
   nf_add('',(int)$o['user_id'],'mine_pay','Order #'.$o['id'].': please pay '.nf_money($o['total']).' to Till Number '.$newTill,'M-PESA > Lipa na M-PESA > Buy Goods and Services. We dispatch your order once payment is confirmed.','orders');
 }
 out(['ok'=>true]);
 }

 // Send a test email to the order address using the saved settings.
 /** action: a_mail_test */
 public static function mailTest(){
  global $in,$act;
post();admin();
 $to=mail_list(cfg('ORDER_EMAIL',''));
 if(!$to)out(['error'=>'Enter the email address that should receive orders first.'],422);
 try{mail_send($to,'Test email from '.cfg('SITE_NAME','your store'),"This is a test. If you can read it, new orders will reach this address.\r\n");}
 catch(Throwable $x){out(['error'=>'Could not send: '.$x->getMessage()],502);}
 out(['ok'=>true,'to'=>implode(', ',$to)]);
 }
}
