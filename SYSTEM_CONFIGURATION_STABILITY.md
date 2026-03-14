# System Configuration Stability

## Broadcast Configuration

- `config/broadcasting.php` now defaults to:
  - `log` in console mode
  - `reverb` in web mode
- Safe fallback is enabled:
  - if any of `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID` is missing, broadcaster is forced to `log`.
- This prevents artisan/runtime crashes like:
  - `Failed to create broadcaster for connection "reverb" ... auth_key null`.

## Required Environment Variables

Recommended baseline:

- `BROADCAST_CONNECTION=log`
- `REVERB_APP_KEY=`
- `REVERB_APP_SECRET=`
- `REVERB_APP_ID=`

If Reverb is intentionally enabled in production, set all three REVERB credentials.

## Deploy Flow

Use `scripts/deploy.sh`:

1. `php artisan config:clear`
2. `php artisan route:clear`
3. `php artisan cache:clear`
4. `php artisan migrate --force`
5. `php artisan config:cache`
6. `php artisan route:cache`
7. `php artisan queue:restart`

## Health and Diagnostics

- New command: `php artisan system:check`
  - validates env/broadcast settings
  - checks DB/Redis/Queue
  - checks parser settings and proxy configuration
- New endpoint: `GET /api/v1/system/health`
  - returns `database`, `redis`, `queue`, `broadcast`, `parser` sections
  - includes safe-fallback visibility for broadcast driver

## Reverb Safety

- If Reverb credentials are missing, the app uses `log` broadcaster.
- Reverb process should only be started when credentials are configured.
