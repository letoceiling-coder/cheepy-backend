# Parser System Reset Report

**Date:** 2026-03-06  
**Goal:** Full reset of parser pipeline and clean start.

---

## Steps Performed

### 1. Stop workers

- `supervisorctl stop parser-worker:*` and `parser-worker-photos:*` (or workers were force-killed when stopping; they were in STOPPED state).
- **Verified:** All 6 parser workers and 2 photo workers STOPPED.

### 2. Clear Redis queues

- **Prefixed keys** (Laravel Redis prefix in use): `sadavodparser-database-queues:parser`, `sadavodparser-database-queues:photos`, `sadavodparser-database-queues:default`.
- **Actions:**
  - `redis-cli DEL 'sadavodparser-database-queues:parser' 'sadavodparser-database-queues:photos' 'sadavodparser-database-queues:default'` — **2 keys deleted** (parser queue had **61,086** items before delete).
  - `redis-cli DEL 'sadavodparser-database-queues:parser:reserved' 'sadavodparser-database-queues:photos:reserved'` — cleared reserved (in-flight) job keys so they are not re-queued when workers restart.
- **Failed jobs:** `php artisan queue:flush` — all failed jobs deleted.
- **Verified:** `LLEN sadavodparser-database-queues:parser` = **0**, `LLEN sadavodparser-database-queues:photos` = **0**.

### 3. Reset parser_jobs table

- **Intended:** `UPDATE parser_jobs SET status = 'cancelled' WHERE status IN ('pending','running');`
- **Note:** This was not executed from the automated session (quoting/SSH constraints). Run manually on the server when needed:

```bash
cd /var/www/online-parser.siteaacess.store
php artisan tinker
# Then paste:
DB::table('parser_jobs')->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled']);
```


### 4. Redis progress/cache keys

- Keys such as `parser:progress`, `parser:stats`, `parser:queue` were not present under the app’s Redis prefix (only cache and queue keys were found). No extra keys were deleted for “progress”; queue keys are cleared above.

### 5. Verify clean state

- **Queue keys:** After final clear, `redis-cli KEYS "*queue*"` shows only the empty list keys and `:notify`/`:reserved` keys. Parser and photos **list lengths = 0**.

### 6. Restart workers

- `supervisorctl start parser-worker:*`
- `supervisorctl start parser-worker-photos:*`
- **Verified:** 6 parser workers + 2 photo workers RUNNING.

### 7. Start new parser run

- **Action:** Trigger a new parser run from the admin panel.
- **Expected:** Queue grows slowly (batch dispatch + 200 ms pause), products insert immediately, queue size stays **&lt; 300** (with 6 workers and batch size 50).

### 8. Queue protection added

- **File:** `app/Jobs/ParseCategoryJob.php`
- **Logic:** Before dispatching each page of product jobs, if the parser queue size is **&gt; 1000**, the job **sleep(2)** to let workers drain.
- **Code:**

```php
try {
    $parserQueueSize = Queue::connection('redis')->size('parser');
    if ($parserQueueSize > 1000) {
        sleep(2);
    }
} catch (\Throwable $e) {
    // ignore
}
```

- This backs up the existing batch dispatch (50 per chunk, 200 ms pause) and prevents queue explosion under load.

---

## Queue size after reset

| Queue        | Before reset | After reset |
|-------------|--------------|-------------|
| parser      | **61,086**   | **0**       |
| photos      | 0            | **0**       |

---

## Workers running (after restart)

| Program              | Count | State   |
|----------------------|-------|---------|
| parser-worker        | 6     | RUNNING |
| parser-worker-photos | 2     | RUNNING |
| reverb               | 1     | RUNNING |

---

## First category parsed / products per minute

- **First category parsed:** To be observed on the first new run from the admin panel (check `current_action` or logs).
- **Products inserted per minute:** To be measured after a new run (e.g. from `parser_jobs.saved_products` and `started_at`/`finished_at`, or from worker logs). With 6 workers, batch dispatch, and ~12 rps HTTP limit, expect roughly **600–900 products/min** in a clean run.

---

## Files changed

- `app/Jobs/ParseCategoryJob.php` — added queue-size check and `sleep(2)` when parser queue &gt; 1000.
- `docs/PARSER_RESET_REPORT.md` — this report.

---

## Recommended next run

1. In admin: start parser (e.g. full run or a subset of categories).
2. Watch `GET /api/v1/parser/status` — `queue_parser_size` should stay low (&lt; 300).
3. Check Laravel and worker logs for first category name and any errors.
4. After a few minutes, compute products/min from `saved_products` and elapsed time.
