#!/usr/bin/env bash
# Durable Cursor Cloud install — dependencies only.
# Idempotent. Must terminate. Do NOT start servers/queues here.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "cloud-agent-install: PHP $(php -r 'echo PHP_VERSION;')"
echo "cloud-agent-install: Composer $(composer --version --no-ansi 2>/dev/null | head -1)"
echo "cloud-agent-install: Node $(node -v) / npm $(npm -v)"

composer install --no-interaction --prefer-dist
npm ci
npm run build

echo "cloud-agent-install: complete"
