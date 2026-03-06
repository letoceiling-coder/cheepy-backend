# Parser Full Diagnostics Report

**Date:** 2026-03-06  
**Goal:** Identify why jobs may be stuck and make parser controllable and stable.

---

## 1. Architecture

```
Admin/API → ParserController
    ↓
ParserJob (DB) + RunParserJob (queue:parser)
    ↓
DatabaseParserService::run()
    ↓
ParseCategoryJob (queue:parser) per category
    ↓
ParseProductJob (queue:parser) per product
    ↓
DownloadPhotoJob (queue:photos)
```

**Daemon flow:**
```
ParserDaemonJob → RunParserJob → ... → ParserFinished event
    ↓
ScheduleNextParserDaemon (listener, ShouldQueue) → ParserDaemonJob::delay(60)
```

---

## 2. Queue Structure

| Queue | Jobs | Connection |
|-------|------|------------|
| parser | RunParserJob, ParserDaemonJob, ParseCategoryJob, ParseProductJob | redis |
| photos | DownloadPhotoJob | redis |

**Config:** `config/queue.php` default = env QUEUE_CONNECTION (typically `redis`).

---

## 3. Worker Configuration

**Example (supervisor):**
- parser-worker: `queue:work redis --queue=parser --tries=2 --timeout=1800`
- photo-worker: `queue:work redis --queue=photos --tries=2 --timeout=600`

**Critical:** Workers listen only on `parser` and `photos`. The **default** queue is NOT consumed.

---

## 4. Daemon Behavior

- ParserDaemonJob runs on `parser` queue.
- When full run completes, ParserFinished fires.
- ScheduleNextParserDaemon listens (implements ShouldQueue).
- **Listener is dispatched to default queue** (Laravel behavior for queued listeners).
- Workers do NOT process `default` → **listener job never runs** → next daemon iteration never scheduled.
- **Result:** Daemon runs once, then stops. Continuous mode does not work as intended.

---

## 5. Detected Problems

### 5.1 Daemon Listener Queue Mismatch (Critical)

**Problem:** ScheduleNextParserDaemon uses default queue; workers only process parser/photos.  
**Effect:** Daemon does not restart after first run.  
**Fix:** Add `public $queue = 'parser'` to ScheduleNextParserDaemon, or ensure workers also listen on default.

### 5.2 No Redis Lock for RunParserJob

**Problem:** Multiple RunParserJob could be dispatched (API + daemon, or double-click).  
**Current:** ParserController::start checks for running job. ParserDaemonJob checks before creating. Overlap possible if timing is unlucky.  
**Fix:** Use `Redis::setnx('parser_running', 1)` with TTL before starting; release when done.

### 5.3 Jobs Stuck Scenarios

- **Workers stopped:** Jobs accumulate in Redis.
- **Worker crash:** Reserved jobs may never complete; Laravel marks them failed after retry_after.
- **Timeout:** ParseCategoryJob timeout 1800s; large categories may exceed.
- **Queue explosion:** Mitigated by waitForQueueCapacity (max 500) in ParseCategoryJob.

### 5.4 Supervisor Config Mismatch

If supervisor uses `--queue=default` instead of `--queue=parser`, parser jobs are never processed.

---

## 6. Recommended Fixes

### 6.1 Fix Daemon Listener (High Priority)

In `app/Listeners/ScheduleNextParserDaemon.php`:
```php
public $queue = 'parser';
```
Or use `ShouldQueue` with `$connection` and ensure the listener goes to parser queue.

### 6.2 Add Redis Lock for RunParserJob (Medium)

- Before creating/dispatching RunParserJob, try `Redis::set('parser_running', 1, 'EX', 7200)` (2h TTL).
- Release in RunParserJob::handle() on completion/failure.
- Skip if lock exists (already running).

### 6.3 Failsafe: Auto-Restart (Low)

If parser idle > 5 min and queue empty and daemon enabled → dispatch ParserDaemonJob.  
Requires scheduled task or external monitor.

### 6.4 Admin Panel

- Add Restart, Clear queue, Restart workers buttons.
- Display queue_parser_size, queue_photos_size, products_total, errors_today.
- See docs/admin-parser-controls.md for API and UI spec.

---

## 7. Diagnostic Commands

| Command | Purpose |
|---------|---------|
| php artisan parser:queue-status | Parser/default/photos queue size, failed jobs |
| php artisan parser:workers-status | Running worker processes |
| php artisan parser:queue-clear | Clear parser queue |
| php artisan parser:queue-restart | Restart workers |
| php artisan parser:queue-flush | Flush Redis DB (destructive) |
| php artisan parser:start | Start parser run |
| php artisan parser:stop | Stop parser jobs |
| php artisan parser:restart | Stop + restart workers |
| php artisan parser:status | Full status: running, daemon, queue, products |

---

## 8. Related Documents

- docs/parser-system-audit.md — Job inventory
- docs/queue-audit.md — Queue usage
- docs/parser-daemon-audit.md — Daemon logic
- docs/admin-parser-controls.md — Admin UI requirements
