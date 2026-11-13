#!/usr/bin/env bash
# Pull latest code and redeploy the Apache staging install (run as root).
#   bash /var/www/mindease/deploy/staging-apache-update.sh
# Deploys the `main` branch (override with BRANCH=...).
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/mindease}"
BRANCH="${BRANCH:-main}"
PHP=php8.4
cd "$APP_DIR"

# Always deploy BRANCH, even if the server was left on another branch.
git fetch origin "$BRANCH"
git checkout -q "$BRANCH"
git merge --ff-only "origin/$BRANCH"

# Composer: our own copy run on PHP 8.4 (composer84), else whatever is on PATH, else install composer84.
if [ -f /usr/local/bin/composer84 ]; then
  COMPOSER=/usr/local/bin/composer84
elif command -v composer >/dev/null; then
  COMPOSER="$(command -v composer)"
else
  $PHP -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  $PHP /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer84
  rm -f /tmp/composer-setup.php
  COMPOSER=/usr/local/bin/composer84
fi

# Profile photos need GD (php8.4-gd).
$PHP -r 'exit(function_exists("imagecreatefromstring") ? 0 : 1);' || echo "WARNING: php8.4-gd is missing, so profile photo uploads fail. Run: apt install -y php8.4-gd && systemctl reload php8.4-fpm"

$PHP artisan down --retry=15 || true
trap '$PHP artisan up' EXIT

COMPOSER_ALLOW_SUPERUSER=1 $PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
chown -R root:www-data "$APP_DIR"
# The web server (www-data) must write to storage (logs, cache) and public/profile_pics (profile photos).
# Re-apply on every deploy.
mkdir -p public/profile_pics
setfacl -R -m u:www-data:rwX storage bootstrap/cache public/profile_pics
setfacl -dR -m u:www-data:rwX storage bootstrap/cache public/profile_pics
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan queue:restart
systemctl reload php8.4-fpm

echo "Deployed $(git rev-parse --short HEAD) from $BRANCH"
