<?php
// Quotations: staff create, send and track them; customers can view, accept or decline them with a private link,
// and ask for one from the store (they arrive as status "request"). Loaded by api.php.
// Tables are created automatically when the database user may do so; otherwise run database/migrate_quotes.sql once as postgres.
const QT_STATUS=['request','draft','sent','accepted','declined','void'];
const QT_DEFAULT_TERMS="Prices are in the currency shown and valid until the date above.\nPayment: cash on delivery or on pickup unless agreed otherwise.\nDelivery time is confirmed when you accept this quotation.";

function qt_ready(){
 static $ok=null;if($ok!==null)return $ok;
 $chk="SELECT to_regclass('quotations') IS NOT NULL AND to_regclass('quotation_items') IS NOT NULL AND to_regclass('quote_seq') IS NOT NULL";
 try{
  $ok=(bool)db()->query($chk)->fetchColumn();
  if(!$ok){$sql=@file_get_contents(dirname(__DIR__).'/database/migrate_quotes.sql');if($sql){db()->exec($sql);$ok=(bool)db()->query($chk)->fetchColumn();}}
 }catch(Throwable $x){error_log('quotation tables: '.$x->getMessage());$ok=false;}
 return $ok;
}
// Best-seller columns on products (same idea: added automatically when allowed, else run migrate_quotes.sql).
function best_ready(){
 static $ok=null;if($ok!==null)return $ok;
 $chk="SELECT count(*)=2 FROM information_schema.columns WHERE table_name='products' AND column_name IN('best_seller','best_pos') AND table_schema=current_schema()";
 try{
  $ok=(bool)db()->query($chk)->fetchColumn();
  if(!$ok){db()->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS best_seller BOOLEAN NOT NULL DEFAULT false;ALTER TABLE products ADD COLUMN IF NOT EXISTS best_pos INT NOT NULL DEFAULT 0");$ok=(bool)db()->query($chk)->fetchColumn();}
 }catch(Throwable $x){$ok=false;}
 return $ok;
}
function qt_today(){return mh_today();}
// A quotation with its computed status: 'expired' when it was sent but the validity date has passed.
const QT_SQL="SELECT q.*,CASE WHEN q.status='sent' AND q.valid_until<CURRENT_DATE THEN 'expired' ELSE q.status END AS qstatus,i.number AS invoice_number FROM quotations q LEFT JOIN invoices i ON i.id=q.invoice_id";
function qt_row($r){
 $f=fn($k)=>round((float)$r[$k],2);
 return['id'=>(int)$r['id'],'number'=>$r['number'],'token'=>$r['token'],'status'=>$r['status'],'qstatus'=>$r['qstatus'],
  'customer_name'=>$r['customer_name'],'customer_company'=>$r['customer_company'],'customer_email'=>$r['customer_email'],'customer_phone'=>$r['customer_phone'],'customer_address'=>$r['customer_address'],
  'subject'=>$r['subject'],'issue_date'=>$r['issue_date'],'valid_until'=>$r['valid_until'],'tax_rate'=>$f('tax_rate'),'discount'=>$f('discount'),'subtotal'=>$f('subtotal'),'tax'=>$f('tax'),'total'=>$f('total'),
  'notes'=>$r['notes'],'terms'=>$r['terms'],'request_text'=>$r['request_text'],'invoice_id'=>$r['invoice_id']===null?null:(int)$r['invoice_id'],'invoice_number'=>$r['invoice_number'],'created_at'=>$r['created_at']];
}
function qt_load(PDO $pdo,string $where,$param){
 $s=$pdo->prepare(QT_SQL.' WHERE '.$where);$s->execute([$param]);$r=$s->fetch();if(!$r)return null;
 $q=qt_row($r);
 $s=$pdo->prepare('SELECT product_id,description,qty::float8 AS qty,unit_price::float8 AS unit_price FROM quotation_items WHERE quotation_id=? ORDER BY position,id');$s->execute([$q['id']]);
 $q['items']=array_map(fn($i)=>['product_id'=>$i['product_id']===null?null:(int)$i['product_id'],'description'=>$i['description'],'qty'=>(float)$i['qty'],'unit_price'=>(float)$i['unit_price']],$s->fetchAll());
 return $q;
}
// Validates the form and works out totals (in cents). Throws InvalidArgumentException with a readable message.
function qt_clean(array $d):array{
 $bad=fn($m)=>new InvalidArgumentException($m);
 $name=trim((string)($d['customer_name']??''));if($name==='')throw $bad('Enter the customer name.');
 $email=trim((string)($d['customer_email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw $bad('That customer email doesn’t look right.');
 $issue=(string)($d['issue_date']??'');$until=(string)($d['valid_until']??'');
 if(!valid_date($issue)||!valid_date($until))throw $bad('Enter a valid quotation date and “valid until” date.');
 if($until<$issue)throw $bad('“Valid until” can’t be before the quotation date.');
 $tax=(float)($d['tax_rate']??0);if($tax<0||$tax>100)throw $bad('Tax must be between 0 and 100%.');
 $disc=cents($d['discount']??0);if($disc<0)throw $bad('Discount can’t be negative.');
 $items=[];
 foreach((array)($d['items']??[]) as $it){
  $it=(array)$it;$desc=trim((string)($it['description']??''));$q=(float)($it['qty']??0);$p=(float)($it['unit_price']??0);
  if($desc===''&&$q==0&&$p==0)continue;
  if($desc===''||$q<=0||$q>1000000||$p<0||$p>100000000)throw $bad('Each line needs a description, a quantity above 0 and a price of 0 or more.');
  $items[]=['product_id'=>((int)($it['product_id']??0))?:null,'description'=>mb_substr($desc,0,200),'qty'=>round($q,2),'unit_price'=>round($p,2)];
 }
 if(!$items)throw $bad('Add at least one line item.');
 if(count($items)>100)throw $bad('A quotation can have up to 100 lines.');
 $sub=0;foreach($items as $it)$sub+=cents($it['qty']*$it['unit_price']);
 $disc=min($disc,$sub);$net=$sub-$disc;$taxc=(int)round($net*$tax/100);
 return['customer_name'=>mb_substr($name,0,120),'customer_company'=>mb_substr(trim((string)($d['customer_company']??'')),0,120),'customer_email'=>mb_substr($email,0,120),
  'customer_phone'=>mb_substr(trim((string)($d['customer_phone']??'')),0,40),'customer_address'=>mb_substr(trim((string)($d['customer_address']??'')),0,300),
  'subject'=>mb_substr(trim((string)($d['subject']??'')),0,160),'issue_date'=>$issue,'valid_until'=>$until,'tax_rate'=>round($tax,2),'discount'=>$disc,'subtotal'=>$sub,'tax'=>$taxc,'total'=>$net+$taxc,
  'notes'=>mb_substr(trim((string)($d['notes']??'')),0,1000),'terms'=>mb_substr(trim((string)($d['terms']??'')),0,1500),'items'=>$items];
}
function qt_items(PDO $pdo,int $id,array $items){
 $s=$pdo->prepare('INSERT INTO quotation_items(quotation_id,product_id,description,qty,unit_price,position) VALUES(?,?,?,?,?,?)');
 foreach($items as $n=>$it)$s->execute([$id,$it['product_id'],$it['description'],$it['qty'],$it['unit_price'],$n]);
}
function qt_number(PDO $pdo){return cfg('QUOTE_PREFIX','QUO-').str_pad((string)$pdo->query("SELECT nextval('quote_seq')")->fetchColumn(),4,'0',STR_PAD_LEFT);}
function qt_create(PDO $pdo,array $c,string $status,?int $by):array{
 $num=qt_number($pdo);$tok=bin2hex(random_bytes(16));
 $s=$pdo->prepare('INSERT INTO quotations(number,token,status,customer_name,customer_company,customer_email,customer_phone,customer_address,subject,issue_date,valid_until,tax_rate,discount,subtotal,tax,total,notes,terms,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
 $s->execute([$num,$tok,$status,$c['customer_name'],$c['customer_company'],$c['customer_email'],$c['customer_phone'],$c['customer_address'],$c['subject'],$c['issue_date'],$c['valid_until'],$c['tax_rate'],
  money_str($c['discount']),money_str($c['subtotal']),money_str($c['tax']),money_str($c['total']),$c['notes'],$c['terms'],$by]);
 $id=(int)$s->fetchColumn();qt_items($pdo,$id,$c['items']);
 return['id'=>$id,'number'=>$num,'token'=>$tok];
}
function qt_terms(){$t=trim((string)cfg('QUOTE_TERMS',''));return $t!==''?$t:QT_DEFAULT_TERMS;}

// ---------- routing: all q_* actions ----------
function quote_route($act){
 if(!preg_match('/^q_[a-z_]+$/',(string)$act))return;
 if(!qt_ready())out(['error'=>'The quotation tables are missing. Run database/migrate_quotes.sql once as the postgres user (see README), then reload.'],503);
 $fn='qt_'.$act;if(!function_exists($fn))out(['error'=>'Unknown action.'],404);$fn();
}
function qt_q_list(){
 staff();$pdo=db();
 $r=array_map('qt_row',$pdo->query(QT_SQL.' ORDER BY q.id DESC LIMIT 500')->fetchAll());
 $t=['requests'=>0,'drafts'=>0,'open_n'=>0,'open_value'=>0.0,'won_month'=>0.0,'won_n'=>0];
 $month=substr(qt_today(),0,7);
 foreach($r as $x){
  if($x['qstatus']==='request')$t['requests']++;
  if($x['qstatus']==='draft')$t['drafts']++;
  if($x['qstatus']==='sent'){$t['open_n']++;$t['open_value']+=$x['total'];}
  if($x['status']==='accepted'&&substr((string)$x['created_at'],0,7)===$month){$t['won_month']+=$x['total'];$t['won_n']++;}
 }
 $t['open_value']=round($t['open_value'],2);$t['won_month']=round($t['won_month'],2);
 out(['totals'=>$t,'items'=>$r,'defaults'=>['valid_days'=>(int)cfg('QUOTE_VALID_DAYS',30),'terms'=>qt_terms()]]);
}
function qt_q_count(){staff();out(['requests'=>(int)db()->query("SELECT COUNT(*) FROM quotations WHERE status='request'")->fetchColumn()]);}
function qt_q_get(){staff();$q=qt_load(db(),'q.id=?',(int)($_GET['id']??0));if(!$q)out(['error'=>'Unknown quotation.'],404);out($q);}
function qt_q_save(){
 global $in;post();staff();
 try{$c=qt_clean($in);}catch(InvalidArgumentException $x){out(['error'=>$x->getMessage()],422);}
 $id=(int)($in['id']??0);$pdo=db();$pdo->beginTransaction();
 if($id){
  $s=$pdo->prepare('SELECT status,invoice_id FROM quotations WHERE id=? FOR UPDATE');$s->execute([$id]);$o=$s->fetch();
  if(!$o){$pdo->rollBack();out(['error'=>'Unknown quotation.'],404);}
  if($o['invoice_id']!==null||in_array($o['status'],['void','accepted'],true)){$pdo->rollBack();out(['error'=>'This quotation is '.($o['invoice_id']!==null?'already an invoice':$o['status']).' and can’t be edited. Duplicate it to make a new version.'],409);}
  $pdo->prepare('UPDATE quotations SET customer_name=?,customer_company=?,customer_email=?,customer_phone=?,customer_address=?,subject=?,issue_date=?,valid_until=?,tax_rate=?,discount=?,subtotal=?,tax=?,total=?,notes=?,terms=?,updated_at=now() WHERE id=?')
   ->execute([$c['customer_name'],$c['customer_company'],$c['customer_email'],$c['customer_phone'],$c['customer_address'],$c['subject'],$c['issue_date'],$c['valid_until'],$c['tax_rate'],money_str($c['discount']),money_str($c['subtotal']),money_str($c['tax']),money_str($c['total']),$c['notes'],$c['terms'],$id]);
  $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id=?')->execute([$id]);qt_items($pdo,$id,$c['items']);
  // a customer's website request becomes a draft once priced; "send" marks any editable quotation as sent
  if(!empty($in['send']))$pdo->prepare("UPDATE quotations SET status='sent' WHERE id=?")->execute([$id]);
  elseif($o['status']==='request')$pdo->prepare("UPDATE quotations SET status='draft' WHERE id=?")->execute([$id]);
  $s=$pdo->prepare('SELECT number,token FROM quotations WHERE id=?');$s->execute([$id]);$n=$s->fetch();$res=['id'=>$id,'number'=>$n['number'],'token'=>$n['token']];
 }else $res=qt_create($pdo,$c,!empty($in['send'])?'sent':'draft',uid());
 $pdo->commit();out(['ok'=>true]+$res);
}
function qt_q_status(){
 global $in;post();staff();$id=(int)($in['id']??0);$st=str('status');
 if(!in_array($st,['draft','sent','accepted','declined','void'],true))out(['error'=>'Unknown status.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status,invoice_id,total FROM quotations WHERE id=? FOR UPDATE');$s->execute([$id]);$o=$s->fetch();
 if(!$o){$pdo->rollBack();out(['error'=>'Unknown quotation.'],404);}
 if($o['status']==='void'){$pdo->rollBack();out(['error'=>'Void quotations can’t be reopened.'],409);}
 if($o['invoice_id']!==null&&$st!=='void'){$pdo->rollBack();out(['error'=>'This quotation was already turned into an invoice.'],409);}
 if($o['status']==='request'&&in_array($st,['sent','accepted'],true)&&(float)$o['total']<=0){$pdo->rollBack();out(['error'=>'Price this request first (Edit), then send it.'],409);}
 $pdo->prepare('UPDATE quotations SET status=?,updated_at=now(),responded_at=CASE WHEN ?::text IN(\'accepted\',\'declined\') THEN now() ELSE NULL END WHERE id=?')->execute([$st,$st,$id]);
 $pdo->commit();out(['ok'=>true]);
}
function qt_q_delete(){
 global $in;post();staff();
 $s=db()->prepare("DELETE FROM quotations WHERE id=? AND status IN('draft','request','void')");$s->execute([(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Only drafts, requests and void quotations can be deleted. Void it instead.'],409);out(['ok'=>true]);
}
function qt_q_duplicate(){
 global $in;post();staff();$pdo=db();
 $q=qt_load($pdo,'q.id=?',(int)($in['id']??0));if(!$q)out(['error'=>'Unknown quotation.'],404);
 $t=qt_today();$days=(int)cfg('QUOTE_VALID_DAYS',30);
 try{$c=qt_clean(array_merge($q,['issue_date'=>$t,'valid_until'=>(new DateTime($t))->modify('+'.$days.' days')->format('Y-m-d')]));}catch(InvalidArgumentException $x){out(['error'=>'This quotation has no priced lines to copy. Edit it instead.'],409);}
 $pdo->beginTransaction();$r=qt_create($pdo,$c,'draft',uid());$pdo->commit();out(['ok'=>true]+$r);
}
// Turns a quotation into a DRAFT invoice (same customer, lines, discount and tax) and marks the quotation accepted.
function qt_q_to_invoice(){
 global $in;post();staff();$pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT * FROM quotations WHERE id=? FOR UPDATE');$s->execute([(int)($in['id']??0)]);$q=$s->fetch();
 if(!$q){$pdo->rollBack();out(['error'=>'Unknown quotation.'],404);}
 if($q['invoice_id']!==null){$pdo->rollBack();out(['id'=>(int)$q['invoice_id'],'existing'=>true]);}
 if(in_array($q['status'],['void','declined','request'],true)){$pdo->rollBack();out(['error'=>'A '.$q['status'].' quotation can’t be turned into an invoice.'],409);}
 $s=$pdo->prepare('SELECT product_id,description,qty,unit_price FROM quotation_items WHERE quotation_id=? ORDER BY position,id');$s->execute([$q['id']]);
 $t=qt_today();
 try{$c=inv_clean(['customer_name'=>$q['customer_name'],'customer_email'=>$q['customer_email'],'customer_phone'=>$q['customer_phone'],'customer_address'=>$q['customer_address'],
  'issue_date'=>$t,'due_date'=>(new DateTime($t))->modify('+'.(int)cfg('DUE_DAYS',14).' days')->format('Y-m-d'),'tax_rate'=>$q['tax_rate'],'discount'=>$q['discount'],'notes'=>'From quotation '.$q['number'],'items'=>$s->fetchAll()]);}
 catch(InvalidArgumentException $x){$pdo->rollBack();out(['error'=>$x->getMessage()],422);}
 $r=inv_create($pdo,$c,'draft',null,null);
 $pdo->prepare("UPDATE quotations SET invoice_id=?,status='accepted',updated_at=now(),responded_at=COALESCE(responded_at,now()) WHERE id=?")->execute([$r['id'],$q['id']]);
 $pdo->commit();
 nf_add('staff',null,'quote_invoice','Invoice created from quotation '.$q['number'],$q['customer_name'],'tab:v');
 out(['id'=>$r['id'],'existing'=>false]);
}
// PUBLIC: a customer asks for a quotation from the store. Limited per address; a hidden field catches simple bots.
function qt_q_request(){
 global $in;post();
 if(trim((string)($in['website']??''))!=='')out(['ok'=>true]);
 $k='quote:'.ip();throttle($k,5);
 $who=str('who');$ph=str('phone');$em=str('email');$need=str('need');
 if($who===''||strlen($ph)<7||$need==='')out(['error'=>'Enter your name, phone number and what you need.'],422);
 if($em!==''&&!filter_var($em,FILTER_VALIDATE_EMAIL))out(['error'=>'That email doesn’t look right.'],422);
 fail_try($k);
 $pdo=db();$t=qt_today();$tok=bin2hex(random_bytes(16));$pdo->beginTransaction();$num=qt_number($pdo);
 $s=$pdo->prepare("INSERT INTO quotations(number,token,status,customer_name,customer_email,customer_phone,issue_date,valid_until,request_text) VALUES(?,?,'request',?,?,?,?,?,?)");
 $s->execute([$num,$tok,mb_substr($who,0,120),mb_substr($em,0,120),mb_substr($ph,0,40),$t,(new DateTime($t))->modify('+'.(int)cfg('QUOTE_VALID_DAYS',30).' days')->format('Y-m-d'),mb_substr($need,0,2000)]);
 $pdo->commit();
 nf_add('staff',null,'quote_new','New quotation request '.$num,$who.' · '.$ph,'tab:q');
 mh_mail(mh_admin_mail(),'New quotation request '.$num.' from '.$who,"Name: $who\r\nPhone: $ph\r\nEmail: $em\r\n\r\n$need\r\n");
 out(['ok'=>true,'number'=>$num]);
}
// PUBLIC: the customer accepts or declines from the private link.
function qt_q_respond(){
 global $in;post();$k='quote-r:'.ip();throttle($k,20);fail_try($k);
 $tok=str('token');$d=str('decision');
 if(!preg_match('/^[a-f0-9]{32}$/',$tok)||!in_array($d,['accepted','declined'],true))out(['error'=>'Invalid request.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare("SELECT id,number,customer_name,status,valid_until<CURRENT_DATE AS expired FROM quotations WHERE token=? FOR UPDATE");$s->execute([$tok]);$q=$s->fetch();
 if(!$q){$pdo->rollBack();out(['error'=>'Quotation not found.'],404);}
 if($q['status']!=='sent'){$pdo->rollBack();out(['error'=>'This quotation can no longer be answered online. Please contact us.'],409);}
 if($q['expired']){$pdo->rollBack();out(['error'=>'This quotation has expired. Please contact us for a new one.'],409);}
 $pdo->prepare('UPDATE quotations SET status=?,responded_at=now(),updated_at=now() WHERE id=?')->execute([$d,$q['id']]);$pdo->commit();
 nf_add('staff',null,'quote_reply','Quotation '.$q['number'].' '.$d,$q['customer_name'].' answered online','tab:q');
 mh_mail(mh_admin_mail(),'Quotation '.$q['number'].' '.$d.' by '.$q['customer_name'],'Quotation '.$q['number'].' was '.$d." online by the customer.\r\n");
 out(['ok'=>true,'status'=>$d]);
}

// ---------- Website content: every text, image and hour on the store front page, edited in Admin > Website ----------
// Stored as one JSON setting (SITE_CONTENT). Only these keys are kept; an empty value means "use the built-in text".
const CONTENT_KEYS=[
 'hero_t1'=>60,'hero_t2'=>60,'hero_sub'=>200,'hero_btn1'=>40,'hero_btn2'=>40,'hero_s1'=>40,'hero_s2'=>40,'hero_img1'=>300,'hero_img2'=>300,'hero_img3'=>300,'hero_img4'=>300,
 'trust1_t'=>50,'trust1_s'=>90,'trust2_t'=>50,'trust2_s'=>90,'trust3_t'=>50,'trust3_s'=>90,'trust4_t'=>50,'trust4_s'=>90,
 'best_title'=>60,'all_title'=>60,
 'quote_h'=>90,'quote_p'=>300,'quote_btn'=>40,'quote_b1'=>40,'quote_b2'=>40,'quote_b3'=>40,'quote_img'=>300,
 'hours'=>400,'footer_tag'=>120,'news_blurb'=>160,'search_ph'=>80,'whatsapp'=>40,
 'faq_about'=>1200,'faq_ship'=>1200,'faq_ret'=>1200,'faq_pay'=>1200,'faq_bulk'=>1200];
function content_get():array{$j=json_decode((string)cfg('SITE_CONTENT',''),true);return is_array($j)?array_intersect_key($j,CONTENT_KEYS):[];}
function content_save(array $in){
 $out=[];
 foreach(CONTENT_KEYS as $k=>$len){
  if(!array_key_exists($k,$in))continue;$v=trim((string)$in[$k]);
  if(substr($k,-4)==='_img'||preg_match('/^hero_img/',$k)){if($v!==''&&!img_ok($v))out(['error'=>'A photo path or web address isn’t valid. Use the Upload button, or a path like img/logo.png.'],422);}
  if($k==='hours'&&$v!==''){foreach(preg_split('/\R/',$v) as $ln){if(trim($ln)!==''&&count(explode('|',$ln))<2)out(['error'=>'Opening hours: write each line like  Mon – Fri: | 8:00 AM - 5:00 PM'],422);}}
  if($k==='whatsapp'&&$v!==''&&strlen(preg_replace('/\D/','',$v))<9)out(['error'=>'The WhatsApp number looks too short. Use the international form, like +254 721 373107.'],422);
  $out[$k]=mb_substr($v,0,$len);
 }
 db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v')->execute(['SITE_CONTENT',json_encode($out,JSON_UNESCAPED_UNICODE)]);
}
