#!/bin/bash
# Server audit script - run on production
# Usage: ssh root@85.117.235.93 'cd /var/www/online-parser.siteaacess.store && bash scripts/audit-server.sh'

set -e

echo "=========================================="
echo "PARSER PLATFORM — FULL SERVER AUDIT"
echo "=========================================="

echo ""
echo "=== 1. Server Information ==="
echo "OS: $(uname -a)"
echo "CPU cores: $(nproc 2>/dev/null || echo '?')"
free -h 2>/dev/null || echo "free: failed"
df -h / 2>/dev/null | tail -1 || echo "df: failed"

echo ""
echo "=== 2. Project validation ==="
cd /var/www/online-parser.siteaacess.store
pwd
ls -la artisan 2>/dev/null || { echo "ERROR: artisan missing"; exit 1; }

echo ""
echo "=== 3. Laravel routes (ws-status) ==="
php artisan route:list 2>/dev/null | grep -E "ws-status|up|health" || echo "Route list failed"

echo ""
echo "=== 4. Clear Laravel cache ==="
php artisan route:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan optimize:clear 2>/dev/null || true
echo "Cache cleared"

echo ""
echo "=== 5. Test ws-status endpoint ==="
curl -s "https://online-parser.siteaacess.store/api/v1/ws-status" || echo "curl failed"

echo ""
echo "=== 6. Redis ==="
redis-cli ping 2>/dev/null || echo "Redis: FAIL"
redis-cli config get appendonly 2>/dev/null || true

echo ""
echo "=== 7. Queue system ==="
supervisorctl status 2>/dev/null || echo "Supervisor: FAIL"
redis-cli LLEN queues:default 2>/dev/null || echo "Queue size: ?"

echo ""
echo "=== 8. Reverb server ==="
ps aux | grep reverb | grep -v grep || echo "Reverb: NOT RUNNING"
lsof -i :8080 2>/dev/null || echo "Port 8080: no listener"

echo ""
echo "=== 9. Nginx config ==="
grep -A8 "location /app" /etc/nginx/sites-enabled/* 2>/dev/null | head -25 || echo "Check nginx config manually"

echo ""
echo "=== 10. System resources ==="
echo "Load: $(cat /proc/loadavg 2>/dev/null || echo '?')"
swapon --show 2>/dev/null || echo "Swap: check manually"

echo ""
echo "=== 11. Critical API endpoints ==="
echo "up:    $(curl -s -o /dev/null -w '%{http_code}' https://online-parser.siteaacess.store/api/v1/up)"
echo "health: $(curl -s -o /dev/null -w '%{http_code}' https://online-parser.siteaacess.store/api/v1/health)"
echo "ws-status: $(curl -s -o /dev/null -w '%{http_code}' https://online-parser.siteaacess.store/api/v1/ws-status)"

echo ""
echo "=========================================="
echo "AUDIT COMPLETE"
echo "Run: Copy output to FINAL_SERVER_AUDIT_REPORT.md"
echo "=========================================="
