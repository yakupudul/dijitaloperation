#!/usr/bin/env bash
set -euo pipefail

cd /workspace

if [[ -f /tmp/moxdop-final-manual-qa.env ]]; then
    set -a
    # shellcheck disable=SC1091
    source /tmp/moxdop-final-manual-qa.env
    set +a
fi

export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${MOXDOP_E2E_DATABASE:-${DB_DATABASE:-/tmp/moxdop-final-manual-qa.sqlite}}"
export APP_ENV="${APP_ENV:-local}"
export APP_DEBUG="${APP_DEBUG:-true}"
export MAIL_MAILER="${MAIL_MAILER:-log}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export MOXDOP_PROSPECT_RESEARCH_FIXTURES="${MOXDOP_PROSPECT_RESEARCH_FIXTURES:-true}"

if [[ ! -f "$DB_DATABASE" ]]; then
    echo "Isolated E2E database missing: $DB_DATABASE" >&2
    exit 1
fi

PORT="${MOXDOP_E2E_PORT:-8013}"
export APP_URL="${MOXDOP_E2E_BASE_URL:-http://127.0.0.1:${PORT}}"

exec php artisan serve --host=127.0.0.1 --port="$PORT"
