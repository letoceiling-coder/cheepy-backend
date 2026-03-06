#!/usr/bin/env bash
# Hard reset: stop workers, flush Redis, truncate parser data, deploy, start workers, run single category test.
PROJECT_DIR="${PROJECT_DIR:-/var/www/online-parser.siteaacess.store}"
cd "$PROJECT_DIR"

echo "=== 1 STOP WORKERS ==="
supervisorctl stop 'parser-worker:*' 2>/dev/null || true
supervisorctl stop 'parser-worker-photos:*' 2>/dev/null || true
supervisorctl status || true

echo "=== 2 FLUSH REDIS ==="
redis-cli -n 0 FLUSHDB
echo "Redis keys: $(redis-cli -n 0 KEYS '*' | wc -l)"

echo "=== 3 TRUNCATE PARSER TABLES ==="
php scripts/truncate-parser-tables.php

echo "=== 4 DEPLOY ==="
git pull
php artisan config:clear
php artisan cache:clear
php artisan queue:restart

echo "=== 5 START WORKERS ==="
supervisorctl start 'parser-worker:*' 2>/dev/null || true
supervisorctl start 'parser-worker-photos:*' 2>/dev/null || true
sleep 2
supervisorctl status || true

echo "=== 6 VERIFY QUEUE EMPTY ==="
Q=$(redis-cli -n 0 LLEN sadavodparser-database-queues:parser 2>/dev/null || echo "0")
echo "parser queue: $Q"

echo "=== 7 START ONE CATEGORY TEST (platya) ==="
php scripts/start-parser-category.php platya

echo "=== DONE. Watch: tail -f storage/logs/laravel.log ==="
