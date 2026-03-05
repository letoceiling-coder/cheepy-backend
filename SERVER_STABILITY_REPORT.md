# Server Stability Report — Parser Platform

**Server:** root@85.117.235.93  
**Parser API:** https://online-parser.siteaacess.store  
**Admin panel:** https://siteaacess.store/admin  

---

## 1. Server Information

| Item | Value |
|------|-------|
| OS version | _(run: `uname -a`)_ |
| CPU cores | _(run: `nproc`)_ |
| RAM | _(run: `free -h`)_ |
| Disk | _(run: `df -h`)_ |

---

## 2. Laravel Status

| Check | Command | Expected |
|-------|---------|----------|
| Artisan exists | `ls -la /var/www/online-parser.siteaacess.store/artisan` | File exists |
| Routes | `php artisan route:list \| grep ws-status` | `GET api/v1/ws-status` |
| ws-status endpoint | `curl https://online-parser.siteaacess.store/api/v1/ws-status` | JSON with reverb, queue_workers, redis |

**Critical endpoints (unchanged):**
- POST /api/v1/parser/start
- GET /api/v1/parser/status
- GET /api/v1/parser/progress

---

## 3. Redis Validation

| Check | Command | Expected |
|-------|---------|----------|
| Ping | `redis-cli ping` | PONG |
| Persistence | `redis-cli config get appendonly` | appendonly yes |
| Laravel | `php artisan tinker` → `Redis::ping()` | true |

---

## 4. Queue System

| Check | Command | Expected |
|-------|---------|----------|
| Workers | `supervisorctl status` | parser-worker_00..03 RUNNING |
| Photos workers | | parser-worker-photos_00..01 RUNNING |
| Queue length | `redis-cli LLEN queues:default` | Number |

---

## 5. Reverb WebSocket Server

| Check | Command | Expected |
|-------|---------|----------|
| Process | `ps aux \| grep reverb` | reverb process running |
| Port | `lsof -i :8080` | Listener on 8080 |

---

## 6. Nginx WebSocket Proxy

Config file: `/etc/nginx/sites-enabled/online-parser.siteaacess.store`

Required block:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Reload: `sudo systemctl reload nginx`

---

## 7. WebSocket Realtime Test

| Step | Expected |
|------|----------|
| Open https://siteaacess.store/admin | Admin loads |
| DevTools → Network → WS | Connection to wss://online-parser.siteaacess.store/app/parser-key |
| Status | 101 Switching Protocols |
| Connection | Stays open |
| Events | ParserStarted, ParserProgressUpdated, ProductParsed, ParserFinished |

---

## 8. Parser Pipeline

| Step | Expected |
|------|----------|
| POST /api/v1/parser/start | Job created, 201 |
| Queue | RunParserJob dispatched |
| Execution | DatabaseParserService runs |
| Result | Products inserted into DB |

---

## 9. System Stability

| Check | Command |
|-------|---------|
| CPU | `top` or `htop` |
| Memory | `free -h` |
| Swap | `swapon --show` |
| Queue latency | Monitor during parser run |

---

## 10. Deployment & Fix Commands

```bash
# SSH
ssh root@85.117.235.93
cd /var/www/online-parser.siteaacess.store

# Deploy (git pull or scp)
git pull

# Clear caches (fix 404)
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan optimize:clear

# Verify ws-status
php artisan route:list | grep ws-status
curl https://online-parser.siteaacess.store/api/v1/ws-status

# Restart services
supervisorctl restart all
# If Reverb in Supervisor:
supervisorctl restart reverb
```

---

# SYSTEM STABLE AND REALTIME MONITORING OPERATIONAL

When all checks pass:

- `/api/v1/ws-status` returns valid JSON
- Admin panel receives real-time parser updates
- Redis queue workers process jobs reliably
- WebSocket connection remains stable
- Parser platform operates without monitoring gaps
