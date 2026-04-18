#!/usr/bin/env bash
# Однократная очистка донорских товаров на проде (из Git Bash на ПК с доступом по SSH).
# Использование: SSH_HOST=user@host bash scripts/ssh-wipe-donor-products-prod.sh
set -euo pipefail
HOST="${SSH_HOST:-root@85.117.235.93}"

ssh "$HOST" 'bash -s' <<'ENDSSH'
set -euo pipefail
cd /var/www/online-parser.siteaacess.store
supervisorctl stop 'parser-worker:*' 2>/dev/null || true
supervisorctl stop 'parser-worker-default:*' 2>/dev/null || true
supervisorctl stop 'parser-worker-photos:*' 2>/dev/null || true
git fetch origin
git checkout main
git reset --hard origin/main
php artisan parser:wipe-donor-products --force
php artisan queue:restart
supervisorctl start 'parser-worker:*' 2>/dev/null || true
supervisorctl start 'parser-worker-default:*' 2>/dev/null || true
supervisorctl start 'parser-worker-photos:*' 2>/dev/null || true
php artisan tinker --execute="echo 'products='.\\DB::table('products')->count().' parser_jobs='.\\DB::table('parser_jobs')->count().PHP_EOL;"
ENDSSH
