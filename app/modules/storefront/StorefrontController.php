<?php
// Public shop: site info, products, cart, checkout and the customer’s own orders.
// One public static method per action. Routes for this module are listed in routes.php.
class StorefrontController{

 /** action: site */
 public static function site(){
  global $in,$act;
 $has=(bool)db()->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
 $d=db()->query('SELECT dept FROM products GROUP BY dept ORDER BY MIN(id)')->fetchAll(PDO::FETCH_COLUMN);
 $di=db()->query("SELECT DISTINCT ON(dept) dept,image FROM products WHERE image<>'' ORDER BY dept,stock=0,id")->fetchAll(PDO::FETCH_KEY_PAIR);
 out(['name'=>cfg('SITE_NAME','Store'),'currency'=>cfg('CURRENCY','$'),'phone'=>cfg('SITE_PHONE',''),'content'=>(object)content_get(),'phone2'=>cfg('SITE_PHONE2',''),'address'=>cfg('SITE_ADDRESS',''),'quote_days'=>(int)cfg('QUOTE_VALID_DAYS',30),'email'=>cfg('SITE_EMAIL',''),'branches'=>map_branches(),'depts'=>$d,'dept_images'=>(object)$di,'tax_name'=>cfg('TAX_NAME','Tax'),'default_tax'=>(float)cfg('DEFAULT_TAX',0),'due_days'=>(int)cfg('DUE_DAYS',14),'mpesa'=>mpesa_ready()&&mpesa_table_ready()&&pay_ready(),'admin_signup'=>cfg('ADMIN_SIGNUP_CODE','')!==''?'code':($has?'closed':'first')]);
 }

 /** action: products */
 public static function products(){
  global $in,$act;
 $q=trim($_GET['q']??'');$d=$_GET['dept']??'All';$w=[];$p=[];
 if($q!==''){$l='%'.addcslashes($q,'%_\\').'%';$w[]='(name ILIKE ? OR dept ILIKE ? OR descr ILIKE ?)';array_push($p,$l,$l,$l);}
 if($d!=='All'){$w[]='dept=?';$p[]=$d;}
 $s=db()->prepare('SELECT id,name,descr,price,was,emoji,image,dept,stock FROM products'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY id');
 $s->execute($p);out(array_map('prod',$s->fetchAll()));
 }

 /** action: best */
 public static function best(){
  global $in,$act;
 // Best sellers: the products the admin ticked (Admin > Products), read fresh on every visit. With none ticked, the shop's top sellers of the last 30 days are shown instead.
 header('Cache-Control: no-store');$cols='id,name,descr,price,was,emoji,image,dept,stock';$L=[];$manual=false;
 if(best_ready()){$L=db()->query("SELECT $cols FROM products WHERE best_seller ORDER BY best_pos,id LIMIT 8")->fetchAll();$manual=count($L)>0;}
 if(!$manual){
  $L=db()->query("SELECT p.id,p.name,p.descr,p.price,p.was,p.emoji,p.image,p.dept,p.stock FROM products p JOIN (SELECT oi.product_id,SUM(oi.qty) AS n FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status<>'cancelled' AND o.created_at>now()-interval '30 days' AND oi.product_id IS NOT NULL GROUP BY oi.product_id) s ON s.product_id=p.id WHERE p.stock>0 AND p.image<>'' ORDER BY s.n DESC,p.id LIMIT 8")->fetchAll();
  if(count($L)<8){$have=array_column($L,'id');foreach(db()->query("SELECT $cols FROM products WHERE stock>0 AND image<>'' ORDER BY id LIMIT 24")->fetchAll() as $r){if(count($L)>=8)break;if(!in_array($r['id'],$have))$L[]=$r;}}
 }
 out(['manual'=>$manual,'items'=>array_map('prod',$L)]);
 }

 /** action: cart */
 public static function cart(){
  global $in,$act;
post();
 $id=(int)($in['product_id']??0);$n=max(0,min(99,(int)($in['qty']??0)));
 $s=db()->prepare('SELECT stock FROM products WHERE id=?');$s->execute([$id]);$st=$s->fetchColumn();
 if($st===false)out(['error'=>'Unknown product.'],404);
 if($n>(int)$st)out(['error'=>'Only '.(int)$st.' in stock.'],409);
 if($n)$_SESSION['cart'][$id]=$n;else unset($_SESSION['cart'][$id]);
 out(cart());
 }

 /** action: checkout */
 public static function checkout(){
  global $in,$act;
post();
 if(!uid())out(['error'=>'Please sign in to check out.'],401);
 $nm=str('name');$ph=str('phone');$ad=str('address');$nt=str('note');
 $pm=str('payment');$pay=$pm==='pickup'?'Pay on pickup':($pm==='mpesa'?'M-Pesa':'Cash on delivery');
 if($pm==='mpesa'&&!(mpesa_ready()&&mpesa_table_ready()&&pay_ready()))out(['error'=>'M-Pesa payment is not available right now. Choose another payment option.'],503);
 if($nm===''||strlen($ph)<7||$ad==='')out(['error'=>'Enter your name, phone number and delivery address.'],422);
 $c=cart();if(!$c['items'])out(['error'=>'Your cart is empty.'],422);
 $pdo=db();$pdo->beginTransaction();
 $u=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=? RETURNING stock');$left=[];
 foreach($c['items'] as $r){$u->execute([$r['qty'],$r['id'],$r['qty']]);
  $a=$u->fetchColumn();
  if($a===false){$pdo->rollBack();out(['error'=>$r['name'].' doesn’t have enough stock. Lower the quantity and try again.'],409);}
  $left[$r['id']]=(int)$a;}
 $s=$pdo->prepare('INSERT INTO orders(user_id,total,name,phone,address,note,payment) VALUES(?,?,?,?,?,?,?) RETURNING id');
 $s->execute([uid(),number_format($c['total'],2,'.',''),$nm,$ph,$ad,$nt,$pay]);$oid=(int)$s->fetchColumn();
 $i=$pdo->prepare('INSERT INTO order_items(order_id,product_id,name,unit_price,qty) VALUES(?,?,?,?,?)');
 foreach($c['items'] as $r){$i->execute([$oid,$r['id'],$r['name'],number_format($r['price'],2,'.',''),$r['qty']]);
  mv($pdo,$r['id'],$r['name'],'sale',-$r['qty'],$left[$r['id']],$r['price'],$oid,'Online order #'.$oid);}
 $pdo->commit();$_SESSION['cart']=[];
 nf_add('',uid(),'mine_order','Order #'.$oid.' received','We have your order and will confirm it shortly.','orders');
 nf_add('staff',null,'order_new','New order #'.$oid,$nm.' · '.nf_money($c['total']).' · '.$pay,'tab:o');
 foreach($c['items'] as $r)if(($left[$r['id']]??1)<=0)nf_add('staff',null,'stock_out',$r['name'].' is now out of stock','Restock it in Inventory.','tab:i');
 // Answer the customer first, then email the store: a slow mail server must never hold up or break an order.
 ignore_user_abort(true);session_write_close();
 $body=json_encode(['order_id'=>$oid,'total'=>$c['total']]);
 header('Content-Length: '.strlen($body));header('Connection: close');echo $body;
 if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();else{while(ob_get_level())ob_end_flush();flush();}
 try{notify_order($oid);}catch(Throwable $x){error_log('Order email failed: '.$x->getMessage());}
 exit;
 }

 /** action: orders */
 public static function myOrders(){
  global $in,$act;
 if(!uid())out(['error'=>'Sign in to see your orders.'],401);
 $pr=pay_ready();
 $s=db()->prepare("SELECT o.id,".($pr?'(o.paid_at IS NOT NULL)':'false')." AS paid,o.total::float8 AS total,o.status,to_char(o.created_at,'YYYY-MM-DD HH24:MI') AS at,extract(epoch from o.created_at)::bigint AS ts,(SELECT COALESCE(SUM(qty),0)::int FROM order_items WHERE order_id=o.id) AS items FROM orders o WHERE o.user_id=? ORDER BY o.id DESC LIMIT 20");
 $s->execute([uid()]);$rs=$s->fetchAll();$tn=till_no();$mp=mpesa_ready()&&mpesa_table_ready()&&$pr;foreach($rs as &$x){$x['paid']=(bool)$x['paid'];$x['till']=$tn;$x['pay_due']=$tn!==''&&$x['status']==='confirmed'&&!$x['paid'];$x['mpesa_due']=$mp&&!$x['paid']&&in_array($x['status'],['pending','confirmed'],true);}unset($x);out($rs);
 }
}
