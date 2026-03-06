# Parser System Reset — Full Clean Start

**Purpose:** Completely reset the parser environment: remove all parsed data, clear Redis queues, and prepare the system for a clean continuous parsing run.

**Result:**
- No old parser jobs remain
- No old products remain
- Redis queues empty
- Parser can start from a clean state
- System ready for permanent parsing

---

## Prerequisites

- **Backup tag created** (safety): `parser-reset-backup-20260306` (already pushed to origin).
- Server access: SSH to the application server.
- Application path (example): `/var/www/online-parser.siteaacess.store`.

---

## Step 1 — Create backup tag (safety)

Already done. If you need to recreate:

```bash
git tag parser-reset-backup-20260306
git push origin parser-reset-backup-20260306
```

---

## Step 2 — Stop queue workers

On the server:

```bash
supervisorctl stop parser-worker:*
supervisorctl stop parser-worker-photos:*
supervisorctl status
```

**Verify:** All parser and photo workers show **STOPPED**.

---

## Step 3 — Clear Redis

**Warning:** `FLUSHDB` removes **all keys** in the currently selected Redis database (queues, cache, session, rate limits, etc.). Use the same DB index as in Laravel `.env` (e.g. `REDIS_DB=0`).

```bash
redis-cli
SELECT 0
FLUSHDB
EXIT
```

This clears:
- `queues:parser`, `queues:photos`, `queues:default`
- Failed job keys
- Horizon data (if used)
- Rate limits and any other keys in that DB

---

## Step 4 — Clean database (parser data only)

Run from the server. Replace `your_database`, `your_user`, `your_password` with actual credentials (or use `mysql` from app directory and `.env`).

**Option A — MySQL client (one block):**

```bash
cd /var/www/online-parser.siteaacess.store
mysql -u YOUR_USER -p YOUR_DATABASE -e "
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE parser_logs;
TRUNCATE TABLE parser_jobs;
TRUNCATE TABLE product_attributes;
TRUNCATE TABLE product_photos;
TRUNCATE TABLE products;
ALTER TABLE products AUTO_INCREMENT = 1;
ALTER TABLE product_photos AUTO_INCREMENT = 1;
ALTER TABLE product_attributes AUTO_INCREMENT = 1;
ALTER TABLE parser_jobs AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;
"
```

**Option B — Artisan tinker (if you prefer):**

```bash
cd /var/www/online-parser.siteaacess.store
php artisan tinker --execute="
\DB::statement('SET FOREIGN_KEY_CHECKS=0');
\DB::table('parser_logs')->truncate();
\DB::table('parser_jobs')->truncate();
\DB::table('product_attributes')->truncate();
\DB::table('product_photos')->truncate();
\DB::table('products')->truncate();
\DB::statement('ALTER TABLE products AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE product_photos AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE product_attributes AUTO_INCREMENT = 1');
\DB::statement('ALTER TABLE parser_jobs AUTO_INCREMENT = 1');
\DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo 'Done';
"
```

**Do not truncate or drop:**
- `categories`
- `sellers` (optional: keep for re-parsing same sellers)
- `brands`
- `filters_config`
- `attribute_dictionary`
- Other config/reference tables

---

## Step 5 — Clear Laravel cache

```bash
cd /var/www/online-parser.siteaacess.store
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

---

## Step 6 — Restart queue workers

```bash
supervisorctl start parser-worker:*
supervisorctl start parser-worker-photos:*
supervisorctl status
```

**Expected:** All listed parser and photo workers show **RUNNING**.

---

## Step 7 — Verify empty state

**Redis queue size (must be 0):**

```bash
redis-cli LLEN queues:parser
redis-cli LLEN queues:photos
```

If your app uses a Redis prefix (e.g. `sadavodparser-database-`), use the prefixed key names, e.g.:

```bash
redis-cli LLEN sadavodparser-database-queues:parser
redis-cli LLEN sadavodparser-database-queues:photos
```

**Database:**

```bash
mysql -u YOUR_USER -p YOUR_DATABASE -e "SELECT COUNT(*) FROM products; SELECT COUNT(*) FROM parser_jobs;"
```

Or via tinker:

```bash
php artisan tinker --execute="echo 'products: ' . \DB::table('products')->count() . PHP_EOL . 'parser_jobs: ' . \DB::table('parser_jobs')->count();"
```

**Expected:** `0` for both products and parser_jobs, and queue lengths `0`.

---

## Step 8 — Start a new parser run

Trigger a full parser run via API:

```bash
curl -X POST https://YOUR_DOMAIN/api/v1/parser/run \
  -H "Content-Type: application/json" \
  -d '{"type": "full"}'
```

Or from the admin UI: start parser with type **full**.

**Expected:** `RunParserJob` is created and `ParseCategoryJob` dispatches begin.

---

## Step 9 — Monitor

- **Status:** `GET /api/v1/parser/status`  
  - `queue_parser_size` should increase at first, then stabilize.  
  - `products_count` (or equivalent) should increase over time.

- **Logs:** `tail -f storage/logs/laravel.log`  
  - Look for: `Products parsed page`, `ParseProductJob dispatched`, and normal parsing messages.

---

## Step 10 — Deploy (after code/doc changes)

If you pulled new code or docs:

```bash
cd /var/www/online-parser.siteaacess.store
git pull
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

---

## Expected result

| State      | products | queue (parser/photos) | parser_jobs |
|-----------|----------|------------------------|-------------|
| After reset | 0        | 0                      | 0           |
| After start | Increasing | Stable (e.g. &lt; 500) | New job(s)  |

Categories are processed sequentially; products and queue should grow then stabilize. System is then ready for continuous parsing.

---

## Rollback

To restore code to the state before this reset procedure:

```bash
git checkout parser-reset-backup-20260306
# Then redeploy (composer, artisan, restart workers) as needed.
```

Database and Redis are not restored by the tag; only code is. Back up DB/Redis separately if you need to restore data.
