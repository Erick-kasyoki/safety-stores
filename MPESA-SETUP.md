# M-Pesa (STK Push) setup

Customers can now pay for an order with M-Pesa: a payment prompt appears on their phone, they enter their PIN, and the order is marked **Paid** automatically (the same `Paid` flag staff set by hand today, so dispatch rules are unchanged). The manual "Confirm payment" button and the till-number message still work as a fallback.

Where it shows up
- **Checkout:** a new option "M-Pesa (pay now on your phone)" (only appears once M-Pesa is set up).
- **My orders:** unpaid orders get a "Pay KSh … with M-Pesa" button.
- **Staff (admin / worker):** the order shows the M-Pesa code; staff get a notification when a payment arrives, or when one needs review.

## 1. Run the database update (once, as the postgres user)
    psql -U postgres -d safety_store -f database/migrate_mpesa.sql

## 2. Get Daraja credentials (https://developer.safaricom.co.ke)
1. Create an account, create an app, enable **Lipa na M-Pesa Sandbox**. Copy the **Consumer Key** and **Consumer Secret**.
2. Sandbox: the test **shortcode** and **passkey** are on the "Lipa na M-Pesa Online" simulator page.
3. Going live: apply for Go Live in the portal with your till/paybill. For a **till (Buy Goods)** you receive a *store number* (head office) plus the till number. Safaricom emails the live **passkey**.

## 3. Put them in `app/secrets.php` (never in a public folder, never commit it)
    define('SITE_URL','https://your-domain.co.ke');        // required: Safaricom calls back to this address
    define('MPESA_ENV','sandbox');                           // 'live' when ready
    define('MPESA_CONSUMER_KEY','...');
    define('MPESA_CONSUMER_SECRET','...');
    define('MPESA_SHORTCODE','...');                         // till: STORE number; paybill: the paybill
    define('MPESA_PASSKEY','...');
    define('MPESA_TXN_TYPE','CustomerBuyGoodsOnline');       // paybill: 'CustomerPayBillOnline'
    define('MPESA_PARTYB','');                               // till number; '' = use Admin > Settings > M-PESA Till number
    define('MPESA_CALLBACK_SECRET','<32+ random characters>'); // php -r "echo bin2hex(random_bytes(24));"
Environment variables of the same names also work. Until all of key, secret, shortcode, passkey, callback secret and SITE_URL are set, the M-Pesa option stays hidden and the shop behaves exactly as before.

## 4. Callback address
Safaricom posts the result to `https://your-domain.co.ke/api.php?action=m_callback&k=<MPESA_CALLBACK_SECRET>` (built automatically). Requirements:
- It must be public **HTTPS** with a valid certificate (a localhost / XAMPP install cannot receive it; use a live server or a tunnel such as ngrok and set `SITE_URL` to the tunnel address).
- Requests without the right `k` are rejected. Keep the secret private; change it if it leaks.
- If a callback is ever missed, the customer's page asks Safaricom directly for the result after ~15 seconds, so the order still gets marked paid.

## 5. Test in the sandbox
1. Place an order, choose M-Pesa, enter a sandbox test phone number, approve in the Daraja simulator, and check the order turns **Paid**.
2. Cancel a prompt and enter a wrong PIN to see the error messages.
3. Only then switch `MPESA_ENV` to `live` with the live credentials, and test with a small real order.

## How safety is handled
- Amounts come from the order in the database, never from the browser. Totals with cents are rounded **up** to whole shillings (M-Pesa limitation).
- A customer can only pay their own orders; a double click does not send two prompts; at most 5 prompts per order per 15 minutes.
- Callbacks are processed once. If money arrives for a cancelled or already-paid order, for less than the total, or with a repeated M-Pesa code, the order is **not** changed and staff get a "needs review" notification (flagged `needs_review` in `mpesa_payments`) so you can refund or apply it by hand.
- Refunds are not automatic: use your M-Pesa portal (reversal) as before.
- All attempts are kept in the `mpesa_payments` table (phone, amount, status, M-Pesa code).
