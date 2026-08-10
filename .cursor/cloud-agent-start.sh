#!/usr/bin/env bash
# Per-boot Cursor Cloud start — runtime .env / DB / migrations only.
# Idempotent. Do NOT start artisan serve here (belongs in terminals).
# Never prints APP_KEY or secrets.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f composer.json ]]; then
  echo "cloud-agent-start: missing composer.json" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  # Use .cursor/dotenv.* templates (not .env.*) so CI secret-path gates stay clean.
  if [[ -f .cursor/dotenv.cursor-cloud.example ]]; then
    cp .cursor/dotenv.cursor-cloud.example .env
  elif [[ -f .env.example ]]; then
    cp .env.example .env
  else
    echo "cloud-agent-start: missing dotenv template / .env.example" >&2
    exit 1
  fi
fi

# Ensure development SQLite file DB exists (no password; not production).
mkdir -p database
if [[ ! -f database/database.sqlite ]]; then
  touch database/database.sqlite
fi

# Generate APP_KEY only when missing. Discard command output so the key is never logged.
if ! grep -qE '^APP_KEY=base64:' .env; then
  if [[ ! -d vendor ]]; then
    composer install --no-interaction --prefer-dist --quiet
  fi
  php artisan key:generate --force --no-interaction >/dev/null
fi

php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

echo "cloud-agent-start: runtime ready (migrate + seed)"
