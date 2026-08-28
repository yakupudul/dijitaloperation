#!/usr/bin/env bash
# Staging deploy helper for MoxDOP (single VPS: Nginx + PHP-FPM + PostgreSQL + Redis + Supervisor queue workers).
# Run from the application root on the staging host after checking out an exact Git SHA.
# Does NOT print secrets. Never migrate:fresh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "deploy/staging/deploy.sh: not a Laravel app root: $ROOT" >&2
  exit 1
fi

# Product invariant: app.moximu.com is the operator application root.
# Legacy /app/* and /system/* operator prefixes are retired. This branch is stale
# if those routes are still canonical, so deployment must stop before dependencies,
# migrations, caches, or workers are touched.
if [[ ! -f routes/web.php || ! -f routes/demo.php ]]; then
  echo "deploy/staging/deploy.sh: canonical route files are missing" >&2
  exit 1
fi

if grep -Fq -- "->prefix('app')" routes/demo.php || grep -Fq -- '->prefix("app")' routes/demo.php; then
  echo "deploy/staging/deploy.sh: REFUSING DEPLOY — legacy /app operator prefix detected" >&2
  echo "Canonical product URL is https://app.moximu.com/; /app/* is retired." >&2
  echo "Checkout the current canonical release branch before deploying." >&2
  exit 1
fi

if grep -Fq -- "redirect('/app')" routes/web.php \
  || grep -Fq -- 'redirect("/app")' routes/web.php \
  || grep -Fq -- "redirect('/system/login')" routes/web.php \
  || grep -Fq -- 'redirect("/system/login")' routes/web.php; then
  echo "deploy/staging/deploy.sh: REFUSING DEPLOY — legacy /app or /system root redirect detected" >&2
  echo "Canonical product URL is https://app.moximu.com/." >&2
  exit 1
fi

if ! grep -Fq -- "Route::livewire('/', Dashboard::class)" routes/demo.php; then
  echo "deploy/staging/deploy.sh: REFUSING DEPLOY — canonical root dashboard route is missing" >&2
  exit 1
fi

echo "deploy/staging: canonical root-route guard PASS"

if [[ ! -f .env ]]; then
  echo "deploy/staging/deploy.sh: missing .env" >&2
  exit 1
fi

if grep -qE '^DB_CONNECTION=sqlite' .env; then
  echo "deploy/staging/deploy.sh: staging must use PostgreSQL (DB_CONNECTION=pgsql)" >&2
  exit 1
fi

if ! grep -qE '^DB_CONNECTION=pgsql' .env; then
  echo "deploy/staging/deploy.sh: DB_CONNECTION=pgsql is required" >&2
  exit 1
fi

if ! grep -qE '^APP_ENV=staging' .env; then
  echo "deploy/staging/deploy.sh: APP_ENV=staging is required" >&2
  exit 1
fi

if grep -qE '^APP_DEBUG=true' .env; then
  echo "deploy/staging/deploy.sh: APP_DEBUG must be false" >&2
  exit 1
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "deploy/staging/deploy.sh: APP_KEY missing — generate ONCE; do not regenerate on every deploy" >&2
  exit 1
fi

RELEASE_SHA="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
echo "deploy/staging: release SHA ${RELEASE_SHA}"

echo "deploy/staging: composer install"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Current production dependency set uses the phpredis extension, not predis/predis.
# Fail before maintenance mode if an old staging .env still points at Predis.
if grep -qE '^REDIS_CLIENT=predis([[:space:]]*)$' .env; then
  if php -r 'exit(extension_loaded("redis") ? 0 : 1);'; then
    echo "deploy/staging/deploy.sh: REDIS_CLIENT=predis is stale; set REDIS_CLIENT=phpredis before deploy" >&2
  else
    echo "deploy/staging/deploy.sh: Predis package is absent and phpredis extension is unavailable" >&2
  fi
  exit 1
fi

if command -v npm >/dev/null 2>&1; then
  echo "deploy/staging: npm ci && npm run build"
  npm ci --no-fund --no-audit
  npm run build
else
  echo "deploy/staging: npm not found — ensure public/build exists from a prior build"
  if [[ ! -f public/build/manifest.json ]]; then
    echo "deploy/staging/deploy.sh: missing public/build/manifest.json" >&2
    exit 1
  fi
fi

MAINTENANCE_ENTERED=0
cleanup() {
  status=$?
  if [[ "$MAINTENANCE_ENTERED" -eq 1 ]]; then
    php artisan up --no-interaction >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT

php artisan down --retry=60 --no-interaction || true
MAINTENANCE_ENTERED=1

echo "deploy/staging: migrate --force (never migrate:fresh)"
php artisan migrate --force --no-interaction

php artisan storage:link --no-interaction || true

echo "deploy/staging: optimize caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

echo "deploy/staging: signal Laravel queue workers to restart"
php artisan queue:restart --no-interaction || true

if command -v supervisorctl >/dev/null 2>&1; then
  if supervisorctl status 2>/dev/null | grep -q 'moxdop-staging-worker'; then
    supervisorctl restart 'moxdop-staging-worker:*' 2>/dev/null \
      || supervisorctl restart moxdop-staging-worker 2>/dev/null \
      || echo "deploy/staging: worker supervisor restart skipped"
  else
    echo "deploy/staging: moxdop-staging-worker is not configured in Supervisor"
  fi
else
  echo "deploy/staging: supervisorctl not found — queue worker restart skipped"
fi

php artisan up --no-interaction
MAINTENANCE_ENTERED=0
trap - EXIT

echo "deploy/staging: done — SHA ${RELEASE_SHA}"
php artisan about --only=environment 2>/dev/null || true
