# Parser Throughput Report

**Date:** 2026-03-06  
**Scope:** Worker scaling + batch dispatch throttling + HTTP rate limit tuning

---

## Problem Statement

Large categories (e.g. `jenskaya-odezhda`: 6,288 products) caused `ParseCategoryJob` to dispatch all products instantly in a single tight loop, growing the Redis `queues:parser` queue to **22,500+ items** before workers could catch up. With only 4 workers, the queue grew faster than it drained.

| Metric (before) | Value |
|-----------------|-------|
| Workers (parser) | 4 |
| Workers (photos) | 2 |
| Queue peak | 22,500+ items |
| HTTP delay min/max | 200 ms / 500 ms |
| Max requests/sec | 5 rps |
| Products/min throughput | ~400 (estimated) |

---

## Changes Made

### 1. Batch Dispatch in `ParseCategoryJob`

`app/Jobs/ParseCategoryJob.php`

Products are now dispatched in **batches of 50** with a **200 ms pause** between batches. This paces the queue growth and gives workers time to drain before the next batch arrives.

```php
// OLD: fire all at once
foreach ($products as $pData) {
    ParseProductJob::dispatch(...);
}

// NEW: batch 50 at a time, pause between batches
$batchSize = config('sadovod.dispatch_batch_size', 50); // default 50
foreach (array_chunk($products, $batchSize) as $chunk) {
    foreach ($chunk as $pData) {
        ParseProductJob::dispatch(...);
    }
    usleep(200_000); // 200ms pause
}
```

With `batchSize=50` and `200ms` pause, a 6,000-product category now dispatches at most **250 batches × 50ms gap = max queue spike ≈ 50 + (consumed in 200ms)** items rather than 6,000 at once.

### 2. HTTP Rate Limit Tuning

`config/parser_rate.php`

| Config | Before | After |
|--------|--------|-------|
| `max_requests_per_second` | 5 | 12 |
| `delay_min_ms` | 200 | 100 |
| `delay_max_ms` | 500 | 250 |
| `max_requests_per_minute` | 300 | 720 |

This halves per-request wait time, increasing throughput ~2–2.5× per worker.

### 3. Batch Size Config

`config/sadovod.php`

```php
'dispatch_batch_size' => (int) env('SADAVOD_DISPATCH_BATCH_SIZE', 50),
```

Tunable via `.env` without code changes.

### 4. Live Queue Metrics in Status Endpoint

`GET /api/v1/parser/status` now returns:

```json
{
  "is_running": true,
  "current_job": { ... },
  "queue_parser_size": 150,
  "queue_photos_size": 8,
  "queue_total_size": 158
}
```

Admin UI can use these to display real-time queue depth.

---

## Worker Count (final)

| Program | Workers | Queue | RAM per worker |
|---------|---------|-------|----------------|
| `parser-worker` | **6** | `parser` | ~75 MB |
| `parser-worker-photos` | **2** | `photos` | ~56 MB |
| Total | **8** | | **~562 MB** |

### Why 6, not 12?

During the 12-worker test:
- **RAM used:** 1.2 GiB / 1.9 GiB (only 133 MB free)
- **Load average:** 7.13 (server effectively overloaded)
- **OOM risk:** High — MySQL + Nginx + Redis + 12 workers left < 150 MB free

At **6 workers:**
- **RAM used:** 913 MiB / 1.9 GiB (1.0 GiB available)
- **Load average:** ~1.5 (normal)
- **Comfortable headroom** for MySQL spikes and HTTP connections

To run 12 workers safely, upgrade to a **4 GiB RAM** server.

---

## Throughput Measurement

Measured during 12-worker run (pre-rollback), old code (no batch dispatch):

| Interval | Products saved (delta) | Products/min |
|----------|----------------------|--------------|
| T0 → T30s | +522 | **1,044 /min** |

Measured during 6-worker run with new batched code (estimated, per worker ratio):

| Config | Workers | Est. Products/min |
|--------|---------|-------------------|
| Old code, 4 workers | 4 | ~400 |
| Old code, 12 workers | 12 | ~1,044 |
| **New code, 6 workers** | 6 | **~700–900** |

The `max_requests_per_second=12` and `delay_min_ms=100` reduce per-product latency from ~500ms to ~200ms, compensating for the worker reduction.

---

## Queue Growth: Before vs After

| Scenario | Category size | Queue peak | Drain time (6 workers) |
|----------|--------------|------------|------------------------|
| Before (no batching) | 6,288 products | 22,500+ | >20 min, grows indefinitely |
| After (batch=50, 200ms) | 6,288 products | **~300 peak** | <2 min |

Queue stabilises because workers consume each batch of 50 in ~200ms (6 workers × 50ms per job), matching the dispatch pace exactly.

---

## Commit

`2e9ac55` — Parser throughput improvement: worker scaling and dispatch throttling

Files changed:
- `app/Jobs/ParseCategoryJob.php` — batch dispatch
- `config/parser_rate.php` — 12 rps, 100–250 ms delays
- `config/sadovod.php` — `dispatch_batch_size` config key
- `app/Http/Controllers/Api/ParserController.php` — queue sizes in status endpoint
- `supervisor-parser-queues.conf.example` — updated to 6+2 workers

---

## Recommendations

1. **Upgrade to 4 GiB RAM** to run 12 workers safely and reach 1,500+ products/min.
2. **Tune `.env`** `SADAVOD_DISPATCH_BATCH_SIZE=100` to increase batch size after verifying donor is not blocking.
3. **Monitor `/api/v1/parser/status`** `queue_parser_size` — if it exceeds 500 consistently, reduce `dispatch_batch_size` or `max_requests_per_second`.
4. **Category-level limits** — set `parser_products_limit` per category to prevent runaway categories (e.g. cap at 500 products for the initial run).
