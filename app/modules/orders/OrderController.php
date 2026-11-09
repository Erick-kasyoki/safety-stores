<?php
// Staff order handling: list, status, till-payment confirmation, assignment, edit and delete.
// One public static method per action. Routes for this module are listed in routes.php.
class OrderController{

 /** action: a_orders */
 public static function orders(){
  global $in,$act;
staff();
 $pr=pay_ready();$px=$pr?"(o.paid_at IS NOT NULL) AS paid,to_char(o.paid_at,'YYYY-MM-DD HH24:MI') AS paid_at,pb.name AS paid_by_name,":"false AS paid,NULL AS paid_at,NULL AS paid_by_name,";$pj=$pr?' LEFT JOIN users pb ON pb.id=o.paid_by':'';
 $mr=mpesa_table_ready();$px.=$mr?"(SELECT receipt FROM mpesa_payments m WHERE m.order_id=o.id AND m.status='success' AND NOT m.needs_review ORDER BY m.id DESC LIMIT 1) AS mpesa_receipt,":"NULL AS mpesa_receipt,";
 $r=db()->query("SELECT o.id,".$px."o.total::float8 AS total,o.status,o.name,o.phone,o.address,o.note,o.payment,o.assigned_to,aw.name AS assigned_name,ub.name AS updated_by_name,to_char(o.updated_at,'YYYY-MM-DD HH24:MI') AS updated_at,to_char(o.created_at,'YYYY-MM-DD HH24:MI') AS at,(SELECT COALESCE(json_agg(json_build_object('name',i.name,'qty',i.qty)),'[]'::json) FROM order_items i WHERE i.order_id=o.id) AS items FROM orders o LEFT JOIN users aw ON aw.id=o.assigned_to LEFT JOIN users ub ON ub.id=o.updated_by".$pj." ORDER BY o.id DESC LIMIT 200")->fetchAll();
 foreach($r as &$o){$o['paid']=(bool)$o['paid'];$o['items']=json_decode($o['items'],true)?:[];$o['assigned_to']=$o['assigned_to']===null?null:(int)$o['assigned_to'];}unset($o);
 // Order target (working hours) from Settings > Business hours: show how much of it each open order has used.
 $tgt=(float)cfg('BH_TARGET','0')*60;$warn=max(1,(int)cfg('BH_WARN','75'));
 foreach($r as &$o){$o['sla']=null;if($tgt>0&&in_array($o['status'],['pending','confirmed','dispatched'],true)){$pct=(int)round(bh_minutes((int)$o['ts'],time())/$tgt*100);$o['sla']=['pct'=>$pct,'state'=>$pct>=100?'breached':($pct>=$warn?'warning':'ok')];}unset($o['ts']);}unset($o);
 out($r);
 }

 /** action: a_status */
 public static function status(){
  global $in,$act;
post();staff();pay_ready();
 $id=(int)($in['id']??0);$st=str('status');
 if(!in_array($st,['pending','confirmed','dispatched','delivered','cancelled'],true))out(['error'=>'Unknown status.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status,assigned_to FROM orders WHERE id=? FOR UPDATE');$s->execute([$id]);$o0=$s->fetch();
 if(!$o0){$pdo->rollBack();out(['error'=>'Unknown order.'],404);}
 $old=$o0['status'];
 if(role()==='worker'&&$o0['assigned_to']!==null&&(int)$o0['assigned_to']!==uid()){$pdo->rollBack();out(['error'=>'This order is handled by another worker.'],403);}
 if($old==='cancelled'&&$st!=='cancelled'){$pdo->rollBack();out(['error'=>'Cancelled orders can’t be reopened.'],409);}
 if(in_array($st,['dispatched','delivered'],true)&&$old!==$st&&!pay_ready()){$pdo->rollBack();out(['error'=>'Payment tracking is not set up yet. Run database/migrate_payments.sql once as the postgres user, then confirm the till payment before dispatching.'],503);}
 if(pay_ready()&&in_array($st,['dispatched','delivered'],true)&&$old!==$st){$pq=$pdo->prepare('SELECT paid_at IS NOT NULL FROM orders WHERE id=?');$pq->execute([$id]);if(!$pq->fetchColumn()){$pdo->rollBack();out(['error'=>'Payment for order #'.$id.' is not confirmed yet. Confirm the till payment first, then dispatch.'],409);}}
 if($st==='cancelled'&&$old!=='cancelled'){
  $b=$pdo->prepare('SELECT i.product_id,i.name,i.qty,i.unit_price FROM order_items i WHERE i.order_id=? AND i.product_id IS NOT NULL ORDER BY i.product_id');$b->execute([$id]);
  $u=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? RETURNING stock');
  foreach($b->fetchAll() as $r){$u->execute([$r['qty'],$r['product_id']]);mv($pdo,(int)$r['product_id'],$r['name'],'return',(int)$r['qty'],(int)$u->fetchColumn(),(float)$r['unit_price'],$id,'Order #'.$id.' cancelled',uid());}
 }
 // A worker who handles an unassigned order takes it.
 $pdo->prepare('UPDATE orders SET status=?,updated_at=now(),updated_by=?,assigned_to=COALESCE(assigned_to,?) WHERE id=?')->execute([$st,uid(),role()==='worker'?uid():null,$id]);$pdo->commit();
 if($st!==$old){
  $q=$pdo->prepare('SELECT user_id FROM orders WHERE id=?');$q->execute([$id]);$cu=(int)$q->fetchColumn();$by=(me_row()['name']??'Staff');
  $say=['confirmed'=>'is confirmed and being prepared','dispatched'=>'is on its way to you','delivered'=>'was delivered. Thank you for shopping with us!','cancelled'=>'was cancelled'];
  if($st==='confirmed'&&$cu){
   $tn=till_no();$tq=$pdo->prepare('SELECT total FROM orders WHERE id=?');$tq->execute([$id]);$tt=nf_money($tq->fetchColumn());
   if($tn!=='')nf_add('',$cu,'mine_pay','Order #'.$id.' confirmed. Please pay '.$tt.' to Till Number '.$tn,'M-PESA > Lipa na M-PESA > Buy Goods and Services. We dispatch your order once payment is confirmed.','orders');
   else{nf_add('',$cu,'mine_pay','Order #'.$id.' confirmed. Payment is required before dispatch','We will send you our till number shortly.','orders');nf_add('staff',null,'order_paid','Set the M-PESA till number in Settings','Order #'.$id.' was confirmed but customers have no till number to pay yet.','tab:o');}
  }
  elseif(isset($say[$st])&&$cu)nf_add('',$cu,'mine_order','Your order #'.$id.' '.$say[$st],'','orders');
  nf_add('staff',null,'order_status','Order #'.$id.' marked '.$st,'By '.$by,'tab:o');
 }
 out(['ok'=>true]);
 }

 // Payment confirmation: a worker or admin confirms the customer's till payment; only then can the order be dispatched.
 /** action: a_paid */
 public static function paid(){
  global $in,$act;
post();staff();
 if(!pay_ready())out(['error'=>'Payments need a database update: run database/migrate_payments.sql once as the postgres user.'],503);
 $id=(int)($in['id']??0);$yes=!array_key_exists('paid',$in)||(bool)$in['paid'];
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status,assigned_to,user_id,total,paid_at IS NOT NULL AS paid FROM orders WHERE id=? FOR UPDATE');$s->execute([$id]);$o0=$s->fetch();
 if(!$o0){$pdo->rollBack();out(['error'=>'Unknown order.'],404);}
 if(role()==='worker'&&$o0['assigned_to']!==null&&(int)$o0['assigned_to']!==uid()){$pdo->rollBack();out(['error'=>'This order is handled by another worker.'],403);}
 if($o0['status']!=='confirmed'){$pdo->rollBack();out(['error'=>$yes?'Confirm the order first, then confirm its payment.':'Payment can only be changed while the order is confirmed (not yet dispatched).'],409);}
 if((bool)$o0['paid']===$yes){$pdo->rollBack();out(['ok'=>true]);}
 $pdo->prepare('UPDATE orders SET paid_at='.($yes?'now()':'NULL').',paid_by='.($yes?'?':'NULL').',updated_at=now(),updated_by=?,assigned_to=COALESCE(assigned_to,?) WHERE id=?')->execute(array_merge($yes?[uid()]:[],[uid(),role()==='worker'?uid():null,$id]));$pdo->commit();
 $by=(me_row()['name']??'Staff');$cu=(int)$o0['user_id'];
 if($yes){if($cu)nf_add('',$cu,'mine_paid','Payment received for order #'.$id.'. Thank you!','Your order will be dispatched shortly.','orders');nf_add('staff',null,'order_paid','Payment confirmed for order #'.$id,'By '.$by.' · '.nf_money($o0['total']),'tab:o');}
 else nf_add('staff',null,'order_paid','Payment on order #'.$id.' was marked unpaid','By '.$by,'tab:o');
 out(['ok'=>true]);
 }

 // Workers can take an order or give back one of their own. Admins can give any order to any active worker.
 /** action: a_assign */
 public static function assign(){
  global $in,$act;
post();staff();
 $id=(int)($in['id']??0);$w=(int)($in['worker_id']??0);$pdo=db();
 $s=$pdo->prepare('SELECT assigned_to FROM orders WHERE id=?');$s->execute([$id]);$cur=$s->fetchColumn();
 if($cur===false)out(['error'=>'Unknown order.'],404);
 if(role()==='worker'){
  if($w!==0&&$w!==uid())out(['error'=>'Workers can only take orders for themselves.'],403);
  if($w===0&&(int)$cur!==uid())out(['error'=>'You can only release your own orders.'],403);
  if($w!==0&&$cur!==null&&(int)$cur!==uid())out(['error'=>'Another worker already has this order.'],409);
 }elseif($w!==0){
  $s=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='worker' AND active");$s->execute([$w]);
  if(!$s->fetch())out(['error'=>'Choose an active worker.'],422);
 }
 $pdo->prepare('UPDATE orders SET assigned_to=?,updated_at=now(),updated_by=? WHERE id=?')->execute([$w?:null,uid(),$id]);
 $by=(me_row()['name']??'Staff');
 if($w){
  $q=$pdo->prepare('SELECT name FROM users WHERE id=?');$q->execute([$w]);$wn=(string)$q->fetchColumn();
  nf_add('staff',null,'order_assign','Order #'.$id.' assigned to '.$wn,'By '.$by,'tab:o');
  if($w!==(int)uid())nf_add('',$w,'mine_assign','Order #'.$id.' was assigned to you','By '.$by,'tab:o');
  $q=$pdo->prepare('SELECT user_id FROM orders WHERE id=?');$q->execute([$id]);$cu=(int)$q->fetchColumn();
  if($cu&&(int)$cur!==$w)nf_add('',$cu,'mine_order','A team member is now working on your order #'.$id,'','orders');
 }else nf_add('staff',null,'order_assign','Order #'.$id.' released','By '.$by,'tab:o');
 out(['ok'=>true]);
 }

 // Small numbers the staff pages check every few seconds, so new orders show up without a refresh.
 /** action: a_counts */
 public static function counts(){
  global $in,$act;
staff();
 $r=db()->query("SELECT COUNT(*) FILTER(WHERE status='pending')::int AS pending,COUNT(*) FILTER(WHERE assigned_to IS NULL AND status IN('pending','confirmed','dispatched'))::int AS unassigned,COALESCE(MAX(id),0)::int AS last_order FROM orders")->fetch();
 $r['low']=(int)db()->query('SELECT COUNT(*) FROM products WHERE stock<=reorder_level')->fetchColumn();
 $s=db()->prepare("SELECT COUNT(*)::int FROM orders WHERE assigned_to=? AND status IN('pending','confirmed','dispatched')");$s->execute([uid()]);$r['mine']=(int)$s->fetchColumn();
 $r['currency']=cfg('CURRENCY','$');$r['site_name']=cfg('SITE_NAME','Store'); // so open worker/admin pages notice a changed currency or store name
 out($r);
 }

 // ---- orders: edit everything on an order, or delete it. Stock is kept in step with the items. ----
 /** action: a_order_save */
 public static function orderSave(){
  global $in,$act;
post();admin();pay_ready();
 $id=(int)($in['id']??0);$pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetchColumn();
 if($old===false){$pdo->rollBack();out(['error'=>'Unknown order.'],404);}
 $nm=str('name');$ph=str('phone');$ad=str('address');$nt=str('note');$pay=mb_substr(str('payment'),0,60);
 if($nm===''||$ad===''){$pdo->rollBack();out(['error'=>'Name and address are required.'],422);}
 $st=str('status')?:$old;
 if(!in_array($st,['pending','confirmed','dispatched','delivered','cancelled'],true)){$pdo->rollBack();out(['error'=>'Unknown status.'],422);}
 if(in_array($st,['dispatched','delivered'],true)&&$old!==$st&&!pay_ready()){$pdo->rollBack();out(['error'=>'Payment tracking is not set up yet. Run database/migrate_payments.sql once as the postgres user, then confirm the till payment before dispatching.'],503);}
 if(pay_ready()&&in_array($st,['dispatched','delivered'],true)&&$old!==$st){$pq=$pdo->prepare('SELECT paid_at IS NOT NULL FROM orders WHERE id=?');$pq->execute([$id]);if(!$pq->fetchColumn()){$pdo->rollBack();out(['error'=>'Payment for order #'.$id.' is not confirmed yet. Confirm the till payment first, then dispatch.'],409);}}
 if($old==='cancelled'&&$st!=='cancelled'){$pdo->rollBack();out(['error'=>'Cancelled orders can’t be reopened. Edit the details only, or make a new order.'],409);}
 // current items, then the wanted items
 $q=$pdo->prepare('SELECT id,product_id,name,qty,unit_price FROM order_items WHERE order_id=? ORDER BY id FOR UPDATE');$q->execute([$id]);$cur=[];foreach($q->fetchAll() as $r)$cur[(int)$r['id']]=$r;
 $want=is_array($in['items']??null)?$in['items']:null;
 if($want!==null&&$old!=='cancelled'){
  $seen=[];$upd=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? AND stock+?>=0 RETURNING stock');
  foreach($want as $w){
   $iid=(int)($w['id']??0);$qty=(int)($w['qty']??0);$pr=(float)($w['unit_price']??0);
   if($pr<0||$qty<0||$qty>100000){$pdo->rollBack();out(['error'=>'Check the quantities and prices.'],422);}
   if(!isset($cur[$iid])){$pdo->rollBack();out(['error'=>'Unknown order line.'],422);}
   $seen[$iid]=1;$c=$cur[$iid];$diff=$qty-(int)$c['qty'];
   if($diff!==0&&$c['product_id']!==null){
    // taking more from stock (diff>0) or giving back (diff<0)
    $upd->execute([-$diff,$c['product_id'],-$diff]);$a=$upd->fetchColumn();
    if($a===false){$pdo->rollBack();out(['error'=>$c['name'].': not enough stock for that quantity.'],409);}
    mv($pdo,(int)$c['product_id'],$c['name'],$diff>0?'sale':'return',-$diff,(int)$a,(float)$pr,$id,'Order #'.$id.' edited by admin',uid());
   }
   if($qty===0)$pdo->prepare('DELETE FROM order_items WHERE id=?')->execute([$iid]);
   else $pdo->prepare('UPDATE order_items SET qty=?,unit_price=? WHERE id=?')->execute([$qty,number_format($pr,2,'.',''),$iid]);
  }
  foreach($cur as $iid=>$c)if(!isset($seen[$iid])){ // lines left out are removed and their stock returned
   if($c['product_id']!==null){$u=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? RETURNING stock');$u->execute([$c['qty'],$c['product_id']]);
    mv($pdo,(int)$c['product_id'],$c['name'],'return',(int)$c['qty'],(int)$u->fetchColumn(),(float)$c['unit_price'],$id,'Order #'.$id.' line removed by admin',uid());}
   $pdo->prepare('DELETE FROM order_items WHERE id=?')->execute([$iid]);}
 }
 // cancelling here returns the stock of whatever lines remain
 if($st==='cancelled'&&$old!=='cancelled'){
  $b=$pdo->prepare('SELECT product_id,name,qty,unit_price FROM order_items WHERE order_id=? AND product_id IS NOT NULL ORDER BY product_id');$b->execute([$id]);
  $u=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? RETURNING stock');
  foreach($b->fetchAll() as $r){$u->execute([$r['qty'],$r['product_id']]);mv($pdo,(int)$r['product_id'],$r['name'],'return',(int)$r['qty'],(int)$u->fetchColumn(),(float)$r['unit_price'],$id,'Order #'.$id.' cancelled',uid());}
 }
 $t=$pdo->prepare('SELECT COALESCE(SUM(qty*unit_price),0) FROM order_items WHERE order_id=?');$t->execute([$id]);$tot=(float)$t->fetchColumn();
 if(isset($in['total'])&&$in['total']!==''&&$want===null){$tot=max(0,(float)$in['total']);} // total typed by hand when items were not edited
 $aw=array_key_exists('assigned_to',$in)?((int)$in['assigned_to']?:null):null;
 $pdo->prepare('UPDATE orders SET name=?,phone=?,address=?,note=?,payment=?,status=?,total=?,updated_at=now(),updated_by=? WHERE id=?')->execute([$nm,$ph,$ad,$nt,$pay!==''?$pay:'Cash on delivery',$st,number_format($tot,2,'.',''),uid(),$id]);
 if(array_key_exists('assigned_to',$in))$pdo->prepare('UPDATE orders SET assigned_to=? WHERE id=?')->execute([$aw,$id]);
 $pdo->commit();out(['ok'=>true,'total'=>$tot]);
 }

 /** action: a_order_items */
 public static function orderItems(){
  global $in,$act;
admin();
 $s=db()->prepare('SELECT id,product_id,name,qty,unit_price::float8 AS unit_price FROM order_items WHERE order_id=? ORDER BY id');$s->execute([(int)($_GET['id']??0)]);out($s->fetchAll());
 }

 /** action: a_order_delete */
 public static function orderDelete(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE');$s->execute([$id]);$st=$s->fetchColumn();
 if($st===false){$pdo->rollBack();out(['error'=>'Unknown order.'],404);}
 if($st!=='cancelled'&&empty($in['keep_stock'])){ // deleting a live order puts its stock back, unless the admin says the goods were really sold
  $b=$pdo->prepare('SELECT product_id,name,qty,unit_price FROM order_items WHERE order_id=? AND product_id IS NOT NULL ORDER BY product_id');$b->execute([$id]);
  $u=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? RETURNING stock');
  foreach($b->fetchAll() as $r){$u->execute([$r['qty'],$r['product_id']]);mv($pdo,(int)$r['product_id'],$r['name'],'return',(int)$r['qty'],(int)$u->fetchColumn(),(float)$r['unit_price'],null,'Order #'.$id.' deleted',uid());}
 }
 $pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);$pdo->commit();out(['ok'=>true]);
 }
}
