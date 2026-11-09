<?php
// Database backup. Run from the command line ONLY (the web server refuses it):
//   php app/backup.php [folder]
// Writes safety_store-YYYY-MM-DD-HHMM.sql.gz to the folder (default: a safety-store-backups folder next to the project folder, refused when that is inside the web server's folder, as it is with XAMPP's htdocs) and keeps the newest 30.
// Choose a folder OUTSIDE the web server's folder, for example:  php app/backup.php D:/backups   (a backup holds customer names, phones, addresses and password hashes).
// Needs pg_dump (installed with PostgreSQL). Schedule it daily (Windows Task Scheduler or cron), then COPY the folder off-site.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Run this from the command line.');}
require __DIR__.'/config.php';
$dir=$argv[1]??dirname(__DIR__,2).'/safety-store-backups';
$inWeb=(bool)preg_match('#/(htdocs|public_html|www|html|wwwroot)(/|$)#i',str_replace('\\','/',$dir));
if($inWeb&&!isset($argv[1]))exit("The default backup folder ($dir) looks like it is inside the web server's folder, so a backup could be downloaded by anyone. Choose a folder outside it, for example: php app/backup.php D:/backups\n");
if(!is_dir($dir)&&!mkdir($dir,0700,true))exit("Cannot create $dir\n");
// Belt and braces: if this folder is ever inside a web folder, the web server still refuses to serve it.
foreach(['.htaccess'=>"Require all denied\n",'index.html'=>''] as $__n=>$__c)if(!is_file(rtrim($dir,'/\\').'/'.$__n))@file_put_contents(rtrim($dir,'/\\').'/'.$__n,$__c);
if($inWeb)echo "WARNING: $dir looks like it is inside the web server's folder. Use a folder outside it, for example: php app/backup.php D:/backups\n";
$file=rtrim($dir,'/\\').'/'.DB_NAME.'-'.date('Y-m-d-Hi').'.sql.gz';
putenv('PGPASSWORD='.DB_PASS);
$cmd='pg_dump -h '.escapeshellarg(DB_HOST).' -p '.escapeshellarg(DB_PORT).' -U '.escapeshellarg(DB_USER).' --no-owner --no-acl '.escapeshellarg(DB_NAME);
$p=popen($cmd,'r');if(!$p)exit("Could not start pg_dump. Is it on your PATH?\n");
$gz=gzopen($file,'wb9');while(!feof($p))gzwrite($gz,fread($p,65536));gzclose($gz);
if(pclose($p)!==0||filesize($file)<200){@unlink($file);exit("Backup FAILED (pg_dump error). Nothing was saved.\n");}
$all=glob(rtrim($dir,'/\\').'/'.DB_NAME.'-*.sql.gz');rsort($all);foreach(array_slice($all,30) as $old)@unlink($old);
echo "Backup saved: $file (".round(filesize($file)/1024)." KB)\n";
