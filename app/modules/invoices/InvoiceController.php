<?php
// Invoices, invoice payments and recurring invoices.
// One public static method per action. Routes for this module are listed in routes.php.
class InvoiceController{

 // ---- invoices (admin) ----
 /** action: a_invoices */
 public static function invoices(){
  global $in,$act;
staff();
 $pdo=db();if(role()==='admin')run_recurring($pdo);
 $r=array_map('inv_row',$pdo->query(INV_SQL.' ORDER BY x.id DESC LIMIT 500')->fetchAll());
 $t=['outstanding'=>0.0,'overdue'=>0.0,'overdue_n'=>0,'drafts'=>0];
 foreach($r as $x){
  if($x['pstatus']==='draft')$t['drafts']++;
  if(in_array($x['pstatus'],['unpaid','partial','overdue'],true))$t['outstanding']+=$x['balance'];
  if($x['pstatus']==='overdue'){$t['overdue']+=$x['balance'];$t['overdue_n']++;}
 }
 $t['outstanding']=round($t['outstanding'],2);$t['overdue']=round($t['overdue'],2);
 $t['collected_month']=round((float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.status<>'void' AND date_trunc('month',p.paid_on)=date_trunc('month',CURRENT_DATE)")->fetchColumn(),2);
 $rec=[];
 foreach(role()==='admin'?$pdo->query('SELECT id,customer_name,items,tax_rate,discount,frequency,next_date,active FROM recurring_invoices ORDER BY id DESC')->fetchAll():[] as $q){
  $sub=0;foreach(json_decode($q['items'],true)?:[] as $it)$sub+=cents($it['qty']*$it['unit_price']);
  $net=max(0,$sub-cents($q['discount']));$rec[]=['id'=>(int)$q['id'],'customer_name'=>$q['customer_name'],'frequency'=>$q['frequency'],'next_date'=>$q['next_date'],'active'=>(bool)$q['active'],'amount'=>($net+(int)round($net*(float)$q['tax_rate']/100))/100];
 }
 out(['totals'=>$t,'items'=>$r,'recurring'=>$rec,'methods'=>PAY_METHODS]);
 }

 /** action: a_invoice */
 public static function invoice(){
  global $in,$act;
staff();
 $v=inv_load(db(),'x.id=?',(int)($_GET['id']??0));
 if(!$v)out(['error'=>'Unknown invoice.'],404);out($v);
 }

 /** action: a_inv_save */
 public static function invSave(){
  global $in,$act;
post();staff();
 try{$c=inv_clean($in);}catch(InvalidArgumentException $x){out(['error'=>$x->getMessage()],422);}
 $id=(int)($in['id']??0);$pdo=db();$pdo->beginTransaction();
 if($id){
  $s=$pdo->prepare('SELECT status,(SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=invoices.id) AS paid FROM invoices WHERE id=? FOR UPDATE');$s->execute([$id]);$o=$s->fetch();
  if(!$o){$pdo->rollBack();out(['error'=>'Unknown invoice.'],404);}
  if($o['status']==='void'){$pdo->rollBack();out(['error'=>'Void invoices can’t be edited.'],409);}
  if(cents($o['paid'])>$c['total']){$pdo->rollBack();out(['error'=>'This invoice already has '.number_format((float)$o['paid'],2).' paid, which is more than the new total. Remove a payment first.'],409);}
  $pdo->prepare('UPDATE invoices SET customer_name=?,customer_email=?,customer_phone=?,customer_address=?,issue_date=?,due_date=?,tax_rate=?,discount=?,subtotal=?,tax=?,total=?,notes=? WHERE id=?')
   ->execute([$c['customer_name'],$c['customer_email'],$c['customer_phone'],$c['customer_address'],$c['issue_date'],$c['due_date'],$c['tax_rate'],money_str($c['discount']),money_str($c['subtotal']),money_str($c['tax']),money_str($c['total']),$c['notes'],$id]);
  $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$id]);inv_items($pdo,$id,$c['items']);
  if(!empty($in['send'])&&$o['status']==='draft')$pdo->prepare("UPDATE invoices SET status='sent' WHERE id=?")->execute([$id]);
  $s=$pdo->prepare('SELECT number,token FROM invoices WHERE id=?');$s->execute([$id]);$n=$s->fetch();
  $res=['id'=>$id,'number'=>$n['number'],'token'=>$n['token']];
 }else{
  $recId=null;$f=(string)($in['repeat']??'');if($f!==''&&role()!=='admin')out(['error'=>'Only an admin can set up repeating invoices.'],403);
  if($f!==''){
   if(!isset(FREQ[$f])){$pdo->rollBack();out(['error'=>'Choose how often to repeat.'],422);}
   $days=(int)round((strtotime($c['due_date'])-strtotime($c['issue_date']))/86400);
   $q=$pdo->prepare('INSERT INTO recurring_invoices(customer_name,customer_email,customer_phone,customer_address,items,tax_rate,discount,notes,frequency,due_days,start_date,runs,next_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,(?::date+?::interval)::date) RETURNING id');
   $q->execute([$c['customer_name'],$c['customer_email'],$c['customer_phone'],$c['customer_address'],json_encode($c['items'],JSON_UNESCAPED_UNICODE),$c['tax_rate'],money_str($c['discount']),$c['notes'],$f,$days,$c['issue_date'],$c['issue_date'],FREQ[$f]]);
   $recId=(int)$q->fetchColumn();
  }
  $res=inv_create($pdo,$c,!empty($in['send'])?'sent':'draft',null,$recId);
 }
 $pdo->commit();out(['ok'=>true]+$res);
 }

 /** action: a_inv_status */
 public static function invStatus(){
  global $in,$act;
post();staff();
 $id=(int)($in['id']??0);$st=str('status');
 if(!in_array($st,['draft','sent','void'],true))out(['error'=>'Unknown status.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status,(SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=invoices.id) AS n FROM invoices WHERE id=? FOR UPDATE');$s->execute([$id]);$o=$s->fetch();
 if(!$o){$pdo->rollBack();out(['error'=>'Unknown invoice.'],404);}
 if($o['status']==='void'){$pdo->rollBack();out(['error'=>'Void invoices can’t be reopened.'],409);}
 if($st==='draft'&&(int)$o['n']>0){$pdo->rollBack();out(['error'=>'This invoice has payments, so it can’t go back to draft.'],409);}
 $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$st,$id]);$pdo->commit();out(['ok'=>true]);
 }

 /** action: a_inv_delete */
 public static function invDelete(){
  global $in,$act;
post();staff();
 $s=db()->prepare("DELETE FROM invoices WHERE id=? AND status='draft'");$s->execute([(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Only drafts can be deleted. Void the invoice instead.'],409);out(['ok'=>true]);
 }

 /** action: a_inv_pay */
 public static function invPay(){
  global $in,$act;
post();staff();
 $id=(int)($in['id']??0);$amt=cents($in['amount']??0);$method=str('method');$on=str('paid_on');
 if($amt<=0)out(['error'=>'Enter the amount received.'],422);
 if(!in_array($method,PAY_METHODS,true))out(['error'=>'Choose a payment method.'],422);
 if($on!==''&&!valid_date($on))out(['error'=>'Enter a valid payment date.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status,total,(SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=invoices.id) AS paid FROM invoices WHERE id=? FOR UPDATE');$s->execute([$id]);$o=$s->fetch();
 if(!$o){$pdo->rollBack();out(['error'=>'Unknown invoice.'],404);}
 if($o['status']!=='sent'){$pdo->rollBack();out(['error'=>$o['status']==='draft'?'Mark the invoice as sent before recording a payment.':'Void invoices can’t take payments.'],409);}
 $bal=cents($o['total'])-cents($o['paid']);
 if($amt>$bal){$pdo->rollBack();out(['error'=>'The balance due is '.number_format($bal/100,2).', so you can’t record more than that.'],409);}
 $pdo->prepare('INSERT INTO invoice_payments(invoice_id,amount,method,paid_on,note) VALUES(?,?,?,COALESCE(NULLIF(?,\'\')::date,CURRENT_DATE),?)')->execute([$id,money_str($amt),$method,$on,mb_substr(str('note'),0,200)]);
 $pdo->commit();
 $q=$pdo->prepare('SELECT number FROM invoices WHERE id=?');$q->execute([$id]);
 nf_add('staff',null,'invoice_paid','Payment recorded on '.$q->fetchColumn(),nf_money($amt/100).' · '.$method,'tab:v');
 out(['ok'=>true]);
 }

 /** action: a_inv_unpay */
 public static function invUnpay(){
  global $in,$act;
post();admin();
 $s=db()->prepare('DELETE FROM invoice_payments WHERE id=?');$s->execute([(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown payment.'],404);out(['ok'=>true]);
 }

 /** action: a_inv_from_order */
 public static function invFromOrder(){
  global $in,$act;
post();staff();
 $oid=(int)($in['order_id']??0);$pdo=db();
 $s=$pdo->prepare('SELECT id FROM invoices WHERE order_id=? ORDER BY id LIMIT 1');$s->execute([$oid]);
 if($ex=$s->fetchColumn())out(['id'=>(int)$ex,'existing'=>true]);
 $s=$pdo->prepare('SELECT o.*,u.email,CURRENT_DATE::text AS today FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$s->execute([$oid]);$o=$s->fetch();
 if(!$o)out(['error'=>'Unknown order.'],404);
 if($o['status']==='cancelled')out(['error'=>'This order was cancelled.'],409);
 $s=$pdo->prepare('SELECT product_id,name AS description,qty,unit_price FROM order_items WHERE order_id=? ORDER BY id');$s->execute([$oid]);
 // Order prices already include everything the customer paid, so no extra tax is added here.
 try{$c=inv_clean(['customer_name'=>$o['name'],'customer_email'=>$o['email'],'customer_phone'=>$o['phone'],'customer_address'=>$o['address'],'issue_date'=>$o['today'],
  'due_date'=>(new DateTime($o['today']))->modify('+'.(int)cfg('DUE_DAYS',14).' days')->format('Y-m-d'),'tax_rate'=>0,'discount'=>0,'notes'=>'Order #'.$oid.' ('.$o['payment'].')','items'=>$s->fetchAll()]);}
 catch(InvalidArgumentException $x){out(['error'=>$x->getMessage()],422);}
 $pdo->beginTransaction();$r=inv_create($pdo,$c,'draft',$oid);$pdo->commit();out(['id'=>$r['id'],'existing'=>false]);
 }

 /** action: a_rec_toggle */
 public static function recToggle(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$pdo=db();
 if(empty($in['active'])){$pdo->prepare('UPDATE recurring_invoices SET active=false WHERE id=?')->execute([$id]);out(['ok'=>true]);}
 // Resuming skips the periods missed while paused, so it doesn't suddenly create a pile of old invoices.
 $s=$pdo->prepare('SELECT frequency FROM recurring_invoices WHERE id=?');$s->execute([$id]);$fq=$s->fetchColumn();
 if($fq===false)out(['error'=>'Unknown repeating invoice.'],404);
 $pdo->prepare('UPDATE recurring_invoices r SET active=true,runs=COALESCE((SELECT MIN(n) FROM generate_series(r.runs,r.runs+2000) n WHERE (r.start_date+(?::interval*n))::date>=CURRENT_DATE),r.runs) WHERE id=?')->execute([FREQ[$fq],$id]);
 $pdo->prepare('UPDATE recurring_invoices SET next_date=(start_date+(?::interval*runs))::date WHERE id=?')->execute([FREQ[$fq],$id]);
 out(['ok'=>true]);
 }

 /** action: a_rec_delete */
 public static function recDelete(){
  global $in,$act;
post();admin();
 db()->prepare('DELETE FROM recurring_invoices WHERE id=?')->execute([(int)($in['id']??0)]);out(['ok'=>true]);


// ================= Admin: full control (orders, customers, admins, departments, prices, store settings) =================
 }
}
