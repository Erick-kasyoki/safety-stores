<?php
// Sales Management. Every sale, payment, return and cancellation is written inside one database transaction together with
// the stock change, and logged in sale_audit. Completed sales are never deleted: they can only be returned or cancelled.
// Roles: admin and worker can sell, view and take payments (a worker sees only their own sales); returns, cancellations and
// selling more than the stock are admin-only. All amounts are computed on the server in cents from database prices.
class SalesController{
 const METHODS=['M-Pesa','Cash','Bank transfer','Card'];

 private static function prefix():string{return preg_replace('/[^A-Za-z0-9-]/','',(string)cfg('SALE_PREFIX','RCT-'))?:'RCT-';}
 private static function store():array{
  return['name'=>cfg('SITE_NAME',SITE_NAME),'phone'=>cfg('SITE_PHONE',SITE_PHONE),'phone2'=>cfg('SITE_PHONE2',defined('SITE_PHONE2')?SITE_PHONE2:''),'email'=>cfg('SITE_EMAIL',SITE_EMAIL),
   'address'=>cfg('SITE_ADDRESS',''),'logo'=>cfg('LOGO','img/logo.png'),'currency'=>cfg('CURRENCY',CURRENCY),'color'=>cfg('BRAND_COLOR','#ED152A'),'tax_name'=>cfg('TAX_NAME','VAT')];
 }
 private static function own():bool{return role()!=='admin';}
 // Money columns come back as strings; shape one sales row for the browser.
 private static function row(array $r):array{
  $f=fn($k)=>round((float)$r[$k],2);
  $total=$f('total');$ret=$f('returned');$paid=$f('paid');$due=round($total-$ret,2);$bal=round($due-$paid,2);
  $ps=$r['status']==='cancelled'?'cancelled':($bal<=0.004?'paid':($paid>0.004?'partial':'unpaid'));
  return['id'=>(int)$r['id'],'receipt_no'=>$r['receipt_no'],'status'=>$r['status'],'customer_name'=>$r['customer_name'],'customer_phone'=>$r['customer_phone'],
   'subtotal'=>$f('subtotal'),'discount'=>$f('discount'),'tax_rate'=>$f('tax_rate'),'tax'=>$f('tax'),'total'=>$total,'returned'=>$ret,'net'=>$due,'paid'=>$paid,
   'balance'=>$r['status']==='cancelled'?0:$bal,'pstatus'=>$ps,'method'=>$r['method'],'note'=>$r['note'],'oversold'=>(bool)$r['oversold'],'cancel_reason'=>$r['cancel_reason'],
   'at'=>$r['at'],'cashier'=>$r['cashier']??'','cashier_id'=>$r['cashier_id']===null?null:(int)$r['cashier_id'],'units'=>isset($r['units'])?(int)$r['units']:null];
 }
 const SEL="SELECT s.id,s.receipt_no,s.status,s.customer_name,s.customer_phone,s.subtotal,s.discount,s.tax_rate,s.tax,s.total,s.returned,s.paid,s.method,s.note,s.oversold,s.cancel_reason,s.cashier_id,
  to_char(s.created_at,'YYYY-MM-DD HH24:MI') AS at,u.name AS cashier,(SELECT COALESCE(SUM(qty),0) FROM sale_items i WHERE i.sale_id=s.id) AS units
  FROM sales s LEFT JOIN users u ON u.id=s.cashier_id";
 private static function audit($pdo,$sid,$action,$detail=''){$pdo->prepare('INSERT INTO sale_audit(sale_id,user_id,action,detail) VALUES(?,?,?,?)')->execute([$sid,uid(),$action,mb_substr($detail,0,500)]);}
 private static function dmoney($c){return number_format($c/100,2,'.','');}

 // Shared filters for history, reports and exports. Workers are always limited to their own sales.
 private static function filters(string $where='WHERE 1=1'):array{
  $w=$where;$p=[];
  if(self::own()){$w.=' AND s.cashier_id=?';$p[]=uid();}
  elseif(($c=(int)($_GET['cashier']??0))>0){$w.=' AND s.cashier_id=?';$p[]=$c;}
  $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
  if($from!==''){if(!valid_date($from))out(['error'=>'Enter a valid start date.'],422);$w.=' AND s.created_at>=?::date';$p[]=$from;}
  if($to!==''){if(!valid_date($to))out(['error'=>'Enter a valid end date.'],422);$w.=" AND s.created_at<(?::date+1)";$p[]=$to;}
  $m=(string)($_GET['method']??'');if($m!==''){$w.=' AND s.method=?';$p[]=$m;}
  $pid=(int)($_GET['product']??0);if($pid>0){$w.=' AND EXISTS(SELECT 1 FROM sale_items i WHERE i.sale_id=s.id AND i.product_id=?)';$p[]=$pid;}
  $q=trim((string)($_GET['q']??''));
  if($q!==''){$l='%'.addcslashes($q,'%_\\').'%';$w.=' AND (s.receipt_no ILIKE ? OR s.customer_name ILIKE ? OR s.customer_phone ILIKE ? OR EXISTS(SELECT 1 FROM sale_items i WHERE i.sale_id=s.id AND (i.name ILIKE ? OR i.sku ILIKE ?)))';array_push($p,$l,$l,$l,$l,$l);}
  $st=(string)($_GET['status']??'');
  if($st==='cancelled'||$st==='completed'||$st==='returned'){$w.=' AND s.status=?';$p[]=$st;}
  elseif($st==='unpaid')$w.=" AND s.status<>'cancelled' AND s.paid<=0 AND s.total-s.returned>0";
  elseif($st==='partial')$w.=" AND s.status<>'cancelled' AND s.paid>0 AND s.total-s.returned-s.paid>0.004";
  elseif($st==='paid')$w.=" AND s.status<>'cancelled' AND s.total-s.returned-s.paid<=0.004";
  return[$w,$p];
 }
 private static function load(PDO $pdo,int $id,bool $lock=false):?array{
  $s=$pdo->prepare(self::SEL.' WHERE s.id=?'.($lock?' FOR UPDATE OF s':''));$s->execute([$id]);$r=$s->fetch();
  if(!$r)return null;
  if(self::own()&&(int)$r['cashier_id']!==uid())return null;
  return self::row($r);
 }
 private static function full(PDO $pdo,array $sale):array{
  $id=$sale['id'];
  $s=$pdo->prepare('SELECT id,product_id,name,sku,unit_price::float8 AS unit_price,qty,discount::float8 AS discount,net::float8 AS net,returned_qty FROM sale_items WHERE sale_id=? ORDER BY id');$s->execute([$id]);$sale['items']=$s->fetchAll();
  $s=$pdo->prepare("SELECT p.id,p.amount::float8 AS amount,p.method,p.reference,to_char(p.created_at,'YYYY-MM-DD HH24:MI') AS at,u.name AS by FROM sale_payments p LEFT JOIN users u ON u.id=p.user_id WHERE p.sale_id=? ORDER BY p.id");$s->execute([$id]);$sale['payments']=$s->fetchAll();
  $s=$pdo->prepare("SELECT r.id,r.value::float8 AS value,r.refund::float8 AS refund,r.refund_method,r.restock,r.cancel,r.reason,to_char(r.created_at,'YYYY-MM-DD HH24:MI') AS at,u.name AS by,
   (SELECT string_agg(i.name||' ×'||ri.qty,', ' ORDER BY ri.id) FROM sale_return_items ri JOIN sale_items i ON i.id=ri.sale_item_id WHERE ri.return_id=r.id) AS items
   FROM sale_returns r LEFT JOIN users u ON u.id=r.user_id WHERE r.sale_id=? ORDER BY r.id");$s->execute([$id]);$sale['returns']=$s->fetchAll();
  $s=$pdo->prepare("SELECT a.action,a.detail,to_char(a.created_at,'YYYY-MM-DD HH24:MI:SS') AS at,u.name AS by FROM sale_audit a LEFT JOIN users u ON u.id=a.user_id WHERE a.sale_id=? ORDER BY a.id");$s->execute([$id]);$sale['audit']=$s->fetchAll();
  $sale['store']=self::store();
  return $sale;
 }

 /** action: s_products (product search for the sales screen: name, SKU or scanned barcode) */
 public static function products(){
  staff();
  $q=trim((string)($_GET['q']??''));$l='%'.addcslashes($q,'%_\\').'%';
  $s=db()->prepare("SELECT id,name,sku,dept,emoji,image,price::float8 AS price,stock,reorder_level FROM products
   WHERE (?::text='' OR name ILIKE ? OR sku ILIKE ? OR dept ILIKE ?) ORDER BY (lower(sku)=lower(?::text) AND ?::text<>'') DESC,(stock>0) DESC,name LIMIT 40");
  $s->execute([$q,$l,$l,$l,$q,$q]);
  $r=$s->fetchAll();foreach($r as &$p){$p['id']=(int)$p['id'];$p['stock']=(int)$p['stock'];$p['reorder_level']=(int)$p['reorder_level'];}unset($p);
  out(['items'=>$r,'tax_rate'=>(float)cfg('DEFAULT_TAX',0),'tax_name'=>cfg('TAX_NAME','VAT'),'methods'=>self::METHODS,'can_oversell'=>role()==='admin']);
 }

 /** action: s_create (complete a sale: validates, locks the products, records the sale, takes the payment and deducts stock in ONE transaction) */
 public static function create(){
  global $in;post();staff();
  $key=str('key');if(!preg_match('/^[A-Za-z0-9-]{16,64}$/',$key))out(['error'=>'Missing sale key. Reload the page and try again.'],422);
  $pdo=db();
  // Same key sent twice (double click, retry after a bad connection): return the sale that already exists, change nothing.
  $dup=$pdo->prepare('SELECT id,cashier_id FROM sales WHERE idem_key=?');$dup->execute([$key]);
  if($d=$dup->fetch()){if((int)$d['cashier_id']!==uid()&&role()!=='admin')out(['error'=>'This sale key is already used.'],409);out(['id'=>(int)$d['id'],'duplicate'=>true]);}
  // ---- validate input ----
  $lines=[];
  foreach((array)($in['items']??[]) as $it){
   $it=(array)$it;$pid=(int)($it['product_id']??0);$q=$it['qty']??0;
   if($pid<1||!is_numeric($q)||(int)$q!=$q||(int)$q<1||(int)$q>100000)out(['error'=>'Each item needs a product and a whole quantity of 1 or more.'],422);
   $dc=cents($it['discount']??0);if($dc<0)out(['error'=>'A discount cannot be negative.'],422);
   if(isset($lines[$pid])){$lines[$pid]['qty']+=(int)$q;$lines[$pid]['disc']+=$dc;}else $lines[$pid]=['qty'=>(int)$q,'disc'=>$dc];
  }
  if(!$lines)out(['error'=>'Add at least one product.'],422);
  if(count($lines)>100)out(['error'=>'A sale can have up to 100 different products.'],422);
  $extra=cents($in['discount']??0);if($extra<0)out(['error'=>'The discount cannot be negative.'],422);
  $rate=(float)($in['tax_rate']??0);if($rate<0||$rate>100)out(['error'=>'Tax must be between 0 and 100%.'],422);
  $method=str('method');$ref=mb_substr(str('reference'),0,80);
  $cust=mb_substr(str('customer_name'),0,120);$phone=mb_substr(str('customer_phone'),0,40);$note=mb_substr(str('note'),0,300);
  $paidC=cents($in['amount_paid']??0);if($paidC<0)out(['error'=>'The amount paid cannot be negative.'],422);
  if($paidC>0&&!in_array($method,self::METHODS,true))out(['error'=>'Choose a payment method.'],422);
  $allow=!empty($in['allow_oversell']);if($allow&&role()!=='admin')out(['error'=>'Only an administrator can sell more than the stock.'],403);
  $pdo->beginTransaction();
  try{
   $ids=array_keys($lines);sort($ids);
   $s=$pdo->prepare('SELECT id,name,sku,price,stock FROM products WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');$s->execute($ids);
   $P=[];foreach($s->fetchAll() as $p)$P[(int)$p['id']]=$p;
   foreach($ids as $i)if(!isset($P[$i])){$pdo->rollBack();out(['error'=>'A product in this sale no longer exists. Refresh the product list.'],404);}
   $short=[];foreach($ids as $i){$st=(int)$P[$i]['stock'];if($lines[$i]['qty']>$st)$short[]=$P[$i]['name'].' (only '.$st.' in stock, you entered '.$lines[$i]['qty'].')';}
   if($short&&!$allow){$pdo->rollBack();out(['error'=>'Not enough stock: '.implode('; ',$short).'.','short'=>true],409);}
   // ---- money, in cents ----
   $sub=0;$lineDisc=0;$rows=[];
   foreach($ids as $i){
    $uc=cents($P[$i]['price']);$g=$uc*$lines[$i]['qty'];$d=$lines[$i]['disc'];
    if($d>$g){$pdo->rollBack();out(['error'=>'The discount on '.$P[$i]['name'].' is more than its price.'],422);}
    $rows[$i]=['uc'=>$uc,'g'=>$g,'d'=>$d];$sub+=$g;$lineDisc+=$d;
   }
   if($extra>$sub-$lineDisc){$pdo->rollBack();out(['error'=>'The discount is more than the sale total.'],422);}
   // spread the overall discount over the lines in proportion, last line takes the rounding difference
   $base=$sub-$lineDisc;$left=$extra;$n=count($ids);$k=0;
   foreach($ids as $i){
    $k++;$ln=$rows[$i]['g']-$rows[$i]['d'];
    $share=$k===$n?$left:($base>0?(int)round($extra*$ln/$base):0);$share=min($share,$ln,$left);$left-=$share;
    $rows[$i]['d']+=$share;$rows[$i]['net']=$rows[$i]['g']-$rows[$i]['d'];
   }
   $disc=$lineDisc+$extra;$taxable=$sub-$disc;$tax=(int)round($taxable*$rate/100);$total=$taxable+$tax;
   if($paidC>$total){$pdo->rollBack();out(['error'=>'The amount paid is more than the sale total.'],422);}
   if($paidC<$total&&$cust===''){$pdo->rollBack();out(['error'=>'Enter the customer’s name for a part-paid or unpaid sale.'],422);}
   $no=self::prefix().str_pad((string)$pdo->query("SELECT nextval('sale_seq')")->fetchColumn(),6,'0',STR_PAD_LEFT);
   $oversold=(bool)$short;
   $s=$pdo->prepare('INSERT INTO sales(receipt_no,idem_key,cashier_id,customer_name,customer_phone,subtotal,discount,tax_rate,tax,total,paid,method,note,oversold) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
   $s->execute([$no,$key,uid(),$cust,$phone,self::dmoney($sub),self::dmoney($disc),number_format($rate,2,'.',''),self::dmoney($tax),self::dmoney($total),self::dmoney($paidC),$paidC>0?$method:'',$note,$oversold?'t':'f']);
   $sid=(int)$s->fetchColumn();
   $ins=$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,name,sku,unit_price,qty,discount,net,stock_taken) VALUES(?,?,?,?,?,?,?,?,?)');
   foreach($ids as $i){
    $p=$P[$i];$q=$lines[$i]['qty'];$take=min($q,(int)$p['stock']);$new=(int)$p['stock']-$take;
    $ins->execute([$sid,$i,$p['name'],$p['sku'],self::dmoney($rows[$i]['uc']),$q,self::dmoney($rows[$i]['d']),self::dmoney($rows[$i]['net']),$take]);
    if($take>0){
     $pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$new,$i]);
     mv($pdo,$i,$p['name'],'walkin',-$take,$new,(float)$p['price'],null,'Sale '.$no.($take<$q?' (oversold by '.($q-$take).')':''),uid());
    }
   }
   if($paidC>0)$pdo->prepare('INSERT INTO sale_payments(sale_id,amount,method,reference,user_id) VALUES(?,?,?,?,?)')->execute([$sid,self::dmoney($paidC),$method,$ref,uid()]);
   self::audit($pdo,$sid,'created',$no.': '.count($ids).' product(s), total '.self::dmoney($total).', paid '.self::dmoney($paidC).($paidC>0?' by '.$method:''));
   if($oversold)self::audit($pdo,$sid,'stock override','Sold more than the stock: '.implode('; ',$short));
   $pdo->commit();
  }catch(PDOException $x){
   if($pdo->inTransaction())$pdo->rollBack();
   // two identical submissions at the same moment: the second hits the unique key and gets the first sale back
   if($x->getCode()==='23505'){$dup->execute([$key]);if($d=$dup->fetch())out(['id'=>(int)$d['id'],'duplicate'=>true]);}
   throw $x;
  }
  out(['id'=>$sid,'receipt_no'=>$no]);
 }

 /** action: s_list */
 public static function listing(){
  staff();
  [$w,$p]=self::filters();
  $s=db()->prepare(self::SEL.' '.$w.' ORDER BY s.id DESC LIMIT 300');$s->execute($p);
  $r=array_map([self::class,'row'],$s->fetchAll());
  out(['sales'=>$r,'limited'=>count($r)>=300]);
 }

 /** action: s_get (one sale with items, payments, returns, audit trail and store details for the receipt) */
 public static function get(){
  staff();$pdo=db();
  $sale=self::load($pdo,(int)($_GET['id']??0));if(!$sale)out(['error'=>'Sale not found.'],404);
  out(self::full($pdo,$sale));
 }

 /** action: s_pay (take a payment on a part-paid or unpaid sale) */
 public static function pay(){
  global $in;post();staff();$pdo=db();
  $amt=cents($in['amount']??0);$m=str('method');$ref=mb_substr(str('reference'),0,80);
  if($amt<=0)out(['error'=>'Enter the amount received.'],422);
  if(!in_array($m,self::METHODS,true))out(['error'=>'Choose a payment method.'],422);
  $pdo->beginTransaction();
  $sale=self::load($pdo,(int)($in['id']??0),true);
  if(!$sale){$pdo->rollBack();out(['error'=>'Sale not found.'],404);}
  if($sale['status']==='cancelled'){$pdo->rollBack();out(['error'=>'This sale was cancelled.'],409);}
  $bal=cents($sale['balance']);
  if($bal<=0){$pdo->rollBack();out(['error'=>'This sale is already fully paid.'],409);}
  if($amt>$bal){$pdo->rollBack();out(['error'=>'The balance is only '.self::dmoney($bal).'.'],422);}
  $pdo->prepare('UPDATE sales SET paid=paid+?,method=CASE WHEN method=\'\' THEN ? ELSE method END WHERE id=?')->execute([self::dmoney($amt),$m,$sale['id']]);
  $pdo->prepare('INSERT INTO sale_payments(sale_id,amount,method,reference,user_id) VALUES(?,?,?,?,?)')->execute([$sale['id'],self::dmoney($amt),$m,$ref,uid()]);
  self::audit($pdo,$sale['id'],'payment',self::dmoney($amt).' by '.$m.($ref!==''?' ('.$ref.')':''));
  $pdo->commit();out(['ok'=>true]);
 }

 // Shared by s_return and s_cancel. $take: [sale_item_id=>qty]. Runs inside the caller's transaction.
 private static function giveBack(PDO $pdo,array $sale,array $take,bool $restock,float $refund,string $method,string $reason,bool $cancel):array{
  $rate=(float)$sale['tax_rate'];
  $s=$pdo->prepare('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id FOR UPDATE');$s->execute([$sale['id']]);
  $items=[];foreach($s->fetchAll() as $r)$items[(int)$r['id']]=$r;
  $valEx=0;$plan=[];
  foreach($take as $iid=>$q){
   if(!isset($items[$iid])){$pdo->rollBack();out(['error'=>'That item is not part of this sale.'],422);}
   $it=$items[$iid];$remain=(int)$it['qty']-(int)$it['returned_qty'];
   if($q<1||$q>$remain){$pdo->rollBack();out(['error'=>$it['name'].': only '.$remain.' left that can be returned.'],409);}
   $netC=cents($it['net']);$rn=cents($it['returned_net']);
   $v=$q===$remain?$netC-$rn:(int)round($netC*$q/(int)$it['qty']);
   $valEx+=$v;$plan[$iid]=['q'=>$q,'v'=>$v];
  }
  if(!$plan){$pdo->rollBack();out(['error'=>'Choose the items and quantities to return.'],422);}
  $value=(int)round($valEx*(1+$rate/100));
  $left=0;foreach($items as $iid=>$it)$left+=(int)$it['qty']-(int)$it['returned_qty']-($plan[$iid]['q']??0);
  if($left===0)$value=cents($sale['total'])-cents($sale['returned']); // finishing the sale: no rounding drift
  $newRet=cents($sale['returned'])+$value;
  $maxRefund=max(0,cents($sale['paid'])-(cents($sale['total'])-$newRet));
  $refC=cents($refund);if($cancel)$refC=min($refC,$maxRefund);
  if($refC<0||$refC>$maxRefund){$pdo->rollBack();out(['error'=>'The refund can be at most '.self::dmoney($maxRefund).' (what the customer has paid beyond what they now owe).'],422);}
  if($refC>0&&!in_array($method,self::METHODS,true)){$pdo->rollBack();out(['error'=>'Choose how the refund is paid.'],422);}
  $s=$pdo->prepare('INSERT INTO sale_returns(sale_id,value,refund,refund_method,restock,cancel,reason,user_id) VALUES(?,?,?,?,?,?,?,?) RETURNING id');
  $s->execute([$sale['id'],self::dmoney($value),self::dmoney($refC),$refC>0?$method:'',$restock?'t':'f',$cancel?'t':'f',$reason,uid()]);
  $rid=(int)$s->fetchColumn();
  $allBack=true;$restockedTotal=0;
  foreach($plan as $iid=>$pl){
   $it=$items[$iid];$back=0;
   if($restock&&$it['product_id']!==null){
    $back=min($pl['q'],(int)$it['stock_taken']-(int)$it['restocked']);
    if($back>0){
     $ps=$pdo->prepare('SELECT stock FROM products WHERE id=? FOR UPDATE');$ps->execute([$it['product_id']]);$cur=$ps->fetchColumn();
     if($cur!==false){$new=(int)$cur+$back;$pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$new,$it['product_id']]);
      mv($pdo,(int)$it['product_id'],$it['name'],'return',$back,$new,(float)$it['unit_price'],null,($cancel?'Cancelled ':'Return of ').$sale['receipt_no'],uid());$restockedTotal+=$back;}
     else $back=0;
    }
   }
   $pdo->prepare('UPDATE sale_items SET returned_qty=returned_qty+?,returned_net=returned_net+?,restocked=restocked+? WHERE id=?')->execute([$pl['q'],self::dmoney($pl['v']),$back,$iid]);
   $pdo->prepare('INSERT INTO sale_return_items(return_id,sale_item_id,qty,restocked) VALUES(?,?,?,?)')->execute([$rid,$iid,$pl['q'],$back]);
  }
  foreach($items as $iid=>$it){$rq=(int)$it['returned_qty']+($plan[$iid]['q']??0);if($rq<(int)$it['qty'])$allBack=false;}
  $status=$cancel?'cancelled':($allBack?'returned':'completed');
  $pdo->prepare('UPDATE sales SET returned=returned+?,paid=paid-?,status=?,cancel_reason=CASE WHEN ? THEN ? ELSE cancel_reason END WHERE id=?')
   ->execute([self::dmoney($value),self::dmoney($refC),$status,$cancel?'t':'f',$reason,$sale['id']]);
  self::audit($pdo,$sale['id'],$cancel?'cancelled':'return',($cancel?'Sale cancelled. ':'').'Value '.self::dmoney($value).', refunded '.self::dmoney($refC).($refC>0?' by '.$method:'').', '.($restock?$restockedTotal.' unit(s) back in stock':'not restocked').'. Reason: '.$reason);
  return['value'=>$value/100,'refund'=>$refC/100,'restocked'=>$restockedTotal];
 }

 /** action: s_return (admin: return some items of a sale, refund, and put them back in stock only if asked) */
 public static function ret(){
  global $in;post();admin();$pdo=db();
  $reason=mb_substr(str('reason'),0,300);if($reason==='')out(['error'=>'Give a reason for the return.'],422);
  $take=[];foreach((array)($in['items']??[]) as $it){$it=(array)$it;$q=(int)($it['qty']??0);if($q>0)$take[(int)($it['id']??0)]=($take[(int)($it['id']??0)]??0)+$q;}
  $pdo->beginTransaction();
  $sale=self::load($pdo,(int)($in['id']??0),true);
  if(!$sale){$pdo->rollBack();out(['error'=>'Sale not found.'],404);}
  if($sale['status']==='cancelled'){$pdo->rollBack();out(['error'=>'This sale was cancelled.'],409);}
  $r=self::giveBack($pdo,$sale,$take,!empty($in['restock']),(float)($in['refund']??0),str('refund_method'),$reason,false);
  $pdo->commit();out($r+['ok'=>true]);
 }

 /** action: s_cancel (admin: cancel a whole sale; everything goes back to stock and the money paid is refunded) */
 public static function cancel(){
  global $in;post();admin();$pdo=db();
  $reason=mb_substr(str('reason'),0,300);if($reason==='')out(['error'=>'Give a reason for cancelling this sale.'],422);
  $pdo->beginTransaction();
  $sale=self::load($pdo,(int)($in['id']??0),true);
  if(!$sale){$pdo->rollBack();out(['error'=>'Sale not found.'],404);}
  if($sale['status']==='cancelled'){$pdo->rollBack();out(['error'=>'This sale is already cancelled.'],409);}
  $s=$pdo->prepare('SELECT id,qty-returned_qty AS rem FROM sale_items WHERE sale_id=? AND qty>returned_qty');$s->execute([$sale['id']]);
  $take=[];foreach($s->fetchAll() as $r)$take[(int)$r['id']]=(int)$r['rem'];
  if(!$take){ // everything was already returned: just close the sale
   $pdo->prepare("UPDATE sales SET status='cancelled',cancel_reason=? WHERE id=?")->execute([$reason,$sale['id']]);
   self::audit($pdo,$sale['id'],'cancelled','All items had already been returned. Reason: '.$reason);$pdo->commit();out(['ok'=>true]);
  }
  // refund everything the customer has paid beyond what they owe after the return
  $r=self::giveBack($pdo,$sale,$take,true,max(0,$sale['paid']),$sale['method']!==''?$sale['method']:'Cash',$reason,true);
  $pdo->commit();out($r+['ok'=>true]);
 }

 // ---- reports ----
 private static function reportData(PDO $pdo,string $from,string $to):array{
  $_GET['from']=$from;$_GET['to']=$to;unset($_GET['status'],$_GET['q'],$_GET['product'],$_GET['method']);
  [$w,$p]=self::filters();
  $s=$pdo->prepare("SELECT COUNT(*) FILTER (WHERE s.status<>'cancelled')::int AS n,COALESCE(SUM(s.subtotal),0)::float8 AS gross,COALESCE(SUM(s.discount),0)::float8 AS discounts,COALESCE(SUM(s.tax),0)::float8 AS tax,
   COALESCE(SUM(s.total),0)::float8 AS total,COALESCE(SUM(s.returned),0)::float8 AS returned,COALESCE(SUM(s.paid),0)::float8 AS collected,
   COALESCE(SUM(CASE WHEN s.status<>'cancelled' THEN GREATEST(s.total-s.returned-s.paid,0) ELSE 0 END),0)::float8 AS outstanding,
   COALESCE(SUM((SELECT SUM(qty-returned_qty) FROM sale_items i WHERE i.sale_id=s.id)),0)::int AS units
   FROM sales s $w");$s->execute($p);$t=$s->fetch();
  $rf=$pdo->prepare("SELECT COALESCE(SUM(r.refund),0)::float8 FROM sale_returns r JOIN sales s ON s.id=r.sale_id $w");$rf->execute($p);$t['refunds']=(float)$rf->fetchColumn();
  $t['net']=round($t['total']-$t['returned'],2);
  $t['avg']=$t['n']>0?round($t['net']/$t['n'],2):0;
  $s=$pdo->prepare("SELECT i.product_id,i.name,SUM(i.qty-i.returned_qty)::int AS units,COALESCE(SUM(i.net-i.returned_net),0)::float8 AS revenue FROM sale_items i JOIN sales s ON s.id=i.sale_id $w AND s.status<>'cancelled'
   GROUP BY i.product_id,i.name HAVING SUM(i.qty-i.returned_qty)>0 ORDER BY units DESC,revenue DESC LIMIT 10");$s->execute($p);$top=$s->fetchAll();
  $s=$pdo->prepare("SELECT to_char(s.created_at,'YYYY-MM-DD') AS day,COUNT(*) FILTER (WHERE s.status<>'cancelled')::int AS n,COALESCE(SUM(s.total-s.returned),0)::float8 AS net FROM sales s $w GROUP BY 1 ORDER BY 1");$s->execute($p);$days=$s->fetchAll();
  $s=$pdo->prepare("SELECT s.method,COUNT(*)::int AS n,COALESCE(SUM(s.paid),0)::float8 AS paid FROM sales s $w AND s.method<>'' GROUP BY s.method ORDER BY paid DESC");$s->execute($p);$meth=$s->fetchAll();
  $by=[];
  if(!self::own()){$s=$pdo->prepare("SELECT COALESCE(u.name,'(removed)') AS name,COUNT(*)::int AS n,COALESCE(SUM(s.total-s.returned),0)::float8 AS net FROM sales s LEFT JOIN users u ON u.id=s.cashier_id $w GROUP BY 1 ORDER BY net DESC");$s->execute($p);$by=$s->fetchAll();}
  foreach(['gross','discounts','tax','total','returned','collected','outstanding'] as $k)$t[$k]=round($t[$k],2);
  return['totals'=>$t,'top'=>$top,'days'=>$days,'methods'=>$meth,'cashiers'=>$by];
 }

 /** action: s_report (from and to are dates; admin sees the whole shop, a worker sees only their own sales) */
 public static function report(){
  staff();$today=date('Y-m-d');
  $from=(string)($_GET['from']??$today);$to=(string)($_GET['to']??$today);
  if(!valid_date($from)||!valid_date($to)||$to<$from)out(['error'=>'Choose a valid period.'],422);
  if((strtotime($to)-strtotime($from))>366*86400)out(['error'=>'A report can cover up to one year.'],422);
  out(self::reportData(db(),$from,$to)+['from'=>$from,'to'=>$to,'scope'=>self::own()?'mine':'all']);
 }

 /** action: s_summary (dashboard cards: today, this month, top sellers, unpaid balances) */
 public static function summary(){
  staff();$pdo=db();$today=date('Y-m-d');
  $day=self::reportData($pdo,$today,$today);
  $month=self::reportData($pdo,date('Y-m-01'),$today);
  out(['scope'=>self::own()?'mine':'all','today'=>['net'=>$day['totals']['net'],'n'=>$day['totals']['n']],
   'month'=>['net'=>$month['totals']['net'],'n'=>$month['totals']['n'],'units'=>$month['totals']['units']],
   'outstanding'=>$month['totals']['outstanding'],'top'=>array_slice($month['top'],0,3)]);
 }

 /** action: s_export (CSV that opens in Excel; add detail=items for one row per product sold) */
 public static function export(){
  staff();
  [$w,$p]=self::filters();$items=(string)($_GET['detail']??'')==='items';
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="sales-'.date('Y-m-d').($items?'-items':'').'.csv"');header('X-Content-Type-Options: nosniff');
  $o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");
  if($items){
   fputcsv($o,['Receipt','Date','Cashier','Customer','Product','SKU','Qty','Returned qty','Unit price','Discount','Net (before tax)','Status']);
   $s=db()->prepare("SELECT s.receipt_no,to_char(s.created_at,'YYYY-MM-DD HH24:MI') AS at,u.name AS cashier,s.customer_name,i.name,i.sku,i.qty,i.returned_qty,i.unit_price,i.discount,i.net,s.status
    FROM sale_items i JOIN sales s ON s.id=i.sale_id LEFT JOIN users u ON u.id=s.cashier_id $w ORDER BY s.id DESC,i.id LIMIT 50000");$s->execute($p);
   while($r=$s->fetch(PDO::FETCH_NUM)){foreach([0,2,3,4,5] as $k)$r[$k]=cx($r[$k]);fputcsv($o,$r,',','"','');}
  }else{
   fputcsv($o,['Receipt','Date','Cashier','Customer','Phone','Status','Payment status','Method','Gross','Discount','Tax','Total','Returned','Net sales','Paid','Balance']);
   $s=db()->prepare(self::SEL.' '.$w.' ORDER BY s.id DESC LIMIT 50000');$s->execute($p);
   while($r=$s->fetch()){$x=self::row($r);fputcsv($o,[cx($x['receipt_no']),$x['at'],cx($x['cashier']),cx($x['customer_name']),cx($x['customer_phone']),$x['status'],$x['pstatus'],$x['method'],$x['subtotal'],$x['discount'],$x['tax'],$x['total'],$x['returned'],$x['net'],$x['paid'],$x['balance']],',','"','');}
  }
  fclose($o);exit;
 }
}
