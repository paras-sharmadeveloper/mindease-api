#!/usr/bin/env bash
# Deploy latest code. Run on the server as the deploy user:
#   bash /var/www/mindease/deploy/deploy.sh
# --no-pull  skip git pull (used by server-setup.sh)
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

if [ "${1:-}" != "--no-pull" ]; then
  git -C "$APP_DIR" pull --ff-only
fi

php artisan down --retry=15 || true
trap 'php artisan up' EXIT

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize          # config, routes, events, views cache
php artisan queue:restart

# OPcache has validate_timestamps=0, so reload PHP-FPM to pick up new code.
sudo -n systemctl reload php8.4-fpm 2>/dev/null || echo "Note: reload php8.4-fpm as root to clear OPcache"

echo "Deployed $(git -C "$APP_DIR" rev-parse --short HEAD)"
