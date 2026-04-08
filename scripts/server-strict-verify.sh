#!/bin/bash
# DANGER: Uses production DB (sadavod_parser) and live admin JWT via public HTTPS.
# Mutates real category_mapping rows. Do NOT use for routine checks.
# Use scripts/server-safe-api-verify.sh + docs/SAFE_API_TESTING.md instead.
if [ -z "${ALLOW_PRODUCTION_API_TEST:-}" ]; then
  echo "Refusing to run: set ALLOW_PRODUCTION_API_TEST=1 to override (not recommended)."
  exit 1
fi
set -eu
cd /var/www/online-parser.siteaacess.store
echo "=== DB names (default vs testing) ==="
php artisan tinker --execute="echo 'default_db='.config('database.connections.mysql.database');"

echo "=== Counts (production/default connection) ==="
php artisan tinker --execute="echo 'admins='.\App\Models\AdminUser::count().PHP_EOL; echo 'donors='.\App\Models\DonorCategory::count().PHP_EOL; echo 'catalogs='.\App\Models\CatalogCategory::count().PHP_EOL;"

echo "=== JWT token (first active admin) ==="
TOKEN=$(php artisan tinker --execute="
\$u = \App\Models\AdminUser::where('is_active', true)->first();
if (!\$u) { echo ''; exit; }
\$secret = config('jwt.secret') ?: config('app.key');
\$payload = ['sub' => \$u->id, 'email' => \$u->email, 'role' => \$u->role, 'iat' => time(), 'exp' => time() + 3600];
echo \Firebase\JWT\JWT::encode(\$payload, \$secret, 'HS256');
" 2>/dev/null | tr -d '\r\n')

if [ -z "$TOKEN" ]; then
  echo "NO_ADMIN_TOKEN — cannot run authenticated API checks"
  exit 1
fi

CID=$(php artisan tinker --execute="echo \App\Models\CatalogCategory::query()->value('id') ?? '';" 2>/dev/null | tr -d '\r\n')
DID=$(php artisan tinker --execute="echo \App\Models\DonorCategory::query()->value('id') ?? '';" 2>/dev/null | tr -d '\r\n')

echo "sample catalog_id=$CID donor_id=$DID"

BASE="https://online-parser.siteaacess.store/api/v1"

echo "=== 1) Invalid donor_category_id (expect 422) ==="
curl -sS -w "\nHTTP_CODE:%{http_code}\n" -X POST "$BASE/admin/catalog/category-mapping" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"donor_category_id\":999999999,\"catalog_category_id\":$CID,\"confidence\":100}"

echo ""
echo "=== 2a) First POST same donor (create or update) ==="
curl -sS -w "\nHTTP_CODE:%{http_code}\n" -X POST "$BASE/admin/catalog/category-mapping" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"donor_category_id\":$DID,\"catalog_category_id\":$CID,\"confidence\":88,\"is_manual\":true}"

echo ""
echo "=== 2b) Second POST same donor (expect update, same row) ==="
curl -sS -w "\nHTTP_CODE:%{http_code}\n" -X POST "$BASE/admin/catalog/category-mapping" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"donor_category_id\":$DID,\"catalog_category_id\":$CID,\"confidence\":99,\"is_manual\":true}"

echo ""
echo "=== 2c) Row count for this donor ==="
php artisan tinker --execute="echo 'rows_for_donor='.\App\Models\CategoryMapping::where('donor_category_id',$DID)->count();"

echo ""
echo "=== 3) PATCH reorder invalid body (expect 422) ==="
curl -sS -w "\nHTTP_CODE:%{http_code}\n" -X PATCH "$BASE/admin/catalog/categories/reorder" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"invalid":true}'

echo ""
echo "=== 5) Duplicate donor_category_id groups in DB ==="
php artisan tinker --execute="
\$rows = \Illuminate\Support\Facades\DB::select('SELECT donor_category_id, COUNT(*) AS c FROM category_mapping GROUP BY donor_category_id HAVING c > 1');
echo json_encode(\$rows, JSON_PRETTY_PRINT);
"

echo ""
echo "=== 6) Auto-mapping sync ==="
php artisan catalog:auto-map-categories --sync
