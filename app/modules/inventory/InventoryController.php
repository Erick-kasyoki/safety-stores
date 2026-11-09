<?php
// Stock levels, adjustments, reorder levels and stock history.
// One public static method per action. Routes for this module are listed in routes.php.
class InventoryController{

 // ---- inventory (admin) ----
 /** action: a_inventory */
 public static function inventory(){
  global $in,$act;
staff();
 $r=db()->query("SELECT p.id,p.name,p.emoji,p.dept,p.sku,p.price::float8 AS price,p.cost::float8 AS cost,p.stock,p.reorder_level,
  COALESCE((SELECT SUM(-m.qty_change) FROM stock_movements m WHERE m.product_id=p.id AND m.kind IN ('sale','walkin','return') AND m.created_at>now()-interval '30 days'),0)::int AS sold30
  FROM products p ORDER BY p.id")->fetchAll();
 $t=['products'=>count($r),'units'=>0,'value'=>0.0,'low'=>0,'out'=>0,'soon'=>0];
 foreach($r as &$p){
  $p['id']=(int)$p['id'];$p['stock']=(int)$p['stock'];$p['reorder_level']=(int)$p['reorder_level'];$p['sold30']=(int)$p['sold30'];
  $p['status']=$p['stock']<=0?'out':($p['stock']<=$p['reorder_level']?'low':'ok');
  // What to stock, from real sales: selling rate over 30 days, days of cover left, and an order size for ~30 days plus the safety level.
  $daily=$p['sold30']/30;$p['daily']=round($daily,2);
  $p['days_left']=$daily>0?(int)floor($p['stock']/$daily):null;
  $p['soon']=$p['status']==='ok'&&$p['days_left']!==null&&$p['days_left']<=14&&$p['sold30']>=3;
  $p['need']=$p['status']!=='ok'||$p['soon'];
  $p['suggest']=$p['need']?max(1,(int)ceil($daily*30)+$p['reorder_level']-$p['stock']):0;
  $p['why']=$p['status']==='out'?'Out of stock'.($p['sold30']>0?'. Sold '.$p['sold30'].' in the last 30 days':''):($p['status']==='low'?'Low: '.$p['stock'].' left (reorder at '.$p['reorder_level'].')'.($p['sold30']>0?'. Sold '.$p['sold30'].' in 30 days':''):($p['soon']?'Selling fast: about '.$p['days_left'].' day'.($p['days_left']==1?'':'s').' of stock left':''));
  $t['units']+=$p['stock'];$t['value']+=$p['stock']*($p['cost']??$p['price']);
  if($p['status']==='low')$t['low']++;elseif($p['status']==='out')$t['out']++;elseif($p['soon'])$t['soon']++;
 }unset($p);
 $t['value']=round($t['value'],2);
 $x=db()->query("SELECT COALESCE(SUM(-qty_change),0)::int AS units,COALESCE(SUM(-qty_change*unit_price),0)::float8 AS revenue FROM stock_movements WHERE kind IN ('sale','walkin','return') AND created_at>now()-interval '30 days'")->fetch();
 $t['sold30']=(int)$x['units'];$t['revenue30']=round((float)$x['revenue'],2);
 if(role()!=='admin'){unset($t['value'],$t['revenue30']);foreach($r as &$p)$p['cost']=null;unset($p);}
 $rs=array_values(array_filter($r,fn($x)=>$x['need']));
 usort($rs,function($a,$b){$ra=$a['status']==='out'?0:($a['status']==='low'?1:2);$rb=$b['status']==='out'?0:($b['status']==='low'?1:2);if($ra!==$rb)return $ra<=>$rb;if($ra===0)return $b['sold30']<=>$a['sold30'];return ($a['days_left']??9999)<=>($b['days_left']??9999);});
 out(['totals'=>$t,'items'=>$r,'restock'=>array_slice($rs,0,12)]);
 }

 /** action: a_stock */
 public static function stock(){
  global $in,$act;
post();staff();
 $pid=(int)($in['product_id']??0);$kind=str('kind');$n=(int)($in['qty']??0);$note=str('note');
 if(!in_array($kind,['restock','walkin','damaged','adjust'],true))out(['error'=>'Choose what happened to the stock.'],422);
 if($kind==='adjust'?$n<0:$n<1)out(['error'=>$kind==='adjust'?'Enter the counted stock (0 or more).':'Enter a quantity of 1 or more.'],422);
 if($n>1000000)out(['error'=>'That quantity is too large.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT name,price,stock FROM products WHERE id=? FOR UPDATE');$s->execute([$pid]);$p=$s->fetch();
 if(!$p){$pdo->rollBack();out(['error'=>'Unknown product.'],404);}
 $old=(int)$p['stock'];
 $chg=$kind==='restock'?$n:($kind==='adjust'?$n-$old:-$n);
 $new=$old+$chg;
 if($new<0){$pdo->rollBack();out(['error'=>'Only '.$old.' in stock, so you can’t remove '.$n.'.'],409);}
 if($chg===0){$pdo->rollBack();out(['stock'=>$old,'unchanged'=>true]);}
 $pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$new,$pid]);
 mv($pdo,$pid,$p['name'],$kind,$chg,$new,$kind==='walkin'?(float)$p['price']:null,null,$note,uid());
 $pdo->commit();out(['stock'=>$new]);
 }

 /** action: a_reorder */
 public static function reorder(){
  global $in,$act;
post();admin();
 $s=db()->prepare('UPDATE products SET reorder_level=? WHERE id=?');$s->execute([max(0,min(1000000,(int)($in['level']??0))),(int)($in['id']??0)]);
 if($s->rowCount()!==1)out(['error'=>'Unknown product.'],404);out(['ok'=>true]);
 }

 /** action: a_movements */
 public static function movements(){
  global $in,$act;
staff();
 $pid=(int)($_GET['product_id']??0);
 $s=db()->prepare("SELECT m.id,m.product_id,m.name,m.kind,m.qty_change,m.qty_after,m.unit_price::float8 AS unit_price,m.order_id,m.note,to_char(m.created_at,'YYYY-MM-DD HH24:MI') AS at FROM stock_movements m WHERE (?=0 OR m.product_id=?) ORDER BY m.id DESC LIMIT 100");
 $s->execute([$pid,$pid]);out($s->fetchAll());
 }
}
