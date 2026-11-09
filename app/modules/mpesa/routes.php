<?php
// M-Pesa STK Push: send the payment prompt, poll its result, and receive Safaricom's callback.
return ['controller'=>'MpesaController','routes'=>[
  'm_pay'=>'pay',
  'm_status'=>'status',
  'm_callback'=>'callback',
]];
