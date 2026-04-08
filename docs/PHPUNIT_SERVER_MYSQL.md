# PHPUnit on production-like server (MySQL, not SQLite)

Server PHP may not include `pdo_sqlite`. Tests must use MySQL and a **dedicated** database.

## One-time server setup

1. Create database (as MariaDB root):

   ```bash
   mysql -e "CREATE DATABASE IF NOT EXISTS online_parser_siteaacess_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

2. Grant app user (see `DB_USERNAME` in `.env`), e.g.:

   ```bash
   mysql <<'SQL'
   GRANT ALL PRIVILEGES ON online_parser_siteaacess_testing.* TO 'sadavod'@'localhost';
   FLUSH PRIVILEGES;
   SQL
   ```

3. Create `.env.testing` in the app root (overrides `.env` for PHPUnit):

   ```env
   APP_ENV=testing
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=online_parser_siteaacess_testing
   ```

   Append `APP_KEY` from `.env` (required or `ExampleTest` fails):

   ```bash
   grep '^APP_KEY=' .env >> .env.testing
   ```

   Use `DB_HOST=localhost` if MySQL user exists for `@localhost` but not `@127.0.0.1`.

4. `composer install` (with dev dependencies) so `php artisan test` exists.

5. Repo `phpunit.xml` must **not** set `DB_CONNECTION` / `DB_DATABASE` to sqlite.

## Commands

```bash
cd /var/www/online-parser.siteaacess.store
php artisan test
php artisan catalog:auto-map-categories --sync
```
