<?php
// Staff product management: products, store content, best sellers, import / export, departments.
// One public static method per action. Routes for this module are listed in routes.php.
class CatalogController{

 // ---- staff (admins and workers) and admin-only actions ----
 /** action: a_products */
 public static function products(){
  global $in,$act;
staff();
 $L=array_map('prodA',db()->query('SELECT '.PCOLS.' FROM products ORDER BY id')->fetchAll());
 if(role()!=='admin')foreach($L as &$p)$p['cost']=null;
 unset($p);
 if(best_ready()){$bs=db()->query('SELECT id FROM products WHERE best_seller')->fetchAll(PDO::FETCH_COLUMN);foreach($L as &$p)$p['best']=in_array($p['id'],array_map('intval',$bs),true);unset($p);}
 out($L);
 }

 /** action: a_content */
 public static function content(){
  global $in,$act;
admin();out((object)content_get());
 }

 /** action: a_content_save */
 public static function contentSave(){
  global $in,$act;
post();admin();content_save($in);out(['ok'=>true]);
 }

 /** action: a_best */
 public static function best(){
  global $in,$act;
post();admin();
 if(!best_ready())out(['error'=>'Best sellers need a database update: run database/migrate_quotes.sql once as the postgres user (see README).'],503);
 $id=(int)($in['id']??0);$on=!empty($in['on']);$pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT best_seller FROM products WHERE id=? FOR UPDATE');$s->execute([$id]);$cur=$s->fetchColumn();
 if($cur===false){$pdo->rollBack();out(['error'=>'Unknown product.'],404);}
 if($on&&!$cur&&(int)$pdo->query('SELECT COUNT(*) FROM products WHERE best_seller')->fetchColumn()>=8){$pdo->rollBack();out(['error'=>'The store shows up to 8 best sellers. Remove one first.'],409);}
 if($on)$pdo->prepare('UPDATE products SET best_seller=true,best_pos=CASE WHEN best_seller THEN best_pos ELSE (SELECT COALESCE(MAX(best_pos),0)+1 FROM products) END WHERE id=?')->execute([$id]);
 else $pdo->prepare('UPDATE products SET best_seller=false,best_pos=0 WHERE id=?')->execute([$id]);
 $pdo->commit();out(['ok'=>true]);
 }

 /** action: a_best_clear */
 public static function bestClear(){
  global $in,$act;
post();admin();
 if(best_ready())db()->exec('UPDATE products SET best_seller=false,best_pos=0');
 out(['ok'=>true]);
 }

 /** action: a_save */
 public static function save(){
  global $in,$act;
post();admin();
 $w=(float)($in['was']??0);$img=str('image');$cost=trim((string)($in['cost']??''));
 $f=[str('name'),str('descr'),(float)($in['price']??0),$w>0?$w:null,str('emoji')?:'📦',img_ok($img)?$img:'',str('dept'),max(0,(int)($in['stock']??0)),
  mb_substr(str('sku'),0,40),$cost!==''&&(float)$cost>=0?(float)$cost:null,max(0,(int)($in['reorder_level']??5))];
 if($f[0]===''||$f[6]===''||$f[2]<0)out(['error'=>'Name, department and a valid price are required.'],422);
 $id=(int)($in['id']??0);$pdo=db();$pdo->beginTransaction();
 if($id){
  $s=$pdo->prepare('SELECT stock,image FROM products WHERE id=? FOR UPDATE');$s->execute([$id]);$or=$s->fetch();$old=$or?$or['stock']:false;$oldImg=$or?$or['image']:'';
  if($old===false){$pdo->rollBack();out(['error'=>'Unknown product.'],404);}
  $pdo->prepare('UPDATE products SET name=?,descr=?,price=?,was=?,emoji=?,image=?,dept=?,stock=?,sku=?,cost=?,reorder_level=? WHERE id=?')->execute([...$f,$id]);
  if((int)$old!==$f[7])mv($pdo,$id,$f[0],'adjust',$f[7]-(int)$old,$f[7],null,null,'Edited in product form',uid());
 }else{
  $s=$pdo->prepare('INSERT INTO products(name,descr,price,was,emoji,image,dept,stock,sku,cost,reorder_level) VALUES(?,?,?,?,?,?,?,?,?,?,?) RETURNING id');$s->execute($f);$id=(int)$s->fetchColumn();
  if($f[7]>0)mv($pdo,$id,$f[0],'opening',$f[7],$f[7],null,null,'New product',uid());
 }
 $pdo->commit();
 if(!(int)($in['id']??0))nf_add('customers',null,'product_new','New in store: '.$f[0],nf_money($f[2]),'s:'.$f[0]);
 if(isset($oldImg)&&$oldImg!==$f[5])up_gc([$oldImg]);
 out(['ok'=>true]);
 }

 /** action: a_delete */
 public static function delete(){
  global $in,$act;
post();admin();
 $id=(int)($in['id']??0);$g=db()->prepare('SELECT image FROM products WHERE id=?');$g->execute([$id]);$im=$g->fetchColumn();
 db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
 if($im)up_gc([$im]);
 out(['ok'=>true]);
 }

 // Delete EVERY product. Orders, invoices and stock history are kept (their product link is cleared; names stay).
 /** action: a_delete_all */
 public static function deleteAll(){
  global $in,$act;
post();admin();
 if(str('confirm')!=='DELETE ALL')out(['error'=>'Type DELETE ALL to confirm.'],422);
 $pdo=db();$pdo->beginTransaction();
 $imgs=$pdo->query("SELECT image FROM products WHERE image LIKE 'img/uploads/%'")->fetchAll(PDO::FETCH_COLUMN);
 $n=(int)$pdo->exec('DELETE FROM products');
 $pdo->commit();up_gc($imgs);
 out(['ok'=>true,'deleted'=>$n]);
 }

 // Photo upload from the product form (multipart, so it does not use post()). Needs the X-Requested-With header: a cross-site form cannot send it.
 /** action: a_upload */
 public static function upload(){
  global $in,$act;
admin();
 if($_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='fetch')out(['error'=>'Upload with POST required.'],405);
 $f=$_FILES['photo']??null;
 if(!$f||$f['error']!==UPLOAD_ERR_OK){
  $m=[UPLOAD_ERR_INI_SIZE=>'That photo is bigger than the server allows. Raise upload_max_filesize and post_max_size in php.ini, or use a smaller photo.',UPLOAD_ERR_FORM_SIZE=>'That photo is too big.',UPLOAD_ERR_NO_FILE=>'Choose a photo first.'];
  out(['error'=>$m[$f['error']??UPLOAD_ERR_NO_FILE]??'The upload failed. Try again.'],422);
 }
 if($f['size']>8*1024*1024)out(['error'=>'The photo is bigger than 8 MB.'],422);
 $info=@getimagesize($f['tmp_name']);
 $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_GIF=>'gif',IMAGETYPE_WEBP=>'webp'][$info[2]??0]??null;
 if(!$ext)out(['error'=>'Use a JPG, PNG, WebP or GIF photo.'],422);
 $dir=PUBLIC_DIR.'/img/uploads';
 if(!is_dir($dir)){
  if(!@mkdir($dir,0755,true))out(['error'=>'Could not create the img/uploads folder. Create it by hand and allow Apache to write to it.'],500);
  // Uploaded files are images only: never run scripts from this folder.
  @file_put_contents($dir.'/.htaccess',"Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n");
 }
 $name=bin2hex(random_bytes(8)).'.'.$ext;
 if(!@move_uploaded_file($f['tmp_name'],$dir.'/'.$name))out(['error'=>'Could not save the photo. Allow Apache to write to the img/uploads folder.'],500);
 out(['ok'=>true,'path'=>'img/uploads/'.$name]);
 }

 // Export every product as a CSV file (opens in Excel; the same file can be imported again).
 /** action: a_export */
 public static function export(){
  global $in,$act;
admin();
 header_remove('Content-Type');
 header('Content-Type: text/csv; charset=utf-8');
 header('Content-Disposition: attachment; filename="products-'.date('Y-m-d').'.csv"');
 echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
 $row=fn(array $r)=>fputcsv($o,$r,',','"','');
 $row(['name','description','department','price','old_price','stock','reorder_level','sku','unit_cost','emoji','image']);
 foreach(db()->query('SELECT '.PCOLS.' FROM products ORDER BY id') as $p)
  $row([cx($p['name']),cx($p['descr']),cx($p['dept']),$p['price'],$p['was']??'',$p['stock'],$p['reorder_level'],cx($p['sku']),$p['cost']??'',$p['emoji'],cx($p['image'])]);
 fclose($o);exit;
 }

 // Import products. The browser reads the CSV and sends rows; mode 'upsert' updates products with the same SKU (or same name + department) and adds the rest, 'add' always adds.
 /** action: a_import */
 public static function import(){
  global $in,$act;
post();admin();
 $rows=$in['rows']??null;
 if(!is_array($rows)||!$rows)out(['error'=>'There are no rows to import.'],422);
 if(count($rows)>5000)out(['error'=>'Import up to 5000 products at a time.'],422);
 $mode=($in['mode']??'upsert')==='add'?'add':'upsert';
 $pdo=db();$pdo->beginTransaction();$added=$updated=$skipped=0;$errs=[];
 $bySku=$pdo->prepare("SELECT ".PCOLS." FROM products WHERE sku<>'' AND lower(sku)=lower(?) ORDER BY id LIMIT 1 FOR UPDATE");
 $byName=$pdo->prepare('SELECT '.PCOLS.' FROM products WHERE lower(name)=lower(?) AND lower(dept)=lower(?) ORDER BY id LIMIT 1 FOR UPDATE');
 $ins=$pdo->prepare('INSERT INTO products(name,descr,price,was,emoji,image,dept,stock,sku,cost,reorder_level) VALUES(?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
 $upd=$pdo->prepare('UPDATE products SET name=?,descr=?,price=?,was=?,emoji=?,image=?,dept=?,stock=?,sku=?,cost=?,reorder_level=? WHERE id=?');
 $bad=function($n,$why)use(&$errs,&$skipped){$skipped++;if(count($errs)<25)$errs[]='Row '.$n.': '.$why;};
 foreach($rows as $i=>$r){
  $n=$i+2; // row 1 of the file is the header
  if(!is_array($r)){$bad($n,'not a valid row.');continue;}
  $t=fn($k)=>ix($r[$k]??'');
  $name=mb_substr($t('name'),0,200);$dept=mb_substr($t('dept'),0,100);
  if($name===''||$dept===''){$bad($n,'name and department are required.');continue;}
  $price=inum($r['price']??'');
  if($price===null||$price===false||$price<0){$bad($n,'price must be a number, 0 or more.');continue;}
  $was=inum($r['was']??'');$cost=inum($r['cost']??'');$stock=inum($r['stock']??'');$ro=inum($r['reorder_level']??'');
  if($was===false||$cost===false||$stock===false||$ro===false||($cost!==null&&$cost<0)){$bad($n,'old price, stock, reorder level and unit cost must be numbers.');continue;}
  $sku=mb_substr($t('sku'),0,40);$img=$t('image');if(!img_ok($img))$img='';
  $ex=null;
  if($mode==='upsert'){
   if($sku!==''){$bySku->execute([$sku]);$ex=$bySku->fetch()?:null;}
   if(!$ex){$byName->execute([$name,$dept]);$ex=$byName->fetch()?:null;}
  }
  if($ex){
   // Blank cells keep what the product already has. An old price of 0 clears the sale price.
   $nw=$was===null?(float)$ex['was']:$was;$ns=$stock===null?(int)$ex['stock']:max(0,(int)$stock);
   $upd->execute([$name,$t('descr')!==''?$t('descr'):$ex['descr'],number_format($price,2,'.',''),$nw>0?number_format($nw,2,'.',''):null,
    $t('emoji')!==''?mb_substr($t('emoji'),0,8):$ex['emoji'],$img!==''?$img:$ex['image'],$dept,$ns,$sku!==''?$sku:$ex['sku'],
    $cost===null?$ex['cost']:number_format($cost,2,'.',''),$ro===null?(int)$ex['reorder_level']:max(0,(int)$ro),(int)$ex['id']]);
   if($ns!==(int)$ex['stock'])mv($pdo,(int)$ex['id'],$name,'adjust',$ns-(int)$ex['stock'],$ns,null,null,'Product import',uid());
   $updated++;
  }else{
   $ns=$stock===null?0:max(0,(int)$stock);
   $ins->execute([$name,$t('descr'),number_format($price,2,'.',''),$was!==null&&$was>0?number_format($was,2,'.',''):null,$t('emoji')!==''?mb_substr($t('emoji'),0,8):'📦',$img,$dept,$ns,$sku,
    $cost===null?null:number_format($cost,2,'.',''),$ro===null?5:max(0,(int)$ro)]);
   $id=(int)$ins->fetchColumn();
   if($ns>0)mv($pdo,$id,$name,'opening',$ns,$ns,null,null,'Product import',uid());
   $added++;
  }
 }
 $pdo->commit();
 if($added>0)nf_add('customers',null,'product_new',$added.' new product'.($added>1?'s':'').' added to the store','Take a look at what is new.','s:');
 out(['ok'=>true,'added'=>$added,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errs]);
 }

 // ---- departments and prices in bulk ----
 /** action: a_dept_rename */
 public static function deptRename(){
  global $in,$act;
post();admin();
 $from=str('from');$to=str('to');if($from===''||$to==='')out(['error'=>'Enter the new department name.'],422);
 $s=db()->prepare('UPDATE products SET dept=? WHERE dept=?');$s->execute([$to,$from]);out(['ok'=>true,'changed'=>$s->rowCount()]);
 }

 // Raise or lower prices by a percentage for one department or the whole store. Old prices (was) are left alone.
 /** action: a_bulk_price */
 public static function bulkPrice(){
  global $in,$act;
post();admin();
 $pct=(float)($in['percent']??0);$d=str('dept');
 if($pct<=-100||$pct>1000||$pct==0)out(['error'=>'Enter a percentage other than 0 (for example 10 or -5).'],422);
 $s=db()->prepare("UPDATE products SET price=GREATEST(0,ROUND(price*(1+?::numeric/100),2)) WHERE (?::text='' OR dept=?::text)");$s->execute([$pct,$d,$d]);
 out(['ok'=>true,'changed'=>$s->rowCount()]);
 }
}
