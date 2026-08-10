#!/usr/bin/env bash
#
# Provisions a fresh Ubuntu 24.04 host to serve the Hash Buddy API.
#
#   sudo ./provision.sh api.yourdomain.com you@yourdomain.com [git-repo-url]
#
# The repo URL is optional. Leave it off when the code has already been copied
# to /var/www/hash-buddy (see deploy/upload.sh), which is how you deploy work
# that is not committed or lives in a private repo the server cannot read.
#
# Safe to re-run: every step checks before it acts, so a second run repairs a
# half-finished first one rather than duplicating it.
#
# It does NOT open firewall ports — that is the EC2 security group's job, and
# doing it here would give you a false sense of what is exposed.

set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
REPO="${3:-}"

APP_DIR=/var/www/hash-buddy
API_DIR="$APP_DIR/api"

# PHP_VER is detected after apt-get update rather than pinned: Ubuntu 24.04
# ships 8.3 and 26.04 ships 8.5, and composer.json accepts anything from 8.3 up.
PHP_VER=""

if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
    echo "usage: sudo $0 <api-domain> <letsencrypt-email> [git-repo-url]" >&2
    exit 64
fi

# Files copied up by upload.sh land owned by the SSH user, not root.
DEPLOY_USER="${SUDO_USER:-ubuntu}"

if [[ $EUID -ne 0 ]]; then
    echo "run me with sudo" >&2
    exit 1
fi

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }

# -------------------------------------------------------------------- swap ---
# MySQL and `composer install` together will exhaust a 1 GB instance and get the
# composer process OOM-killed halfway through. Swap is slow but it is the
# difference between a slow install and a failed one.
TOTAL_MB="$(free -m | awk '/^Mem:/{print $2}')"
if (( TOTAL_MB < 2048 )) && ! swapon --show | grep -q .; then
    log "Only ${TOTAL_MB}MB RAM — adding a 2G swapfile"
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# ---------------------------------------------------------------- packages ---
log "Updating package lists"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# Newest php X.Y-fpm this release offers: 8.3 on Ubuntu 24.04, 8.5 on 26.04.
PHP_VER="$(apt-cache search --names-only '^php[0-9]\.[0-9]+-fpm$' \
    | sed -E 's/^php([0-9]+\.[0-9]+)-fpm.*/\1/' | sort -V | tail -1)"

if [[ -z "$PHP_VER" ]]; then
    echo "Could not find a php-fpm package in apt." >&2
    exit 1
fi

log "Installing packages (PHP ${PHP_VER})"
apt-get install -y -qq \
    nginx mysql-server git unzip curl ca-certificates \
    "php${PHP_VER}-fpm" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
    "php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-zip" \
    "php${PHP_VER}-bcmath" "php${PHP_VER}-intl" \
    certbot python3-certbot-nginx

if ! command -v composer >/dev/null 2>&1; then
    log "Installing Composer"
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------- database ---
# Generated once and then read back out of .env, so re-running does not lock the
# app out of its own database with a fresh password.
if [[ -f "$API_DIR/.env" ]] && grep -q '^DB_PASSWORD=.\+' "$API_DIR/.env"; then
    DB_PASS="$(grep '^DB_PASSWORD=' "$API_DIR/.env" | cut -d= -f2-)"
    log "Reusing database password from existing .env"
else
    DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
    log "Generated a new database password"
fi

log "Configuring MySQL"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS hash_buddy
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hashbuddy'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'hashbuddy'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON hash_buddy.* TO 'hashbuddy'@'localhost';
FLUSH PRIVILEGES;
SQL

# -------------------------------------------------------------------- code ---
if [[ -n "$REPO" ]]; then
    if [[ -d "$APP_DIR/.git" ]]; then
        log "Updating existing checkout"
        git -C "$APP_DIR" pull --ff-only
    else
        log "Cloning $REPO"
        mkdir -p "$(dirname "$APP_DIR")"
        git clone --depth 1 "$REPO" "$APP_DIR"
    fi
elif [[ -f "$API_DIR/artisan" ]]; then
    log "Using the code already at $APP_DIR"
else
    echo "No repo URL given and no Laravel app found at $API_DIR." >&2
    echo "Either pass a git URL or copy the code up first (deploy/upload.sh)." >&2
    exit 1
fi

chown -R "$DEPLOY_USER":www-data "$APP_DIR"

log "Installing PHP dependencies"
cd "$API_DIR"
# As the deploy user, not root: composer refuses to run plugins as root and the
# vendor tree should not end up root-owned either.
sudo -u "$DEPLOY_USER" COMPOSER_ALLOW_SUPERUSER=0 \
    composer install --no-dev --optimize-autoloader --no-interaction

# --------------------------------------------------------------------- env ---
if [[ ! -f .env ]]; then
    log "Writing .env"
    cp .env.example .env
fi

set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        # '|' as the delimiter so URLs and base64 keys pass through untouched.
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${DOMAIN}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE hash_buddy
set_env DB_USERNAME hashbuddy
set_env DB_PASSWORD "$DB_PASS"
# Ignored in production anyway, but leaving a true here reads like a live bypass.
set_env HASHBUDDY_OTP_DEBUG false

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

# ----------------------------------------------------------- permissions -----
log "Setting permissions"
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# -------------------------------------------------------------- migrations ---
# Every artisan call from here runs as www-data. As root they would leave
# root-owned files in storage/ and bootstrap/cache — after which the web server
# cannot write its own log, and the next deploy cannot rewrite the config cache.
log "Running migrations"
sudo -u www-data php artisan migrate --force
# ZoneSeeder only. DatabaseSeeder also pulls in DemoSeeder's fake travellers.
sudo -u www-data php artisan db:seed --class=ZoneSeeder --force

log "Caching config and routes"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache

# ------------------------------------------------------------------- nginx ---
log "Configuring nginx"
cat > /etc/nginx/sites-available/hash-buddy <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};

    # Points at api/public, never the repo root — anything higher would serve
    # .env to the internet.
    root ${API_DIR}/public;

    index index.php;
    charset utf-8;
    client_max_body_size 8M;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/hash-buddy /etc/nginx/sites-enabled/hash-buddy
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# --------------------------------------------------------------------- TLS ---
if [[ -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    log "Certificate already present, skipping certbot"
else
    log "Requesting certificate for ${DOMAIN}"
    if ! certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${EMAIL}" --redirect; then
        echo
        echo "certbot failed. The usual cause is DNS: ${DOMAIN} must already"
        echo "resolve to this host's public IP. Check with:  dig +short ${DOMAIN}"
        echo "Everything else is set up — re-run this script once DNS is live."
        exit 1
    fi
fi

systemctl reload "php${PHP_VER}-fpm"

log "Done"
cat <<DONE

  API:     https://${DOMAIN}/api/v1/zones
  Health:  https://${DOMAIN}/up
  Logs:    ${API_DIR}/storage/logs/laravel.log

  Nobody can sign in yet. Add the numbers you are testing with to
  HASHBUDDY_OTP_TEST_NUMBERS in ${API_DIR}/.env, then:

      cd ${API_DIR} && php artisan config:cache

  Then build the app against this host:

      flutter build apk --release --split-per-abi \\
        --dart-define=HASH_BUDDY_API=https://${DOMAIN}/api/v1

DONE
