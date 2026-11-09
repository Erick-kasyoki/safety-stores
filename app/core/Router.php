<?php
// Front-controller router. Every folder in app/modules/ is one module: a controller class plus a routes.php that maps
// each "?action=" name to a public static method of that controller. To add a feature, add a module folder (see app/modules/README.md).
class Router{
 private static function table():array{
  $t=[];
  foreach(glob(__DIR__.'/../modules/*/routes.php')?:[] as $f){
   $m=require $f;$dir=dirname($f);
   foreach($m['routes'] as $action=>$method)$t[$action]=[$dir.'/'.$m['controller'].'.php',$m['controller'],$method];
  }
  return $t;
 }
 public static function dispatch(string $act):void{
  // Library modules that route themselves: Expense Tracker + Leave (x_*, lv_*) and Quotations (q_*). They stop the request when they match.
  mod_route($act);
  quote_route($act);
  $t=self::table();
  if(!isset($t[$act]))out(['error'=>'Unknown action.'],404);
  [$file,$class,$method]=$t[$act];
  require_once $file;
  $class::$method();
  out(['error'=>'Unknown action.'],404); // a controller normally ends the request with out()
 }
}
