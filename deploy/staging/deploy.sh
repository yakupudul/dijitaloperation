#!/usr/bin/env bash
# Staging deploy helper for MoxDOP (single VPS: Nginx + PHP-FPM + PostgreSQL + Redis + Horizon + dedicated collection worker).
# Run from the application root on the staging host after checking out an exact Git SHA.
# Does NOT start provider pulls directly. Does NOT print secrets. Never migrate:fresh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

as_root() {
  if [[ "$(id -u)" -eq 0 ]]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    echo "deploy/staging: ERROR — root privileges are required for Supervisor/cron installation" >&2
    exit 1
  fi
}

if [[ ! -f artisan ]]; then
  echo "deploy/staging: not a Laravel app root: $ROOT" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "deploy/staging: missing .env" >&2
  exit 1
fi

if ! grep -qE '^DB_CONNECTION=pgsql' .env; then
  echo "deploy/staging: DB_CONNECTION=pgsql is required" >&2
  exit 1
fi

if ! grep -qE '^APP_ENV=staging' .env; then
  echo "deploy/staging: APP_ENV=staging is required" >&2
  exit 1
fi

if grep -qE '^APP_DEBUG=true' .env; then
  echo "deploy/staging: APP_DEBUG must be false" >&2
  exit 1
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "deploy/staging: APP_KEY missing" >&2
  exit 1
fi

RELEASE_SHA="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
echo "deploy/staging: release SHA ${RELEASE_SHA}"

echo "deploy/staging: composer install"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if command -v npm >/dev/null 2>&1; then
  echo "deploy/staging: npm ci && npm run build"
  npm ci --no-fund --no-audit
  npm run build
else
  if [[ ! -f public/build/manifest.json ]]; then
    echo "deploy/staging: missing public/build/manifest.json" >&2
    exit 1
  fi
fi

php artisan down --retry=60 --no-interaction || true

echo "deploy/staging: migrate --force"
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction || true

echo "deploy/staging: optimize caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

echo "deploy/staging: verify collection Redis backend"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\Collection\CollectionQueueGate::class)->assertReady();
echo "collection queue backend reachable".PHP_EOL;
' || exit 1

if ! command -v supervisorctl >/dev/null 2>&1; then
  echo "deploy/staging: ERROR — supervisorctl is required" >&2
  exit 1
fi

echo "deploy/staging: install Supervisor and scheduler configs"
as_root install -m 0644 deploy/staging/supervisor-horizon.conf.example /etc/supervisor/conf.d/moxdop-staging-horizon.conf
as_root install -m 0644 deploy/staging/supervisor-collection.conf.example /etc/supervisor/conf.d/moxdop-staging-collection.conf
as_root install -m 0644 deploy/staging/cron.example /etc/cron.d/moxdop-staging

as_root supervisorctl reread
as_root supervisorctl update

php artisan horizon:terminate --no-interaction || true
as_root supervisorctl restart moxdop-staging-horizon || true
as_root supervisorctl restart moxdop-staging-collection || true

sleep 3

HORIZON_STATUS="$(as_root supervisorctl status moxdop-staging-horizon 2>/dev/null || true)"
COLLECTION_STATUS="$(as_root supervisorctl status moxdop-staging-collection 2>/dev/null || true)"

echo "$HORIZON_STATUS"
echo "$COLLECTION_STATUS"

if ! grep -q 'RUNNING' <<<"$HORIZON_STATUS"; then
  echo "deploy/staging: ERROR — Horizon is not RUNNING" >&2
  as_root supervisorctl status || true
  exit 1
fi

if ! grep -q 'RUNNING' <<<"$COLLECTION_STATUS"; then
  echo "deploy/staging: ERROR — dedicated collection worker is not RUNNING" >&2
  as_root tail -n 80 /var/log/moxdop-staging-collection.log 2>/dev/null || true
  exit 1
fi

echo "deploy/staging: restart cron scheduler"
as_root systemctl restart cron 2>/dev/null || as_root service cron restart 2>/dev/null || true

echo "deploy/staging: recover stranded collection jobs"
php artisan moxdop:collection:redispatch-stale --force --no-interaction || exit 1

# Give the dedicated worker a short opportunity to consume redispatched jobs.
sleep 2

echo "deploy/staging: collection worker status"
as_root supervisorctl status moxdop-staging-collection || exit 1

echo "deploy/staging: collection queue depth"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("moxdop-collection.queue_connection", "redis");
$queue = (string) config("moxdop-collection.queue", "collection");
echo Illuminate\Support\Facades\Queue::connection($connection)->size($queue).PHP_EOL;
' || exit 1

php artisan up --no-interaction

echo "deploy/staging: done — SHA ${RELEASE_SHA}"
php artisan about --only=environment 2>/dev/null || true
