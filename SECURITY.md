# Security guide

The five hardening items: what is already built in, and what only you can do on your own server.

After installing, open **http://localhost/safety-store/public/check.php** on the server itself. It tests the database user, the tables and whether private files can be opened from the web, and shows a **Fix** line for anything wrong. Delete `check.php` when you are done.

## 1. Prevent access to private files
Built in:
- Only `public/` is meant to be served. `app/`, `database/` and `docs/` each have a `.htaccess` that denies all access. The top-level `.htaccess` also hides `.sql`, `.md`, `.ini`, `.log`, `.bak`, `.sh` and `.bat` files when the whole folder is served (XAMPP).
- `public/.htaccess` hides every PHP file except `api.php`, `invoice.php`, `quote.php` and `check.php`, plus dotfiles and backup, log and SQL files, and turns directory listing off.
- Uploaded photos go to `public/img/uploads/`, where scripts cannot run. Only real JPG, PNG, WebP or GIF images are accepted, and they get random names.
- `check.php` opens only on the server itself (`http://localhost/...`).
- `php app/backup.php` refuses to use its default folder when that is inside the web server's folder (as it is with XAMPP's `htdocs`), warns if you pass such a folder yourself, and puts a deny-all `.htaccess` in the backup folder. Backups hold customer names, phones, addresses and password hashes, so always name a folder outside the web folder, for example `php app/backup.php D:/backups`.

Test: `check.php` runs the "Private files must not open from the web" list for you. By hand, open `/app/config.php`, `/database/schema.sql` and `/SECURITY.md`: each must show Forbidden or Not Found, never code or text.

## 2. Secure PHP session settings
Built in (`app/session_lib.php`, used by `api.php` and `invoice.php`): strict mode (the server refuses session ids it did not create), cookies only (no session id in URLs), HttpOnly, Secure on HTTPS, SameSite=Lax, a `__Host-` cookie name on HTTPS, a 4-hour idle timeout, long random ids, and a brand-new session id at every sign-in and sign-up. Sign-in is rate limited. POST requests must be JSON and uploads need a custom header, which blocks cross-site requests.

On a live server also set these in `php.ini` and restart Apache: `display_errors=Off` (the app already keeps errors out of web pages and writes them to the error log), `expose_php=Off`, and keep `session.save_path` outside the web folder (the PHP default already is).

## 3. Restrict PostgreSQL access
1. Edit the password in `database/restrict_postgres.sql` (16+ characters; the script refuses to run with the placeholder) and run it as the postgres user: `psql -U postgres -d safety_store -f database/restrict_postgres.sql`. It creates `store_app`, which can only read and write rows (it is not a superuser and cannot create or change tables), and removes access for everyone else. Safe to run again.
2. Copy `app/secrets.example.php` to `app/secrets.php` and put `store_app` and its password there.
3. In `postgresql.conf` set `listen_addresses = 'localhost'` and `password_encryption = scram-sha-256`.
4. In `pg_hba.conf` allow only local connections for this database, for example:
   `host  safety_store  store_app  127.0.0.1/32  scram-sha-256`
   `host  safety_store  store_app  ::1/128       scram-sha-256`
   and remove any `0.0.0.0/0` or `::/0` line. Restart PostgreSQL.
5. Block port 5432 in the server firewall (and in your hosting provider's firewall). If PostgreSQL runs on another machine, allow only the web server's address.
6. Run `schema.sql` and every `migrate_*.sql` as the `postgres` user, never as `store_app`.

Test: `check.php` shows "limited user, not an administrator" and "cannot create or change tables" (both fail while the site still logs in as `postgres`). From a different computer, `psql -h your-server -U store_app safety_store` must not connect.

## 4. Keep PHP source code private
- The PHP code lives in `app/`, outside `public/`. `public/api.php`, `invoice.php` and `check.php` are three-line files that just load the real code.
- **Live server (VPS):** set the document root to the `public/` folder (`docs/apache-https-vhost.conf` is a full example), so `app/`, `database/` and `docs/` cannot be reached at all, even if `.htaccess` files are ignored.
- **Shared hosting (cPanel):** put the contents of `public/` (including its hidden `.htaccess`) in `public_html/`, put `app/` next to `public_html/` (not inside it) and do not upload `database/` or `docs/`. Then add `define('PUBLIC_DIR','/home/youruser/public_html');` to `app/secrets.php` so photo uploads land in the right folder.
- The database login goes in `app/secrets.php` (or environment variables), never in a public file. Do not zip, e-mail or commit `secrets.php`.
- PHP errors are never printed to visitors; they go to the Apache error log.

## 5. Enable HTTPS
- The code side is built in: `public/.htaccess` redirects HTTP to HTTPS on every host except localhost, the PHP code refuses plain HTTP outside localhost (`FORCE_HTTPS`), the session cookie is Secure, and `Strict-Transport-Security` is sent. If a proxy or CDN handles HTTPS for you, it must send `X-Forwarded-Proto: https`, and visitors must not be able to reach the server directly over plain HTTP.
- You still need a certificate. Linux server: `sudo certbot --apache -d shop.example.com` (free, renews itself); `docs/apache-https-vhost.conf` is a full example. Windows/XAMPP: use win-acme (Let's Encrypt) or your host's AutoSSL. Shared hosting: switch on AutoSSL / Let's Encrypt in the control panel.
- Set `SITE_URL` in `app/secrets.php` (for example `https://shop.example.com`) so links in order emails always use your real https address.
- Test: `http://your-domain/` must jump to `https://`, the padlock must show a valid certificate, and the browser's cookie list must show the session cookie `__Host-store` marked Secure and HttpOnly.
- To test over plain HTTP on a LAN (for example from a phone), add `define('FORCE_HTTPS','0');` to `app/secrets.php` and comment out the redirect block at the top of `public/.htaccess`. Undo both before going live.

## Still open
- Administrator MFA (authenticator app) is not built yet.
- Backups: schedule `php app/backup.php D:/backups` daily, copy the folder off the server, and test a restore.
- Keep PHP, Apache and PostgreSQL updated, and run `check.php` after every update or move to a new server.

## Remember me

The sign-in and sign-up forms (store, admin, worker) have a "Remember me" tick box.
- Not ticked: the session cookie ends when the browser closes, and you are signed out after 4 hours of no activity (as before).
- Ticked: the cookie lasts 30 days and is renewed each time you visit. It is still HttpOnly, Secure (on HTTPS) and SameSite=Lax. Tell staff to leave it unticked on shared computers.
- The time limits are `SESSION_IDLE` and `SESSION_REMEMBER` in `app/session_lib.php`.
