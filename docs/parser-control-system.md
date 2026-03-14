# Parser Control System

**Date:** 2026-03-06  
**Status:** Production-grade, fully controllable parsing pipeline

---

## Overview

The parser control system provides full control over the parsing pipeline: start/stop, queue management, worker control, live metrics, diagnostics, and failsafe behavior.

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

### Redis Lock

- **Key:** `parser_lock`
- **TTL:** 7200 seconds
- **Purpose:** Prevents multiple simultaneous parser runs
- **Acquired in:** `ParserController::start()`, `ParserDaemonJob::handle()`
- **Released in:** `ParserController::stop()`, `ParserController::restart()`, `RunParserJob::failed()`, `ReleaseParserLockOnFinished` listener

### Jobs Summary

| Job | Queue | Timeout | Tries | Connection |
|-----|-------|---------|-------|------------|
| RunParserJob | parser | 3600s | 3 | redis |
| ParserDaemonJob | parser | 120s | 1 | redis |
| ParseCategoryJob | parser | 1800s | 2 | redis |
| ParseProductJob | parser | 300s | 2 | redis |
| DownloadPhotoJob | photos | 600s | 2 | redis |

---

## Queues

| Queue | Jobs | Purpose |
|-------|------|---------|
| parser | RunParserJob, ParserDaemonJob, ParseCategoryJob, ParseProductJob | Main parsing pipeline |
| photos | DownloadPhotoJob | Photo downloads |
| default | (optional) | Other Laravel jobs |

### Redis Keys

- `{prefix}queues:parser` — parser queue list
- `{prefix}queues:photos` — photos queue list
- `{prefix}queues:default` — default queue
- `{prefix}queues:parser:reserved` — reserved (in-flight) jobs
- `parser_lock` — parser run lock

---

## Workers

### Supervisor Configuration

Use `supervisor-parser-queues.conf.example` as a template.

**Required workers:**

```ini
[program:parser-worker]
command=php /path/to/artisan queue:work redis --queue=parser --sleep=3 --tries=2 --timeout=1800
numprocs=6

[program:photo-worker]
command=php /path/to/artisan queue:work redis --queue=photos --sleep=3 --tries=2 --timeout=600
numprocs=2
```

**Optional default worker:**

```ini
[program:default-worker]
command=php /path/to/artisan queue:work redis --queue=default --sleep=3 --tries=2
numprocs=1
```

Workers **must** listen on `parser` and `photos` queues. Queue mismatch causes jobs to never execute.

---

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `parser:start` | Start single parser run (creates ParserJob, dispatches RunParserJob) |
| `parser:stop` | Stop running jobs, mark as stopped |
| `parser:restart` | Stop jobs + restart workers |
| `parser:status` | Print current status (running, queue sizes) |
| `parser:queue-status` | Show queue sizes for parser, photos, default |
| `parser:queue-clear [--queue=parser]` | Clear jobs from specified queue |
| `parser:queue-flush` | **Destructive** — flush all queues on Redis connection |
| `parser:queue-restart` | Broadcast worker restart signal (`queue:restart`) |
| `parser:workers-status` | Count queue:work processes |
| `parser:workers-restart` | Alias for `parser:queue-restart` |
| `parser:diagnostics` | Full diagnostics: queues, workers, lock, metrics |
| `parser:watchdog [--idle-minutes=5] [--dry-run]` | Failsafe: restart if idle + empty queue |

---

## Admin API Endpoints

**Base path:** `/api/v1/parser`

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | /start | Start parser run |
| POST | /stop | Stop running jobs |
| POST | /restart | Stop + restart workers |
| POST | /queue-clear | Clear queue (body: `queue=parser` or `photos`) |
| POST | /clear-queue | Alias for queue-clear |
| POST | /queue-restart | Restart workers |
| POST | /restart-workers | Alias for queue-restart |
| POST | /start-daemon | Enable continuous parsing |
| POST | /stop-daemon | Disable daemon |
| GET | /status | Current status (is_running, queue sizes, daemon) |
| GET | /stats | Aggregated stats (products, errors, last run) |
| GET | /diagnostics | Full diagnostics: queues, workers, lock, metrics |
| GET | /progress | SSE stream for live progress |
| GET | /jobs | Paginated list of ParserJobs |
| GET | /jobs/{id} | Job detail |

### Diagnostics Response (GET /diagnostics)

```json
{
  "parser_running": true,
  "daemon_enabled": false,
  "lock_held": true,
  "current_job": { "id": 1, "status": "running", ... },
  "queue": { "parser": 150, "default": 0, "photos": 30, "total": 180 },
  "failed_jobs": 0,
  "products_total": 50000,
  "products_today": 1200,
  "errors_today": 5,
  "metrics": {
    "requests_per_minute": 10,
    "blocked_requests": 0,
    "retry_count": 0,
    "products_per_minute": 20.5
  }
}
```

---

## Live Metrics

### ParserMetricsService

- **requests_per_minute** — HTTP requests in current minute (rate limiting)
- **blocked_requests** — Requests blocked by rate limit
- **retry_count** — Retries due to transient errors
- **products_per_minute** — Products parsed in last hour / 60

### Status Endpoint Metrics

- `queue_parser_size`, `queue_photos_size`, `queue_total_size`
- `products_total`, `products_today`, `errors_today`
- `parser_running`, `daemon_enabled`

---

## Failsafe / Watchdog

### Behavior

The `parser:watchdog` command runs every 5 minutes (via scheduler) and:

1. Checks if parser has a running/pending job
2. Checks if queue is empty (parser + photos)
3. If parser is **idle** (no activity for N minutes) and queue is **empty**:
   - If daemon enabled: stop job, release lock, dispatch `ParserDaemonJob` with 30s delay
   - If daemon disabled: call `parser:queue-restart` (restart workers)

### Schedule

Defined in `routes/console.php`:

```php
Schedule::command('parser:watchdog', ['--idle-minutes' => 5])
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
```

### Manual Run

```bash
# Dry run (log only, no actions)
php artisan parser:watchdog --dry-run

# Custom idle threshold
php artisan parser:watchdog --idle-minutes=10
```

### Alerts

Actions are logged via `Log::info('Parser watchdog: ...')`. Configure your logging driver to send alerts on these messages if needed.

---

## Safety Limits

| Limit | Value | Source |
|-------|-------|--------|
| Parser lock TTL | 7200 s | Redis SET EX NX |
| Max queue wait | 500 | ParseCategoryJob waits before dispatch |
| Batch size | 50 | ParseCategoryJob product chunks |
| Batch pause | 200 ms | Between product batches |

---

## Production Checklist

1. **Supervisor:** Run parser-worker and photo-worker with correct `--queue=parser` and `--queue=photos`
2. **Scheduler:** Ensure `php artisan schedule:run` runs every minute (cron)
3. **Redis:** `QUEUE_CONNECTION=redis` in production
4. **Lock:** Never run multiple parser starts simultaneously; use API or daemon, not both carelessly
5. **Flush:** Avoid `parser:queue-flush` in production unless intentional (drops all queued jobs)

---

## Related Documentation

- [parser-system-audit.md](parser-system-audit.md) — Jobs and queues audit
- [queue-audit.md](queue-audit.md) — Queue dispatch locations
- [parser-daemon-audit.md](parser-daemon-audit.md) — Daemon behavior
- [admin-parser-controls.md](admin-parser-controls.md) — Admin UI requirements
