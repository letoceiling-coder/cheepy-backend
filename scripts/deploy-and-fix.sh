#!/bin/bash
# Deploy latest code and fix common issues (404 on ws-status, cache)
# Run on server: cd /var/www/online-parser.siteaacess.store && bash scripts/deploy-and-fix.sh

set -e

APP_PATH="/var/www/online-parser.siteaacess.store"
cd "$APP_PATH"

echo "=== 1. Deploy latest code ==="
git pull 2>/dev/null || echo "Git pull skipped (no repo or already up to date)"

echo ""
echo "=== 2. Clear all Laravel caches ==="
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan optimize:clear
echo "Caches cleared"

echo ""
echo "=== 3. Verify ws-status route ==="
php artisan route:list | grep -E "ws-status|up|health" || echo "Routes not found"

echo ""
echo "=== 4. Test ws-status ==="
curl -s "https://online-parser.siteaacess.store/api/v1/ws-status"
echo ""

echo ""
echo "=== 5. Restart Supervisor workers ==="
supervisorctl reread 2>/dev/null || true
supervisorctl update 2>/dev/null || true
supervisorctl status 2>/dev/null || true

echo ""
echo "Done. Run: bash scripts/audit-server.sh for full audit"
