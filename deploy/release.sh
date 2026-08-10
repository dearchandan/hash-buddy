#!/usr/bin/env bash
#
# Deploys the latest commit to a server that already has a git checkout at
# /var/www/hash-buddy (see provision.sh for the first run).
#
# Run it on the server:
#
#     sudo bash /var/www/hash-buddy/deploy/release.sh
#
# ...or from your machine in one line:
#
#     ssh -i ~/.ssh/key.pem ubuntu@HOST 'sudo bash /var/www/hash-buddy/deploy/release.sh'
#
# It pulls in place rather than building a new tree and swapping it. That is a
# deliberate trade: an in-place pull means a few seconds where the code is newer
# than the caches, but it keeps the app at one fixed absolute path forever.
# Laravel bakes absolute paths into bootstrap/cache/config.php, so a tree that is
# cached at one path and then moved to another will insist the log directory
# lives somewhere that no longer exists — and every request that touches the
# logger 500s while the ones that do not keep working, which is a genuinely
# confusing way to be broken.
#
# The rollback is `git checkout <previous-sha>` plus a re-run of this script;
# the previous SHA is printed at the start of every run.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hash-buddy}"
API_DIR="$APP_DIR/api"
BRANCH="${BRANCH:-main}"

# The vhost answers on this name; used for the post-deploy smoke test so we
# exercise the real server block rather than whatever the default is.
HOST_HEADER="${HOST_HEADER:-api.remoteshala.com}"

if [[ $EUID -ne 0 ]]; then
    echo "run me with sudo" >&2
    exit 1
fi

if [[ ! -d "$API_DIR" ]]; then
    echo "No app at $API_DIR. Run provision.sh first." >&2
    exit 1
fi

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }

DEPLOY_USER="${SUDO_USER:-ubuntu}"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

cd "$APP_DIR"

PREVIOUS="$(git rev-parse --short HEAD)"
log "Currently at $PREVIOUS — roll back with: git checkout $PREVIOUS && sudo bash $0"

# ------------------------------------------------------------------- pull ---
log "Fetching origin/$BRANCH"
# As the deploy user: git refuses to operate on a tree owned by someone else,
# and running it as root would leave root-owned objects in .git.
sudo -u "$DEPLOY_USER" git fetch --quiet origin "$BRANCH"

if [[ "$(git rev-parse HEAD)" == "$(git rev-parse "origin/$BRANCH")" ]]; then
    log "Already up to date — rebuilding caches anyway"
else
    sudo -u "$DEPLOY_USER" git merge --ff-only "origin/$BRANCH"
fi

log "Now at $(git rev-parse --short HEAD) — $(git log --oneline -1 --format=%s)"

# ------------------------------------------------------------ dependencies ---
log "Installing PHP dependencies"
cd "$API_DIR"
sudo -u "$DEPLOY_USER" COMPOSER_ALLOW_SUPERUSER=0 \
    composer install --no-dev --optimize-autoloader --no-interaction --quiet

# ------------------------------------------------------------- permissions ---
# composer's package:discover writes bootstrap/cache as the deploy user, so this
# has to run after it, not before.
log "Restoring permissions"
mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# -------------------------------------------------------- migrate + cache ---
log "Migrating"
sudo -u www-data php artisan migrate --force

log "Rebuilding caches"
# Cleared first: a stale cache built at a different path, or against a vendor
# tree that had dev dependencies, survives a plain re-cache in some cases.
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache

systemctl reload "php${PHP_VER}-fpm"

# ------------------------------------------------------------- smoke test ---
# Deploying without checking is just moving files and hoping.
log "Smoke test"
sleep 2
FAILED=0
check() {
    local path="$1" expected="$2"
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${HOST_HEADER}" \
        "http://127.0.0.1${path}")"
    if [[ "$code" == "$expected" ]]; then
        printf '  ok    %-24s %s\n' "$path" "$code"
    else
        printf '  FAIL  %-24s %s (wanted %s)\n' "$path" "$code" "$expected"
        FAILED=1
    fi
}

# /up is the one that matters most here: it writes to the log, so it fails
# whenever permissions or the cached log path are wrong — the exact breakages
# that a read-only endpoint like /zones sails straight through.
check /up 200
check /api/v1/zones 200

if (( FAILED )); then
    echo
    echo "Smoke test failed. The previous release was $PREVIOUS:"
    echo "    cd $APP_DIR && sudo -u $DEPLOY_USER git checkout $PREVIOUS && sudo bash $0"
    echo "Logs: $API_DIR/storage/logs/laravel.log"
    exit 1
fi

log "Deployed $(git -C "$APP_DIR" rev-parse --short HEAD)"
