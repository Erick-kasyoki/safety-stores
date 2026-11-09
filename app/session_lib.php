<?php
// Secure session + HTTPS rules shared by api.php and invoice.php.
function is_https(){return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');}
function is_local_host(){$h=strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));return in_array($h,['localhost','127.0.0.1','[::1]'],true);}
// On a live server (anything that is not localhost) refuse plain HTTP: logins and orders must never travel unencrypted.
function require_https(){
 if(PHP_SAPI==='cli'||is_https()||is_local_host()||!filter_var(FORCE_HTTPS,FILTER_VALIDATE_BOOLEAN))return;
 http_response_code(426);header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['error'=>'This site must be opened with https://']);exit;
}
const SESSION_IDLE=14400; // sign out after 4 hours without activity
const SESSION_REMEMBER=2592000; // "Remember me" ticked: stay signed in for 30 days (renewed each visit)
function secure_session(){
 require_https();
 $https=is_https();
 ini_set('session.use_strict_mode','1');      // refuse session ids the server did not create
 ini_set('session.use_only_cookies','1');     // never accept the id from the URL
 ini_set('session.use_trans_sid','0');
 ini_set('session.cookie_httponly','1');      // JavaScript cannot read the cookie
 ini_set('session.cookie_secure',$https?'1':'0');
 ini_set('session.cookie_samesite','Lax');
 if(PHP_VERSION_ID<80400){ini_set('session.sid_length','48');ini_set('session.sid_bits_per_character','6');} // PHP 8.4+ deprecates these two settings; its default ids are already 128-bit random
 ini_set('session.gc_maxlifetime',(string)SESSION_REMEMBER); // the server keeps session files long enough for "Remember me"; the 4-hour rule below still applies to everyone else
 session_name($https?'__Host-store':'store_sid'); // __Host- cookies are HTTPS-only and locked to this exact site
 session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
 session_start();
 $lim=empty($_SESSION['_rem'])?SESSION_IDLE:SESSION_REMEMBER;
 if(isset($_SESSION['_t'])&&time()-$_SESSION['_t']>$lim){$_SESSION=[];session_regenerate_id(true);}
 $_SESSION['_t']=time();
 if(!empty($_SESSION['_rem']))remember_cookie(); // keep a remembered sign-in alive while the person keeps visiting
}

// "Remember me": the session cookie normally ends when the browser closes. When the box is ticked it lasts 30 days instead.
function remember_cookie(){setcookie(session_name(),session_id(),['expires'=>time()+SESSION_REMEMBER,'path'=>'/','secure'=>is_https(),'httponly'=>true,'samesite'=>'Lax']);}
// Call right after a successful sign-in or sign-up (after session_regenerate_id). Reads "remember" from the JSON body.
function remember_session(){
 $on=filter_var($GLOBALS['in']['remember']??false,FILTER_VALIDATE_BOOLEAN);
 $_SESSION['_rem']=$on?1:0;
 if($on)remember_cookie();
}
