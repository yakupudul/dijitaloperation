#!/usr/bin/env bash
# Automatic deploy for MoxDOP staging (yakup, 2026-10-06: "Otomatik deploy").
#
# Every 15 minutes (cron, root) this looks at the watched branches. A branch head is deployed only when
#   1. it contains the live release (git merge-base --is-ancestor <live> <head>): a branch that was not built on
#      top of what is live would roll back another thread's work, so it is skipped and reported instead;
#   2. the full PHPUnit suite passes on that exact commit (separate checkout, never the live DB): on PostgreSQL in a
#      throwaway test database of the live server (audit T2, 2026-10-22: the live app runs on PostgreSQL, and views,
#      partitions and column lengths behave differently from SQLite), SQLite :memory: only when that cannot be prepared;
#   3. a successful backup exists from the last 30 hours, or one is taken now (audit T3): no migration without a backup.
# Then the app checks the commit out (detached) and runs deploy/staging/deploy.sh, and the main screens are opened as an
# admin (moxdop:deploy:smoke, desktop + phone viewport). A deploy or screen check that fails puts the live release
# back. A failed test run or deploy is reported once per commit (phone + Ayarlar › Geliştirme havuzu) and that commit
# is not tried again; a missing backup only waits (the same commit goes ahead once a backup exists).
#
#   bash deploy/staging/auto-deploy.sh --install   # once, as root: installs /etc/cron.d/moxdop-autodeploy
#   bash deploy/staging/auto-deploy.sh             # one check now (what cron runs)
#   touch storage/app/auto-deploy.off              # pause; delete the file to resume
#
# Watched branches: MOXDOP_AUTODEPLOY_BRANCHES (space separated), default below.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

BRANCHES="${MOXDOP_AUTODEPLOY_BRANCHES:-claude/kume-cakismasi-301-l1blwu claude/project-thread-e5yimf}"
WEB_USER="${MOXDOP_WEB_USER:-www-data}"
CHECKOUT="${MOXDOP_AUTODEPLOY_CHECKOUT:-${ROOT}-autodeploy}"
STATE_DIR="storage/app"
STATUS_FILE="${STATE_DIR}/auto-deploy.json"
TRIED_FILE="${STATE_DIR}/auto-deploy.tried"
FAILURE_FILE="${STATE_DIR}/auto-deploy-failure.txt"
LOG_DIR="storage/logs/auto-deploy"
TEST_DB="${MOXDOP_AUTODEPLOY_TEST_DB:-moxdop_autodeploy_test}"
BACKUP_HOURS="${MOXDOP_AUTODEPLOY_BACKUP_HOURS:-30}"

if [[ "${1:-}" == "--install" ]]; then
  if [[ "$(id -u)" -ne 0 ]]; then
    echo "auto-deploy: run --install as root" >&2
    exit 1
  fi
  printf '%s\n' "# MoxDOP automatic deploy (installed by deploy/staging/auto-deploy.sh --install)" \
    "*/15 * * * * root bash ${ROOT}/deploy/staging/auto-deploy.sh >> ${ROOT}/storage/logs/auto-deploy.log 2>&1" \
    > /etc/cron.d/moxdop-autodeploy
  chmod 0644 /etc/cron.d/moxdop-autodeploy
  # The pages show "installed, first check pending" at once instead of "not installed" until the first cron tick.
  if [[ ! -f "$STATUS_FILE" ]]; then
    mkdir -p "$STATE_DIR"
    printf '{"state":"installed","branch":"","sha":"","message":"İlk kontrol en geç 15 dakika içinde.","checked_at":"%s","live":""}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATUS_FILE"
    chmod 0644 "$STATUS_FILE"
    chown "${WEB_USER}:${WEB_USER}" "$STATUS_FILE" 2>/dev/null || true
  fi
  echo "auto-deploy: installed /etc/cron.d/moxdop-autodeploy (every 15 minutes; branches: ${BRANCHES})"
  echo "auto-deploy: pause with: touch ${ROOT}/storage/app/auto-deploy.off"
  exit 0
fi

# One run at a time; a long test run must not overlap the next cron tick.
# (MOXDOP_AUTODEPLOY_LOCK: the tests run the script inside a running check and need a lock of their own.)
exec 9>"${MOXDOP_AUTODEPLOY_LOCK:-/tmp/moxdop-autodeploy.lock}"
if ! flock -n 9; then
  exit 0
fi

mkdir -p "$STATE_DIR" "$LOG_DIR"
touch "$TRIED_FILE"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# JSON string body: control characters (colours, newlines) become spaces, a letter cut in half at 600 bytes is dropped.
json_escape() { printf '%s' "$1" | LC_ALL=C tr '\000-\037\177' ' ' | head -c 600 | { iconv -f UTF-8 -t UTF-8 -c 2>/dev/null || cat; } | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

# status <state> <branch> <sha> <message>; state: installed | idle | testing | deploying | deployed | tests_failed | deploy_failed | blocked | paused
status() {
  local tmp
  tmp="$(mktemp "${STATE_DIR}/.auto-deploy.json.XXXXXX")"
  printf '{"state":"%s","branch":"%s","sha":"%s","message":"%s","checked_at":"%s","live":"%s"}\n' \
    "$1" "$(json_escape "$2")" "$3" "$(json_escape "$4")" "$(now)" "$(git rev-parse HEAD 2>/dev/null || true)" > "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$STATUS_FILE"
  chown "${WEB_USER}:${WEB_USER}" "$STATUS_FILE" 2>/dev/null || true
}

# notify <state> <branch> <sha> <message>: phone notice through the app (runs as the web user; never fails the run).
notify() {
  sudo -u "$WEB_USER" php artisan moxdop:auto-deploy:report "$1" --branch="$2" --sha="$3" --reason="$4" --no-interaction >/dev/null 2>&1 || true
}

# A value of the live .env (never printed): env_value DB_DATABASE
env_value() { sed -n "s/^${1}=//p" "${ROOT}/.env" 2>/dev/null | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }

# The suite's PostgreSQL database: dropped and created fresh for every run, owned by the app's database user, on the
# live server's PostgreSQL. Never the live database: a name equal to DB_DATABASE is refused. Prints nothing; on
# success TEST_PG_* are set for the test run.
prepare_test_db() {
  local live_db
  [[ "$(env_value DB_CONNECTION)" == "pgsql" ]] || return 1
  live_db="$(env_value DB_DATABASE)"
  TEST_PG_USER="$(env_value DB_USERNAME)"
  TEST_PG_PASSWORD="$(env_value DB_PASSWORD)"
  TEST_PG_HOST="$(env_value DB_HOST)"
  TEST_PG_PORT="$(env_value DB_PORT)"
  [[ -n "$TEST_PG_USER" && -n "$live_db" && "$TEST_DB" != "$live_db" && "$TEST_DB" =~ ^[a-z0-9_]+$ ]] || return 1
  command -v psql >/dev/null 2>&1 || return 1
  sudo -u postgres dropdb --if-exists "$TEST_DB" >/dev/null 2>&1 || return 1
  sudo -u postgres createdb -O "$TEST_PG_USER" "$TEST_DB" >/dev/null 2>&1 || return 1
}

# A command the live release knows (a new gate is skipped until the release that brings it is live).
has_command() { sudo -u "$WEB_USER" php artisan list --raw --no-interaction 2>/dev/null | grep -q "^${1}[[:space:]]"; }

tried() { grep -qx "$1" "$TRIED_FILE"; }
mark_tried() { echo "$1" >> "$TRIED_FILE"; tail -n 200 "$TRIED_FILE" > "${TRIED_FILE}.tmp" && mv -f "${TRIED_FILE}.tmp" "$TRIED_FILE"; }

# Commits on the watched branches that are not live yet (Geliştirme havuzu › Sürümler): sha, branch, time, subject.
declare -A HEADS=()
write_pending() {
  local tmp live branch
  live="$(git rev-parse HEAD)"
  tmp="$(mktemp "${STATE_DIR}/.auto-deploy-pending.XXXXXX")"
  for branch in "${!HEADS[@]}"; do
    git log --no-merges --format="%H%x09${branch}%x09%cI%x09%s" -n 40 "${live}..${HEADS[$branch]}" 2>/dev/null | tr -d '\r' || true
  done | sort -t "$(printf '\t')" -k1,1 -u > "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "${STATE_DIR}/auto-deploy-pending.tsv"
  chown "${WEB_USER}:${WEB_USER}" "${STATE_DIR}/auto-deploy-pending.tsv" 2>/dev/null || true
}

if [[ -f "${STATE_DIR}/auto-deploy.off" ]]; then
  status paused "" "" "Otomatik deploy durduruldu (storage/app/auto-deploy.off)."
  exit 0
fi

# Local edits on the server would be lost or block the checkout: never deploy over them.
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  status blocked "" "" "Sunucudaki uygulama klasöründe kaydedilmemiş değişiklik var; otomatik deploy beklemede."
  notify blocked "" "" "Sunucudaki uygulama klasöründe kaydedilmemiş değişiklik var."
  exit 0
fi

LIVE="$(git rev-parse HEAD)"
TARGET=""
TARGET_BRANCH=""
for branch in $BRANCHES; do
  if ! git fetch --quiet origin "$branch" 2>/dev/null; then
    continue
  fi
  head="$(git rev-parse FETCH_HEAD)"
  HEADS[$branch]="$head"
  if [[ "$head" == "$LIVE" ]] || git merge-base --is-ancestor "$head" "$LIVE"; then
    continue # nothing new on this branch
  fi
  if ! git merge-base --is-ancestor "$LIVE" "$head"; then
    if ! tried "blocked:${head}"; then
      mark_tried "blocked:${head}"
      msg="${branch} dalı canlı sürümün (${LIVE:0:8}) üzerine kurulmamış; deploy edilirse başka işleri geri alır. Dal canlı sürümle birleştirilmeli."
      status blocked "$branch" "$head" "$msg"
      notify blocked "$branch" "$head" "$msg"
    fi
    continue
  fi
  if tried "$head"; then
    continue # already failed its tests or its deploy; a new commit is needed
  fi
  # Two branches both ahead of live: take the one that contains the other, else the first.
  if [[ -z "$TARGET" ]] || git merge-base --is-ancestor "$TARGET" "$head"; then
    TARGET="$head"
    TARGET_BRANCH="$branch"
  fi
done
write_pending

if [[ -z "$TARGET" ]]; then
  if [[ ! -f "$STATUS_FILE" ]] || grep -qE '"state":"(installed|deployed|idle|paused|testing|deploying)"' "$STATUS_FILE"; then
    status idle "" "$LIVE" "Yeni commit yok."
  fi
  # Idle: the monthly restore drill may run (it decides itself whether one is due; audit D5).
  if [[ -f deploy/staging/restore-drill.sh ]]; then
    bash deploy/staging/restore-drill.sh >> "${LOG_DIR}/restore-drill.log" 2>&1 || true
  fi
  exit 0
fi

SHORT="${TARGET:0:8}"
LOG="${LOG_DIR}/${SHORT}.log"

# 1) Tests on the exact commit, in a separate checkout (dev dependencies), on the throwaway PostgreSQL test database.
status testing "$TARGET_BRANCH" "$TARGET" "${SHORT} için testler çalışıyor."
: >> "$LOG"
TEST_ENGINE="sqlite"
if tried "tested:${TARGET}"; then
  TEST_ENGINE="done" # passed on an earlier check that then waited for a backup
elif prepare_test_db; then
  TEST_ENGINE="pgsql"
else
  echo "auto-deploy: PostgreSQL test veritabanı hazırlanamadı; testler SQLite ile çalışıyor." >> "$LOG"
fi
if [[ ! -d "${CHECKOUT}/.git" && ! -f "${CHECKOUT}/.git" ]]; then
  git worktree add --detach "$CHECKOUT" "$TARGET" >/dev/null 2>&1 || git clone --quiet "$ROOT" "$CHECKOUT"
fi
if [[ "$TEST_ENGINE" != "done" ]] && ! (
  cd "$CHECKOUT"
  git fetch --quiet "$ROOT" "$TARGET" 2>/dev/null || true
  git checkout --quiet --force --detach "$TARGET"
  git clean -fdq -e vendor -e .env
  composer install --no-interaction --prefer-dist --no-progress --quiet
  [[ -f .env ]] || cp .env.example .env
  php artisan key:generate --force --no-interaction >/dev/null
  php artisan config:clear --no-interaction >/dev/null
  if [[ "$TEST_ENGINE" == "pgsql" ]]; then
    DB_CONNECTION=pgsql DB_HOST="${TEST_PG_HOST:-127.0.0.1}" DB_PORT="${TEST_PG_PORT:-5432}" DB_DATABASE="$TEST_DB" \
      DB_USERNAME="$TEST_PG_USER" DB_PASSWORD="$TEST_PG_PASSWORD" php artisan test --compact
  else
    DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact
  fi
) >> "$LOG" 2>&1; then
  mark_tried "$TARGET"
  # Without colour codes: the failing tests by name for the status, and each failure with its first lines for the
  # pages and Claude (Sürümler, system-health) so the cause can be read without the server.
  plain="$(sed -r 's/\x1B\[[0-9;]*[A-Za-z]//g' "$LOG")"
  count="$(printf '%s\n' "$plain" | grep -cE '^\s*FAILED ' || true)"
  failed="$(printf '%s\n' "$plain" | grep -E '^\s*FAILED ' | sed -E 's/^\s*FAILED\s+//; s/\s+$//' | head -n 5 | tr '\n' ';')"
  [[ -n "$failed" ]] || failed="$(printf '%s\n' "$plain" | grep -E 'FAIL|Failed asserting|Error' | head -n 3 | tr '\n' ' ')"
  { printf '%s · %s\n\n' "$SHORT" "$(now)"; printf '%s\n' "$plain" | grep -E -A 12 '^\s*FAILED ' | head -c 12000; } > "$FAILURE_FILE" || true
  failed="${count:-0} test: ${failed}"
  status tests_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçmedi, canlıya alınmadı. ${failed}"
  notify tests_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçmedi, canlıya alınmadı. ${failed}"
  [[ "$TEST_ENGINE" == "pgsql" ]] && sudo -u postgres dropdb --if-exists "$TEST_DB" >/dev/null 2>&1 || true
  exit 0
fi
[[ "$TEST_ENGINE" == "pgsql" ]] && sudo -u postgres dropdb --if-exists "$TEST_DB" >/dev/null 2>&1 || true
tried "tested:${TARGET}" || mark_tried "tested:${TARGET}"

# 2) No migration without a backup: one from the last BACKUP_HOURS hours, or one taken now. Without it the deploy waits
#    (the commit is not marked tried, so the next check deploys it once a backup exists); reported once per commit.
if has_command moxdop:deploy:backup-gate; then
  if ! gate="$(sudo -u "$WEB_USER" php artisan moxdop:deploy:backup-gate --hours="$BACKUP_HOURS" --no-interaction 2>&1)"; then
    echo "$gate" >> "$LOG"
    msg="${SHORT} testleri geçti ama son ${BACKUP_HOURS} saatte yedek yok ve yeni yedek alınamadı; deploy yedek alınınca yapılacak. $(printf '%s' "$gate" | tail -n 1)"
    status blocked "$TARGET_BRANCH" "$TARGET" "$msg"
    if ! tried "backup:${TARGET}"; then
      mark_tried "backup:${TARGET}"
      notify blocked "$TARGET_BRANCH" "$TARGET" "$msg"
    fi
    exit 0
  fi
  echo "$gate" >> "$LOG"
fi

# A release deployed by hand while the tests ran (2026-10-22) must not be replaced by this older target, nor rolled
# back to the release this check started from: the next check starts again from the new live release.
if [[ "$(git rev-parse HEAD)" != "$LIVE" ]]; then
  echo "auto-deploy: live moved to $(git rev-parse --short HEAD) during the tests; ${SHORT} skipped" >> "$LOG"
  status idle "" "$(git rev-parse HEAD)" "Canlı sürüm elle değişti; sıradaki kontrol yeni sürümden başlar."
  exit 0
fi
# 3) Deploy: the app checks the tested commit out and runs the normal deploy script; then the main screens must open
#    (desktop + phone viewport) as an admin, or the release goes back like a failed deploy.
status deploying "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçti, deploy ediliyor."
screens_open() {
  has_command moxdop:deploy:smoke || return 0
  sudo -u "$WEB_USER" php artisan moxdop:deploy:smoke --no-interaction >> "$LOG" 2>&1
}
if git checkout --quiet --detach "$TARGET" && bash deploy/staging/deploy.sh >> "$LOG" 2>&1 && screens_open; then
  write_pending
  rm -f "$FAILURE_FILE"
  status deployed "$TARGET_BRANCH" "$TARGET" "${SHORT} canlıda ($(git log -1 --format=%s "$TARGET" | head -c 120))."
  notify deployed "$TARGET_BRANCH" "$TARGET" "$(git log -1 --format=%s "$TARGET" | head -c 160)"
else
  mark_tried "$TARGET"
  # The deploy log's last lines go where the system health page and the MCP read them (deploy.sh prints no secrets).
  { printf '%s · %s · deploy\n\n' "$SHORT" "$(now)"; tail -n 60 "$LOG" | head -c 12000; } > "$FAILURE_FILE" || true
  # Back to the release that was live, so the app never stays on half-deployed code.
  echo "auto-deploy: ${SHORT} failed; restoring ${LIVE:0:8}" >> "$LOG"
  restored="geri alındı, ${LIVE:0:8} canlıda kaldı"
  if ! { git checkout --quiet --force --detach "$LIVE" && bash deploy/staging/deploy.sh >> "$LOG" 2>&1; }; then
    restored="ESKİ SÜRÜME DÖNÜŞ DE BAŞARISIZ, sunucuya bakılmalı"
  fi
  status deploy_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} deploy sırasında durdu (${restored}); ayrıntı storage/logs/auto-deploy/${SHORT}.log."
  notify deploy_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} deploy sırasında durdu (${restored})."
fi
chown -R "${WEB_USER}:${WEB_USER}" "$STATE_DIR" "$LOG_DIR" 2>/dev/null || true
