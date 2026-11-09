# Install on XAMPP (Windows), step by step

Fresh install and upgrade of the chat both covered. Takes about 10 minutes.

## A. First time install
1. **Install PostgreSQL** (XAMPP does not include it). Remember the password you give the `postgres` user.
2. **Turn on the PostgreSQL extension for PHP.** Open `C:\xampp\php\php.ini`, remove the `;` in front of
   `extension=pdo_pgsql` and `extension=pgsql`, save. In the XAMPP Control Panel press **Stop** then **Start** on Apache.
3. **Copy the folder.** Put the whole `safety-store` folder in `C:\xampp\htdocs\` so you have `C:\xampp\htdocs\safety-store\public\admin.html`.
4. **Create the database.** Open **SQL Shell (psql)** from the Start menu (or `cmd`, then `cd "C:\Program Files\PostgreSQL\16\bin"`) and run:

       psql -U postgres -c "CREATE DATABASE safety_store ENCODING 'UTF8' TEMPLATE template0;"
       psql -U postgres -d safety_store -f C:/xampp/htdocs/safety-store/database/schema.sql

   `schema.sql` already includes the chat and the Attach button, so you do not need any migrate file on a fresh install.
5. **Configure database credentials.** Copy `app\secrets.example.php` to `app\secrets.php`, then set `DB_USER` and `DB_PASS`
   to your database login. For a live server, use the limited `store_app` account described in `SECURITY.md`, not `postgres`.
6. **Check it.** Open http://localhost/safety-store/public/check.php and fix any red line (each one says how).
7. **Create the admin.** Open http://localhost/safety-store/public/admin.html > **Create admin account**. Do this first, right after installing.
8. **Add workers** in Admin > Workers; each worker then signs up at http://localhost/safety-store/public/worker.html.

Addresses: shop `http://localhost/safety-store/`, admin `.../public/admin.html`, worker `.../public/worker.html`.

## B. You already run the shop: add the new chat
1. Copy over these from the new folder: `public\` (all of it), `app\modules\chat\`, `app\check.php`, `database\migrate_chat.sql`.
2. Double-click **`update-chat.bat`** (asks for the postgres password once). It adds the Attach columns. Safe to run twice.
3. Press **Ctrl + F5** on the admin and worker pages.

## C. Using the chat on one computer
A browser keeps ONE login per website. To test admin and worker together use two different browsers (for example Chrome and Edge),
or a normal window and a private window. Two tabs of the same browser share the login.

## D. Troubleshooting
- **"Attachments need a database update"**: run `update-chat.bat`.
- **"Could not create the app/chat_files folder"**: right-click `C:\xampp\htdocs\safety-store\app` > Properties > Security and allow the Apache user (or "Users") to write.
- **Big files fail**: in `php.ini` set `upload_max_filesize=8M` and `post_max_size=10M`, restart Apache. Chat files are limited to 5 MB.
- **Blank page / "Connection failed"**: PostgreSQL service is not running, or the port is 5433 (set `DB_PORT` in `app\secrets.php`).
