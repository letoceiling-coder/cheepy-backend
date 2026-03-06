# Parser Architecture Upgrade Report

## Summary

The Cheepy parser was upgraded from a **sequential** (single-worker) architecture to a **parallel queue pipeline**. Existing API and admin UI remain compatible.

---

## Files Created

| File | Purpose |
|------|--------|
| `app/Jobs/ParseCategoryJob.php` | Job: parse one category (pages), dispatch ParseProductJob per product |
| `app/Jobs/ParseProductJob.php` | Job: parse one product, save, optionally dispatch DownloadPhotoJob |
| `app/Jobs/DownloadPhotoJob.php` | Job: download product images, update `product_photos` and `photos_count` |
| `docs/PARSER_ROLLBACK.md` | Rollback instructions to stable tag `parser-stable-v1` |
| `docs/PARSER_PERFORMANCE.md` | Performance notes and test template |
| `supervisor-parser-queues.conf.example` | Supervisor config for 4 parser workers + 2 photo workers |

---

## Files Modified

| File | Changes |
|------|--------|
| `app/Services/DatabaseParserService.php` | Added `runFullPipeline()` (dispatches ParseCategoryJob per category); `saveProductFromListing()` is public, accepts `$dispatchPhotosToQueue` and dispatches DownloadPhotoJob when true; logging context (product_external_id, job_id); zero-categories completion handling |
| `app/Jobs/RunParserJob.php` | Queue set to `parser` (was `default`) |
| `app/Services/SadovodParser/Parsers/CatalogParser.php` | Added `parseCategoryPage($path, $pageNumber)` for single-page fetch (used by ParseCategoryJob) |
| `app/Services/SadovodParser/HttpClient.php` | Uses `config(parser_rate.max_requests_per_second)` for rate limit (5 req/s); delay defaults 200–500 ms |
| `config/sadovod.php` | `request_delay_ms` default 200 |
| `config/parser_rate.php` | `max_requests_per_second` = 5; `max_requests_per_minute` = 300; delay_min_ms 200, delay_max_ms 500 |
| `app/Http/Controllers/Api/ParserController.php` | `stats()` returns `queue_parser_size` and `queue_photos_size` in addition to `queue_size` |

---

## Queue Changes

| Queue | Jobs | Recommended workers |
|-------|------|---------------------|
| `parser` | RunParserJob, ParseCategoryJob, ParseProductJob | 4 |
| `photos` | DownloadPhotoJob | 2 |

- **RunParserJob** and **ParseCategoryJob** / **ParseProductJob** now use queue `parser`.
- **DownloadPhotoJob** uses queue `photos` to avoid blocking parser work.

---

## Pipeline Flow

1. **POST /api/v1/parser/start** (type `full`) → creates ParserJob, dispatches **RunParserJob** to `parser`.
2. **RunParserJob** runs menu sync, then dispatches one **ParseCategoryJob** per category to `parser`.
3. **ParseCategoryJob** for each category: fetches catalog pages via `parseCategoryPage()`, dispatches **ParseProductJob** for each product to `parser`.
4. **ParseProductJob** runs product parse + save + attributes; if `save_photos` is true, creates photo records and dispatches **DownloadPhotoJob** to `photos`.
5. **DownloadPhotoJob** downloads images, updates job `photos_downloaded` / `photos_failed`, and product `photos_count`.
6. When **parsed_categories** reaches **total_categories**, the last **ParseCategoryJob** sets ParserJob status to `completed` and fires **ParserFinished**.

---

## HTTP & Rate Limiting

- **Request delay:** `config('sadovod.request_delay_ms')` default **200** ms.
- **Rate limit:** **5 requests per second** via `parser_rate.max_requests_per_second`.
- **Retries:** 3 with exponential backoff [2, 5, 10] seconds (unchanged).
- **Connections:** HttpClient already uses `Connection: keep-alive`.

---

## Progress & API Compatibility

- **GET /api/v1/parser/status** — unchanged; returns current/last job with progress.
- **GET /api/v1/parser/progress** — unchanged.
- **GET /api/v1/parser/stats** — now includes `queue_parser_size` and `queue_photos_size`; `queue_size` = sum of both.

Progress fields (`total_categories`, `parsed_categories`, `parsed_products`, `saved_products`, etc.) are updated atomically by jobs (`increment()`).

---

## Rollback

- **Tag:** `parser-stable-v1`
- **Branch:** `backup/parser-stable-YYYYMMDD`
- See **docs/PARSER_ROLLBACK.md** for checkout and redeploy steps.

---

## Performance

- **Before:** One worker; categories and products processed sequentially.
- **After:** Up to 4 parser workers + 2 photo workers; categories and products processed in parallel.
- Fill actual **products per minute** and comparison in **docs/PARSER_PERFORMANCE.md** after a test run on 5 categories.
