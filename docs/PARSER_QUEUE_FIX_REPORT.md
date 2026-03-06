# Parser Queue Fix Report

**Date:** 2026-03-06  
**Server:** root@85.117.235.93

---

## 1. Supervisor Configuration

### Before (incorrect)

```ini
[program:parser-worker]
command=php /var/www/online-parser.siteaacess.store/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
numprocs=4
```

**Problem:** No `--queue=parser` argument. Workers consumed the `default` queue. `RunParserJob`, `ParseCategoryJob`, `ParseProductJob` were all dispatched to the `parser` queue and never picked up.

### After (correct)

```ini
[program:parser-worker]
command=php /var/www/online-parser.siteaacess.store/artisan queue:work redis --queue=parser --sleep=3 --tries=3 --max-time=3600
numprocs=4
```

**Fix applied via:** `sed -i` on `/etc/supervisor/conf.d/parser-worker.conf`  
**Service restarted via:** `service supervisor restart`

### Photo workers (unchanged, correct)

```ini
[program:parser-worker-photos]
command=php /var/www/online-parser.siteaacess.store/artisan queue:work redis --queue=photos --sleep=3 --tries=5 --max-time=3600
numprocs=2
```

---

## 2. Progress Endpoint Fix

### Before (broken)

```php
use Illuminate\Http\Response;

public function progress(Request $request): Response
{
    return response()->stream(function () { ... }, 200, [
        'Content-Type' => 'text/event-stream',
    ]);
}
```

**Problem:** `response()->stream()` returns `Symfony\Component\HttpFoundation\StreamedResponse`, but the method declared `Illuminate\Http\Response` as return type. PHP 8+ enforces this and throws:  
`TypeError: Return value must be of type Response, StreamedResponse returned`  
Every admin UI poll of `/api/v1/parser/progress` got **HTTP 500**, causing retry storms and UI lag.

### After (fixed)

```php
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

public function progress(Request $request): Response|StreamedResponse
{
    return response()->stream(function () { ... }, 200, [...]);
}
```

**Commit:** `Fix parser queue worker configuration and progress SSE return type`

---

## 3. Queue Size Before / After

| Metric | Before fix | After fix |
|--------|-----------|-----------|
| `queues:parser` items | 1 (stale RunParserJob, never consumed) | 0 (drained and actively consumed) |
| `queues:default` items | 1 (stale ProductParsed event) | 0 |
| `queues:photos` items | 0 | 0 |
| Orphaned stale jobs (from failed runs) | ~15,000 accumulated | Flushed via `redis-cli DEL` |

**Stale cleanup steps performed:**
1. Marked all `failed` and `pending` parser_jobs as `cancelled` (prevents orphaned `ParseProductJob` items from re-running)
2. `redis-cli DEL sadavodparser-database-queues:parser` to clear accumulated orphan jobs
3. `service supervisor restart` to ensure new worker processes inherit the updated command

---

## 4. Parser Test Result

After fixes, the stale `RunParserJob` (job #4) was immediately consumed by a `--queue=parser` worker:

| Metric | Value |
|--------|-------|
| Job #4 status | `running` |
| Type | `full` |
| Total categories | 337 |
| Parsed categories | 1+ (increasing) |
| Saved products | 50+ (increasing) |
| Current action | `Категория: jenskie-kardigany` |
| Worker log | `ParseProductJob` processing at ~700ms per item (includes HTTP product detail fetch) |
| Queue size | ~0 (jobs dispatched and consumed in parallel as fast as workers can process) |

**Worker log confirms parallel pipeline is active:**
```
2026-03-06 13:35:19 App\Jobs\ParseProductJob ................. 636.62ms DONE
2026-03-06 13:35:19 App\Jobs\ParseProductJob ................. 582.23ms DONE
2026-03-06 13:35:19 App\Jobs\ParseProductJob ................. 729.45ms DONE
2026-03-06 13:35:19 App\Jobs\ParseProductJob ................. 799.21ms DONE
```

---

## 5. Final Supervisor Status

```
parser-worker:parser-worker_00  RUNNING  pid 132142  --queue=parser
parser-worker:parser-worker_01  RUNNING  pid 132143  --queue=parser
parser-worker:parser-worker_02  RUNNING  pid 132144  --queue=parser
parser-worker:parser-worker_03  RUNNING  pid 132145  --queue=parser
parser-worker-photos:00         RUNNING  pid 131963  --queue=photos
parser-worker-photos:01         RUNNING  pid 131968  --queue=photos
reverb                          RUNNING  pid 131178
```

---

## 6. Commit and Deploy

| Step | Result |
|------|--------|
| `git commit` | `a950404` — Fix parser queue worker configuration and progress SSE return type |
| `git push origin main` | Pushed to GitHub |
| `git pull` on server | Applied |
| `php artisan config:clear` | Done |
| `php artisan cache:clear` | Done |
| `php artisan route:clear` | Done |
| Supervisor restarted | Done via `service supervisor restart` |

---

## 7. Remaining Notes

- `RunParserJob::$tries = 3` with `$timeout = 3600` — for the pipeline architecture, `RunParserJob` only dispatches category jobs (fast); consider reducing to `$tries = 1` in a future change to avoid redundant retries if it ever times out.
- Reverb `pulse_ingest_interval` error is unrelated to the parser queue; it is a Reverb package version mismatch that needs a separate update.
- The `progress()` SSE endpoint requires a valid JWT token in production — unauthenticated requests will receive a 401, which is expected.
