<?php
// The notification bell.
// One public static method per action. Routes for this module are listed in routes.php.
class NotificationController{

 /** action: notifs */
 public static function notifs(){
  global $in,$act;
nf_list();
 }
}
