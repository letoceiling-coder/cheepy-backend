#!/bin/bash
set -e

echo "START DEPLOY"

########################################
# BACKEND
########################################

cd /var/www/online-parser.siteaacess.store

git reset --hard
git clean -fd
git pull origin main

composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force

php artisan config:clear
php artisan cache:clear
php artisan route:clear

########################################
# FRONTEND
########################################

cd /var/www/siteaacess.store

git reset --hard
git clean -fd
git pull origin main

npm ci
npm run build

if [ ! -d "dist" ]; then
  echo "DIST BUILD FAILED"
  exit 1
fi

########################################
# SERVICES
########################################

systemctl reload nginx

supervisorctl restart all

########################################
# HEALTH
########################################

echo "CHECK API"

API=$(curl -s https://online-parser.siteaacess.store/api/v1/health)

echo "$API"

echo "$API" | grep -q '"status"' || exit 1
echo "$API" | grep -q '"status":"ok"' || exit 1

echo "DEPLOY DONE"
