# Parser Network Diagnostics

Date: 2026-03-14
Server: `85.117.235.93`
Target: `sadovodbaza.ru:443`

## 1) DNS checks

- `dig +short sadovodbaza.ru` -> `95.213.188.163`
- `nslookup sadovodbaza.ru` -> `95.213.188.163`

Conclusion: DNS resolves correctly.

## 2) Connection checks

- `curl -Iv https://sadovodbaza.ru --max-time 20` -> `cURL error 28`, connect timeout
- `curl -4 -Iv https://sadovodbaza.ru --max-time 20` -> `cURL error 28`, connect timeout

Observed:
- IPv6 is not used (`IPv6: (none)`).
- IPv4 connect to `95.213.188.163:443` times out before TLS handshake.

Conclusion: this is network path/firewall/routing issue between VPS and donor host, not a parser logic error.

## 3) Runtime hardening applied

### HTTP client

Updated `app/Services/Parser/HttpClient.php`:

- forced IPv4 in curl options:
  - `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4`
- timeout remains `60s`
- retry `3`
- delay defaults raised to `1.5s..3s`

### Parser settings defaults

Updated defaults to slower safer delays:

- `request_delay_min = 1500`
- `request_delay_max = 3000`

Added migration:

- `2026_03_14_181000_update_parser_settings_delay_defaults.php`
  - upgrades existing rows to at least `1500/3000`

### Photo downloader

Updated `app/Services/PhotoDownloadService.php`:

- delay driven by parser settings
- delay range `1.5s..3s`

## 4) Supervisor workers normalization

Applied on server:

- parser workers: `2` (already configured)
- photo workers: changed `numprocs` from `6` to `1`

Current supervisor state:

- `parser-worker_00` RUNNING
- `parser-worker_01` RUNNING
- `parser-worker-photos_00` RUNNING

## 5) Deploy actions

- pushed backend changes
- server deploy:
  - `git fetch && git reset --hard origin/main`
  - `composer install --no-dev --optimize-autoloader`
  - `php artisan migrate --force`
  - `php artisan config:clear`
  - `php artisan route:cache`
  - `php artisan queue:restart`

## 6) Final status

- parser API and admin are up
- network timeout to donor still reproducible from server
- parser now uses safer request pacing and reduced queue pressure

## 7) Recommended infra follow-up

- request datacenter/network provider check for outbound 443 to `95.213.188.163`
- test from another VPS/egress IP
- optionally add fallback proxy/egress node for parser traffic
