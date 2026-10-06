#!/usr/bin/env bash
# Automatic deploy for MoxDOP staging (yakup, 2026-10-06: "Otomatik deploy").
#
# Every 15 minutes (cron, root) this looks at the watched branches. A branch head is deployed only when
#   1. it contains the live release (git merge-base --is-ancestor <live> <head>): a branch that was not built on
#      top of what is live would roll back another thread's work, so it is skipped and reported instead;
#   2. the full PHPUnit suite passes on that exact commit (separate checkout, SQLite :memory:, never the live DB).
# Then the app checks the commit out (detached) and runs deploy/staging/deploy.sh. A failed test run or deploy is
# reported once per commit (phone + Ayarlar › Geliştirme havuzu) and that commit is not tried again.
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
LOG_DIR="storage/logs/auto-deploy"

if [[ "${1:-}" == "--install" ]]; then
  if [[ "$(id -u)" -ne 0 ]]; then
    echo "auto-deploy: run --install as root" >&2
    exit 1
  fi
  printf '%s\n' "# MoxDOP automatic deploy (installed by deploy/staging/auto-deploy.sh --install)" \
    "*/15 * * * * root bash ${ROOT}/deploy/staging/auto-deploy.sh >> ${ROOT}/storage/logs/auto-deploy.log 2>&1" \
    > /etc/cron.d/moxdop-autodeploy
  chmod 0644 /etc/cron.d/moxdop-autodeploy
  echo "auto-deploy: installed /etc/cron.d/moxdop-autodeploy (every 15 minutes; branches: ${BRANCHES})"
  echo "auto-deploy: pause with: touch ${ROOT}/storage/app/auto-deploy.off"
  exit 0
fi

# One run at a time; a long test run must not overlap the next cron tick.
exec 9>"/tmp/moxdop-autodeploy.lock"
if ! flock -n 9; then
  exit 0
fi

mkdir -p "$STATE_DIR" "$LOG_DIR"
touch "$TRIED_FILE"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }

json_escape() { printf '%s' "$1" | head -c 600 | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr '\n\r\t' '   '; }

# status <state> <branch> <sha> <message>; state: idle | testing | deploying | deployed | tests_failed | deploy_failed | blocked | paused
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
  if [[ ! -f "$STATUS_FILE" ]] || grep -qE '"state":"(deployed|idle|paused|testing|deploying)"' "$STATUS_FILE"; then
    status idle "" "$LIVE" "Yeni commit yok."
  fi
  exit 0
fi

SHORT="${TARGET:0:8}"
LOG="${LOG_DIR}/${SHORT}.log"

# 1) Tests on the exact commit, in a separate checkout (dev dependencies, SQLite :memory: from phpunit.xml).
status testing "$TARGET_BRANCH" "$TARGET" "${SHORT} için testler çalışıyor."
if [[ ! -d "${CHECKOUT}/.git" && ! -f "${CHECKOUT}/.git" ]]; then
  git worktree add --detach "$CHECKOUT" "$TARGET" >/dev/null 2>&1 || git clone --quiet "$ROOT" "$CHECKOUT"
fi
if ! (
  cd "$CHECKOUT"
  git fetch --quiet "$ROOT" "$TARGET" 2>/dev/null || true
  git checkout --quiet --force --detach "$TARGET"
  git clean -fdq -e vendor -e .env
  composer install --no-interaction --prefer-dist --no-progress --quiet
  [[ -f .env ]] || cp .env.example .env
  php artisan key:generate --force --no-interaction >/dev/null
  php artisan config:clear --no-interaction >/dev/null
  DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact
) > "$LOG" 2>&1; then
  mark_tried "$TARGET"
  failed="$(grep -E 'FAIL|Failed asserting|Error' "$LOG" | head -n 3 | tr '\n' ' ')"
  status tests_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçmedi, canlıya alınmadı. ${failed}"
  notify tests_failed "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçmedi, canlıya alınmadı. ${failed}"
  exit 0
fi

# 2) Deploy: the app checks the tested commit out and runs the normal deploy script.
status deploying "$TARGET_BRANCH" "$TARGET" "${SHORT} testleri geçti, deploy ediliyor."
if git checkout --quiet --detach "$TARGET" && bash deploy/staging/deploy.sh >> "$LOG" 2>&1; then
  write_pending
  status deployed "$TARGET_BRANCH" "$TARGET" "${SHORT} canlıda ($(git log -1 --format=%s "$TARGET" | head -c 120))."
  notify deployed "$TARGET_BRANCH" "$TARGET" "$(git log -1 --format=%s "$TARGET" | head -c 160)"
else
  mark_tried "$TARGET"
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
