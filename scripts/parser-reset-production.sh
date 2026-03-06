#!/usr/bin/env bash
# Full parser system reset on production server.
# Run on server: bash scripts/parser-reset-production.sh
# Or: ssh root@85.117.235.93 'cd /var/www/online-parser.siteaacess.store && bash scripts/parser-reset-production.sh'

# Do not use set -e: supervisorctl status returns 3 when processes are STOPPED (expected during reset)
PROJECT_DIR="${PROJECT_DIR:-/var/www/online-parser.siteaacess.store}"
cd "$PROJECT_DIR"

echo "=== Step 1–2: Project dir ==="
pwd
ls -la .env 2>/dev/null || { echo "No .env"; exit 1; }

echo ""
echo "=== Step 3: Stop queue workers ==="
supervisorctl stop 'parser-worker:*' 2>/dev/null || true
supervisorctl stop 'parser-worker-photos:*' 2>/dev/null || true
supervisorctl status || true

echo ""
echo "=== Step 4: Clear Redis (DB 0) ==="
redis-cli -n 0 FLUSHDB
echo "Redis queue lengths:"
redis-cli -n 0 LLEN queues:parser 2>/dev/null || redis-cli LLEN queues:parser 2>/dev/null || echo "0"
redis-cli -n 0 LLEN queues:photos 2>/dev/null || redis-cli LLEN queues:photos 2>/dev/null || echo "0"
# If app uses prefix (e.g. Laravel Redis prefix):
PREFIX="${REDIS_PREFIX:-}"
if [ -n "$PREFIX" ]; then
  redis-cli LLEN "${PREFIX}queues:parser" 2>/dev/null || true
  redis-cli LLEN "${PREFIX}queues:photos" 2>/dev/null || true
fi

echo ""
echo "=== Step 5: Clean parser database data ==="
php scripts/truncate-parser-tables.php

echo ""
echo "=== Step 6: Deploy latest code & clear caches ==="
git pull
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan queue:restart

echo ""
echo "=== Step 7: Start workers ==="
supervisorctl start 'parser-worker:*' 2>/dev/null || true
supervisorctl start 'parser-worker-photos:*' 2>/dev/null || true
sleep 2
supervisorctl status || true

echo ""
echo "=== Step 8: Verify clean state ==="
echo "Redis queues:"
redis-cli -n 0 LLEN queues:parser 2>/dev/null || echo "0"
redis-cli -n 0 LLEN queues:photos 2>/dev/null || echo "0"
echo "Database:"
php artisan tinker --execute="echo 'products=' . \DB::table('products')->count() . ', parser_jobs=' . \DB::table('parser_jobs')->count();"

echo ""
echo "=== Step 9: Start first clean parser run ==="
php scripts/start-parser-job.php || true

echo ""
echo "=== Step 10: Brief wait and queue check ==="
sleep 5
echo "Queue length (parser):"
redis-cli -n 0 LLEN queues:parser 2>/dev/null || echo "0"
echo "Last log lines:"
tail -n 30 storage/logs/laravel.log 2>/dev/null || true

echo ""
echo "=== Step 11: Final diagnostics ==="
echo "1) supervisorctl status:"
supervisorctl status || true
echo "2) Redis queue size (parser / photos):"
redis-cli -n 0 LLEN queues:parser 2>/dev/null || echo "0"
redis-cli -n 0 LLEN queues:photos 2>/dev/null || echo "0"
echo "3) Products count:"
php artisan tinker --execute="echo \DB::table('products')->count();"
echo "4) Parser job status (latest):"
php artisan tinker --execute="\$j = \DB::table('parser_jobs')->orderBy('id','desc')->first(); if (\$j) { echo 'id='.\$j->id.' status='.\$j->status.' parsed_categories='.(\$j->parsed_categories ?? 0).' parsed_products='.(\$j->parsed_products ?? 0); } else { echo 'no jobs'; }"
echo ""
echo "Parser reset script finished."
