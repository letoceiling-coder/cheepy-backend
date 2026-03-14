#!/usr/bin/env bash
set -euo pipefail

php artisan config:clear
php artisan route:clear
php artisan cache:clear

php artisan migrate --force

php artisan config:cache
php artisan route:cache

php artisan queue:restart
