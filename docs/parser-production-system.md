# Parser Production System

**Date:** 2026-03-06  
**Status:** Production-grade, fully controllable parsing system

---

## Overview

The parser production system provides full control over the parsing pipeline from the admin panel, without SSH access. It includes queue safety, lock protection, stuck job handling, watchdog failsafe, and live diagnostics.

---

## Architecture

### Pipeline Flow

```
ParserController / ParserDaemonJob
        ↓
   RunParserJob (parser queue)
        ↓
   DatabaseParserService
        ↓
   ParseCategoryJob (parser queue) × categories
        ↓
   ParseProductJob (parser queue) × products
        ↓
   DownloadPhotoJob (photos queue) × photos
```

### Jobs

| Job | Queue | Timeout | Tries |
|-----|-------|---------|-------|
| RunParserJob | parser | 3600s | 3 |
| ParserDaemonJob | parser | 120s | 1 |
| ParseCategoryJob | parser | 1800s | 2 |
| ParseProductJob | parser | 300s | 2 |
| DownloadPhotoJob | photos | 600s | 2 |

---

## Queues

| Queue | Jobs | Purpose |
|-------|------|---------|
| parser | RunParserJob, ParserDaemonJob, ParseCategoryJob, ParseProductJob | Main pipeline |
| photos | DownloadPhotoJob | Photo downloads |
| default | (optional) | Other Laravel jobs |

### Queue Safety

- **Max queue size:** 1000 (config: `sadovod.max_parser_queue_size`)
- When parser queue exceeds 1000, ParseCategoryJob pauses dispatch until queue drains
- **Batch dispatch:** 50 products per batch, 200ms pause between batches
- Prevents queue explosion on large categories

---

## Workers (Supervisor)

**Correct configuration:**

```ini
[program:parser-worker]
command=php /path/to/artisan queue:work redis --queue=parser --sleep=3 --tries=2 --timeout=600
numprocs=6

[program:photo-worker]
command=php /path/to/artisan queue:work redis --queue=photos --sleep=3 --tries=2 --timeout=600
numprocs=2

[program:default-worker]
command=php /path/to/artisan queue:work redis --queue=default --sleep=3 --tries=2
numprocs=1
```

After editing:

```bash
supervisorctl reread
supervisorctl update
supervisorctl restart all
```

---

## Redis Lock

- **Key:** `parser_lock`
- **TTL:** 7200 seconds
- **Heartbeat:** `parser:lock-heartbeat` runs every 30s via scheduler; refreshes TTL while parser is running
- **Safety:** If lock exists but no parser job running → use `POST /api/v1/parser/release-lock` or `php artisan parser:release-lock`

---

## Stuck Job Protection

- **parser:kill-stuck** — marks parser jobs stuck (no activity > N minutes) as failed
- Releases lock and restarts workers
- Default idle threshold: 10 minutes
- API: `POST /api/v1/parser/kill-stuck`

---

## Admin Panel Controls

### Parser

- Start parser
- Stop parser
- Restart parser (stop + restart workers)
- Start/stop daemon (continuous parsing)

### Queues

- Clear parser queue
- Clear photos queue
- Flush all queues (parser, photos, default)
- Restart workers

### Emergency

- Kill stuck jobs
- Release parser lock
- Reset system (stop, clear queues, restart workers)

---

## API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | /api/v1/parser/start | Start parser |
| POST | /api/v1/parser/stop | Stop parser |
| POST | /api/v1/parser/restart | Stop + restart workers |
| POST | /api/v1/parser/queue-clear | Clear queue (body: queue=parser|photos|default) |
| POST | /api/v1/parser/queue-flush | Clear all queues |
| POST | /api/v1/parser/restart-workers | Restart workers |
| POST | /api/v1/parser/kill-stuck | Kill stuck jobs |
| POST | /api/v1/parser/release-lock | Release parser lock |
| POST | /api/v1/parser/reset | Emergency reset |
| GET | /api/v1/parser/diagnostics | Full diagnostics |

### Diagnostics Response

```json
{
  "workers_running": 8,
  "parser_running": true,
  "parser_queue_size": 150,
  "photos_queue_size": 30,
  "failed_jobs_count": 0,
  "parser_lock_status": "held",
  "products_total": 50000,
  "products_today": 1200,
  "errors_today": 5,
  "metrics": {
    "products_per_minute": 20.5,
    "requests_per_minute": 10
  }
}
```

---

## Dashboard Metrics

The admin parser page displays (auto-refresh every 5 seconds):

- Parser status (running/stopped)
- Workers count
- Queue sizes (parser, photos)
- Products parsed today
- Products per minute
- Parser lock status
- Errors today

---

## Watchdog Failsafe

**Command:** `php artisan parser:watchdog`  
**Schedule:** Every 5 minutes (Laravel scheduler)

### Logic

1. **Queue > 0 and workers = 0** → Restart workers (broadcast `queue:restart`)
2. **Parser idle > 10 minutes and queue empty** → Restart parser (if daemon) or workers

---

## Artisan Commands

| Command | Purpose |
|---------|---------|
| parser:start | Start single run |
| parser:stop | Stop jobs |
| parser:restart | Stop + restart workers |
| parser:status | Show status |
| parser:queue-status | Queue sizes |
| parser:queue-clear | Clear specific queue |
| parser:queue-flush | Flush Redis DB (destructive) |
| parser:queue-restart | Restart workers |
| parser:kill-stuck | Mark stuck jobs failed |
| parser:release-lock | Release lock |
| parser:reset | Emergency reset |
| parser:diagnostics | Full diagnostics |
| parser:watchdog | Failsafe |
| parser:lock-heartbeat | Refresh lock TTL |

---

## Production Checklist

1. **Supervisor:** Workers listen on `parser` and `photos` queues
2. **Scheduler:** Cron runs `php artisan schedule:run` every minute
3. **Redis:** `QUEUE_CONNECTION=redis`
4. **Admin:** Full control via admin panel — no SSH required for normal operations

---

## Full System Test

```bash
# Clear queues (optional)
php artisan parser:queue-clear --queue=parser
php artisan parser:queue-clear --queue=photos

# Start parser
php artisan parser:start

# Verify
php artisan parser:diagnostics
supervisorctl status
redis-cli llen queues:parser
```

Expected: Workers process jobs, queue drains, products inserted, diagnostics update.
