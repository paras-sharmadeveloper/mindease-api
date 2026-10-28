#!/usr/bin/env bash
# MindEase API - one-time server setup for a fresh Ubuntu 24.04 VPS (run as root).
#
#   DOMAIN=api.mindease.me \
#   REPO_URL=git@github.com:paras-sharmadeveloper/mindease-api.git \
#   ADMIN_EMAIL=you@example.com \
#   bash server-setup.sh
#
# Optional: APP_SUBDIR=.  (folder of the Laravel app inside the repo; "." = repo root, the default)
#           BRANCH=main   STAGING=1 (makes /docs/api public for the mobile developer)
#
# Installs: Nginx, PHP 8.4-FPM, MySQL 8, Composer, Supervisor (queue worker), cron (scheduler),
#           Certbot (HTTPS), UFW firewall, fail2ban, unattended security upgrades.
set -euo pipefail

: "${DOMAIN:?Set DOMAIN, e.g. api.mindease.com.au}"
: "${REPO_URL:?Set REPO_URL (git clone URL)}"
: "${ADMIN_EMAIL:?Set ADMIN_EMAIL for the SSL certificate}"
APP_SUBDIR="${APP_SUBDIR:-.}"
BRANCH="${BRANCH:-main}"
STAGING="${STAGING:-0}"

DEPLOY_USER=deploy
BASE=/var/www/mindease
if [ "$APP_SUBDIR" = "." ]; then APP_DIR="$BASE"; else APP_DIR="$BASE/$APP_SUBDIR"; fi
DB_NAME=mindease
DB_USER=mindease
CRED_FILE=/root/mindease-credentials.txt

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }

log "System update + base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
apt-get install -y software-properties-common curl git unzip ufw fail2ban supervisor \
  nginx mysql-server certbot python3-certbot-nginx unattended-upgrades acl

log "PHP 8.4"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-intl php8.4-bcmath php8.4-opcache php8.4-sqlite3
php -r 'defined("PASSWORD_ARGON2ID") or exit(1);' || { echo "PHP has no Argon2id support"; exit 1; }

cat > /etc/php/8.4/fpm/conf.d/99-mindease.ini <<'INI'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
opcache.enable = 1
opcache.memory_consumption = 128
opcache.validate_timestamps = 0
INI
systemctl restart php8.4-fpm

log "Composer"
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "Deploy user"
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
usermod -aG www-data "$DEPLOY_USER"
mkdir -p "/home/$DEPLOY_USER/.ssh"
if [ ! -f "/home/$DEPLOY_USER/.ssh/id_ed25519" ]; then
  sudo -u "$DEPLOY_USER" ssh-keygen -t ed25519 -N "" -f "/home/$DEPLOY_USER/.ssh/id_ed25519" -C "mindease-deploy@$DOMAIN"
fi
chown -R "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
# deploy.sh may reload PHP-FPM (clears OPcache) - nothing else.
echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm" > /etc/sudoers.d/mindease-deploy
chmod 440 /etc/sudoers.d/mindease-deploy

log "MySQL database"
if [ ! -f "$CRED_FILE" ]; then
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)"
  echo "DB_PASSWORD=$DB_PASS" > "$CRED_FILE"
  chmod 600 "$CRED_FILE"
fi
DB_PASS="$(grep DB_PASSWORD "$CRED_FILE" | cut -d= -f2)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Clone repo"
mkdir -p "$BASE"
chown "$DEPLOY_USER:www-data" "$BASE"
if [ ! -d "$BASE/.git" ]; then
  if ! sudo -u "$DEPLOY_USER" GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" \
       git clone --branch "$BRANCH" "$REPO_URL" "$BASE"; then
    echo
    echo "Clone failed. Add this deploy key (read-only) to your Git repo, then re-run this script:"
    echo
    cat "/home/$DEPLOY_USER/.ssh/id_ed25519.pub"
    exit 1
  fi
fi
[ -f "$APP_DIR/artisan" ] || { echo "No Laravel app at $APP_DIR (check APP_SUBDIR)"; exit 1; }

log ".env"
if [ ! -f "$APP_DIR/.env" ]; then
  cp "$APP_DIR/deploy/env.production.example" "$APP_DIR/.env"
  sed -i "s#^APP_URL=.*#APP_URL=https://$DOMAIN#" "$APP_DIR/.env"
  sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=$DB_PASS#" "$APP_DIR/.env"
  if [ "$STAGING" = "1" ]; then
    sed -i "s#^APP_ENV=.*#APP_ENV=staging#; s#^MINDEASE_DEVELOPER_DOCS=.*#MINDEASE_DEVELOPER_DOCS=true#" "$APP_DIR/.env"
  fi
  chown "$DEPLOY_USER:www-data" "$APP_DIR/.env"
  chmod 640 "$APP_DIR/.env"
fi

log "Install app"
sudo -u "$DEPLOY_USER" bash -c "cd '$APP_DIR' && composer install --no-dev --optimize-autoloader --no-interaction"
if ! grep -q '^APP_KEY=base64' "$APP_DIR/.env"; then
  sudo -u "$DEPLOY_USER" php "$APP_DIR/artisan" key:generate --force
fi
setfacl -R -m u:www-data:rwX -m u:"$DEPLOY_USER":rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
setfacl -dR -m u:www-data:rwX -m u:"$DEPLOY_USER":rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
sudo -u "$DEPLOY_USER" bash "$APP_DIR/deploy/deploy.sh" --no-pull
sudo -u "$DEPLOY_USER" php "$APP_DIR/artisan" mindease:import-content

log "Nginx"
sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__ROOT__#$APP_DIR/public#g" \
  "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/mindease
ln -sf /etc/nginx/sites-available/mindease /etc/nginx/sites-enabled/mindease
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

log "Queue worker (Supervisor) + scheduler (cron)"
sed -e "s#__APP__#$APP_DIR#g" -e "s#__USER__#www-data#g" \
  "$APP_DIR/deploy/supervisor.conf" > /etc/supervisor/conf.d/mindease-worker.conf
supervisorctl reread && supervisorctl update && supervisorctl restart mindease-worker:* || true
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/mindease
chmod 644 /etc/cron.d/mindease

log "Firewall + fail2ban + auto security updates"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
systemctl enable --now fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades

log "HTTPS (Let's Encrypt)"
if ! certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL" --redirect; then
  echo "Certbot failed. Point the DNS A record of $DOMAIN to this server, then run:"
  echo "  certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m $ADMIN_EMAIL --redirect"
fi

log "Done"
echo "API:        https://$DOMAIN/v1"
echo "Health:     https://$DOMAIN/up"
[ "$STAGING" = "1" ] && echo "Docs:       https://$DOMAIN/docs/api  and  https://$DOMAIN/developers"
echo "DB creds:   $CRED_FILE (root only)"
echo
echo "Still to do in $APP_DIR/.env: MAIL_* (SES Sydney), STRIPE_*, ZOOM_VIDEO_SDK_*, then: sudo -u $DEPLOY_USER bash $APP_DIR/deploy/deploy.sh --no-pull"
echo "BACK UP APP_KEY from .env somewhere safe. Encrypted answers can't be read without it."
