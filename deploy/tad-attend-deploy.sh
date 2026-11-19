#!/usr/bin/env bash
# Atomic release activator for TAD-attend Laravel backend on Ubuntu 24.04 VPS.
# Installed at /usr/local/sbin/tad-attend-deploy.sh (root:root, 750)
# and invoked by `deploy` user via sudoers NOPASSWD rule.
set -euo pipefail

SHA="${1:-}"
if [[ ! "$SHA" =~ ^[0-9a-f]{7,40}$ ]]; then
  echo "ERROR: Invalid release SHA" >&2
  exit 1
fi

APP_BASE="/var/www/tad-attend"
SHARED="$APP_BASE/shared"
RELEASES="$APP_BASE/releases"
INCOMING="$APP_BASE/incoming/release-${SHA}.tar.gz"
TS="$(date -u +%Y%m%d%H%M%S)"
REL_DIR="$RELEASES/${TS}-${SHA}"
BACKEND_DIR="$REL_DIR/backend"

if [ ! -f "$INCOMING" ]; then
  echo "ERROR: Release archive not found: $INCOMING" >&2
  exit 1
fi

echo "==> Extracting release ${TS}-${SHA}..."
mkdir -p "$REL_DIR"
tar -xzf "$INCOMING" -C "$REL_DIR"
rm -f "$INCOMING"

if [ ! -f "$BACKEND_DIR/artisan" ]; then
  echo "ERROR: Invalid archive structure (missing backend/artisan)" >&2
  rm -rf "$REL_DIR"
  exit 1
fi

rm -rf "$BACKEND_DIR/storage"
ln -sfn "$SHARED/storage" "$BACKEND_DIR/storage"
ln -sfn "$SHARED/.env" "$BACKEND_DIR/.env"

chown -R root:www-data "$REL_DIR"
chown -R www-data:www-data "$BACKEND_DIR/bootstrap/cache"
chmod -R 775 "$BACKEND_DIR/bootstrap/cache"

echo "==> Running migrations & cache optimization..."
sudo -u www-data php "$BACKEND_DIR/artisan" migrate --force --no-interaction
sudo -u www-data php "$BACKEND_DIR/artisan" optimize --no-interaction

echo "==> Activating release atomically..."
ln -sfn "$BACKEND_DIR" "$APP_BASE/current"
systemctl reload php8.3-fpm

echo "==> Verifying healthcheck..."
APP_URL="$(grep -E '^APP_URL=' "$SHARED/.env" | head -n1 | cut -d= -f2-)"
HOST_HEADER="${APP_URL#http://}"
HOST_HEADER="${HOST_HEADER#https://}"
HOST_HEADER="${HOST_HEADER%%/*}"

if [[ "$APP_URL" == https://* ]]; then
  HTTP_CODE="$(curl -sS -k -o /dev/null -w '%{http_code}' --resolve "${HOST_HEADER}:443:127.0.0.1" "https://${HOST_HEADER}/up")"
else
  HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${HOST_HEADER}" "http://127.0.0.1/up")"
fi

echo "Healthcheck /up -> HTTP ${HTTP_CODE}"
if [ "$HTTP_CODE" != "200" ]; then
  echo "ERROR: Healthcheck failed (HTTP ${HTTP_CODE})" >&2
  exit 1
fi

echo "==> Pruning old releases (keeping latest 5)..."
ls -1dt "$RELEASES"/* | tail -n +6 | xargs -r rm -rf

echo "==> Deploy complete: ${TS}-${SHA}"
