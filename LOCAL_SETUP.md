# Running the system locally (WAMP / XAMPP)

The deployed system runs on PHP + PostgreSQL (Supabase). For testing on your own machine it
also runs on the MySQL that ships with WAMP and XAMPP, so you don't need to install Postgres.

---

## 1. Put the project in the web root

Copy the project folder into your server's web root:

| Server | Folder |
| --- | --- |
| WAMP | `C:\wamp64\www\` |
| XAMPP | `C:\xampp\htdocs\` |

So the files end up at, for example, `C:\wamp64\www\riveros-boardinghouse-management\`.

Start Apache and MySQL from the WAMP/XAMPP control panel and make sure the tray icon is green.

## 2. Create the database

Open **phpMyAdmin** (<http://localhost/phpmyadmin>), go to the **Import** tab, and import:

```
database/schema_mysql.sql
```

That creates a database called `boardinghouse` with all the tables, the default admin
account, and the default settings rows.

If you prefer the command line:

```
mysql -u root -p < database/schema_mysql.sql
```

## 3. Point the app at your database

Copy `config.sample.php` to `config.php` and edit it if your MySQL login is different from
WAMP's default (`root` with no password):

```
copy config.sample.php config.php      :: Windows
cp   config.sample.php config.php      #  macOS / Linux
```

```php
return [
    'db_driver'   => 'mysql',
    'db_host'     => '127.0.0.1',
    'db_port'     => '3306',
    'db_name'     => 'boardinghouse',
    'db_user'     => 'root',
    'db_password' => '',
    'supabase_url'         => '',   // leave empty locally
    'supabase_service_key' => '',   // leave empty locally
];
```

`config.php` is git-ignored, so your local settings are never committed. With the Supabase
values left empty, uploads (payment screenshots, profile pictures, the GCash QR code) are
saved into the project's `uploads/` folder instead of cloud storage.

## 4. Open it

<http://localhost/riveros-boardinghouse-management/>

Log in with the default administrator account:

| Username | Password |
| --- | --- |
| `admin` | `password` |

Change that password straight away under **Settings → Administrator Profile**.

---

## Requirements

* PHP 8.0 or newer (WAMP 3.3 and XAMPP 8.x both qualify)
* The `pdo_mysql` extension — enabled by default in WAMP and XAMPP
* The `gd` and `curl` extensions — also on by default; `curl` is only needed for SMS and
  Supabase uploads, neither of which local testing uses

To check, create a file `info.php` in the project folder containing `<?php phpinfo();` and
open <http://localhost/riveros-boardinghouse-management/info.php>. Delete it afterwards.

## Notes

* **Sub-folder URLs work.** Redirects are built from the folder the app is served from, so it
  runs at both `http://localhost/riveros-boardinghouse-management/` and a domain root.
* **The `uploads/` folder must be writable.** On Windows it usually already is. On macOS or
  Linux run `chmod -R 775 uploads`.
* **Schema updates apply themselves.** The first page load after pulling new code runs any
  pending migration, on MySQL and on PostgreSQL alike — there is nothing to import by hand.
* **SMS stays off locally** unless you put a real API key in Settings → SMS API Integration.
  Without one, notifications are skipped and the rest of the flow carries on working.

## Connecting to the live Supabase database instead

Set `db_driver` to `pgsql` in `config.php` and fill in the Supabase host, port, database,
user and password. Everything else works the same.

## Troubleshooting

| Message | Fix |
| --- | --- |
| `Database connection failed ... Access denied` | The user or password in `config.php` doesn't match your MySQL. WAMP's default is `root` with an empty password. |
| `Database connection failed ... Unknown database` | The import in step 2 didn't run. Import `database/schema_mysql.sql` again. |
| `could not find driver` | `pdo_mysql` is off. In WAMP: tray icon → PHP → PHP extensions → tick `php_pdo_mysql`. |
| Page is blank | Turn errors on to see why: set `display_errors = On` in WAMP's `php.ini`, then restart Apache. |
| Uploaded images don't appear | The `uploads/` folder isn't writable. |
