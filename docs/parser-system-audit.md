# Parser System Audit

**Date:** 2026-03-06  
**Goal:** Document all parser-related jobs, queues, and behavior.

---

## Parser Jobs

| Job | Queue | Timeout | Tries | Backoff | Connection |
|-----|-------|---------|-------|---------|------------|
| RunParserJob | parser | 3600s | 3 | [60, 300, 900] | redis |
| ParserDaemonJob | parser | 120s | 1 (default) | - | redis |
| ParseCategoryJob | parser | 1800s | 2 | default | redis |
| ParseProductJob | parser | 300s | 2 | default | redis |
| DownloadPhotoJob | photos | 600s | 2 | default | redis |

---

## Job Details

### RunParserJob

- **File:** `app/Jobs/RunParserJob.php`
- **Queue:** `parser`
- **Dispatch locations:**
  - `ParserController::start()` — API POST /parser/start
  - `ParserController::startDaemon()` — API POST /parser/start-daemon
  - `ParserDaemonJob::handle()`
  - `scripts/start-parser-job.php`
  - `scripts/start-parser-category.php`
- **Worker requirements:** Must listen on `parser` queue with `redis` connection
- **Failure behavior:** Updates ParserJob to `failed`, logs error, retries 3× with backoff
- **Notes:** Calls DatabaseParserService::run() synchronously; dispatches ParseCategoryJob for each category

### ParserDaemonJob

- **File:** `app/Jobs/ParserDaemonJob.php`
- **Queue:** `parser`
- **Dispatch locations:**
  - `ParserController::startDaemon()`
  - `ParserDaemonStart` command
  - `ScheduleNextParserDaemon` listener (on ParserFinished)
  - Self-dispatch (when run already in progress, schedules next in 60s)
- **Worker requirements:** Same as RunParserJob
- **Failure behavior:** Default (no explicit failed handler)
- **Notes:** Checks `parser_daemon_enabled` setting; if run in progress, re-dispatches self with 60s delay

### ParseCategoryJob

- **File:** `app/Jobs/ParseCategoryJob.php`
- **Queue:** `parser`
- **Dispatch locations:** `DatabaseParserService::runFullPipeline()` (foreach category)
- **Worker requirements:** Must listen on `parser` queue
- **Failure behavior:** Retries 2×
- **Notes:** Dispatches ParseProductJob in batches (50/chunk, 200ms pause); waits when queue > 500

### ParseProductJob

- **File:** `app/Jobs/ParseProductJob.php`
- **Queue:** `parser`
- **Dispatch locations:** `ParseCategoryJob::handle()` (foreach product in page)
- **Worker requirements:** Same as ParseCategoryJob
- **Failure behavior:** Retries 2×

### DownloadPhotoJob

- **File:** `app/Jobs/DownloadPhotoJob.php`
- **Queue:** `photos`
- **Dispatch locations:** `DatabaseParserService::saveProductFromListing()` (when savePhotos)
- **Worker requirements:** Must listen on `photos` queue (separate from parser)
- **Failure behavior:** Retries 2×

---

## Queue Structure

| Queue | Jobs | Worker count (example) |
|-------|------|------------------------|
| parser | RunParserJob, ParserDaemonJob, ParseCategoryJob, ParseProductJob | 6 |
| photos | DownloadPhotoJob | 2 |

---

## Potential Issues

1. **RunParserJob blocks worker:** RunParserJob calls DatabaseParserService::run() which dispatches ParseCategoryJobs and waits (sync) — actually no, run() returns after dispatching; RunParserJob finishes quickly. DatabaseParserService::run() uses match() and for full type calls runFullPipeline() which only dispatches ParseCategoryJobs and returns. So RunParserJob exits quickly.

2. **ParserDaemonJob on same queue:** Competes with ParseCategoryJob/ParseProductJob for workers. Under heavy load, daemon iteration may be delayed.

3. **No Redis lock:** Multiple RunParserJob could be dispatched (e.g. API + daemon) — ParserDaemonJob checks for running job, but API start does not check daemon.
