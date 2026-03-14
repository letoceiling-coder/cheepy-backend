# Parser Architecture Report

## Overview

Cheepy parser pipeline is built on Laravel jobs + Redis queues:

- `ParserDaemonJob` controls continuous mode.
- `RunParserJob` starts each parser run.
- `ParseCategoryJob` processes categories.
- `DownloadPhotoJob` processes photo downloads.
- `ParserWatchdog` protects from stalled workers/runs.

Core state:

- `parser_state` (single source of truth: running/stopped/paused)
- `parser_jobs` (run metadata and counters)
- `parser_progress` (live totals/current URL)
- `parser_logs` (info/warning/error diagnostics)
- `parser_settings` (runtime parser tuning)

## HTTP Stability

Unified parser HTTP layer:

- `App\Services\Parser\HttpClient`
  - timeout: 60s (configurable)
  - retry: 3
  - incremental backoff (`attempt * 5000`)
  - random delay before request (0.8-2.0s by default)
  - browser-like headers

`App\Services\SadovodParser\HttpClient` uses parser settings and logs timeout events:

- timeout warnings: `Parser timeout`
- block/captcha detection markers
- metrics updates (`ParserMetricsService`)

Photo downloader stability (`PhotoDownloadService`):

- timeout from parser settings
- 3 attempts + backoff
- random request delay
- timeout warnings: `Parser timeout`

## Queue and Workers

Queues:

- `parser` for parser/daemon/category jobs
- `photos` for photo tasks

Recommended Supervisor setup:

- parser workers: 2
- photo workers: 1

This separation prevents photo spikes from blocking category/product parsing.

## Logging and Diagnostics

`parser_logs` now supports extended diagnostics:

- `type`
- `url`
- `product_id`
- `attempt`

Diagnostics API (`/api/v1/parser/diagnostics`) includes:

- queue sizes
- worker status
- memory usage
- lock status
- last parser errors
- errors per hour
- progress snapshot

## Parser Settings

`parser_settings`:

- `download_photos`
- `store_photo_links`
- `max_workers`
- `request_delay_min`
- `request_delay_max`
- `timeout_seconds`

Exposed in API:

- `GET /api/v1/parser/settings`
- `POST /api/v1/parser/settings`

## Progress

`parser_progress` stores:

- total_items
- processed_items
- failed_items
- current_url

Exposed in API:

- `GET /api/v1/parser/progress-overview`
- `GET /api/v1/parser/progress` (SSE stream)

## Attribute Extraction

Two layers are used:

- `AttributeExtractionService` (rule-based canonical extraction for persistence)
- `App\Services\Parser\AttributeExtractor` (DOM key-value normalization helper)

This keeps extraction resilient to HTML changes and supports normalized output (sizes/colors/etc).

## Validation Command

`php artisan parser:test` checks:

- HTTP accessibility
- HTML structure
- main selectors (`/catalog/`, `/odejda/` links)

## Operational Notes

- Stop parser flow sets `parser_state=stopped`, clears queues, restarts workers.
- Daemon/watchdog/scheduler do not relaunch parser when state is not `running`.
- Admin parser page consumes diagnostics + progress + settings APIs for transparent runtime control.
