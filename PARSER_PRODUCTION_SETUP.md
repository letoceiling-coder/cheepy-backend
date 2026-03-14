# Parser Production Setup

## 1) Proxy Gateway

- Parser traffic is routed through:
  - `http://89.169.39.244:3128`
- Proxy is treated as mandatory in production parser flow.
- HTTP requests are executed with:
  - IPv4 force: `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4`
  - retry + exponential backoff
  - random delay between requests
  - user-agent rotation

## 2) Queue Architecture

- Dedicated queues:
  - `parser`
  - `photos`
- Recommended worker layout:
  - parser workers: `2`
  - photo workers: `1`
- Queue protection:
  - if parser backlog grows above threshold (`500`), daemon throttles new starts.

## 3) Auto Recovery and Circuit Breaker

- Network circuit breaker:
  - after 5 consecutive timeout/network errors parser state switches to `paused_network`.
- Recovery:
  - scheduled recovery checks run every 5 minutes.
  - on successful connectivity check parser resumes.
- Watchdog:
  - restarts workers when queue has jobs but workers are missing.
  - restarts daemon when parser is idle and queue is empty.

## 4) Progress and Speed

- `parser_progress` stores:
  - `total_items`
  - `processed_items`
  - `failed_items`
  - `current_url`
  - `speed_per_min`
- Speed is computed as:
  - `processed_items / elapsed_minutes`
- Speed refresh is throttled to every 10 processed items.

## 5) Health and Diagnostics

- Endpoint:
  - `GET /api/v1/parser/health`
- Returns:
  - parser state
  - queue sizes
  - worker count
  - proxy status
  - donor availability status

## 6) Deploy Flow

- Deploy script: `scripts/deploy.sh`
- First gate:
  - `php artisan system:preflight`
- If preflight fails, deploy stops.
- Then standard sequence:
  1. `php artisan config:clear`
  2. `php artisan route:clear`
  3. `php artisan cache:clear`
  4. `php artisan migrate --force`
  5. `php artisan config:cache`
  6. `php artisan route:cache`
  7. `php artisan queue:restart`
