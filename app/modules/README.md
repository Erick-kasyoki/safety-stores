# Modules and controllers

Every request to `api.php?action=NAME` goes through `core/Router.php`, which finds the module that owns `NAME` and calls one method of that module's controller.

| Module | Controller | What it does |
|---|---|---|
| `storefront` | `StorefrontController` | Public shop: site info, products, cart, checkout and the customer’s own orders |
| `accounts` | `AccountController` | Sign-in, sign-up, passwords and profile photos |
| `notifications` | `NotificationController` | The notification bell |
| `catalog` | `CatalogController` | Staff product management: products, store content, best sellers, import / export, departments |
| `orders` | `OrderController` | Staff order handling: list, status, till-payment confirmation, assignment, edit and delete |
| `workers` | `WorkerController` | Admin: manage worker accounts |
| `inventory` | `InventoryController` | Stock levels, adjustments, reorder levels and stock history |
| `invoices` | `InvoiceController` | Invoices, invoice payments and recurring invoices |
| `sales` | `SalesController` | Sales Management: point of sale, receipts and invoices, sales history, returns and refunds, sales reports and CSV export |
| `chat` | `ChatController` | Staff live chat between admins and workers |
| `customers` | `CustomerController` | Admin: manage customer accounts |
| `admins` | `AdminController` | Admin: manage administrator accounts |
| `settings` | `SettingController` | Store settings and the test email |
| (library) expenses, leave | `modules_lib.php` | Expense Tracker and Employee Leave (`x_*`, `lv_*`) |
| (library) quotations | `quotes_lib.php` | Quotations (`q_*`) |

## Adding a feature
1. Make a folder `app/modules/yourmodule/`.
2. Add `YourController.php` with `class YourController{ public static function doThing(){ global $in; post(); staff(); out([...]); } }`.
3. Add `routes.php` returning `['controller'=>'YourController','routes'=>['your_action'=>'doThing']]`.

The router picks the module up automatically. Shared helpers (`out()`, `post()`, `staff()`, `admin()`, `uid()`, `role()`, `str()`, `db()`) live in `bootstrap.php` and the `*_lib.php` files.
