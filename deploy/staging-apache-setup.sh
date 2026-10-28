#!/usr/bin/env bash
# MindEase API - STAGING setup on an EXISTING Ubuntu 22.04/24.04 server that already runs
# Apache + MySQL + other sites. Safe for those sites:
#   - PHP 8.4-FPM is installed ALONGSIDE the existing PHP; the CLI default stays as it was.
#   - Only a new Apache vhost (api domain) is added; nothing else in Apache is touched.
#   - Uses the existing MySQL server (new database + user only).
#
#   DOMAIN=api.mindease.me ADMIN_EMAIL=you@example.com bash staging-apache-setup.sh
#
# STAGING ONLY: use dummy data. Production must run in an Australian region.
set -euo pipefail

: "${DOMAIN:?Set DOMAIN, e.g. api.mindease.me}"
: "${ADMIN_EMAIL:?Set ADMIN_EMAIL for the SSL certificate}"
REPO_URL="${REPO_URL:-https://github.com/paras-sharmadeveloper/mindease-api.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/mindease}"
PHP=php8.4
DB_NAME=mindease
DB_USER=mindease
CRED_FILE=/root/mindease-credentials.txt

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
export DEBIAN_FRONTEND=noninteractive

# Remember the current PHP CLI so other apps' cron jobs keep using it.
OLD_PHP="$(readlink -f /usr/bin/php 2>/dev/null || true)"

log "PHP 8.4-FPM (side by side with existing PHP)"
if ! grep -rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/; then
  apt-get install -y software-properties-common
  add-apt-repository -y ppa:ondrej/php
fi
apt-get update -y
apt-get install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-intl php8.4-bcmath php8.4-opcache git unzip supervisor certbot python3-certbot-apache acl
if [ -n "$OLD_PHP" ] && [ -x "$OLD_PHP" ]; then
  update-alternatives --set php "$OLD_PHP" || true
fi
$PHP -r 'defined("PASSWORD_ARGON2ID") or exit(1);' || { echo "php8.4 has no Argon2id"; exit 1; }
systemctl enable --now php8.4-fpm

log "Composer (dedicated copy, always run with PHP 8.4)"
# The server may already have a system composer that runs on an older PHP. Use our own.
if [ ! -f /usr/local/bin/composer84 ]; then
  $PHP -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  $PHP /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer84
  rm -f /tmp/composer-setup.php
fi

log "Database (existing MySQL)"
if [ ! -f "$CRED_FILE" ]; then
  echo "DB_PASSWORD=$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)" > "$CRED_FILE"
  chmod 600 "$CRED_FILE"
fi
DB_PASS="$(grep DB_PASSWORD "$CRED_FILE" | cut -d= -f2)"
MYSQL="mysql"
mysql -e "SELECT 1" >/dev/null 2>&1 || MYSQL="mysql -u root -p"   # asks for the MySQL root password if needed
$MYSQL <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Code"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
else
  git -C "$APP_DIR" pull --ff-only
fi
cd "$APP_DIR"

log ".env"
if [ ! -f .env ]; then
  cat > .env <<ENV
APP_NAME=MindEase
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=UTC

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

HASH_DRIVER=argon2id
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

# Staging: emails are written to storage/logs instead of being sent. Switch to SES for real mail.
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@mindease.me"
MAIL_FROM_NAME="MindEase"

MINDEASE_ACCESS_TTL_MINUTES=15
MINDEASE_REFRESH_TTL_DAYS=60
MINDEASE_RESET_URL="mindease://reset-password?token={token}&email={email}"
MINDEASE_VIDEO_CAP_ENABLED=false
MINDEASE_DEVELOPER_DOCS=true

MINDEASE_BOOKING_PRICE_CENTS=15000
MINDEASE_SESSION_MINUTES=50
MINDEASE_REFUND_CUTOFF_HOURS=24

# Stripe TEST keys only on staging. Webhook: https://$DOMAIN/v1/webhooks/stripe
STRIPE_SECRET=
STRIPE_PUBLISHABLE_KEY=
STRIPE_WEBHOOK_SECRET=

ZOOM_VIDEO_SDK_KEY=
ZOOM_VIDEO_SDK_SECRET=
ENV
  chmod 640 .env
fi

log "Install app"
COMPOSER_ALLOW_SUPERUSER=1 $PHP /usr/local/bin/composer84 install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64' .env || $PHP artisan key:generate --force
chown -R root:www-data "$APP_DIR"
chmod 640 .env
setfacl -R -m u:www-data:rwX storage bootstrap/cache
setfacl -dR -m u:www-data:rwX storage bootstrap/cache
$PHP artisan migrate --force
$PHP artisan mindease:import-content
$PHP artisan optimize

log "Apache vhost for $DOMAIN (other sites untouched)"
a2enmod proxy_fcgi setenvif rewrite headers >/dev/null
cat > /etc/apache2/sites-available/mindease-api.conf <<VHOST
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $APP_DIR/public

    <Directory $APP_DIR/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # This site only runs on PHP 8.4-FPM; other sites keep their PHP.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"

    ErrorLog \${APACHE_LOG_DIR}/mindease-error.log
    CustomLog \${APACHE_LOG_DIR}/mindease-access.log combined
</VirtualHost>
VHOST
a2ensite mindease-api >/dev/null
apache2ctl configtest
systemctl reload apache2

log "Queue worker + scheduler"
cat > /etc/supervisor/conf.d/mindease-worker.conf <<SUP
[program:mindease-worker]
command=$PHP $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/worker.log
SUP
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart mindease-worker >/dev/null 2>&1 || true
echo "* * * * * www-data cd $APP_DIR && $PHP artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/mindease
chmod 644 /etc/cron.d/mindease

log "HTTPS"
certbot --apache -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL" --redirect \
  || echo "Certbot failed - check DNS for $DOMAIN, then re-run this script."

log "Done"
echo "API:   https://$DOMAIN/v1"
echo "Check: https://$DOMAIN/up"
echo "Docs:  https://$DOMAIN/docs/api   and   https://$DOMAIN/developers"
echo "DB password: $CRED_FILE"
echo "BACK UP APP_KEY:  grep APP_KEY $APP_DIR/.env"
echo
echo "Update later:  bash $APP_DIR/deploy/staging-apache-update.sh"
