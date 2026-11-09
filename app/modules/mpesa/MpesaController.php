<?php
// M-Pesa STK Push (Daraja). Flow: m_pay sends the prompt to the customer's phone -> the customer enters their PIN ->
// Safaricom calls m_callback -> the order is marked paid (orders.paid_at, the same field staff set by hand today).
// m_status lets the page poll, and asks Safaricom directly if the callback is late or never arrives.
class MpesaController{
 const PENDING_SECS=90; // a prompt this young is still live: do not send a second one

 // The signed-in customer's own order (staff may use any order).
 private static function myOrder(int $id):array{
  need(['customer','worker','admin']);
  $s=db()->prepare('SELECT id,user_id,status,total,phone,paid_at IS NOT NULL AS paid FROM orders WHERE id=?');$s->execute([$id]);$o=$s->fetch();
  if(!$o||(role()==='customer'&&(int)$o['user_id']!==uid()))out(['error'=>'Unknown order.'],404);
  return $o;
 }
 private static function ready():void{
  if(!mpesa_ready()||!mpesa_table_ready()||!pay_ready())out(['error'=>'M-Pesa payment is not available right now. Please pay with the till number or on delivery.'],503);
 }

 /** action: m_pay (POST {order_id, phone?}) */
 public static function pay(){
  global $in;
  post();self::ready();
  $id=(int)($in['order_id']??0);$o=self::myOrder($id);
  if($o['paid'])out(['error'=>'This order is already paid.'],409);
  if(!in_array($o['status'],['pending','confirmed'],true))out(['error'=>'This order can no longer be paid online.'],409);
  $phone=mpesa_phone(str('phone')!==''?str('phone'):(string)$o['phone']);
  if($phone==='')out(['error'=>'Enter the Safaricom number that will pay, like 0712345678.'],422);
  $amount=mpesa_amount($o['total']);if($amount<1)out(['error'=>'This order has nothing to pay.'],422);
  $pdo=db();$pdo->beginTransaction();
  $pdo->prepare('SELECT 1 FROM orders WHERE id=? FOR UPDATE')->execute([$id]); // serialises double-clicks on the same order
  $s=$pdo->prepare("SELECT id,status,phone,(created_at>now()-make_interval(secs=>?)) AS live FROM mpesa_payments WHERE order_id=? ORDER BY id DESC LIMIT 1");$s->execute([self::PENDING_SECS,$id]);$last=$s->fetch();
  if($last&&$last['status']==='pending'&&$last['live']){$pdo->commit();out(['ok'=>true,'order_id'=>$id,'already_sent'=>true,'phone'=>self::mask($last['phone']),'message'=>'A payment request was just sent. Check your phone and enter your M-Pesa PIN.']);}
  $c=$pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE order_id=? AND created_at>now()-interval '15 minutes'");$c->execute([$id]);
  if((int)$c->fetchColumn()>=5){$pdo->rollBack();out(['error'=>'Too many payment attempts. Wait a few minutes and try again.'],429);}
  $i=$pdo->prepare('INSERT INTO mpesa_payments(order_id,user_id,phone,amount) VALUES(?,?,?,?) RETURNING id');$i->execute([$id,uid(),$phone,$amount]);$pid=(int)$i->fetchColumn();
  $pdo->commit();
  try{$r=mpesa_stk_push($phone,$amount,'Order '.$id,'Order '.$id);}
  catch(Throwable $x){
   db()->prepare("UPDATE mpesa_payments SET status='failed',result_desc=? WHERE id=?")->execute([mb_substr($x->getMessage(),0,200),$pid]);
   out(['error'=>'We could not send the M-Pesa request: '.$x->getMessage()],502);
  }
  db()->prepare('UPDATE mpesa_payments SET checkout_request_id=?,merchant_request_id=? WHERE id=?')->execute([(string)$r['CheckoutRequestID'],(string)($r['MerchantRequestID']??''),$pid]);
  out(['ok'=>true,'order_id'=>$id,'phone'=>self::mask($phone),'amount'=>$amount,'message'=>'Check your phone and enter your M-Pesa PIN to pay.']);
 }

 /** action: m_status (?order_id=) polled by the page every few seconds */
 public static function status(){
  global $in;
  $id=(int)($_GET['order_id']??$in['order_id']??0);$o=self::myOrder($id);
  header('Cache-Control: no-store');
  if($o['paid']){$r=db()->prepare("SELECT receipt FROM mpesa_payments WHERE order_id=? AND status='success' ORDER BY id DESC LIMIT 1");$r->execute([$id]);out(['state'=>'success','message'=>'Payment received. Thank you!','receipt'=>(string)$r->fetchColumn()]);}
  if(!mpesa_table_ready())out(['state'=>'none']);
  $s=db()->prepare("SELECT id,status,result_code,result_desc,checkout_request_id,extract(epoch from now()-created_at)::int AS age FROM mpesa_payments WHERE order_id=? ORDER BY id DESC LIMIT 1");$s->execute([$id]);$p=$s->fetch();
  if(!$p)out(['state'=>'none']);
  // Callback late or lost: after ~15 s ask Safaricom directly. Only a real answer from Daraja is trusted; "still processing" leaves it pending.
  if($p['status']==='pending'&&$p['checkout_request_id']&&$p['age']>=15&&mpesa_ready()){
   try{$q=mpesa_stk_query($p['checkout_request_id']);if($q['code']!==null){self::settle($p['checkout_request_id'],$q['code'],$q['desc'],[]);}}
   catch(Throwable $x){error_log('M-Pesa query: '.$x->getMessage());}
   $s->execute([$id]);$p=$s->fetch();
  }
  if($p['status']==='pending'&&$p['age']>240){db()->prepare("UPDATE mpesa_payments SET status='timeout',result_desc='No answer from the customer or Safaricom' WHERE id=? AND status='pending'")->execute([$p['id']]);$p['status']='timeout';$p['result_code']=1037;}
  if($p['status']==='success')out(['state'=>'success','message'=>'Payment received. Thank you!']); // paid but the order could not take it: staff were alerted
  if($p['status']==='pending')out(['state'=>'pending','message'=>'Waiting for you to enter your M-Pesa PIN…']);
  out(['state'=>'failed','message'=>mpesa_fail_text($p['result_code']===null?null:(int)$p['result_code'],(string)$p['result_desc'])]);
 }

 /** action: m_callback (POST from Safaricom, no login). The secret in the address proves the request is meant for us. */
 public static function callback(){
  global $in;
  if(!mpesa_ready()||!hash_equals(MPESA_CALLBACK_SECRET,(string)($_GET['k']??'')))out(['ResultCode'=>1,'ResultDesc'=>'Rejected'],403);
  if($_SERVER['REQUEST_METHOD']!=='POST')out(['ResultCode'=>1,'ResultDesc'=>'POST required'],405);
  $cb=$in['Body']['stkCallback']??null;
  if(!is_array($cb)||empty($cb['CheckoutRequestID'])||!isset($cb['ResultCode'])){error_log('M-Pesa callback: unexpected body');out(['ResultCode'=>0,'ResultDesc'=>'Ignored']);}
  $meta=[];foreach(($cb['CallbackMetadata']['Item']??[]) as $it)if(is_array($it)&&isset($it['Name']))$meta[(string)$it['Name']]=$it['Value']??null;
  try{self::settle((string)$cb['CheckoutRequestID'],(int)$cb['ResultCode'],(string)($cb['ResultDesc']??''),$meta);}
  catch(Throwable $x){error_log('M-Pesa callback failed: '.$x);out(['ResultCode'=>1,'ResultDesc'=>'Error'],500);} // Safaricom may retry
  out(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
 }

 /** Records the outcome of one prompt. Safe to call twice (callback + status poll): the second call changes nothing. */
 private static function settle(string $checkoutId,int $code,string $desc,array $meta):void{
  $pdo=db();$pdo->beginTransaction();
  $s=$pdo->prepare('SELECT id,order_id,user_id,amount,status FROM mpesa_payments WHERE checkout_request_id=? FOR UPDATE');$s->execute([$checkoutId]);$p=$s->fetch();
  // A success found by the status query has no M-Pesa code yet; the callback that follows brings it, so keep it.
  if($p&&$p['status']==='success'&&$code===0&&trim((string)($meta['MpesaReceiptNumber']??''))!==''){
   $pdo->prepare('UPDATE mpesa_payments SET receipt=? WHERE id=? AND receipt IS NULL')->execute([trim((string)$meta['MpesaReceiptNumber']),$p['id']]);$pdo->commit();return;}
  if(!$p||$p['status']!=='pending'){$pdo->rollBack();return;} // unknown (our push has not saved its id yet: the status poll will settle it) or already done
  $oid=(int)$p['order_id'];
  if($code!==0){
   $st=$code===1032?'cancelled':(in_array($code,[1037,1019],true)?'timeout':'failed');
   $pdo->prepare('UPDATE mpesa_payments SET status=?,result_code=?,result_desc=?,callback_at=now() WHERE id=?')->execute([$st,$code,mb_substr($desc,0,200),$p['id']]);$pdo->commit();return;
  }
  $receipt=trim((string)($meta['MpesaReceiptNumber']??''));$paid=isset($meta['Amount'])?round((float)$meta['Amount'],2):null;
  if($receipt===''){ // a success with no receipt number came from the status query: Daraja does not send it there; keep the code and let staff confirm in M-Pesa
   $receipt=null;if($paid===null)$paid=(float)$p['amount'];
  }
  $dup=false;if($receipt!==null){$d=$pdo->prepare('SELECT 1 FROM mpesa_payments WHERE receipt=? AND id<>?');$d->execute([$receipt,$p['id']]);if($d->fetchColumn()){$dup=true;$desc='Duplicate M-Pesa code '.$receipt;$receipt=null;}} // the same code twice: never count one payment two times
  $o=$pdo->prepare('SELECT status,total,user_id,paid_at IS NOT NULL AS paid FROM orders WHERE id=? FOR UPDATE');$o->execute([$oid]);$ord=$o->fetch();
  $total=$ord?(float)$ord['total']:0.0;
  $review=$dup||!$ord||$ord['status']==='cancelled'||$ord['paid']||$paid===null||$paid+0.005<$total;
  $pdo->prepare("UPDATE mpesa_payments SET status='success',result_code=0,result_desc=?,receipt=?,paid_amount=?,paid_phone=?,txn_date=?,needs_review=?,callback_at=now() WHERE id=?")
   ->execute([mb_substr($desc,0,200),$receipt,$paid===null?null:number_format($paid,2,'.',''),(string)($meta['PhoneNumber']??''),(string)($meta['TransactionDate']??''),$review?'t':'f',$p['id']]);
  if(!$review)$pdo->prepare('UPDATE orders SET paid_at=now(),paid_by=NULL,updated_at=now() WHERE id=? AND paid_at IS NULL')->execute([$oid]);
  $pdo->commit();
  $cu=$ord&&$ord['user_id']?(int)$ord['user_id']:0;$ref=$receipt?' (M-Pesa '.$receipt.')':'';
  if($review){
   $why=!$ord?'the order no longer exists':($ord['status']==='cancelled'?'the order was cancelled':($dup?'this M-Pesa code was already used':($ord['paid']?'the order was already paid':'the amount received '.nf_money($paid).' is less than the order total')));
   nf_add('staff',null,'order_paid','M-Pesa payment needs review: order #'.$oid,nf_money($paid).$ref.' received but '.$why.'. Check it in M-Pesa and refund or apply it by hand.','tab:o');
   return;
  }
  if($cu)nf_add('',$cu,'mine_paid','Payment received for order #'.$oid.'. Thank you!','M-Pesa'.($receipt?' code '.$receipt:'').'. Your order will be dispatched shortly.','orders');
  nf_add('staff',null,'order_paid','M-Pesa payment received for order #'.$oid,nf_money($paid).$ref,'tab:o');
 }
 private static function mask(string $p):string{return strlen($p)>=9?substr($p,0,6).'***'.substr($p,-3):$p;}
}
