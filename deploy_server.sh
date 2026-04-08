#!/bin/bash

LOG_FILE=/var/www/online-parser.siteaacess.store/deploy.log
: > "$LOG_FILE"

exec > >(tee -a $LOG_FILE) 2>&1

set -e

cd /var/www/online-parser.siteaacess.store

if [[ -n $(git status --porcelain) ]]; then
  echo "GIT DIRTY - STOP"
  exit 1
fi

echo "START DEPLOY"

git pull origin main

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan cache:clear
php artisan config:clear
php artisan route:clear

cd /var/www/siteaacess.store

npm ci
npm run build

systemctl reload nginx

supervisorctl restart all

echo "DEPLOY DONE"
