# Parser Production Diagnostics Report

**Date:** 2026-03-06  
**Server:** root@85.117.235.93  
**Backend path:** /var/www/online-parser.siteaacess.store  
**Analyst:** automated diagnostics

---

## 1. Server Environment

| Parameter | Value |
|-----------|-------|
| OS | Ubuntu (Linux) |
| PHP | 8.2 (php-fpm8.2) |
| MySQL | MariaDB (mariadbd) |
| Redis | 127.0.0.1:6379 |
| Disk | 29G total, 20G used, 8.9G free (69%) |
| RAM | 1.9 GiB total, 792 MiB used, 258 MiB free, 1.1 GiB buffered |
| Swap | 2.0 GiB total, 276 MiB used |
| Load average | 0.31 / 0.12 / 0.10 (healthy) |

---

## 2. Supervisor Workers

```
parser-worker:parser-worker_00  RUNNING  pid 128074  uptime 0:23:19
parser-worker:parser-worker_01  RUNNING  pid 128479  uptime 0:13:56
parser-worker:parser-worker_02  RUNNING  pid 126118  uptime 1:30:30
parser-worker:parser-worker_03  RUNNING  pid 128419  uptime 0:15:26
parser-worker-photos:00         RUNNING  pid 128080  uptime 0:23:17
parser-worker-photos:01         RUNNING  pid 128069  uptime 0:23:20
reverb                          RUNNING  pid 124080  uptime 1:57:37
```

All 6 workers appear RUNNING. However, actual worker command lines (from `ps aux`) reveal a **critical misconfiguration**:

```
# parser-worker (4 processes):
queue:work redis --sleep=3 --tries=3 --max-time=3600
                 ↑ NO --queue=parser FLAG

# photo workers (2 processes):
queue:work redis --queue=photos --sleep=3 --tries=5 --max-time=3600
                 ↑ correct
```

**Parser workers are listening on the DEFAULT queue, not the PARSER queue.**  
The new `RunParserJob`, `ParseCategoryJob`, `ParseProductJob` are dispatched to `queue=parser`, which no worker is consuming.

---

## 3. Queue Sizes

| Queue (Redis key) | Jobs waiting |
|-------------------|-------------|
| `queues:parser` (prefixed) | **1** |
| `queues:default` (prefixed) | **1** |
| `queues:photos` (prefixed) | 0 |

The item in `queues:parser` is **`App\Jobs\RunParserJob`** — job #4, dispatched but never picked up.  
The item in `queues:default` is **`App\Events\ProductParsed`** — a broadcast event queued into `default`.

---

## 4. Failed Jobs

| # | Failed At | Job | Error |
|---|-----------|-----|-------|
| 1 | 2026-03-06 09:54:32 | RunParserJob | `MaxAttemptsExceededException: attempted too many times` |
| 2 | 2026-03-06 12:09:08 | RunParserJob | `MaxAttemptsExceededException: attempted too many times` |

Secondary failure evidence:
- `RunParserJob permanently failed for job 2: has timed out.` (10:53:01)
- `RunParserJob permanently failed for job 3: has timed out.` (13:07:37)

**Pattern**: Every parser run that started was on the `default` queue (before upgrade). The job ran the full sequential loop for exactly 1 hour (`timeout=3600`), then timed out and was retried `tries=3` times. All three retries timed out → `MaxAttemptsExceededException`.

---

## 5. Current Running Processes

| PID | CPU% | MEM% | RSS | Command |
|-----|------|------|-----|---------|
| 126118 | 1.4% | 3.5% | 71 MB | queue:work redis (no --queue, ≈default) |
| 128074 | 0.2% | 3.0% | 62 MB | queue:work redis (no --queue, ≈default) |
| 128419 | 0.1% | 3.0% | 62 MB | queue:work redis (no --queue, ≈default) |
| 128479 | 0.0% | 3.0% | 62 MB | queue:work redis (no --queue, ≈default) |
| 128080 | 0.0% | 2.7% | 56 MB | queue:work redis --queue=photos |
| 128069 | 0.0% | 2.7% | 56 MB | queue:work redis --queue=photos |
| 124080 | 0.0% | 2.0% | 42 MB | reverb:start |

Total worker memory: ~410 MB. Server load is low. Workers are largely idle.

---

## 6. Redis Memory

| Metric | Value |
|--------|-------|
| `used_memory_human` | **2.87 MB** |
| `used_memory_peak_human` | 21.82 MB (peak was during active parsing) |
| `used_memory_peak_perc` | 13.02% |
| `maxmemory` | 0 (unlimited) |
| `mem_fragmentation_ratio` | 3.29 |
| Total Redis keys | 106 |
| Cache keys (`sadavodparser-cache-*`) | 98 |

Redis is healthy. Memory usage is minimal. No overload.

---

## 7. Server Load

| Metric | Value |
|--------|-------|
| Load average (1/5/15 min) | 0.31 / 0.12 / 0.10 |
| Highest CPU process | mysql 0.8%, worker_02 1.4% |
| RAM available | 1.1 GiB |
| Disk usage | 69% (8.9 GB free) |
| Slow queries | 0 |
| InnoDB lock waits | 0 |

Server load is healthy. No CPU spikes, no memory pressure, no DB locks.

---

## 8. Parser Job Status (parser_jobs table)

| ID | Type | Status | total_cat | parsed_cat | saved_prod | Started | Finished |
|----|------|--------|-----------|------------|------------|---------|----------|
| 4 | full | **pending** | 0 | 0 | 0 | - | - |
| 3 | full | failed | 337 | 3 | 4850 | 12:07:37 | 13:07:37 (1h timeout) |
| 2 | full | failed | 337 | 5 | 5039 | 09:53:01 | 10:53:01 (1h timeout) |
| 1 | full | failed | 337 | 9 | 6276 | 23:44:59 | 00:44:59 (1h timeout) |

**Job #4** is `pending` in Redis queue `parser`. It is stuck there because no worker listens on the `parser` queue.

Jobs #1–#3 ran sequentially (old code path, on `default` queue) and all hit the 3600-second timeout after parsing only 3–9 of 337 categories, because sequential parsing of 337 categories with HTTP requests takes many hours.

Total products in DB: **1,954**  
Total sellers in DB: **27**

---

## 9. Laravel Log Errors

| Timestamp | Error |
|-----------|-------|
| 09:03 | `Block detected: HTTP 200` (donor site blocking) |
| 09:19–09:21 | `CategorySync failed: Block detected: HTTP 200` |
| 09:54 | `RunParserJob has been attempted too many times.` (job #2) |
| 10:53 | `RunParserJob permanently failed for job 2: has timed out.` |
| 11:12 | `Blueprint::unsignedSmallInt does not exist` (migration error, historical) |
| 11:47 | `Table 'attribute_value_normalization' already exists` (migration error, historical) |
| 11:51 | `mb_strtolower(): Argument #2 must be a valid encoding, "0" given` (AuditAttributes) |
| 12:04 | **`ParserController::progress(): Return value must be of type Response, StreamedResponse returned`** |
| 12:09 | `RunParserJob has been attempted too many times.` (job #3) |
| 13:07 | `RunParserJob permanently failed for job 3: has timed out.` |
| 13:15 | **`ParserController::progress(): Return value must be of type Response, StreamedResponse returned`** |
| Recurring | `Undefined array key "pulse_ingest_interval"` (Reverb version mismatch) |

---

## 10. API Response Time

| Endpoint | HTTP | Time |
|----------|------|------|
| `GET /api/v1/system/status` | 200 | **0.032 s** |
| `GET /api/v1/parser/status` | 200 | **0.028 s** |
| `GET /api/v1/parser/progress` | 500 (TypeError) | N/A |

API response times are excellent. The `progress` SSE endpoint is broken (return type mismatch).

---

## 11. Queue Blocking Analysis

Active queue monitoring over 10s showed:
- `parser` queue: 1 stale `RunParserJob` waiting since deployment, untouched.
- `default` queue: 1 `ProductParsed` event processing by workers periodically.
- No burst or repeat pattern. Workers are mostly sleeping (3-second sleep interval).
- Worker log contains 26,654 processed events total (mostly `ProductParsed` from past runs).

---

## 12. Root Cause Analysis

### PRIMARY ISSUE: **Queue name mismatch**

The parser upgrade changed `RunParserJob::$this->onQueue('parser')` (was `default`).  
But the Supervisor `parser-worker` config does **NOT include `--queue=parser`**:

```ini
; ACTUAL (wrong):
command=... queue:work redis --sleep=3 --tries=3 --max-time=3600

; REQUIRED:
command=... queue:work redis --queue=parser --sleep=3 --tries=3 --max-time=3600
```

**Effect**: `RunParserJob` is dispatched to Redis key `queues:parser`, but all 4 parser workers consume `queues:default`. **The parser job sits in the queue forever and never starts.**

---

### SECONDARY ISSUES

| # | Issue | Impact |
|---|-------|--------|
| S1 | `parser/progress` endpoint returns `StreamedResponse` but method declares `Response` return type | Admin UI progress polling returns HTTP 500 → UI shows no progress, appears frozen |
| S2 | Previous jobs (#1–#3) ran sequential parser (old code) for 3600s then hit timeout | Historic failures; no current functional impact now that new code is deployed |
| S3 | `RunParserJob::$tries = 3` × `$timeout = 3600` = up to 3 hours per run retry cycle | If job somehow picks up and fails, it blocks workers for 3h per attempt |
| S4 | Recurring `pulse_ingest_interval` Reverb error (version mismatch in reverb package) | Reverb keeps crashing and restarting; affects WebSocket progress updates |
| S5 | Donor site "Block detected: HTTP 200" on category sync | Category sync from admin panel fails intermittently |

---

## 13. Final Conclusion

| Symptom | Root Cause |
|---------|-----------|
| **Parser progress does not move** | Queue mismatch: RunParserJob dispatched to `parser` queue, workers listen on `default` → job never executes |
| **Counters stuck at 0** | Job #4 is `pending` in queue and never starts |
| **Admin panel slow / login sessions drop** | `parser/progress` SSE endpoint throws HTTP 500 (TypeError) — every poll from admin UI fails with error, causing retry storms; Reverb WebSocket crashes repeatedly |
| **Queue metrics suspicious** | 1 RunParserJob frozen in `parser` queue; 1 ProductParsed event in `default` queue; both unprocessed for different reasons |
| **UI becomes laggy** | Admin panel polls failing endpoints repeatedly; Reverb WebSocket disconnects and reconnects; combined network overhead slows the UI |

---

## 14. Required Fixes (for reference only — no code changes made in this audit)

1. **Fix Supervisor parser-worker config** — add `--queue=parser` to the worker command:
   ```ini
   command=... queue:work redis --queue=parser --sleep=3 --tries=3 --max-time=3600
   ```
   Then: `supervisorctl reread && supervisorctl update && supervisorctl restart parser-worker:*`

2. **Fix `ParserController::progress()` return type** — change method signature from `: Response` to `: Response|\Symfony\Component\HttpFoundation\StreamedResponse` or `mixed`, since it returns a `StreamedResponse`.

3. **Flush stale queue items** — after fixing supervisor:
   ```bash
   php artisan queue:flush   # clear failed_jobs
   # re-dispatch job #4 or start new parser run from admin UI
   ```

4. **Increase RunParserJob::$tries = 1** — with the new pipeline architecture, RunParserJob only dispatches category jobs (fast); retrying after timeout makes no sense and wastes workers for 3h.

5. **Reverb `pulse_ingest_interval` error** — update `laravel/reverb` package or pin to a compatible version.

---

**Bottom line: The system is NOT overloaded. Server, Redis, and MySQL are all healthy. The parser is stuck because of a single Supervisor config line missing `--queue=parser`. The admin UI slowness is caused by the progress SSE endpoint returning HTTP 500 on every poll.**
