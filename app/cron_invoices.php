<?php
// Creates any recurring invoices that are due today. Run it once a day (see README). Command line only.
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Run this from the command line.\n");}
require __DIR__.'/config.php';
require __DIR__.'/invoice_lib.php';
echo run_recurring(db())." invoice(s) created.\n";
