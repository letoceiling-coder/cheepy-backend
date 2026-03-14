# Parser System Architecture

## 1. Current Pipeline

1. `ParserDaemonJob` starts a new parser cycle only when `parser_state.status = running`.
2. `RunParserJob` executes parsing and writes product/category results.
3. `ScheduleNextParserDaemon` schedules the next daemon iteration.
4. `ParserWatchdog` monitors workers and queue health.
5. `parser:network-recover` attempts automatic recovery from network pause state.

## 2. Concurrency and Queue Model

- Queue separation:
  - `parser` queue for parser orchestration and parsing jobs.
  - `photos` queue for image downloads.
- Expected worker allocation:
  - parser workers: 2
  - photo workers: 1
- Queue protection:
  - `queue_threshold` in `parser_settings` limits daemon re-dispatch when backlog is too high.

## 3. HTTP Stability Layer

`app/Services/Parser/HttpClient.php` is the unified HTTP layer:

- timeout: 60 seconds (configurable)
- retry: 3 attempts
- exponential backoff: 1s, 2s, 4s
- random delay before request: 1.5-3.0 sec
- forced IPv4: `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4`
- rotating user-agent pool
- optional proxy gateway via `proxy_enabled + proxy_url`

Proxy gateway:

- endpoint: `http://89.169.39.244:3128`
- used only by parser HTTP traffic when enabled

## 4. Auto-Recovery (Circuit Breaker)

- On timeout streak (`>=5`), parser switches to `paused_network`.
- Timeout streak is stored in cache key `parser:network_timeout_streak`.
- Scheduler runs `parser:network-recover` every 5 minutes:
  - checks donor availability (via current parser HTTP settings/proxy)
  - if healthy, resets streak, sets state to `running`, dispatches daemon

State lifecycle:

- `running` -> `paused_network` (network fault)
- `paused_network` -> `running` (recovery success)
- `running` -> `paused` / `stopped` (manual control)

## 5. Logging and Diagnostics

- `parser_logs` stores typed events:
  - `info`, `warning`, `error`, `network_error`, `parsing_error`
- `ParserLogger` centralizes parser-specific logging to `parser_logs`.
- Diagnostics API (`/api/v1/parser/diagnostics`) reports:
  - parser state
  - queue sizes
  - worker status
  - memory usage
  - recent errors and error frequency
  - progress snapshot

## 6. Progress Tracking

`parser_progress` stores:

- `total_items`
- `processed_items`
- `failed_items`
- `current_url`
- `speed_per_min`

Progress is updated continuously during parsing; UI consumes overview via API.

## 7. Attribute Parsing

`app/Services/Parser/AttributeExtractor.php` extracts and normalizes:

- product name
- price
- brand
- sizes
- colors
- key-value characteristics

This is intended as a shared parser utility to reduce selector drift and duplicated logic.

## 8. Operational Flow (Internal Scheme)

```text
Admin UI -> ParserController -> parser_state + parser_settings
          -> dispatch ParserDaemonJob (queue: parser)
          -> RunParserJob -> DatabaseParserService
                           -> SadovodParser\HttpClient
                              -> Parser\HttpClient
                              -> (optional proxy)
                           -> ParserLogger + parser_logs
                           -> parser_progress updates

Scheduler:
  parser:watchdog (5m)      -> workers/queue safety
  parser:network-recover(5m)-> circuit-breaker recovery
  parser:lock-heartbeat(30s)-> lock keepalive
```

## 9. Bottlenecks and Mitigations

- Network timeouts / anti-bot:
  - mitigated by proxy, retries, backoff, UA rotation, delay jitter
- Queue overflow:
  - mitigated by `queue_threshold` gate and separated queues
- Worker drift:
  - mitigated by watchdog and supervisor restart workflow
- Selector drift:
  - mitigated by `parser:test` and `AttributeExtractor`

## 10. Deployment Checklist

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan config:clear`
4. `php artisan route:cache`
5. `php artisan queue:restart`
6. Frontend: `npm run build`
7. Web: `systemctl reload nginx`
