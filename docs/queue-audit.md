# Queue Audit

**Date:** 2026-03-06

---

## Dispatch Usage

| Job | Queue | Connection | Dispatch Location |
|-----|-------|------------|-------------------|
| RunParserJob | parser | redis* | ParserController::start, startDaemon; ParserDaemonJob; scripts |
| ParserDaemonJob | parser | redis* | ParserController::startDaemon; ParserDaemonStart; ScheduleNextParserDaemon; self |
| ParseCategoryJob | parser | redis* | DatabaseParserService::runFullPipeline |
| ParseProductJob | parser | redis* | ParseCategoryJob::handle |
| DownloadPhotoJob | photos | redis* | DatabaseParserService::saveProductFromListing |

\* Jobs use `$this->onQueue('parser')` or `onQueue('photos')`; connection comes from `config('queue.default')` (env QUEUE_CONNECTION). Production typically uses `redis`.

---

## Code References

### dispatch()

- `ParserController::start()` → RunParserJob::dispatch($job->id)
- `ParserController::startDaemon()` → ParserDaemonJob::dispatch()
- `ParserDaemonJob` → RunParserJob::dispatch($job->id); self::dispatch()->delay(60)
- `ScheduleNextParserDaemon` → ParserDaemonJob::dispatch()->delay(60)
- `DatabaseParserService::runFullPipeline()` → ParseCategoryJob::dispatch()
- `ParseCategoryJob::handle()` → ParseProductJob::dispatch()
- `DatabaseParserService::saveProductFromListing()` → DownloadPhotoJob::dispatch()

### onQueue()

- RunParserJob: `$this->onQueue('parser')`
- ParserDaemonJob: `$this->onQueue('parser')`
- ParseCategoryJob: `$this->onQueue('parser')`
- ParseProductJob: `$this->onQueue('parser')`
- DownloadPhotoJob: `$this->onQueue('photos')`

### Queue::connection()

- ParserController::status, stats: `Queue::connection('redis')`
- ParseCategoryJob: `Queue::connection('redis')->size('parser')`
- routes/api.php (system status): `Queue::connection(config('queue.default'))->size('default')`

---

## config/queue.php

| Setting | Value |
|---------|-------|
| default | env('QUEUE_CONNECTION', 'database') |
| redis.driver | redis |
| redis.connection | env('REDIS_QUEUE_CONNECTION', 'default') |
| redis.queue | env('REDIS_QUEUE', 'default') |
| redis.retry_after | env('REDIS_QUEUE_RETRY_AFTER', 90) |
| failed.driver | env('QUEUE_FAILED_DRIVER', 'database-uuids') |
| failed.table | failed_jobs |

**Note:** Jobs explicitly call `onQueue('parser')` or `onQueue('photos')`, overriding the default queue name. The connection is still from `queue.default` (usually redis in production).

---

## Redis Key Format

With Redis prefix `APP_NAME-database-` (e.g. `sadavodparser-database-`):

- `{prefix}queues:parser` — parser queue list
- `{prefix}queues:photos` — photos queue list
- `{prefix}queues:default` — default queue (if any jobs use it)
- `{prefix}queues:parser:reserved` — reserved (in-flight) jobs
