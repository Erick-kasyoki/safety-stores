<?php
// Branded invoice page. Opened with its private link: invoice.php?t=<token>
// Add &print=1 to open the print dialog straight away (choose "Save as PDF" as the printer).
require __DIR__.'/config.php';
require __DIR__.'/invoice_lib.php';
require __DIR__.'/session_lib.php';
require_https();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$t=(string)($_GET['t']??'');
$inv=preg_match('/^[a-f0-9]{32}$/',$t)?inv_load(db(),'x.token=?',$t):null;
if(!$inv){http_response_code(404);echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice not found</title><body style="font:16px system-ui;max-width:480px;margin:15vh auto;padding:0 16px"><h1>Invoice not found</h1><p>This link is not valid. Please ask for a new one.</p>';exit;}

$cur=cfg('CURRENCY','$');
$brand=preg_match('/^#[0-9a-f]{6}$/i',cfg('BRAND_COLOR','#12385f'))?cfg('BRAND_COLOR','#12385f'):'#12385f';
[$r,$g,$b]=sscanf($brand,'#%02x%02x%02x');
$on=(0.299*$r+0.587*$g+0.114*$b)>160?'#1a1a1a':'#ffffff'; // readable text on the brand colour
$logo=cfg('LOGO','img/logo.png');if(!preg_match('#^(https?://\S+|[\w./-]+)$#',$logo))$logo='';
$m=fn($n)=>$cur.number_format((float)$n,2);
$qty=fn($n)=>rtrim(rtrim(number_format((float)$n,2,'.',''),'0'),'.');
$d=fn($s)=>(new DateTime($s))->format('j M Y');
$taxName=cfg('TAX_NAME','Tax');
$stamp=['draft'=>'DRAFT','void'=>'VOID','paid'=>'PAID','overdue'=>'OVERDUE','partial'=>'PART PAID','unpaid'=>''][$inv['pstatus']]??'';
$stampClass=in_array($inv['pstatus'],['paid'],true)?'ok':(in_array($inv['pstatus'],['overdue','void'],true)?'bad':'');
$line=fn($i)=>(int)round($i['qty']*$i['unit_price']*100)/100;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<link rel="icon" type="image/png" href="img/favicon.png?v=3"><link rel="shortcut icon" href="favicon.ico?v=3"><link rel="apple-touch-icon" href="img/apple-touch-icon.png?v=3">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Invoice <?= h($inv['number']) ?> · <?= h(cfg('SITE_NAME','Store')) ?></title>
<style>
:root{--brand:<?= h($brand) ?>;--on:<?= h($on) ?>;--tx:#222;--mu:#666;--bd:#ddd}
*{box-sizing:border-box}
body{margin:0;background:#eef0f3;color:var(--tx);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.bar{max-width:820px;margin:16px auto 0;padding:0 16px;display:flex;gap:10px;justify-content:flex-end}
.bar button,.bar a{border:0;border-radius:999px;padding:10px 20px;font:inherit;font-weight:700;background:var(--brand);color:var(--on);cursor:pointer;text-decoration:none}
.bar a.alt{background:#fff;color:#222;border:1px solid var(--bd)}
.sheet{position:relative;max-width:820px;margin:16px auto;background:#fff;box-shadow:0 2px 14px rgba(0,0,0,.12);overflow:hidden}
.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding:28px 36px;background:var(--brand);color:var(--on)}
.top img{max-height:64px;max-width:200px;display:block;margin-bottom:8px;background:#fff;border-radius:6px;padding:4px}
.top h1{margin:0;font-size:24px;letter-spacing:-.3px}.top small{display:block;opacity:.9;margin-top:2px}
.top .ti{text-align:right}.top .ti b{display:block;font-size:30px;letter-spacing:2px}
.body{padding:28px 36px 36px}
.cols{display:flex;flex-wrap:wrap;gap:24px 40px;justify-content:space-between;margin-bottom:24px}
.cols h2{margin:0 0 4px;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:var(--mu)}
.cols p{margin:0;white-space:pre-line}
table{width:100%;border-collapse:collapse}
th{background:var(--brand);color:var(--on);text-align:left;padding:9px 10px;font-size:12px;letter-spacing:.4px}
td{padding:10px;border-bottom:1px solid var(--bd);vertical-align:top}
.n{text-align:right;white-space:nowrap}
.tot{width:min(340px,100%);margin:18px 0 0 auto}
.tot div{display:flex;justify-content:space-between;padding:5px 10px}
.tot .g{font-weight:800;font-size:16px;border-top:2px solid var(--brand);margin-top:4px;padding-top:9px}
.tot .due{background:var(--brand);color:var(--on);font-weight:800;font-size:17px;border-radius:6px;margin-top:8px;padding:10px}
.sec{margin-top:26px}.sec h2{margin:0 0 6px;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:var(--mu)}
.sec p{margin:0;white-space:pre-line}
.pay td{padding:6px 10px;font-size:13px}
.sum{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:16px 24px}
.sum .tot{margin:18px 0 0 auto}
.stamp{align-self:center;margin:26px 0 0 12px;transform:rotate(-8deg);border:4px solid #999;color:#999;font-weight:900;font-size:32px;letter-spacing:3px;padding:2px 16px;border-radius:8px;opacity:.6;white-space:nowrap}
.stamp.ok{border-color:#1b8a3a;color:#1b8a3a}.stamp.bad{border-color:#c4161c;color:#c4161c}
.foot{margin-top:32px;padding-top:12px;border-top:1px solid var(--bd);color:var(--mu);font-size:12px;text-align:center}
@media(max-width:620px){.top,.body{padding-left:18px;padding-right:18px}.top{flex-direction:column}.top .ti{text-align:left}.stamp{font-size:24px;margin-top:8px}.ow{overflow-x:auto}}
@media print{@page{size:A4;margin:0}body{background:#fff}.bar{display:none}.sheet{margin:0;max-width:none;box-shadow:none}tr{break-inside:avoid}}
</style></head><body>
<div class="bar"><a class="alt" href="./">Back to store</a><button type="button" onclick="window.print()">Download PDF / Print</button></div>
<main class="sheet">
<div class="top">
 <div><?php if($logo): ?><img src="<?= h($logo) ?>" alt="" onerror="this.remove()"><?php endif ?>
  <h1><?= h(cfg('SITE_NAME','Store')) ?></h1>
  <?php foreach([cfg('SITE_ADDRESS',''),cfg('SITE_PHONE',''),cfg('SITE_EMAIL','')] as $x): if($x!==''): ?><small><?= h($x) ?></small><?php endif; endforeach ?>
 </div>
 <div class="ti"><b>INVOICE</b><small><?= h($inv['number']) ?></small></div>
</div>
<div class="body">
 <div class="cols">
  <div><h2>Bill to</h2><p><b><?= h($inv['customer_name']) ?></b><?php foreach(['customer_address','customer_phone','customer_email'] as $k) if($inv[$k]!=='') echo "\n".h($inv[$k]); ?></p></div>
  <div><h2>Issued</h2><p><?= h($d($inv['issue_date'])) ?></p></div>
  <div><h2>Due</h2><p><?= h($d($inv['due_date'])) ?></p></div>
  <?php if($inv['order_id']): ?><div><h2>Order</h2><p>#<?= (int)$inv['order_id'] ?></p></div><?php endif ?>
 </div>
 <div class="ow"><table>
  <tr><th>Description</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Amount</th></tr>
  <?php foreach($inv['items'] as $i): ?>
  <tr><td><?= h($i['description']) ?></td><td class="n"><?= h($qty($i['qty'])) ?></td><td class="n"><?= h($m($i['unit_price'])) ?></td><td class="n"><?= h($m($line($i))) ?></td></tr>
  <?php endforeach ?>
 </table></div>
 <div class="sum">
 <?php if($stamp): ?><div class="stamp <?= $stampClass ?>"><?= h($stamp) ?></div><?php endif ?>
 <div class="tot">
  <div><span>Subtotal</span><span><?= h($m($inv['subtotal'])) ?></span></div>
  <?php if($inv['discount']>0): ?><div><span>Discount</span><span>−<?= h($m($inv['discount'])) ?></span></div><?php endif ?>
  <?php if($inv['tax_rate']>0): ?><div><span><?= h($taxName) ?> (<?= h($qty($inv['tax_rate'])) ?>%)</span><span><?= h($m($inv['tax'])) ?></span></div><?php endif ?>
  <div class="g"><span>Total</span><span><?= h($m($inv['total'])) ?></span></div>
  <?php if($inv['paid']>0): ?><div><span>Paid</span><span>−<?= h($m($inv['paid'])) ?></span></div><?php endif ?>
  <?php if($inv['status']==='sent'||$inv['status']==='draft'): ?><div class="due"><span>Balance due</span><span><?= h($m($inv['balance'])) ?></span></div><?php endif ?>
 </div>
 </div>
 <?php if($inv['payments']): ?>
 <div class="sec"><h2>Payments received</h2><table class="pay">
  <?php foreach($inv['payments'] as $p): ?><tr><td><?= h($d($p['paid_on'])) ?></td><td><?= h($p['method']) ?><?= $p['note']!==''?' · '.h($p['note']):'' ?></td><td class="n"><?= h($m($p['amount'])) ?></td></tr><?php endforeach ?>
 </table></div>
 <?php endif ?>
 <?php if($inv['notes']!==''): ?><div class="sec"><h2>Notes</h2><p><?= h($inv['notes']) ?></p></div><?php endif ?>
 <?php if(cfg('PAY_INFO','')!==''&&$inv['status']!=='void'&&$inv['balance']>0): ?><div class="sec"><h2>How to pay</h2><p><?= h(cfg('PAY_INFO','')) ?></p></div><?php endif ?>
 <div class="foot">Thank you for your business.</div>
</div>
</main>
<?php if(!empty($_GET['print'])): ?><script>addEventListener('load',()=>setTimeout(()=>print(),400))</script><?php endif ?>
</body></html>
