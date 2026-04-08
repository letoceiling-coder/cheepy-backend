# Safe API testing (no production data)

Production PHP-FPM / Nginx uses **`.env`** → database **`sadavod_parser`**.  
Calling `https://your-domain/api/...` **always hits production** unless you change server config.

## Rules

1. **Test database only:** `DB_DATABASE=online_parser_siteaacess_testing` (via **`.env.testing`** + `--env=testing`).
2. **Hard guards:** `SafeApiTestingGuards` — seeder and cleanup **throw** if `APP_ENV` is not `testing` or DB name is wrong.
3. **Dedicated admin:** `api_safe_test@testing.invalid` — created only in the test DB by `SafeApiTestingSeeder`.
4. **Isolated IDs:** **`donor_category_id = 1000001`** (`SafeApiTestingSeeder::ISOLATED_DONOR_ID`).
5. **Cleanup:** `php artisan testing:safe-api-cleanup --env=testing`  
   SQL equivalent: `DELETE FROM category_mapping WHERE donor_category_id >= 1000000;` then `DELETE FROM donor_categories WHERE id >= 1000000;`

## Single command (preferred)

From project root:

```bash
php artisan testing:safe-api-run --env=testing
```

Flow:

1. `migrate:fresh` (testing connection only)
2. `SafeApiTestingSeeder`
3. Ephemeral `php artisan serve` on **127.0.0.1** with a **random free port 9000–9999** (or `--port=` in that range) + HTTP checks (mapping validation, upsert, reorder 422, no duplicate donors)
4. `testing:safe-api-cleanup`

Server readiness: **10** HTTP GET attempts to `/up` with logged attempts.

**Do not use** `scripts/server-safe-api-verify.sh` — it exits with instructions to use Artisan only.

## Manual seed / cleanup

```bash
php artisan db:seed --class=Database\\Seeders\\SafeApiTestingSeeder --env=testing --force
php artisan testing:safe-api-cleanup --env=testing
```

## Test admin credentials (test DB only)

| Field    | Value |
|----------|--------|
| Email    | `api_safe_test@testing.invalid` |
| Password | `SafeApiTest_Only_2026!` |

Do not reuse this password in production.

## What NOT to do

- Do **not** run `scripts/server-strict-verify.sh` without understanding it (production risk; requires `ALLOW_PRODUCTION_API_TEST=1`).
- Do **not** run `SafeApiTestingSeeder` or `testing:safe-api-run` without `--env=testing` — **hard guards will throw**.
