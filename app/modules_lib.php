<?php
// Expense Tracker and Employee Leave Management. Loaded by api.php, which provides out(), post(), admin(), need(), str(), uid(), db(), cfg() and the rest.
// The tables are created automatically when the database user may do so; otherwise run database/migrate_expenses_leave.sql once as postgres.
const MOD_SQL=<<<'SQL'
-- Adds the Expense Tracker and Employee Leave Management to an EXISTING safety_store database (safe to run more than once):
--   psql -U postgres -d safety_store -f database/migrate_expenses_leave.sql
-- New installs do not need this file: schema.sql already includes it.
SET client_encoding='UTF8';
-- Expenses: every cost of running the business. Each category has an optional monthly budget (0 = no budget).
CREATE TABLE IF NOT EXISTS expense_categories(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,budget NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(budget>=0));
CREATE TABLE IF NOT EXISTS expenses(id SERIAL PRIMARY KEY,spent_on DATE NOT NULL,category TEXT NOT NULL REFERENCES expense_categories(name) ON UPDATE CASCADE ON DELETE RESTRICT,description TEXT NOT NULL DEFAULT '',vendor TEXT NOT NULL DEFAULT '',amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL DEFAULT 'Cash',reference TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS expenses_date_idx ON expenses(spent_on DESC);
CREATE INDEX IF NOT EXISTS expenses_cat_idx ON expenses(category);
INSERT INTO expense_categories(name) VALUES('Rent'),('Salaries'),('Utilities'),('Transport'),('Stock purchases'),('Marketing'),('Repairs & maintenance'),('Other') ON CONFLICT(name) DO NOTHING;
-- Leave: types with a yearly allowance (0 = no limit), and the requests workers make. status: pending | approved | rejected | cancelled
CREATE TABLE IF NOT EXISTS leave_types(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,days_per_year INT NOT NULL DEFAULT 0 CHECK(days_per_year>=0),paid BOOLEAN NOT NULL DEFAULT true,active BOOLEAN NOT NULL DEFAULT true);
CREATE TABLE IF NOT EXISTS leave_requests(id SERIAL PRIMARY KEY,user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,type_id INT NOT NULL REFERENCES leave_types(id) ON DELETE RESTRICT,start_date DATE NOT NULL,end_date DATE NOT NULL,days NUMERIC(5,1) NOT NULL CHECK(days>0),reason TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN('pending','approved','rejected','cancelled')),decided_by INT REFERENCES users(id) ON DELETE SET NULL,decided_at TIMESTAMPTZ,decision_note TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(end_date>=start_date));
CREATE INDEX IF NOT EXISTS leave_requests_user_idx ON leave_requests(user_id,start_date);
CREATE INDEX IF NOT EXISTS leave_requests_status_idx ON leave_requests(status,start_date);
INSERT INTO leave_types(name,days_per_year,paid) VALUES('Annual leave',21,true),('Sick leave',14,true),('Compassionate leave',5,true),('Unpaid leave',0,false) ON CONFLICT(name) DO NOTHING;
-- If you use the limited store_app user, the default privileges set by restrict_postgres.sql already cover these tables.
-- Otherwise run:  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;

SQL;

// ---------- helpers ----------
function mh_today(){
 try{return (new DateTime('now',new DateTimeZone(app_tz())))->format('Y-m-d');}
 catch(Throwable $x){return gmdate('Y-m-d');}
}
function mh_tz(){return app_tz();}
function mh_month($s){return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)$s)?(string)$s:substr(mh_today(),0,7);}
function mh_mail(array $to,$subject,$body){
 if(!$to||trim((string)cfg('SMTP_HOST',''))==='')return; // only when SMTP is set up in Admin > Settings; never blocks the request
 try{mail_send($to,$subject,$body);}catch(Throwable $x){error_log('mail: '.$x->getMessage());}
}
function mh_admin_mail(){return mail_list(cfg('ORDER_EMAIL',''));}
function mh_fmt_date($s){return date('D j M Y',strtotime($s.' 12:00:00'));}

// ---------- routing ----------
function mod_ready(){
 static $ok=null;if($ok!==null)return $ok;
 $chk="SELECT to_regclass('expenses') IS NOT NULL AND to_regclass('expense_categories') IS NOT NULL AND to_regclass('leave_types') IS NOT NULL AND to_regclass('leave_requests') IS NOT NULL";
 try{
  $ok=(bool)db()->query($chk)->fetchColumn();
  if(!$ok){db()->exec(MOD_SQL);$ok=(bool)db()->query($chk)->fetchColumn();} // works with the postgres user; the limited store_app user cannot create tables
 }catch(Throwable $x){error_log('expenses/leave tables: '.$x->getMessage());$ok=false;}
 return $ok;
}
function mod_route($act){
 if(!preg_match('/^(x|lv)_[a-z_]+$/',(string)$act))return;
 if(!mod_ready())out(['error'=>'The Expenses and Leave tables are missing. Run database/migrate_expenses_leave.sql once as the postgres user (see README), then reload.'],503);
 $fn='mod_'.$act;
 if(!function_exists($fn))out(['error'=>'Unknown action.'],404);
 $fn();
}

// =====================================================================
// EXPENSE TRACKER (admin only)
// =====================================================================
function mod_x_list(){
 admin();
 $pdo=db();$m=mh_month($_GET['month']??'');$from=$m.'-01';
 $to=(new DateTime($from))->modify('+1 month')->format('Y-m-d');
 $first=(new DateTime($from))->modify('-5 months')->format('Y-m-d');
 $cats=$pdo->query('SELECT id,name,budget::float8 AS budget FROM expense_categories ORDER BY name')->fetchAll();
 $s=$pdo->prepare('SELECT category,SUM(amount)::float8 AS spent,COUNT(*)::int AS n FROM expenses WHERE spent_on>=? AND spent_on<? GROUP BY category');
 $s->execute([$from,$to]);$sp=[];foreach($s->fetchAll() as $r)$sp[$r['category']]=$r;
 $total=0.0;$budget=0.0;
 foreach($cats as &$c){$c['id']=(int)$c['id'];$c['spent']=(float)($sp[$c['name']]['spent']??0);$c['n']=(int)($sp[$c['name']]['n']??0);$total+=$c['spent'];$budget+=$c['budget'];}
 unset($c);
 $s=$pdo->prepare('SELECT e.id,e.spent_on::text AS spent_on,e.category,e.description,e.vendor,e.amount::float8 AS amount,e.method,e.reference,u.name AS by_name FROM expenses e LEFT JOIN users u ON u.id=e.user_id WHERE e.spent_on>=? AND e.spent_on<? ORDER BY e.spent_on DESC,e.id DESC');
 $s->execute([$from,$to]);$items=$s->fetchAll();
 foreach($items as &$i){$i['id']=(int)$i['id'];}unset($i);
 // Six-month trend: expenses against sales (online sales, in-store sales, minus cancelled orders)
 $s=$pdo->prepare("SELECT to_char(spent_on,'YYYY-MM') AS mo,SUM(amount)::float8 AS v FROM expenses WHERE spent_on>=? AND spent_on<? GROUP BY 1");
 $s->execute([$first,$to]);$ex=$s->fetchAll(PDO::FETCH_KEY_PAIR);
 $tz=$pdo->quote(mh_tz());
 $s=$pdo->prepare("SELECT to_char(created_at AT TIME ZONE $tz,'YYYY-MM') AS mo,COALESCE(SUM(-qty_change*unit_price),0)::float8 AS v FROM stock_movements WHERE kind IN('sale','walkin','return') AND unit_price IS NOT NULL AND (created_at AT TIME ZONE $tz)>=?::date AND (created_at AT TIME ZONE $tz)<?::date GROUP BY 1");
 $s->execute([$first,$to]);$sa=$s->fetchAll(PDO::FETCH_KEY_PAIR);
 $trend=[];for($k=5;$k>=0;$k--){$mo=(new DateTime($from))->modify("-$k months")->format('Y-m');$trend[]=['month'=>$mo,'expenses'=>(float)($ex[$mo]??0),'sales'=>(float)($sa[$mo]??0)];}
 out(['month'=>$m,'categories'=>$cats,'items'=>$items,'total'=>$total,'budget'=>$budget,'sales'=>end($trend)['sales'],'trend'=>$trend,'methods'=>PAY_METHODS,'today'=>mh_today()]);
}
function mod_x_save(){
 post();admin();global $in;
 $id=(int)($in['id']??0);
 $d=str('spent_on');if(!valid_date($d))out(['error'=>'Enter a valid date.'],422);
 $cat=mb_substr(str('category'),0,60);if($cat==='')out(['error'=>'Choose a category.'],422);
 $a=inum($in['amount']??'');if(!$a||$a<=0||$a>999999999)out(['error'=>'Enter an amount greater than zero.'],422);
 $meth=str('method');if(!in_array($meth,PAY_METHODS,true))$meth='Other';
 $desc=mb_substr(str('description'),0,200);$ven=mb_substr(str('vendor'),0,120);$ref=mb_substr(str('reference'),0,80);
 $amt=number_format($a,2,'.','');
 try{
  if($id){
   $s=db()->prepare('UPDATE expenses SET spent_on=?,category=?,description=?,vendor=?,amount=?,method=?,reference=? WHERE id=?');
   $s->execute([$d,$cat,$desc,$ven,$amt,$meth,$ref,$id]);
   if(!$s->rowCount())out(['error'=>'That expense no longer exists.'],404);
  }else{
   db()->prepare('INSERT INTO expenses(spent_on,category,description,vendor,amount,method,reference,user_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$d,$cat,$desc,$ven,$amt,$meth,$ref,uid()]);
   nf_add('admin',null,'expense_new','Expense recorded: '.nf_money($a),$cat.($desc!==''?' · '.$desc:''),'tab:x');
  }
 }catch(PDOException $x){if($x->getCode()==='23503')out(['error'=>'Choose one of the listed categories.'],422);throw $x;}
 out(['ok'=>true]);
}
function mod_x_delete(){
 post();admin();global $in;
 db()->prepare('DELETE FROM expenses WHERE id=?')->execute([(int)($in['id']??0)]);
 out(['ok'=>true]);
}
function mod_x_cat_save(){
 post();admin();global $in;
 $id=(int)($in['id']??0);$n=mb_substr(str('name'),0,60);
 if($n==='')out(['error'=>'Enter a category name.'],422);
 $b=($in['budget']??'')===''?0:inum($in['budget']);
 if($b===false||$b===null||$b<0||$b>999999999)out(['error'=>'The budget must be a number, or 0 for no budget.'],422);
 $b=number_format($b,2,'.','');
 try{
  if($id){$s=db()->prepare('UPDATE expense_categories SET name=?,budget=? WHERE id=?');$s->execute([$n,$b,$id]);if(!$s->rowCount())out(['error'=>'Category not found.'],404);} // renaming updates its expenses too
  else db()->prepare('INSERT INTO expense_categories(name,budget) VALUES(?,?)')->execute([$n,$b]);
 }catch(PDOException $x){if($x->getCode()==='23505')out(['error'=>'There is already a category with that name.'],409);throw $x;}
 out(['ok'=>true]);
}
function mod_x_cat_delete(){
 post();admin();global $in;
 try{db()->prepare('DELETE FROM expense_categories WHERE id=?')->execute([(int)($in['id']??0)]);}
 catch(PDOException $x){if($x->getCode()==='23503')out(['error'=>'This category has expenses. Move or delete them first, or just rename it.'],409);throw $x;}
 out(['ok'=>true]);
}
function mod_x_export(){
 admin();
 $all=($_GET['month']??'')==='all';$m=mh_month($_GET['month']??'');
 $from=$m.'-01';$to=(new DateTime($from))->modify('+1 month')->format('Y-m-d');
 $sql='SELECT spent_on::text,category,description,vendor,amount,method,reference FROM expenses'.($all?'':' WHERE spent_on>=? AND spent_on<?').' ORDER BY spent_on,id';
 $s=db()->prepare($sql);$s->execute($all?[]:[$from,$to]);
 header_remove('Content-Type');
 header('Content-Type: text/csv; charset=utf-8');
 header('Content-Disposition: attachment; filename="expenses-'.($all?'all':$m).'.csv"');
 echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
 fputcsv($o,['date','category','description','vendor','amount','method','reference'],',','"','');
 foreach($s->fetchAll() as $r)fputcsv($o,[$r['spent_on'],cx($r['category']),cx($r['description']),cx($r['vendor']),$r['amount'],cx($r['method']),cx($r['reference'])],',','"','');
 fclose($o);exit;
}

// =====================================================================
// EMPLOYEE LEAVE MANAGEMENT
// Employees are the worker accounts. Workers request leave on worker.html, the admin approves on admin.html.
// Days off that do not count (default Sunday): define('LEAVE_OFF_DAYS','7') in app/secrets.php; use '67' for Saturday and Sunday (1=Mon ... 7=Sun).
// =====================================================================
function mh_offdays(){$o=[];foreach(str_split(preg_replace('/\D/','',(string)cfg('LEAVE_OFF_DAYS','7'))) as $c)if($c>='1'&&$c<='7')$o[(int)$c]=1;return array_keys($o);}
function mh_days($a,$b){
 $off=mh_offdays();$n=0;$d=new DateTime($a);$e=new DateTime($b);
 for($i=0;$d<=$e&&$i<400;$i++){if(!in_array((int)$d->format('N'),$off,true))$n++;$d->modify('+1 day');}
 return $n;
}
// Days of one leave type already used (or booked) by an employee in a calendar year. A request counts in the year it starts.
function mh_used($uid,$type,$year,array $statuses,$skip=0){
 $list=implode(',',array_map(fn($x)=>"'".$x."'",array_intersect($statuses,['pending','approved'])));
 $s=db()->prepare("SELECT COALESCE(SUM(days),0)::float8 FROM leave_requests WHERE user_id=? AND type_id=? AND status IN($list) AND start_date>=? AND start_date<=? AND id<>?");
 $s->execute([$uid,$type,$year.'-01-01',$year.'-12-31',$skip]);return (float)$s->fetchColumn();
}
function mh_overlap($uid,$a,$b,$skip=0){
 $s=db()->prepare("SELECT 1 FROM leave_requests WHERE user_id=? AND status IN('pending','approved') AND start_date<=? AND end_date>=? AND id<>? LIMIT 1");
 $s->execute([$uid,$b,$a,$skip]);return (bool)$s->fetchColumn();
}
// Validates a leave request for an employee; returns the number of days, or stops with an error.
function mh_leave_check($uid,$typeId,$a,$b,$allowPast){
 $s=db()->prepare('SELECT id,name,days_per_year FROM leave_types WHERE id=? AND active');$s->execute([$typeId]);$t=$s->fetch();
 if(!$t)out(['error'=>'Choose a leave type.'],422);
 if(!valid_date($a)||!valid_date($b))out(['error'=>'Choose the first and last day of the leave.'],422);
 if($b<$a)out(['error'=>'The last day cannot be before the first day.'],422);
 if(!$allowPast&&$a<mh_today())out(['error'=>'The first day cannot be in the past. Ask the admin to record it.'],422);
 if((new DateTime($a))->diff(new DateTime($b))->days>365)out(['error'=>'A single request cannot be longer than a year.'],422);
 $days=mh_days($a,$b);
 if($days<1)out(['error'=>'All of those dates are days off, so no leave is needed.'],422);
 if(mh_overlap($uid,$a,$b))out(['error'=>'These dates overlap leave that is already requested or approved.'],409);
 $allow=(int)$t['days_per_year'];
 if($allow>0){
  $y=substr($a,0,4);$used=mh_used($uid,(int)$t['id'],$y,['pending','approved']);$left=$allow-$used;
  if($days>$left+0.001)out(['error'=>'Not enough '.$t['name'].' left for '.$y.': '.rtrim(rtrim(number_format(max(0,$left),1),'0'),'.').' day(s) left, you asked for '.$days.'.'],422);
 }
 return $days;
}
function mh_lrow($r){return['id'=>(int)$r['id'],'type'=>$r['type'],'start'=>$r['start_date'],'end'=>$r['end_date'],'days'=>(float)$r['days'],'reason'=>$r['reason'],'status'=>$r['status'],'note'=>$r['decision_note']];}

function mod_lv_mine(){
 need(['worker']);
 $u=uid();$today=mh_today();$y=substr($today,0,4);$pdo=db();
 $types=[];
 foreach($pdo->query('SELECT id,name,days_per_year,paid FROM leave_types WHERE active ORDER BY id')->fetchAll() as $t){
  $id=(int)$t['id'];$allow=(int)$t['days_per_year'];$used=mh_used($u,$id,$y,['approved']);$pend=mh_used($u,$id,$y,['pending']);
  $types[]=['id'=>$id,'name'=>$t['name'],'allowance'=>$allow,'paid'=>(bool)$t['paid'],'used'=>$used,'pending'=>$pend,'left'=>$allow>0?max(0,$allow-$used-$pend):null];
 }
 $s=$pdo->prepare('SELECT r.id,t.name AS type,r.start_date::text AS start_date,r.end_date::text AS end_date,r.days::float8 AS days,r.reason,r.status,r.decision_note FROM leave_requests r JOIN leave_types t ON t.id=r.type_id WHERE r.user_id=? ORDER BY r.start_date DESC,r.id DESC LIMIT 60');
 $s->execute([$u]);
 $rows=array_map(function($r)use($today){$x=mh_lrow($r);$x['can_cancel']=$r['status']==='pending'||($r['status']==='approved'&&$r['start_date']>$today);return $x;},$s->fetchAll());
 out(['year'=>$y,'today'=>$today,'off_days'=>mh_offdays(),'types'=>$types,'requests'=>$rows]);
}
function mod_lv_request(){
 post();need(['worker']);global $in;
 $u=uid();$a=str('start');$b=str('end');$tid=(int)($in['type_id']??0);$why=mb_substr(str('reason'),0,300);
 $days=mh_leave_check($u,$tid,$a,$b,false);
 $s=db()->prepare('INSERT INTO leave_requests(user_id,type_id,start_date,end_date,days,reason) VALUES(?,?,?,?,?,?) RETURNING id');
 $s->execute([$u,$tid,$a,$b,$days,$why]);$id=(int)$s->fetchColumn();
 $me=me_row();$tn=db()->prepare('SELECT name FROM leave_types WHERE id=?');$tn->execute([$tid]);
 nf_add('admin',null,'leave_new','Leave request from '.$me['name'],mh_fmt_date($a).' to '.mh_fmt_date($b).' ('.$days.' working day'.($days==1?'':'s').')','tab:l');
 mh_mail(mh_admin_mail(),'Leave request from '.$me['name'],$me['name'].' asked for '.$tn->fetchColumn().': '.mh_fmt_date($a).' to '.mh_fmt_date($b).' ('.$days." working day(s)).\r\n".($why!==''?"Reason: $why\r\n":'')."\r\nApprove or reject it in Admin > Leave.\r\n");
 out(['ok'=>true,'id'=>$id,'days'=>$days]);
}
function mod_lv_cancel(){
 post();need(['worker']);global $in;
 $s=db()->prepare("UPDATE leave_requests SET status='cancelled' WHERE id=? AND user_id=? AND (status='pending' OR (status='approved' AND start_date>?))");
 $s->execute([(int)($in['id']??0),uid(),mh_today()]);
 if(!$s->rowCount())out(['error'=>'This request can no longer be cancelled. Leave that has started must be changed by the admin.'],409);
 out(['ok'=>true]);
}

function mod_lv_count(){
 admin();
 out(['pending'=>(int)db()->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn()]);
}
function mod_lv_admin(){
 admin();
 $pdo=db();$today=mh_today();$y=substr($today,0,4);
 $types=$pdo->query('SELECT id,name,days_per_year,paid,active FROM leave_types ORDER BY id')->fetchAll();
 foreach($types as &$t){$t['id']=(int)$t['id'];$t['days_per_year']=(int)$t['days_per_year'];$t['paid']=(bool)$t['paid'];$t['active']=(bool)$t['active'];}unset($t);
 $workers=$pdo->query("SELECT id,name FROM users WHERE role='worker' AND active ORDER BY name")->fetchAll();
 foreach($workers as &$w)$w['id']=(int)$w['id'];unset($w);
 // approved days used this year per employee and type
 $s=$pdo->prepare("SELECT user_id,type_id,SUM(days)::float8 AS d FROM leave_requests WHERE status='approved' AND start_date>=? AND start_date<=? GROUP BY 1,2");
 $s->execute([$y.'-01-01',$y.'-12-31']);$used=[];foreach($s->fetchAll() as $r)$used[(int)$r['user_id']][(int)$r['type_id']]=(float)$r['d'];
 $bal=[];foreach($workers as $w)foreach($types as $t)if($t['active'])$bal[$w['id']][$t['id']]=(float)($used[$w['id']][$t['id']]??0);
 $sel='SELECT r.id,r.user_id,u.name AS employee,t.name AS type,t.id AS type_id,t.days_per_year,r.start_date::text AS start_date,r.end_date::text AS end_date,r.days::float8 AS days,r.reason,r.status,r.decision_note,r.created_at::date::text AS asked FROM leave_requests r JOIN users u ON u.id=r.user_id JOIN leave_types t ON t.id=r.type_id';
 $map=function($r)use($used,$y){$x=mh_lrow($r);$x+=['employee'=>$r['employee'],'user_id'=>(int)$r['user_id'],'asked'=>$r['asked']];$al=(int)$r['days_per_year'];$x['left']=$al>0?max(0,$al-(float)($used[(int)$r['user_id']][(int)$r['type_id']]??0)):null;return $x;};
 $pending=array_map($map,$pdo->query($sel." WHERE r.status='pending' ORDER BY r.start_date,r.id")->fetchAll());
 $st=(string)($_GET['status']??'all');$uid=(int)($_GET['user_id']??0);$w=[];$p=[];
 if(in_array($st,['pending','approved','rejected','cancelled'],true)){$w[]='r.status=?';$p[]=$st;}
 if($uid){$w[]='r.user_id=?';$p[]=$uid;}
 $s=$pdo->prepare($sel.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY r.start_date DESC,r.id DESC LIMIT 200');$s->execute($p);
 $hist=array_map($map,$s->fetchAll());
 $s=$pdo->prepare("SELECT r.id,u.name AS employee,t.name AS type,r.start_date::text AS start_date,r.end_date::text AS end_date,r.days::float8 AS days FROM leave_requests r JOIN users u ON u.id=r.user_id JOIN leave_types t ON t.id=r.type_id WHERE r.status='approved' AND r.end_date>=? AND r.start_date<=? ORDER BY r.start_date,u.name");
 $s->execute([$today,(new DateTime($today))->modify('+60 days')->format('Y-m-d')]);$away=$s->fetchAll();
 foreach($away as &$a){$a['now']=$a['start_date']<=$today;}unset($a);
 out(['year'=>$y,'today'=>$today,'off_days'=>mh_offdays(),'types'=>$types,'workers'=>$workers,'balances'=>(object)$bal,'pending'=>$pending,'history'=>$hist,'away'=>$away]);
}
function mod_lv_decide(){
 post();admin();global $in;
 $id=(int)($in['id']??0);$dec=str('decision');$note=mb_substr(str('note'),0,300);
 if(!in_array($dec,['approve','reject'],true))out(['error'=>'Choose approve or reject.'],422);
 $pdo=db();$pdo->beginTransaction();
 $s=$pdo->prepare('SELECT r.id,r.user_id,r.type_id,r.start_date::text AS start_date,r.end_date::text AS end_date,r.days::float8 AS days,r.status,t.name AS tname,t.days_per_year FROM leave_requests r JOIN leave_types t ON t.id=r.type_id WHERE r.id=? FOR UPDATE OF r');
 $s->execute([$id]);$r=$s->fetch();
 if(!$r){$pdo->rollBack();out(['error'=>'Request not found.'],404);}
 if($r['status']!=='pending'){$pdo->rollBack();out(['error'=>'This request was already '.$r['status'].'.'],409);}
 if($dec==='approve'){
  $allow=(int)$r['days_per_year'];
  if($allow>0){
   $left=$allow-mh_used((int)$r['user_id'],(int)$r['type_id'],substr($r['start_date'],0,4),['approved'],$id);
   if($r['days']>$left+0.001){$pdo->rollBack();out(['error'=>'Only '.rtrim(rtrim(number_format(max(0,$left),1),'0'),'.').' day(s) of '.$r['tname'].' are left for this person. Raise the allowance in Leave types, or reject.'],422);}
  }
 }
 $pdo->prepare('UPDATE leave_requests SET status=?,decided_by=?,decided_at=now(),decision_note=? WHERE id=?')->execute([$dec==='approve'?'approved':'rejected',uid(),$note,$id]);
 $pdo->commit();
 nf_add('',(int)$r['user_id'],'mine_leave','Your '.$r['tname'].' request was '.($dec==='approve'?'approved':'rejected'),mh_fmt_date($r['start_date']).' to '.mh_fmt_date($r['end_date']).($note!==''?' · '.$note:''),'tab:l');
 $e=$pdo->prepare('SELECT email,name FROM users WHERE id=?');$e->execute([(int)$r['user_id']]);$emp=$e->fetch();
 if($emp)mh_mail(mail_list($emp['email']),'Your leave request was '.($dec==='approve'?'approved':'rejected'),'Hello '.$emp['name'].",\r\n\r\nYour ".$r['tname'].' request ('.mh_fmt_date($r['start_date']).' to '.mh_fmt_date($r['end_date']).') was '.($dec==='approve'?'approved':'rejected').".\r\n".($note!==''?"Note from the admin: $note\r\n":''));
 out(['ok'=>true]);
}
// The admin records leave for an employee (for example sick leave taken or leave agreed in person). It is approved straight away.
function mod_lv_admin_add(){
 post();admin();global $in;
 $uid=(int)($in['user_id']??0);$tid=(int)($in['type_id']??0);$a=str('start');$b=str('end');$why=mb_substr(str('reason'),0,300);
 $s=db()->prepare("SELECT 1 FROM users WHERE id=? AND role='worker' AND active");$s->execute([$uid]);
 if(!$s->fetchColumn())out(['error'=>'Choose an employee.'],422);
 $days=mh_leave_check($uid,$tid,$a,$b,true);
 db()->prepare("INSERT INTO leave_requests(user_id,type_id,start_date,end_date,days,reason,status,decided_by,decided_at) VALUES(?,?,?,?,?,?,'approved',?,now())")->execute([$uid,$tid,$a,$b,$days,$why,uid()]);
 out(['ok'=>true,'days'=>$days]);
}
function mod_lv_admin_cancel(){
 post();admin();global $in;
 $s=db()->prepare("UPDATE leave_requests SET status='cancelled',decided_by=?,decided_at=now() WHERE id=? AND status IN('pending','approved')");
 $s->execute([uid(),(int)($in['id']??0)]);
 if(!$s->rowCount())out(['error'=>'Nothing to cancel.'],409);
 out(['ok'=>true]);
}
function mod_lv_type_save(){
 post();admin();global $in;
 $id=(int)($in['id']??0);$n=mb_substr(str('name'),0,60);
 if($n==='')out(['error'=>'Enter a name for the leave type.'],422);
 $d=($in['days_per_year']??'')===''?0:$in['days_per_year'];
 if(!is_numeric($d)||$d<0||$d>365||floor((float)$d)!=(float)$d)out(['error'=>'Days per year must be a whole number from 0 to 365 (0 means no limit).'],422);
 $paid=!empty($in['paid'])?'true':'false';$act=!array_key_exists('active',$in)||!empty($in['active'])?'true':'false';
 try{
  if($id){$s=db()->prepare("UPDATE leave_types SET name=?,days_per_year=?,paid=$paid,active=$act WHERE id=?");$s->execute([$n,(int)$d,$id]);if(!$s->rowCount())out(['error'=>'Leave type not found.'],404);}
  else db()->prepare("INSERT INTO leave_types(name,days_per_year,paid,active) VALUES(?,?,$paid,$act)")->execute([$n,(int)$d]);
 }catch(PDOException $x){if($x->getCode()==='23505')out(['error'=>'There is already a leave type with that name.'],409);throw $x;}
 out(['ok'=>true]);
}
function mod_lv_type_delete(){
 post();admin();global $in;
 try{db()->prepare('DELETE FROM leave_types WHERE id=?')->execute([(int)($in['id']??0)]);}
 catch(PDOException $x){if($x->getCode()==='23503')out(['error'=>'This leave type has requests. Switch it off instead of deleting it.'],409);throw $x;}
 out(['ok'=>true]);
}
