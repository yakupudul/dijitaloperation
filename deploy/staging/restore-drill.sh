#!/usr/bin/env bash
# Monthly restore drill (audit D5, 2026-10-22): a backup counts only when it can be put back. auto-deploy.sh (root,
# cron) calls this on an idle check between 04:00 and 07:00 server time when the last drill is older than 30 days.
#
# The newest PostgreSQL backup is restored into a throwaway database (never the live one) on the same server, a few
# tables are counted, the database is dropped again and the result goes to storage/app/restore-drill.json
# (Sistem sağlığı, the MCP system-health). A failed drill reaches the phone. Nothing is printed from .env.
#
#   bash deploy/staging/restore-drill.sh          # what auto-deploy.sh runs (only when due)
#   bash deploy/staging/restore-drill.sh --now    # one drill now, as root
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

WEB_USER="${MOXDOP_WEB_USER:-www-data}"
DRILL_DB="${MOXDOP_RESTORE_DRILL_DB:-moxdop_restore_drill}"
RESULT="storage/app/restore-drill.json"
DAYS="${MOXDOP_RESTORE_DRILL_DAYS:-30}"

env_value() { sed -n "s/^${1}=//p" "${ROOT}/.env" 2>/dev/null | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }
now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
json_escape() { printf '%s' "$1" | LC_ALL=C tr '\000-\037\177' ' ' | head -c 600 | { iconv -f UTF-8 -t UTF-8 -c 2>/dev/null || cat; } | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

# result <ok|failed|skipped> <message> [backup file] [seconds]
result() {
  local tmp
  tmp="$(mktemp "storage/app/.restore-drill.XXXXXX")"
  printf '{"state":"%s","message":"%s","backup":"%s","seconds":%s,"checked_at":"%s"}\n' \
    "$1" "$(json_escape "$2")" "$(json_escape "${3:-}")" "${4:-0}" "$(now)" > "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$RESULT"
  chown "${WEB_USER}:${WEB_USER}" "$RESULT" 2>/dev/null || true
  if [[ "$1" == "failed" ]]; then
    sudo -u "$WEB_USER" php artisan moxdop:backup:drill-report --no-interaction >/dev/null 2>&1 || true
  fi
}

if [[ "${1:-}" != "--now" ]]; then
  hour="$(date +%H)"
  [[ "$hour" -ge 4 && "$hour" -lt 7 ]] || exit 0
  if [[ -f "$RESULT" ]] && [[ -n "$(find "$RESULT" -mtime "-${DAYS}" 2>/dev/null)" ]] && ! grep -q '"state":"skipped"' "$RESULT"; then
    exit 0 # drilled within the last DAYS days
  fi
  # A skipped drill (no room, no backup) is tried again after a day, not every 15 minutes.
  if [[ -f "$RESULT" ]] && grep -q '"state":"skipped"' "$RESULT" && [[ -n "$(find "$RESULT" -mtime -1 2>/dev/null)" ]]; then
    exit 0
  fi
fi

[[ "$(env_value DB_CONNECTION)" == "pgsql" ]] || { result skipped "Canlı veritabanı PostgreSQL değil; prova yalnız PostgreSQL için."; exit 0; }
LIVE_DB="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
export PGPASSWORD="$(env_value DB_PASSWORD)"
if [[ -z "$LIVE_DB" || -z "$DB_USER" || "$DRILL_DB" == "$LIVE_DB" || ! "$DRILL_DB" =~ ^[a-z0-9_]+$ ]]; then
  result failed "Prova veritabanı adı geçersiz ya da canlı veritabanıyla aynı; prova yapılmadı."
  exit 0
fi

BACKUP_DIR="$(env_value MOXDOP_BACKUP_DIR)"
BACKUP_DIR="${BACKUP_DIR:-${ROOT}/storage/app/backups}"
BACKUP="$(ls -1t "$BACKUP_DIR"/moxdop-*-pgsql.sql.gz 2>/dev/null | head -n 1 || true)"
if [[ -z "$BACKUP" ]]; then
  result skipped "Geri yüklenecek PostgreSQL yedeği yok (${BACKUP_DIR})."
  exit 0
fi

psql_live() { psql -X -q -t -A -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$1" -c "$2" 2>/dev/null; }

# Room: the restored database needs about as much as the live one (the dump leaves some table data out); 20% margin.
need="$(psql_live "$LIVE_DB" "select (pg_database_size(current_database()) * 1.2)::bigint" || echo 0)"
data_dir="$(sudo -u postgres psql -X -q -t -A -c 'show data_directory' 2>/dev/null || true)"
free="$(df -B1 --output=avail "${data_dir:-/var/lib/postgresql}" 2>/dev/null | tail -n 1 | tr -d ' ' || echo 0)"
if [[ -z "$need" || "$need" -le 0 || -z "$free" || "$free" -lt "$need" ]]; then
  result skipped "Prova için diskte yer yok (gereken ~$(( ${need:-0} / 1024 / 1024 / 1024 )) GB, boş $(( ${free:-0} / 1024 / 1024 / 1024 )) GB); yedek dosyası doğrulandı ama geri yükleme denenmedi." "$(basename "$BACKUP")"
  exit 0
fi

started="$(date +%s)"
sudo -u postgres dropdb --if-exists "$DRILL_DB" >/dev/null 2>&1 || true
if ! sudo -u postgres createdb -O "$DB_USER" "$DRILL_DB" >/dev/null 2>&1; then
  result failed "Prova veritabanı oluşturulamadı." "$(basename "$BACKUP")"
  exit 0
fi
errors="$(gunzip -c "$BACKUP" | psql -X -q -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$DRILL_DB" 2>&1 >/dev/null | grep -c 'ERROR:' || true)"
seconds=$(( $(date +%s) - started ))

problems=""
for table in migrations users brands digital_assets; do
  restored="$(psql_live "$DRILL_DB" "select count(*) from ${table}" || echo "")"
  live="$(psql_live "$LIVE_DB" "select count(*) from ${table}" || echo "")"
  if [[ -z "$restored" || "$restored" -eq 0 ]] || [[ -n "$live" && "$live" -gt 0 && "$restored" -lt $(( live * 9 / 10 )) ]]; then
    problems="${problems} ${table} (yedekte ${restored:-okunamadı}, canlıda ${live:-?})"
  fi
done
sudo -u postgres dropdb --if-exists "$DRILL_DB" >/dev/null 2>&1 || true

if [[ -n "$problems" ]]; then
  result failed "Yedek geri yüklendi ama tablolar eksik:${problems}; ${errors:-0} SQL hatası." "$(basename "$BACKUP")" "$seconds"
else
  result ok "Yedek $(( seconds / 60 )) dakikada geri yüklendi, ana tablolar tam (${errors:-0} SQL uyarısı)." "$(basename "$BACKUP")" "$seconds"
fi
