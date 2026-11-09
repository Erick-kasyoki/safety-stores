<?php
// Staff live chat: one-to-one messages between admins and workers.
return ['controller'=>'ChatController','routes'=>[
  'ch_users'=>'users',
  'ch_msgs'=>'msgs',
  'ch_send'=>'send',
  'ch_clear'=>'clear',
  'ch_file'=>'file',
  'ch_unread'=>'unread',
]];
