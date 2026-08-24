#!/usr/bin/env bash
# Staging deploy helper for MoxDOP (single VPS: Nginx + PHP-FPM + PostgreSQL + Redis + Horizon).
# Run from the application root on the staging host after checking out an exact Git SHA.
# Does NOT start Google/Meta/AI/DataForSEO. Does NOT print secrets. Never migrate:fresh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "deploy/staging/deploy.sh: not a Laravel app root: $ROOT" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "deploy/staging/deploy.sh: missing .env — copy .env.staging.example and set secrets first" >&2
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

php artisan down --retry=60 --no-interaction || true

echo "deploy/staging: migrate --force (never migrate:fresh)"
php artisan migrate --force --no-interaction

php artisan storage:link --no-interaction || true

echo "deploy/staging: optimize caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

echo "deploy/staging: graceful Horizon restart"
php artisan horizon:terminate --no-interaction || true

if command -v supervisorctl >/dev/null 2>&1; then
  if ! supervisorctl restart moxdop-staging-horizon:* 2>/dev/null \
      && ! supervisorctl restart moxdop-staging-horizon 2>/dev/null; then
    echo "deploy/staging: ERROR — Supervisor could not restart moxdop-staging-horizon" >&2
    supervisorctl status 2>/dev/null || true
    exit 1
  fi

  sleep 2
  HORIZON_STATUS="$(supervisorctl status moxdop-staging-horizon 2>/dev/null || supervisorctl status moxdop-staging-horizon:* 2>/dev/null || true)"
  echo "$HORIZON_STATUS"
  if ! grep -q 'RUNNING' <<<"$HORIZON_STATUS"; then
    echo "deploy/staging: ERROR — Horizon supervisor is not RUNNING" >&2
    echo "deploy/staging: expected command to use /var/www/moxdop/artisan horizon" >&2
    exit 1
  fi
else
  echo "deploy/staging: ERROR — supervisorctl not found; collection workers cannot be verified" >&2
  exit 1
fi

php artisan up --no-interaction

echo "deploy/staging: done — SHA ${RELEASE_SHA}"
php artisan about --only=environment 2>/dev/null || true
