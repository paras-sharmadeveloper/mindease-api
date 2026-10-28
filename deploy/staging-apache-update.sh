#!/usr/bin/env bash
# Pull latest code and redeploy the Apache staging install (run as root).
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/mindease}"
PHP=php8.4
cd "$APP_DIR"

git pull --ff-only
$PHP artisan down --retry=15 || true
trap '$PHP artisan up' EXIT

COMPOSER_ALLOW_SUPERUSER=1 $PHP "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
chown -R root:www-data "$APP_DIR"
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan queue:restart
systemctl reload php8.4-fpm

echo "Deployed $(git rev-parse --short HEAD)"
