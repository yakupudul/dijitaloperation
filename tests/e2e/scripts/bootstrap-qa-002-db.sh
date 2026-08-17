#!/usr/bin/env bash
# Fresh isolated SQLite for Autonomous E2E QA 002. Never touches the manual QA file.
set -euo pipefail

cd /workspace

DB="${MOXDOP_E2E_DATABASE:-/tmp/moxdop-e2e-qa-002.sqlite}"
PASSWORD_FILE="${MOXDOP_E2E_PASSWORD_FILE:-/tmp/moxdop-final-manual-qa-admin.secret}"
EMAIL="${MOXDOP_E2E_EMAIL:-qa-final@moxdop.local}"

if [[ "$DB" == "/tmp/moxdop-final-manual-qa.sqlite" ]]; then
    echo "Refusing to bootstrap the manual QA sqlite file." >&2
    exit 1
fi

if [[ ! -r "$PASSWORD_FILE" ]]; then
    echo "QA password source is not readable: $PASSWORD_FILE" >&2
    exit 1
fi

rm -f "$DB" "${DB}-journal" "${DB}-wal" "${DB}-shm"
touch "$DB"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
export APP_ENV=local
export MAIL_MAILER=log
unset DB_URL || true

php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

php tests/e2e/scripts/ensure-qa-admin.php

echo "QA 002 isolated database ready: $DB ($EMAIL)"
