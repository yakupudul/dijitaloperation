#!/usr/bin/env bash
# Prepare a disposable Laravel test environment for fresh CI runners.
# Idempotent. Never prints APP_KEY. Never commits .env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f composer.json ]]; then
  exit 0
fi

if [[ ! -f .env ]]; then
  if [[ ! -f .env.example ]]; then
    echo "bootstrap_test_env: missing .env.example" >&2
    exit 1
  fi
  cp .env.example .env
fi

# Generate (or refresh) an ephemeral APP_KEY for this runner only.
# Output is discarded so the key never appears in CI logs.
if [[ ! -d vendor ]]; then
  composer install --no-interaction --prefer-dist --quiet
fi

php artisan key:generate --force --no-interaction >/dev/null

# phpunit.xml already forces sqlite/:memory: for tests.
# Do not override production-oriented .env.example defaults here.
