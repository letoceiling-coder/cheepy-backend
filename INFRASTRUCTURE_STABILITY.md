# Infrastructure Stability

## MySQL and Redis Runtime State

- MySQL/MariaDB service must be active before any artisan action:
  - `systemctl status mysql` or `systemctl status mariadb`
- Redis service must be active:
  - `systemctl status redis`
- Expected listeners:
  - MySQL: `127.0.0.1:3306`
  - Redis: `127.0.0.1:6379`

## MySQL Socket and Host Mode

- Check socket path:
  - `mysqladmin variables | grep socket`
- Use one of two valid DB modes in `.env`:
  - socket-friendly local host: `DB_HOST=localhost`
  - explicit TCP mode: `DB_HOST=127.0.0.1`
- Keep `DB_PORT=3306` for TCP mode.

## Redis Environment

Recommended `.env` values:

- `REDIS_HOST=127.0.0.1`
- `REDIS_PORT=6379`

## Broadcast Safety

- Broadcast fallback is hardened in `config/broadcasting.php`.
- Console commands default to `log` broadcaster.
- If Reverb credentials are missing, app falls back to `log`.

## Deploy Safety

`scripts/deploy.sh` now starts with:

1. `php artisan system:preflight`

Deployment continues only if preflight passes. Then:

1. `php artisan config:clear`
2. `php artisan route:clear`
3. `php artisan cache:clear`
4. `php artisan migrate --force`
5. `php artisan config:cache`
6. `php artisan route:cache`
7. `php artisan queue:restart`

## Preflight Coverage

`php artisan system:preflight` checks:

- database connection
- redis connection
- proxy connection (when enabled)
- donor access (`https://sadovodbaza.ru`)
