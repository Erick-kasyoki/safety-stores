<?php
// Staff order handling: list, status, till-payment confirmation, assignment, edit and delete.
return ['controller'=>'OrderController','routes'=>[
  'a_orders'=>'orders',
  'a_status'=>'status',
  'a_paid'=>'paid',
  'a_assign'=>'assign',
  'a_counts'=>'counts',
  'a_order_save'=>'orderSave',
  'a_order_items'=>'orderItems',
  'a_order_delete'=>'orderDelete',
]];
