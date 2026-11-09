<?php
// M-Pesa (Safaricom Daraja) STK Push helpers. Credentials come from app/secrets.php (or environment variables), never from the
// settings table, so they cannot be read through Admin > Settings. See MPESA-SETUP.md.

function mpesa_ready():bool{return MPESA_CONSUMER_KEY!==''&&MPESA_CONSUMER_SECRET!==''&&MPESA_SHORTCODE!==''&&MPESA_PASSKEY!==''&&MPESA_CALLBACK_SECRET!==''&&mpesa_callback_url()!=='';}
function mpesa_table_ready():bool{
 static $ok=null;if($ok!==null)return $ok;
 try{$ok=(bool)db()->query("SELECT to_regclass('mpesa_payments') IS NOT NULL")->fetchColumn();}catch(Throwable $x){$ok=false;}
 return $ok;
}
function mpesa_base():string{if(MPESA_BASE_URL!=='')return rtrim(MPESA_BASE_URL,'/'); // only for testing against a stand-in server
 return strtolower(MPESA_ENV)==='live'?'https://api.safaricom.co.ke':'https://sandbox.safaricom.co.ke';}
// Safaricom must be able to reach this address over public HTTPS, so SITE_URL (your real https address) is required.
function mpesa_callback_url():string{
 if(MPESA_CALLBACK_URL!=='')return MPESA_CALLBACK_URL;
 return SITE_URL!==''?rtrim(SITE_URL,'/').'/api.php?action=m_callback&k='.rawurlencode(MPESA_CALLBACK_SECRET):'';
}
// 0712345678, 0112345678, +254712345678, 254712345678, 712345678 -> 254712345678. Returns '' when it is not a Kenyan mobile number.
function mpesa_phone(string $p):string{
 $p=preg_replace('/[\s\-().]/','',$p);
 if(preg_match('/^\+?254([17]\d{8})$/',$p,$m)||preg_match('/^0([17]\d{8})$/',$p,$m)||preg_match('/^([17]\d{8})$/',$p,$m))return '254'.$m[1];
 return '';
}
// Daraja only takes whole shillings, so a total such as 1250.50 is asked as 1251 (rounded up; never less than the order total).
function mpesa_amount($total):int{return (int)ceil(round((float)$total,2)-0.00001);}

function mpesa_http(string $method,string $path,?array $body,array $headers):array{
 $ch=curl_init(mpesa_base().$path);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
 if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));}
 $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
 if($raw===false)throw new RuntimeException('M-Pesa request failed: '.$err);
 $j=json_decode((string)$raw,true);
 return[$code,is_array($j)?$j:[],(string)$raw];
}
// OAuth token, cached on disk for ~50 minutes (Daraja tokens last an hour). The cache file sits in the system temp folder, readable only by the web user.
function mpesa_token():string{
 $f=sys_get_temp_dir().'/mpesa_token_'.md5(MPESA_ENV.MPESA_CONSUMER_KEY).'.json';
 $c=@json_decode((string)@file_get_contents($f),true);
 if(is_array($c)&&($c['exp']??0)>time()+60&&!empty($c['t']))return $c['t'];
 [$code,$j,$raw]=mpesa_http('GET','/oauth/v1/generate?grant_type=client_credentials',null,['Authorization: Basic '.base64_encode(MPESA_CONSUMER_KEY.':'.MPESA_CONSUMER_SECRET)]);
 if($code!==200||empty($j['access_token'])){error_log('M-Pesa token failed: HTTP '.$code.' '.substr($raw,0,300));throw new RuntimeException('Could not get an M-Pesa access token. Check the consumer key and secret.');}
 $t=(string)$j['access_token'];$tmp=$f.'.'.bin2hex(random_bytes(4));
 if(@file_put_contents($tmp,json_encode(['t'=>$t,'exp'=>time()+(int)($j['expires_in']??3599)-600]),LOCK_EX)!==false){@chmod($tmp,0600);@rename($tmp,$f);}
 return $t;
}
// Daraja wants Nairobi time (EAT) for the timestamp, whatever timezone the shop displays.
function mpesa_stamp():string{return (new DateTime('now',new DateTimeZone('Africa/Nairobi')))->format('YmdHis');}
function mpesa_password(string $ts):string{return base64_encode(MPESA_SHORTCODE.MPESA_PASSKEY.$ts);}

/** Sends the payment prompt to the customer's phone. Returns Daraja's reply (MerchantRequestID, CheckoutRequestID...). Throws on any failure. */
function mpesa_stk_push(string $phone,int $amount,string $ref,string $desc):array{
 $ts=mpesa_stamp();
 // Till (Buy Goods): BusinessShortCode = your store/head-office number, PartyB = the till. Paybill: both are the paybill number.
 $partyB=MPESA_PARTYB!==''?MPESA_PARTYB:(MPESA_TXN_TYPE==='CustomerBuyGoodsOnline'&&till_no()!==''?till_no():MPESA_SHORTCODE);
 $body=['BusinessShortCode'=>MPESA_SHORTCODE,'Password'=>mpesa_password($ts),'Timestamp'=>$ts,'TransactionType'=>MPESA_TXN_TYPE,'Amount'=>$amount,
  'PartyA'=>$phone,'PartyB'=>$partyB,'PhoneNumber'=>$phone,'CallBackURL'=>mpesa_callback_url(),'AccountReference'=>mb_substr($ref,0,12),'TransactionDesc'=>mb_substr($desc,0,13)];
 [$code,$j,$raw]=mpesa_http('POST','/mpesa/stkpush/v1/processrequest',$body,['Authorization: Bearer '.mpesa_token(),'Content-Type: application/json']);
 if($code!==200||(string)($j['ResponseCode']??'')!=='0'||empty($j['CheckoutRequestID'])){
  error_log('M-Pesa STK push rejected: HTTP '.$code.' '.substr($raw,0,500));
  throw new RuntimeException((string)($j['errorMessage']??$j['ResponseDescription']??'Safaricom did not accept the payment request.'));
 }
 return $j;
}
/** Asks Safaricom what happened to a prompt (used when the callback has not arrived). Returns ['code'=>int|null,'desc'=>string]; code null = still being processed. */
function mpesa_stk_query(string $checkoutId):array{
 $ts=mpesa_stamp();
 [$code,$j,$raw]=mpesa_http('POST','/mpesa/stkpushquery/v1/query',['BusinessShortCode'=>MPESA_SHORTCODE,'Password'=>mpesa_password($ts),'Timestamp'=>$ts,'CheckoutRequestID'=>$checkoutId],['Authorization: Bearer '.mpesa_token(),'Content-Type: application/json']);
 // While the customer is still looking at the prompt Daraja answers with an error body (HTTP 500, "The transaction is being processed"): treat as pending.
 if(!isset($j['ResultCode']))return['code'=>null,'desc'=>(string)($j['errorMessage']??'')];
 return['code'=>(int)$j['ResultCode'],'desc'=>(string)($j['ResultDesc']??'')];
}
function mpesa_fail_text(?int $code,string $desc):string{
 $m=[1=>'Not enough M-Pesa balance.',1032=>'You cancelled the payment request.',1037=>'The request timed out. Please try again.',2001=>'Wrong M-Pesa PIN entered.',1019=>'The request expired. Please try again.',1025=>'The prompt could not be sent. Please try again.',9999=>'The prompt could not be sent. Please try again.'];
 return $m[$code]??($desc!==''?$desc:'The payment was not completed.');
}
