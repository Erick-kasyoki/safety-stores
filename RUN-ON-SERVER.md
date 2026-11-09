# Run these on your server (Linux VPS, Apache + PostgreSQL)

Replace shop.example.com and the paths. Do them in order.

## 1. Database user
    psql -U postgres -d safety_store -f database/restrict_postgres.sql
    # before running: replace the CHANGE-ME value in database/restrict_postgres.sql
    # with a randomly generated password; put the same value in app/secrets.php
    chmod 600 app/secrets.php

## 2. PostgreSQL network lock-down
    # postgresql.conf
    listen_addresses = 'localhost'
    password_encryption = scram-sha-256
    # pg_hba.conf (delete any 0.0.0.0/0 or ::/0 line)
    host  safety_store  store_app  127.0.0.1/32  scram-sha-256
    host  safety_store  store_app  ::1/128       scram-sha-256
    sudo systemctl restart postgresql
    # firewall (ufw example)
    sudo ufw deny 5432/tcp && sudo ufw allow 80,443/tcp && sudo ufw status
    # also close 5432 in your hosting provider's firewall panel

## 3. HTTPS + web root
    # set DocumentRoot to /path/to/safety-store/public (see docs/apache-https-vhost.conf)
    sudo certbot --apache -d shop.example.com
    # add to app/secrets.php:
    define('SITE_URL','https://shop.example.com');

## 4. Verify, then delete check.php
    # open http://localhost/check.php ON the server; everything must pass
    rm public/check.php
    # from another computer this must fail:  psql -h your-server -U store_app safety_store
