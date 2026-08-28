#!/usr/bin/env bash
# Staging deploy helper for MoxDOP (single VPS: Nginx + PHP-FPM + PostgreSQL + Redis + Horizon + DB-driven collection workers).
# Run from the application root after checking out an exact Git SHA.
# Does NOT print secrets. Never migrate:fresh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

APP_DOWN=0
cleanup() {
  if [[ "$APP_DOWN" -eq 1 ]]; then
    php artisan up --no-interaction >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

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

# Product invariant: app.moximu.com is the operator application root.
# Legacy /app/* and /system/* operator prefixes are retired and must never be deployed again.
if [[ ! -f routes/web.php || ! -f routes/demo.php ]]; then
  echo "deploy/staging: ERROR — canonical route files are missing" >&2
  exit 1
fi

if grep -Fq -- "->prefix('app')" routes/demo.php || grep -Fq -- '->prefix("app")' routes/demo.php; then
  echo "deploy/staging: ERROR — legacy /app operator prefix detected in routes/demo.php" >&2
  echo "deploy/staging: app.moximu.com/ is canonical; /app/* is retired" >&2
  exit 1
fi

if grep -Fq -- "redirect('/app')" routes/web.php \
  || grep -Fq -- 'redirect("/app")' routes/web.php \
  || grep -Fq -- "redirect('/system/login')" routes/web.php \
  || grep -Fq -- 'redirect("/system/login")' routes/web.php; then
  echo "deploy/staging: ERROR — legacy /app or /system root redirect detected in routes/web.php" >&2
  echo "deploy/staging: refuse to replace the canonical root operator application" >&2
  exit 1
fi

if ! grep -Fq -- "Route::livewire('/', Dashboard::class)" routes/demo.php; then
  echo "deploy/staging: ERROR — canonical root dashboard route is missing" >&2
  exit 1
fi

echo "deploy/staging: canonical root-route guard PASS"

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
APP_DOWN=1

echo "deploy/staging: migrate --force"
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction || true

echo "deploy/staging: optimize caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

echo "deploy/staging: verify collection dispatch sink"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\Collection\CollectionQueueGate::class)->assertReady();
echo "collection dispatch sink reachable: ".config("moxdop-collection.queue_connection").PHP_EOL;
' || exit 1

# PostgreSQL CollectionDatasetRun rows are authoritative. Older releases mirrored
# dataset work into redis:collection, which can leave delayed duplicate jobs behind.
# Clear that legacy mirror before restarting Horizon; DB workers will recover every
# still-eligible queued/retrying row from PostgreSQL state.
echo "deploy/staging: clear legacy redis:collection mirror"
php artisan queue:clear redis --queue=collection --no-interaction || true

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
as_root supervisorctl restart moxdop-staging-google-ads-collection || true

sleep 3

HORIZON_STATUS="$(as_root supervisorctl status moxdop-staging-horizon 2>/dev/null || true)"
COLLECTION_STATUS="$(as_root supervisorctl status moxdop-staging-collection 2>/dev/null || true)"
GOOGLE_ADS_COLLECTION_STATUS="$(as_root supervisorctl status moxdop-staging-google-ads-collection 2>/dev/null || true)"

echo "$HORIZON_STATUS"
echo "$COLLECTION_STATUS"
echo "$GOOGLE_ADS_COLLECTION_STATUS"

if ! grep -q 'RUNNING' <<<"$HORIZON_STATUS"; then
  echo "deploy/staging: ERROR — Horizon is not RUNNING" >&2
  as_root supervisorctl status || true
  exit 1
fi

if ! grep -q 'RUNNING' <<<"$COLLECTION_STATUS"; then
  echo "deploy/staging: ERROR — general collection worker is not RUNNING" >&2
  as_root tail -n 80 /var/log/moxdop-staging-collection.log 2>/dev/null || true
  exit 1
fi

if ! grep -q 'RUNNING' <<<"$GOOGLE_ADS_COLLECTION_STATUS"; then
  echo "deploy/staging: ERROR — Google Ads collection worker is not RUNNING" >&2
  as_root tail -n 80 /var/log/moxdop-staging-google-ads-collection.log 2>/dev/null || true
  exit 1
fi

echo "deploy/staging: restart cron scheduler"
as_root systemctl restart cron 2>/dev/null || as_root service cron restart 2>/dev/null || true

echo "deploy/staging: recover stranded collection DB state"
php artisan moxdop:collection:redispatch-stale --force --no-interaction || exit 1

# Give DB-driven workers time to pick up stranded rows.
sleep 3

echo "deploy/staging: collection worker status"
as_root supervisorctl status moxdop-staging-collection || exit 1
as_root supervisorctl status moxdop-staging-google-ads-collection || exit 1

echo "deploy/staging: collection state"
php artisan moxdop:collection:status --no-interaction || true
php artisan moxdop:collection:status --provider=GOOGLE_ADS --no-interaction || true

echo "deploy/staging: collection dispatch sink depth"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("moxdop-collection.queue_connection", "null");
$queue = (string) config("moxdop-collection.queue", "collection");
echo "connection={$connection} queue={$queue} depth=".Illuminate\Support\Facades\Queue::connection($connection)->size($queue).PHP_EOL;
' || exit 1

php artisan up --no-interaction
APP_DOWN=0

echo "deploy/staging: done — SHA ${RELEASE_SHA}"
php artisan about --only=environment 2>/dev/null || true
