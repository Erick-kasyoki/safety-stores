<?php
// Shared invoice helpers, used by api.php, invoice.php and cron_invoices.php.
// Settings the admin saves in admin.html (table `settings`) win over config.php. Falls back quietly if the table does not exist yet.
function db_settings(){
 static $c=null;if($c!==null)return $c;$c=[];
 try{foreach(db()->query('SELECT k,v FROM settings')->fetchAll() as $r)$c[$r['k']]=$r['v'];}catch(Throwable $x){}
 return $c;
}
function cfg($k,$d=''){$s=db_settings();if(array_key_exists($k,$s))return $s[$k];return defined($k)?constant($k):$d;}
// The store timezone: Admin > Settings (BH_TZ), else the old TIMEZONE setting, else Nairobi.
function app_tz():string{$t=(string)cfg('BH_TZ','');if($t==='')$t=(string)cfg('TIMEZONE','');return in_array($t,DateTimeZone::listIdentifiers(),true)?$t:'Africa/Nairobi';}
function cents($v){return (int)round(((float)$v)*100);}
function money_str($c){return number_format($c/100,2,'.','');}
function valid_date($s){$d=DateTime::createFromFormat('!Y-m-d',(string)$s);return $d&&$d->format('Y-m-d')===$s;}
const FREQ=['weekly'=>'1 week','monthly'=>'1 month','quarterly'=>'3 months','yearly'=>'1 year'];
const PAY_METHODS=['Cash','Bank transfer','Mobile money','Card','Cheque','Other'];

// An invoice with what has been paid and its status: draft, unpaid, partial, overdue, paid or void.
const INV_SQL="SELECT x.*,CASE WHEN x.status<>'sent' THEN x.status WHEN x.paid>=x.total THEN 'paid' WHEN x.due_date<CURRENT_DATE THEN 'overdue' WHEN x.paid>0 THEN 'partial' ELSE 'unpaid' END AS pstatus
 FROM (SELECT i.*,COALESCE((SELECT SUM(amount) FROM invoice_payments p WHERE p.invoice_id=i.id),0) AS paid FROM invoices i) x";

function inv_row($r){
 $f=fn($k)=>round((float)$r[$k],2);
 return['id'=>(int)$r['id'],'number'=>$r['number'],'token'=>$r['token'],'status'=>$r['status'],'pstatus'=>$r['pstatus'],
  'customer_name'=>$r['customer_name'],'customer_email'=>$r['customer_email'],'customer_phone'=>$r['customer_phone'],'customer_address'=>$r['customer_address'],
  'order_id'=>$r['order_id']===null?null:(int)$r['order_id'],'recurring_id'=>$r['recurring_id']===null?null:(int)$r['recurring_id'],
  'issue_date'=>$r['issue_date'],'due_date'=>$r['due_date'],'tax_rate'=>$f('tax_rate'),'discount'=>$f('discount'),'subtotal'=>$f('subtotal'),'tax'=>$f('tax'),
  'total'=>$f('total'),'paid'=>$f('paid'),'balance'=>round((float)$r['total']-(float)$r['paid'],2),'notes'=>$r['notes']];
}
// Full invoice (with lines and payments) by a WHERE fragment on x, e.g. 'x.token=?'.
function inv_load(PDO $pdo,string $where,$param){
 $s=$pdo->prepare(INV_SQL.' WHERE '.$where);$s->execute([$param]);$r=$s->fetch();
 if(!$r)return null;$inv=inv_row($r);
 $s=$pdo->prepare('SELECT product_id,description,qty::float8 AS qty,unit_price::float8 AS unit_price FROM invoice_items WHERE invoice_id=? ORDER BY position,id');$s->execute([$inv['id']]);
 $inv['items']=array_map(fn($i)=>['product_id'=>$i['product_id']===null?null:(int)$i['product_id'],'description'=>$i['description'],'qty'=>(float)$i['qty'],'unit_price'=>(float)$i['unit_price']],$s->fetchAll());
 $s=$pdo->prepare('SELECT id,amount::float8 AS amount,method,paid_on,note FROM invoice_payments WHERE invoice_id=? ORDER BY paid_on,id');$s->execute([$inv['id']]);
 $inv['payments']=array_map(fn($p)=>['id'=>(int)$p['id'],'amount'=>(float)$p['amount'],'method'=>$p['method'],'paid_on'=>$p['paid_on'],'note'=>$p['note']],$s->fetchAll());
 return $inv;
}

// Validates form data and works out the totals (in cents). Throws InvalidArgumentException with a message the admin can read.
function inv_clean(array $d):array{
 $bad=fn($m)=>new InvalidArgumentException($m);
 $name=trim((string)($d['customer_name']??''));if($name==='')throw $bad('Enter the customer name.');
 $email=trim((string)($d['customer_email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw $bad('That customer email doesn’t look right.');
 $issue=(string)($d['issue_date']??'');$due=(string)($d['due_date']??'');
 if(!valid_date($issue)||!valid_date($due))throw $bad('Enter a valid issue date and due date.');
 if($due<$issue)throw $bad('The due date can’t be before the issue date.');
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
 if(count($items)>100)throw $bad('An invoice can have up to 100 lines.');
 $sub=0;foreach($items as $it)$sub+=cents($it['qty']*$it['unit_price']);
 $disc=min($disc,$sub);$net=$sub-$disc;$taxc=(int)round($net*$tax/100);
 return['customer_name'=>mb_substr($name,0,120),'customer_email'=>mb_substr($email,0,120),'customer_phone'=>mb_substr(trim((string)($d['customer_phone']??'')),0,40),
  'customer_address'=>mb_substr(trim((string)($d['customer_address']??'')),0,300),'issue_date'=>$issue,'due_date'=>$due,'tax_rate'=>round($tax,2),
  'discount'=>$disc,'subtotal'=>$sub,'tax'=>$taxc,'total'=>$net+$taxc,'notes'=>mb_substr(trim((string)($d['notes']??'')),0,1000),'items'=>$items];
}
function inv_items(PDO $pdo,int $id,array $items){
 $s=$pdo->prepare('INSERT INTO invoice_items(invoice_id,product_id,description,qty,unit_price,position) VALUES(?,?,?,?,?,?)');
 foreach($items as $n=>$it)$s->execute([$id,$it['product_id'],$it['description'],$it['qty'],$it['unit_price'],$n]);
}
// Inserts an invoice from inv_clean() output. Call inside a transaction.
function inv_create(PDO $pdo,array $c,string $status,?int $orderId=null,?int $recId=null):array{
 $num=cfg('INVOICE_PREFIX','INV-').str_pad((string)$pdo->query("SELECT nextval('invoice_seq')")->fetchColumn(),4,'0',STR_PAD_LEFT);
 $tok=bin2hex(random_bytes(16));
 $s=$pdo->prepare('INSERT INTO invoices(number,token,status,customer_name,customer_email,customer_phone,customer_address,order_id,recurring_id,issue_date,due_date,tax_rate,discount,subtotal,tax,total,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
 $s->execute([$num,$tok,$status,$c['customer_name'],$c['customer_email'],$c['customer_phone'],$c['customer_address'],$orderId,$recId,$c['issue_date'],$c['due_date'],$c['tax_rate'],
  money_str($c['discount']),money_str($c['subtotal']),money_str($c['tax']),money_str($c['total']),$c['notes']]);
 $id=(int)$s->fetchColumn();inv_items($pdo,$id,$c['items']);
 return['id'=>$id,'number'=>$num,'token'=>$tok];
}
// Creates every recurring invoice that is due (catching up on missed periods). Returns how many were made.
function run_recurring(PDO $pdo,int $max=50):int{
 $made=0;
 while($made<$max){
  $pdo->beginTransaction();
  $r=$pdo->query('SELECT * FROM recurring_invoices WHERE active AND next_date<=CURRENT_DATE ORDER BY next_date,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
  if(!$r){$pdo->commit();break;}
  try{
   $due=(new DateTime($r['next_date']))->modify('+'.(int)$r['due_days'].' days')->format('Y-m-d');
   $c=inv_clean(['customer_name'=>$r['customer_name'],'customer_email'=>$r['customer_email'],'customer_phone'=>$r['customer_phone'],'customer_address'=>$r['customer_address'],
    'issue_date'=>$r['next_date'],'due_date'=>$due,'tax_rate'=>$r['tax_rate'],'discount'=>$r['discount'],'notes'=>$r['notes'],'items'=>json_decode($r['items'],true)?:[]]);
   inv_create($pdo,$c,'sent',null,(int)$r['id']);$made++;
   // next date counts from the start date, so a monthly invoice started on the 31st doesn't drift.
   $pdo->prepare('UPDATE recurring_invoices SET runs=runs+1,next_date=(start_date+(?::interval*(runs+1)))::date WHERE id=?')->execute([FREQ[$r['frequency']],$r['id']]);
  }catch(InvalidArgumentException $x){
   $pdo->prepare('UPDATE recurring_invoices SET active=false WHERE id=?')->execute([$r['id']]); // a broken template pauses itself
  }
  $pdo->commit();
 }
 return $made;
}

// Shop branches for the store map. One per line in the setting MAP_BRANCHES: Name | Address | latitude | longitude
function map_branches():array{
 $out=[];
 foreach(preg_split('/\R/',(string)cfg('MAP_BRANCHES',''))as $ln){
  $p=array_map('trim',explode('|',$ln));
  if(count($p)<4||$p[0]===''||!is_numeric($p[2])||!is_numeric($p[3]))continue;
  $out[]=['name'=>$p[0],'address'=>$p[1],'lat'=>(float)$p[2],'lng'=>(float)$p[3]];
 }
 return $out;
}
