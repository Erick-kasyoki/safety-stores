# Safety store: PHP + PostgreSQL on XAMPP

Folder layout
- `public/` is the ONLY folder visitors can reach: the pages (`index.html`, `admin.html`, `worker.html`), scripts, styles, the `img/` photos and three small entry files (`api.php`, `invoice.php`, `check.php`) that just load the real code.
- `app/` holds all private PHP code and the database login (`config.php`, `secrets.php`). It is never served to browsers.
- `database/` holds the SQL files (`schema.sql`, `migrate_*.sql`, `restrict_postgres.sql`). `docs/` holds an example HTTPS server config. Both are private too.
- Upgrading from the old flat layout? Move your photos from the old `img/` folder into `public/img/` (including `img/uploads/`), then use this new folder.

1. Install PostgreSQL (XAMPP does not bundle it).
2. In `C:\xampp\php\php.ini` uncomment `extension=pdo_pgsql` and `extension=pgsql`, then restart Apache.
3. Create the database. This project uses a new database name, `safety_store`:
   `psql -U postgres -c "CREATE DATABASE safety_store ENCODING 'UTF8' TEMPLATE template0;"`
   `psql -U postgres -d safety_store -f database/schema.sql`
4. Copy `app/secrets.example.php` to `app/secrets.php` and put your database login there. For anything real, first run `database/restrict_postgres.sql` and use the limited `store_app` login it creates (see SECURITY.md). Edit `app/config.php` for the store name, phone, email and currency.
5. Copy this folder to `C:\xampp\htdocs\safety-store` and open http://localhost/safety-store/ (it forwards to `public/`). On a live server make `public/` the web root, so the PHP code in `app/` can never be downloaded (see SECURITY.md).
6. Create the admin: open http://localhost/safety-store/public/admin.html, choose **Create admin account** and sign up. With the default `ADMIN_SIGNUP_CODE=''` in `app/config.php`, only the very first admin can sign up, so do this right after installing. To let more admins sign up later, set a secret code in `ADMIN_SIGNUP_CODE` and give it only to people you trust.
7. In the admin page open the **Workers** tab and add your workers, then tell each one to sign up at `worker.html` (see below).

Who can do what
- **Customers** use the store (`index.html`). They can only create a customer account and sign in there. The store has **no links** to the admin or worker pages, and staff accounts cannot sign in on the store.
- **Admin** goes straight to `admin.html` (keep this address private). The admin can do everything: add, edit and delete products and change prices (one by one, or a % change for a whole department or the whole store with **Change prices in bulk**), rename departments, manage inventory and invoices, **edit or delete any order** (customer details, items, quantities, prices, status, who handles it; stock follows automatically), manage **customers** (edit, set a new password, block, delete), manage **other admins**, manage workers, and change the **store details** (name, phone, email, currency, invoice settings) in the **Settings** tab with no file editing.
  Existing install? The Settings table is created automatically when the database user is allowed to; with the limited `store_app` user, run `database/migrate_settings.sql` once as the postgres user. Just copy the new `app/api.php`, `public/admin.html`, `app/invoice_lib.php` and `app/invoice.php` over the old ones.
- **Workers** are added by the admin in **Admin > Workers** (name, email, phone; no password). Then the worker opens `worker.html`, chooses **Sign up**, enters the email the admin added and picks their own password. Sign-up works only for an email the admin added, and only once. Afterwards the worker signs in at `worker.html`. Workers can: see customer orders, **take** an order, move it along (confirmed, dispatched, delivered, cancelled), and manage stock. They cannot change prices or products, delete anything, see costs or invoices, or manage other workers. The admin can edit a worker, **reset their password**, **switch them off** or delete them. Orders of a deleted worker stay and become unassigned.
- The three areas are separate: a customer cannot use the admin or worker pages, a worker cannot use the admin page, and staff cannot sign in as customers.
- **Orders reach staff straight away.** When a customer places an order, it appears in Admin > Orders and in the worker page. Both pages check for new orders every 15 to 20 seconds, show a notice and a count on the Orders tab, and update the list. Stock goes down at checkout, as before.

Notes
- Prices, descriptions and stock in `database/schema.sql` are samples. Replace them in the admin page.
- Photos: in **Admin > Products > Add / Edit product** press **Upload photo** and pick a JPG, PNG, WebP or GIF (up to 8 MB). It is saved in `img/uploads/` and the store shows exactly that photo (the whole picture, not cropped; the emoji only appears when a product has no photo). You can still type a path such as `img/helmet.jpg` or a web address instead. Replacing or deleting a product removes its uploaded photo file. If uploads fail, allow Apache to write to `img/uploads` and check `upload_max_filesize` / `post_max_size` in `php.ini`.
- **Export**: **Admin > Products > Export** downloads every product as a CSV (name, description, department, price, old_price, stock, reorder_level, sku, unit_cost, emoji, image) that opens in Excel.
- **Import**: **Admin > Products > Import** takes a CSV with those columns (**Download a blank template** shows the format; only name, department and price are required; other column names such as category, qty or photo are recognised too). Products with the same SKU, or the same name and department, are **updated** (blank cells keep the current value) and the rest are **added**; or choose to always add. Bad rows are skipped and listed. Image cells hold a photo path (`img/uploads/abc.jpg`) or web address; upload the photo files to the `img` folder first. Stock changes are logged in the inventory history.
- **Delete all products**: **Admin > Products > Delete all products** removes every product after you type `DELETE ALL`. It offers a CSV backup first. Orders, invoices and stock history stay (their product link is cleared, names are kept). This cannot be undone.
- Orders are paid on delivery or pickup. Online payment needs a payment provider account and is not included.
- Checkout reduces stock; cancelling an order in admin puts the stock back.
- Inventory: open the **Inventory** tab in admin.html. It shows stock value, low and out-of-stock items, and what sold in the last 30 days. For each item you can **Restock** (new stock arrived), **Sell** (sold in your shop, not online), **Count** (set the exact stock after a stock take) and open its **History**. Every change is logged with date, amount and note, including online orders and cancellations.
- Low stock: each item has a *reorder level* (5 by default; edit it right in the Inventory table). At or below it the item is marked Low, at 0 it is Out. **Download reorder list** gives a CSV of what to buy, with a suggested quantity (enough to reach twice the reorder level) and, if you entered a unit cost, the estimated cost.
- Set a unit cost and SKU per product in the product form (both optional). Stock value uses the cost, or the price when no cost is set.
- Already running the older version? Add inventory to your existing database once: `psql -U postgres -d safety_store -f database/migrate_inventory.sql`. Fresh installs get it from schema.sql.
- Already running the older version? Add the roles once to your existing database: `psql -U postgres -d safety_store -f database/migrate_roles.sql`. Your current admin (the account with `is_admin`) stays an admin, and all customers stay customers.
- Windows/XAMPP step-by-step guide: see XAMPP-INSTALL.md. Adding only the new chat to a running shop: double-click update-chat.bat.
- Locked out of admin? Promote an account in SQL: `psql -U postgres -d safety_store -c "UPDATE users SET role='admin' WHERE email='you@example.com';"`
- Sign-in is rate limited: after 8 wrong passwords for an email (or 40 from one address) in 15 minutes, further tries are blocked for a while.
- Backups: `php app/backup.php D:/backups` saves a compressed copy of the database (the newest 30 are kept). Always name a folder outside the web server's folder (with no folder given it refuses when the default would be inside `htdocs`), schedule it daily and copy it off the computer.
- Before going live: work through SECURITY.md (limited database user, `public/` as the web root, HTTPS, backups) and run `check.php`.

Invoices
- Open the **Invoices** tab in admin.html. **+ New invoice** takes the customer, dates, line items (pick products from your store or type custom lines), a discount and a tax rate. Save it as a draft, or save and mark it as sent. In the Orders tab, the **Invoice** button turns an order into a draft invoice in one click.
- Payments: **Record payment** logs each amount received (cash, bank transfer, mobile money…). The status updates by itself: Unpaid, Part paid, Paid, or Overdue once the due date passes. Payments can be removed if you made a mistake. **Void** an invoice instead of deleting it (only drafts can be deleted).
- Branded PDF: **View** opens the invoice page, and **More… > Download PDF** opens your browser's print window. Choose "Save as PDF" as the printer. The page uses your store name, phone, email, address, logo and colour.
- Sending: **More… > Send by email** opens your email program with the message and a private link to the invoice; **Send by WhatsApp** does the same in WhatsApp (it picks the customer's chat directly when their phone is saved with a country code, like +254…). Anyone with the link can view that one invoice. Nothing is sent automatically, because XAMPP has no mail server.
- Repeating invoices: tick **Repeat this invoice automatically** when creating an invoice and choose weekly, monthly, quarterly or yearly. New invoices are created (as sent) on each date. They are created whenever you open the Invoices tab. To have them created even when nobody opens it, schedule `php C:\xampp\htdocs\safety-store\app\cron_invoices.php` to run daily in Windows Task Scheduler (use `C:\xampp\php\php.exe` as the program).
- Settings in `app/config.php`: `SITE_ADDRESS`, `LOGO` (for example `img/logo.png`), `BRAND_COLOR`, `INVOICE_PREFIX`, `TAX_NAME`, `DEFAULT_TAX`, `DUE_DAYS` and `PAY_INFO` (bank or mobile-money details printed on unpaid invoices). All are optional. If you keep your old config.php, copy those lines in.
- Already running the older version? Add invoices to your existing database once: `psql -U postgres -d safety_store -f database/migrate_invoices.sql`.
- Invoices do not change stock; stock changes when an order is placed or in the Inventory tab.

Trouble? Open http://localhost/safety-store/public/check.php. It tests PHP, the PostgreSQL extension, your database login (and that it is a limited user), the tables, and that private files cannot be opened from the web, and says how to fix each. It opens only on the server itself. Delete check.php when done.

Order emails
- Open **Admin > Settings > Order emails**, enter the address that should receive orders (several can be separated by commas) and your mail account's SMTP details, then press **Save and send a test email**. Every new customer order is then emailed to you straight away with the customer's name, phone, email, address, items, total and a link to the admin page. The customer's email is the reply-to address.
- Gmail: host `smtp.gmail.com`, port `587`, security `TLS`, user = your Gmail address, password = a Google **App password** (Google account > Security > 2-step verification > App passwords). Other providers (Zoho, Outlook, your hosting's mail) work the same way with their own host and port.
- The email is sent after the customer's order is saved, so a mail problem never blocks an order; failures are written to the Apache error log. If the test fails, the message says why. On XAMPP/Windows a TLS error usually means `openssl.cafile` is not set in `php.ini`; try SSL on port 465 instead.
- The SMTP password is stored in the database settings table and is never shown again in admin.

## Expense Tracker and Employee Leave Management
Both are new tabs. Existing install? Run `psql -U postgres -d safety_store -f database/migrate_expenses_leave.sql` once (the app also tries to create the tables itself when its database user is allowed to), then copy over `app/` and `public/`. New installs get everything from `schema.sql`.

**Admin > Expenses** (admin only)
- **+ Add expense**: date, category, amount, what it was for, who was paid, how it was paid and a receipt number. Edit or delete any expense.
- **Categories & budgets**: eight starter categories (Rent, Salaries, Utilities...). Add your own, rename them, and set a monthly budget for each (0 = no budget). A category that has expenses cannot be deleted.
- Pick any month to see total spent, the budget left, sales and net (sales minus expenses), spending per category with a bar that turns amber at 80% of the budget and red when over, and a 6-month trend. Sales are taken from the stock log (online orders and in-store sales, minus cancelled orders), so record in-store sales in the Inventory tab. Net does not subtract the cost of the stock.
- **Export this month / Export all** download a CSV that opens in Excel.

**Leave**
- Employees are the **worker accounts**. Workers open `worker.html` > **Leave**: they see how many days they have left for each leave type, press **+ Request leave**, and see the answer (with the admin's note). They can cancel a waiting request, or an approved one that has not started.
- **Admin > Leave**: a count shows on the tab when requests are waiting (the admin is also emailed if order emails are set up in Settings). **Approve** or **Reject** each request (a rejection asks for a reason the employee will see). The page also shows who is away now and in the next 60 days, the days each employee has used this year, and the full history with filters. **+ Record leave** adds leave for an employee directly (already approved, past dates allowed), and **Leave types** edits the allowances.
- Starter types: Annual (21 days), Sick (14), Compassionate (5), Unpaid (no limit). Days per year of 0 means no limit. A request counts in the year its first day falls in.
- Only working days count. Sunday is a day off by default. To change it add to `app/secrets.php`: `define('LEAVE_OFF_DAYS','67');` (Saturday and Sunday; 1 = Monday ... 7 = Sunday). The time zone used for "today" is Africa/Nairobi; change it with `define('TIMEZONE','Africa/Kampala');`.
- A request is refused if it overlaps other leave of the same person, starts in the past (workers only), or is more than the days left. Approving re-checks the balance.

## Latest changes
- Stock colours: green = In stock, amber = Low, red = Out of stock (shop, Admin > Products, Admin/Worker > Stock). The Low/Out chips and tiles filter the stock list.
- **What to stock now**: the Stock tab (admin and worker) lists what to order, worked out from the last 30 days of sales, with days of stock left and a suggested order size. Items selling fast are flagged before they run low.
- Workers now have an **Invoices** tab: create and edit invoices, mark as sent, record payments, void drafts. Repeating invoices and removing payments stay admin-only.
- The logo animates while pages load (shop, admin, worker) and shows on the sign-in screens and on invoices.

## Quotations, Best Sellers and Contact numbers (latest)
- **Quotes tab** (admin and workers): **+ New quotation** takes the customer, subject, dates (valid for 30 days by default), lines picked from your products or typed, discount, tax, notes and terms. Save as a draft or **Save & mark as sent**. **View** opens a branded page (**Download PDF** uses Print > Save as PDF). **Send by email / WhatsApp** shares a private link; the customer can **Accept or Decline** on that page and the status updates by itself (with an email to you if SMTP is set up). **Make invoice** creates a draft invoice from the quotation. **Duplicate** copies it as a new draft. Statuses: Request, Draft, Sent, Accepted, Declined, Expired (shown automatically after the valid-until date), Void.
- **Requests from the store**: *Request a Quote* (header button, quote banner and the Bulk & Corporate trust tile) still opens WhatsApp and now also saves the request in the Quotes tab as **Request**; the tab shows a count. Press **Price it** to add prices, then send it.
- Quotation number prefix, validity days and default terms are in **Admin > Settings > Quotations**.
- **Best Sellers**: in **Admin > Products** press **☆ Add** on up to 8 products. The store shows exactly those, read fresh on every visit (and refreshed every 5 minutes while open). With none chosen it shows the last 30 days' top sellers automatically.
- **Contact Us**: the second number (+254 746 525398) is in the footer, the Contact Us pop-up and on quotations; change it in **Admin > Settings > Second phone**.
- Existing install? Run once as postgres: `psql -U postgres -d safety_store -f database/migrate_quotes.sql` (the app also tries this itself when its database user is allowed to). Then copy over `app/`, `public/` and `database/`.

## Website tab (admin)
**Admin > Website** edits what visitors see on the store's front page: banner headline, buttons and photos, the four highlight tiles, section headings, the quote banner, opening hours, WhatsApp number, footer text and the About / Shipping / Returns / Payment / Bulk pop-ups. Photos can be uploaded right there. Saved changes show on the next store visit; **Reset all** goes back to the built-in text. Other store content is edited elsewhere: products, prices, departments and Best Sellers in **Products**, phones, email, address and shop locations in **Settings**.

## Notifications (bell) and the new profile pill
The store, worker and admin headers now show a pill: **bell | name ▾ | initials**. The bell opens the notifications; the name opens the account menu.
- **Customers and visitors** see new products the admin adds, and (when signed in) updates on their own orders: received, confirmed and being prepared, a team member working on it, on its way, delivered or cancelled.
- **Workers** see every order and staff event: new orders, status changes, who took or was given an order, quotation requests and replies, invoice payments, out-of-stock items, plus things addressed to them (an order assigned to them, a leave decision).
- **Admins** see everything above plus new customers, workers signing up, leave requests and recorded expenses.
- Clicking a notification opens the right place (the Orders, Quotes, Invoices, Inventory, Leave, Expenses, Workers or Customers tab; My orders; or a product search). Unread items are highlighted and counted on the bell; closing the panel or pressing *Mark all as read* clears the count. A small pop-up also appears when something new arrives while the page is open (checked every 30 seconds).
- Existing install? Run once as postgres: `psql -U postgres -d safety_store -f database/migrate_notifications.sql` (the app also tries this itself when its database user is allowed to). Old notifications are cleared automatically after 90 days.

## Profile photos (dp)
- **Register / sign-up forms** (store customers, worker sign-up, admin sign-up) have an optional **Profile photo** picker with a round preview. The photo is saved right after the account is created.
- Anyone signed in can **Add / Change / Remove profile photo** from their account menu (the name in the pill). The photo replaces the initials in the pill.
- Photos are checked (JPG, PNG, WebP or GIF, up to 8 MB), cropped to a square and shrunk to 320 px when PHP's GD extension is on (it is by default in XAMPP), and saved in `public/img/uploads/avatars/`. Replacing or removing a photo deletes the old file.
- Existing install? Run once as postgres: `psql -U postgres -d safety_store -f database/migrate_photos.sql` (the app also adds the column itself when its database user is allowed to).


## Code structure (modules and controllers)
`app/api.php` is now only the front controller. It loads `app/bootstrap.php` (shared helpers, session, database) and passes the request to `app/core/Router.php`,
which calls the right controller in `app/modules/`. Each module is a folder with a controller class and a `routes.php`
(storefront, accounts, notifications, catalog, orders, workers, inventory, invoices, customers, admins, settings).
See `app/modules/README.md` for the list and how to add a new module. **When updating an existing server, copy the whole `app/` folder, not just `api.php`.**

Sales Management (Sales tab in admin.html and worker.html)
- Existing install? Run once as the postgres user: `psql -U postgres -d safety_store -f database/migrate_sales.sql`, then copy over `app/` and `public/`. Fresh installs get it from schema.sql. `check.php` reports whether the sales tables exist.
- **New sale**: search by name or SKU (a barcode scanner that types the SKU and presses Enter adds the item at once), set quantities and discounts, then choose Fully paid / Part paid / Unpaid and the method (M-Pesa, Cash, Bank transfer, Card). **Review sale** shows a confirmation screen; **Confirm** records the sale, takes the payment and deducts stock in one database transaction. Each sale gets a receipt number (RCT-000001, prefix `SALE_PREFIX`). Sending the same sale twice (double click, retry) never deducts stock twice.
- Stock: sales appear in the stock history as "Sold in store" and count in the Inventory 30-day figures. A sale for more than the stock is blocked; an **administrator** can tick the override (stock stops at 0 and the shortfall is written in the audit trail).
- **Receipts and invoices**: open any sale > Receipt / Invoice > Print / Save as PDF (browser print window), or share the summary on WhatsApp. Reprinting never records a new sale.
- **Part-paid and unpaid sales**: open the sale > Add payment to record later payments.
- **Returns and cancellations** (admin only): Return / refund lets you pick items, choose whether they go back in stock (untick for damaged goods) and refund up to what was paid. Cancel sale returns everything to stock and refunds the money. Sales are never deleted; every step is in the sale's audit trail.
- **Reports**: Today, Yesterday, This week, This month or custom dates. Gross sales, discounts, tax, returns, net sales (total charged minus returned goods), refunds paid, transactions, units, outstanding balances, top sellers, daily chart, payment methods and (admin) cashiers. CSV downloads open in Excel. Workers see only their own sales and totals; admin sees everything. The admin dashboard shows today's sales, monthly revenue, transactions and top sellers.

Staff live chat (💬 button, bottom right of admin.html and worker.html)
- Existing install? Run once as the postgres user: `psql -U postgres -d safety_store -f database/migrate_chat.sql`.
- Admins and workers pick anyone from the list of active staff (admin or worker) and chat one to one. New messages appear within a few seconds; unread counts show on the button and next to each name. Only staff who have signed up and are switched on appear. Messages are kept in the database.
- Look: dark navy panel, the sender's name above each message, System notes, and an Attach / message box / Send bar at the bottom (Enter also sends; Send stays dim until you type or attach something).
- Attach: images, PDF, Word, Excel, CSV or text files up to 5 MB. Files are stored in `app/chat_files` (never served directly) and only the two people in that chat can open them. Needs the updated `database/migrate_chat.sql` (adds the file columns); run it again once, it is safe to repeat.


## M-Pesa payments
Online M-Pesa (STK Push) is built in: see [MPESA-SETUP.md](MPESA-SETUP.md) and run `database/migrate_mpesa.sql` once.
