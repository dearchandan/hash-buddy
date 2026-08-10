#!/usr/bin/env bash
#
# Copies the working tree to the server, for deploying work that is not
# committed or lives in a private repo the server cannot pull from.
#
#   ./deploy/upload.sh ubuntu@13.200.74.8 ~/.ssh/test-server-mumbai.pem
#
# Uses tar over ssh rather than rsync, which Git Bash on Windows does not ship.
# Build artefacts and dependencies are excluded — the server runs its own
# `composer install`, and nothing in app/ is needed to serve the API.

set -euo pipefail

TARGET="${1:-}"
KEY="${2:-}"
APP_DIR=/var/www/hash-buddy

if [[ -z "$TARGET" ]]; then
    echo "usage: $0 <user@host> [ssh-key-path]" >&2
    exit 64
fi

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
[[ -n "$KEY" ]] && SSH_OPTS+=(-i "$KEY")

cd "$(dirname "$0")/.."

echo "==> Preparing $APP_DIR on $TARGET"
# The tar below extracts as the SSH user, so it needs to own the tree while the
# upload runs. Ownership of storage/ and bootstrap/cache is handed back to
# www-data afterwards — see the repair step at the end.
ssh "${SSH_OPTS[@]}" "$TARGET" "sudo mkdir -p $APP_DIR && sudo chown -R \$USER:\$USER $APP_DIR"

echo "==> Uploading working tree"
# .env is deliberately excluded: the server keeps its own, with its own database
# password and APP_KEY, and overwriting it would break the running app.
#
# bootstrap/cache is excluded for a subtler reason. It holds Laravel's package
# discovery manifest, generated from whatever is in vendor/. Locally that
# includes dev dependencies; the server installs --no-dev. Copying the local
# manifest up makes the server try to boot a provider it does not have
# (Laravel\Pail\PailServiceProvider), and every request 500s.
tar czf - \
    --exclude='./.git' \
    --exclude='./api/vendor' \
    --exclude='./api/.env' \
    --exclude='./api/bootstrap/cache' \
    --exclude='./api/storage/logs/*' \
    --exclude='./api/storage/framework/cache/*' \
    --exclude='./api/storage/framework/sessions/*' \
    --exclude='./api/storage/framework/views/*' \
    --exclude='./app/build' \
    --exclude='./app/.dart_tool' \
    --exclude='./app/android/.gradle' \
    --exclude='./app/android/.kotlin' \
    . | ssh "${SSH_OPTS[@]}" "$TARGET" "tar xzf - -C $APP_DIR"

# Without this the app comes back up unable to write its own log — and because
# the failure is in the logger, the 500 it returns leaves no trace anywhere.
echo "==> Restoring runtime permissions"
ssh "${SSH_OPTS[@]}" "$TARGET" "
    cd $APP_DIR/api &&
    sudo mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} storage/logs &&
    sudo chown -R www-data:www-data storage bootstrap/cache &&
    sudo chmod -R 775 storage bootstrap/cache"

echo "==> Done. Now provision (first run) or reload (subsequent):"
echo
echo "    ssh ${KEY:+-i $KEY} $TARGET 'sudo bash $APP_DIR/deploy/provision.sh api.remoteshala.com you@remoteshala.com'"
echo
echo "    ssh ${KEY:+-i $KEY} $TARGET 'cd $APP_DIR/api &&"
echo "        sudo -u www-data php artisan migrate --force &&"
echo "        sudo -u www-data php artisan config:cache &&"
echo "        sudo -u www-data php artisan route:cache'"
echo
