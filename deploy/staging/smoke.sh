#!/usr/bin/env bash
# Bounded staging smoke — no provider calls, no paid DataForSEO, no mail send.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

# nginx serves the app under APP_URL's host name, so a bare http://127.0.0.1 hits the default site (404).
# Use APP_URL and pin its host to this machine with --resolve, so the check stays local.
APP_URL_ENV="$(grep -E '^APP_URL=' .env 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"'"'"' ' || true)"
BASE_URL="${MOXDOP_STAGING_BASE_URL:-${APP_URL_ENV:-http://127.0.0.1}}"
BASE_URL="${BASE_URL%/}"
BASE_HOST="$(printf '%s' "$BASE_URL" | sed -E 's#^[a-zA-Z]+://([^/:]+).*#\1#')"
BASE_PORT="$(printf '%s' "$BASE_URL" | sed -nE 's#^[a-zA-Z]+://[^/:]+:([0-9]+).*#\1#p')"
if [[ -z "$BASE_PORT" ]]; then
  [[ "$BASE_URL" == https://* ]] && BASE_PORT=443 || BASE_PORT=80
fi
RESOLVE_ARGS=()
if [[ ! "$BASE_HOST" =~ ^[0-9.]+$ && "$BASE_HOST" != "localhost" ]]; then
  RESOLVE_ARGS=(--resolve "${BASE_HOST}:${BASE_PORT}:127.0.0.1")
fi
fail=0

say() { printf '%s\n' "$*"; }
ok() { say "PASS  $*"; }
bad() { say "FAIL  $*"; fail=1; }

if [[ ! -f artisan ]]; then
  bad "not a Laravel root"
  exit 1
fi

if grep -qE '^APP_DEBUG=true' .env 2>/dev/null; then
  bad "APP_DEBUG=true"
else
  ok "APP_DEBUG is not true"
fi

if grep -qE '^DB_CONNECTION=sqlite' .env 2>/dev/null; then
  bad "staging .env still sqlite"
else
  ok "DB_CONNECTION is not sqlite"
fi

if grep -qE '^MOXDOP_SALES_INTENT_PAID_CALLS=true' .env 2>/dev/null; then
  bad "Sales Intent paid calls are ON"
else
  ok "Sales Intent paid calls are not enabled in .env"
fi

php artisan about --only=environment >/tmp/moxdop-staging-about.txt
ok "php artisan about"

php artisan migrate:status --no-interaction >/tmp/moxdop-staging-migrate-status.txt
ok "migrate:status"

php artisan schedule:list --no-interaction >/tmp/moxdop-staging-schedule.txt
if grep -q 'intent' /tmp/moxdop-staging-schedule.txt && grep -qi 'dataforseo\|paid' /tmp/moxdop-staging-schedule.txt; then
  bad "schedule:list appears to include paid intent search"
else
  ok "schedule:list has no paid intent search"
fi

check_http() {
  local path="$1"
  local expect="$2"
  local code
  code="$(curl -k -s "${RESOLVE_ARGS[@]}" -o /tmp/moxdop-staging-http.out -w '%{http_code}' "${BASE_URL}${path}" || true)"
  if [[ "$code" == "$expect" ]]; then
    ok "${path} HTTP ${code}"
  else
    bad "${path} HTTP ${code} (expected ${expect})"
  fi
}

check_http '/login' 200
check_http '/app/login' 410
check_http '/app' 410
check_http '/system/login' 410
check_http '/system' 410
check_http '/up/liveness' 200
check_http '/up/readiness' 200
check_http '/horizon' 302

if grep -Eiq 'credentials|password|token|stack trace|sqlstate' /tmp/moxdop-staging-http.out; then
  bad "health/login response looks like it leaked internals"
else
  ok "sampled HTTP body does not contain obvious secrets"
fi

if [[ "$fail" -ne 0 ]]; then
  say "staging smoke FAILED"
  exit 1
fi

say "staging smoke PASSED"
