#!/bin/bash
set -e

echo "START DEPLOY"

########################################
# BACKEND
########################################

cd /var/www/online-parser.siteaacess.store

echo "STEP: BACKEND RESET"
git reset --hard
git clean -fd

echo "STEP: BACKEND PULL"
git pull origin main

echo "STEP: COMPOSER INSTALL"
composer install --no-dev --optimize-autoloader --no-interaction

echo "STEP: MIGRATIONS"
php artisan migrate --force

echo "STEP: CACHE CLEAR"
php artisan config:clear
php artisan cache:clear
php artisan route:clear

########################################
# FRONTEND
########################################

cd /var/www/siteaacess.store

echo "STEP: FRONTEND RESET"
git reset --hard
git clean -fd

echo "STEP: FRONTEND PULL"
git pull origin main

echo "STEP: NPM CI"
npm ci

echo "STEP: BUILD"
npm run build

if [ ! -d "dist" ]; then
  echo "DIST BUILD FAILED"
  exit 1
fi

########################################
# SERVICES
########################################

echo "STEP: NGINX RELOAD"
systemctl reload nginx

echo "STEP: SUPERVISOR RESTART"
supervisorctl restart all

########################################
# HEALTH
########################################

echo "STEP: HEALTH CHECK"
echo "CHECK API"

API=$(curl -s https://online-parser.siteaacess.store/api/v1/health)

echo "$API"

echo "$API" | grep -q '"status"' || exit 1
echo "$API" | grep -q '"status":"ok"' || exit 1

echo "DEPLOY DONE"
