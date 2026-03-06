# Parser Rollback Instructions

If the **parallel queue pipeline** (ParseCategoryJob / ParseProductJob / DownloadPhotoJob) causes issues, roll back to the stable sequential parser.

---

## 1. Rollback to stable version

```bash
cd /var/www/online-parser.siteaacess.store   # or your backend path

# Option A: Checkout the stable tag (detached HEAD)
git fetch origin --tags
git checkout parser-stable-v1

# Option B: Checkout the backup branch
git fetch origin
git checkout backup/parser-stable-20260306    # replace with actual branch name
```

---

## 2. Redeploy steps

After checking out the stable version:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force    # only if you need to revert migrations; usually not needed
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan queue:restart
supervisorctl restart all
```

If you use **separate queue workers** for `parser` and `photos`, restore your previous Supervisor config (single `default` or `parser` queue) so workers run the old `RunParserJob` only.

---

## 3. Verify

- `GET /api/v1/parser/status` — should return expected structure.
- Start a small parser run (e.g. one category) and confirm products are saved.
- Check `supervisorctl status` — workers should be running.

---

## 4. Return to main (after fixing issues)

When you want to try the new pipeline again:

```bash
git checkout main
git pull origin main
# then redeploy as in Step 2
```

---

**Tag created:** `parser-stable-v1`  
**Backup branch:** `backup/parser-stable-YYYYMMDD` (date when backup was created)
