#!/usr/bin/env bash
# Manual persistent UAT deploy helper for MoxDOP.
# Run from the application root on the UAT host after git pull.
# Does NOT start Meta/Google/AI/discovery. Does NOT print secrets.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "deploy/uat/deploy.sh: not a Laravel app root: $ROOT" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "deploy/uat/deploy.sh: missing .env — copy .cursor/dotenv.uat.example and set secrets first" >&2
  exit 1
fi

# Refuse accidental SQLite UAT.
if grep -qE '^DB_CONNECTION=sqlite' .env; then
  echo "deploy/uat/deploy.sh: persistent UAT must use MySQL (DB_CONNECTION=mysql)" >&2
  exit 1
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
  echo "deploy/uat/deploy.sh: APP_KEY missing — set a STABLE key once; do not regenerate on every deploy" >&2
  exit 1
fi

echo "deploy/uat: composer install"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if command -v npm >/dev/null 2>&1; then
  echo "deploy/uat: npm ci && npm run build"
  npm ci --no-fund --no-audit
  npm run build
else
  echo "deploy/uat: npm not found — ensure public/build exists from CI or prior build"
  if [[ ! -f public/build/manifest.json ]]; then
    echo "deploy/uat/deploy.sh: missing public/build/manifest.json" >&2
    exit 1
  fi
fi

echo "deploy/uat: migrate"
php artisan migrate --force --no-interaction

echo "deploy/uat: optimize caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

if command -v supervisorctl >/dev/null 2>&1; then
  echo "deploy/uat: restart queue worker via supervisor"
  # Program name must match supervisor conf on the host.
  supervisorctl restart moxdop-uat-worker:* 2>/dev/null \
    || supervisorctl restart moxdop-uat-worker 2>/dev/null \
    || echo "deploy/uat: supervisor restart skipped — start/restart worker manually"
else
  echo "deploy/uat: supervisorctl not found — restart queue worker manually"
fi

echo "deploy/uat: done (no provider jobs dispatched)"
php artisan about --only=environment 2>/dev/null || true
