<?php
// Invoices, invoice payments and recurring invoices.
return ['controller'=>'InvoiceController','routes'=>[
  'a_invoices'=>'invoices',
  'a_invoice'=>'invoice',
  'a_inv_save'=>'invSave',
  'a_inv_status'=>'invStatus',
  'a_inv_delete'=>'invDelete',
  'a_inv_pay'=>'invPay',
  'a_inv_unpay'=>'invUnpay',
  'a_inv_from_order'=>'invFromOrder',
  'a_rec_toggle'=>'recToggle',
  'a_rec_delete'=>'recDelete',
]];
