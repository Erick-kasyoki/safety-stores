<?php
// API front controller: boots the app, then hands the request to the module that owns the action (see core/Router.php and modules/).
require __DIR__.'/bootstrap.php';
require __DIR__.'/core/Router.php';
Router::dispatch($act);
