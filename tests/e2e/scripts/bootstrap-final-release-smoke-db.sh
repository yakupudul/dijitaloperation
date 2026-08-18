#!/usr/bin/env bash
# Fresh isolated SQLite for the final autonomous release smoke.
# Never touches the protected QA databases.
set -euo pipefail

cd /workspace

DB="${MOXDOP_E2E_DATABASE:-/tmp/moxdop-final-release-smoke.sqlite}"
PASSWORD_FILE="${MOXDOP_E2E_PASSWORD_FILE:-/tmp/moxdop-final-manual-qa-admin.secret}"
EMAIL="${MOXDOP_E2E_EMAIL:-qa-final@moxdop.local}"

PROTECTED=(
    /tmp/moxdop-final-manual-qa.sqlite
    /tmp/moxdop-e2e-qa-002.sqlite
)

for protected in "${PROTECTED[@]}"; do
    if [[ "$DB" == "$protected" ]]; then
        echo "Refusing to bootstrap protected QA sqlite: $DB" >&2
        exit 1
    fi
done

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

echo "Final release smoke database ready: $DB ($EMAIL)"
