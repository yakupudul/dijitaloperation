#!/usr/bin/env bash
# MoxDOP backup — PostgreSQL dump + persistent application files.
# Does not echo secrets. Redis is not backed up (not source of truth).
#
# Required libpq environment (never pass passwords as CLI flags):
#   PGDATABASE PGUSER PGHOST PGPORT  [PGPASSWORD]
# Optional:
#   MOXDOP_BACKUP_DIR (default /var/backups/moxdop)
#   MOXDOP_BACKUP_KEEP_DAYS (default 14)
#   MOXDOP_APP_ROOT
set -euo pipefail
umask 077

die() { printf 'ops/backup.sh: %s\n' "$*" >&2; exit 1; }

: "${PGDATABASE:?PGDATABASE is required}"
: "${PGUSER:?PGUSER is required}"
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGDATABASE PGUSER

case "$PGDATABASE" in
  *moxdop-final-manual-qa*|*moxdop-e2e-qa-002*)
    die "refusing protected QA database name ${PGDATABASE}"
    ;;
esac

BACKUP_DIR="${MOXDOP_BACKUP_DIR:-/var/backups/moxdop}"
KEEP_DAYS="${MOXDOP_BACKUP_KEEP_DAYS:-14}"
APP_ROOT="${MOXDOP_APP_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/${STAMP}"
mkdir -p "$TARGET"

{
  echo "backup_started_utc=${STAMP}"
  echo "database=${PGDATABASE}"
  echo "host=${PGHOST}"
  echo "app_root=${APP_ROOT}"
  echo "release_sha=$(git -C "$APP_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
} > "${TARGET}/manifest.txt"

echo "ops/backup.sh: dumping PostgreSQL (custom format)"
pg_dump --format=custom --no-owner --no-acl --file="${TARGET}/postgres.dump"
[[ -s "${TARGET}/postgres.dump" ]] || die "postgres.dump is empty"

echo "ops/backup.sh: archiving persistent files"
FILE_LIST=()
for rel in storage/app/private storage/app/public storage/app/raw_ingestion; do
  if [[ -d "${APP_ROOT}/${rel}" ]]; then
    FILE_LIST+=("$rel")
  fi
done

if [[ "${#FILE_LIST[@]}" -gt 0 ]]; then
  tar -C "$APP_ROOT" \
    --exclude='storage/logs' \
    --exclude='storage/framework/cache' \
    --exclude='storage/framework/views' \
    --exclude='storage/framework/sessions' \
    -czf "${TARGET}/files.tar.gz" \
    "${FILE_LIST[@]}"
else
  tar -czf "${TARGET}/files.tar.gz" --files-from /dev/null
fi

chmod 600 "${TARGET}/postgres.dump" "${TARGET}/files.tar.gz" "${TARGET}/manifest.txt"
echo "backup_finished_utc=$(date -u +%Y%m%dT%H%M%SZ)" >> "${TARGET}/manifest.txt"
echo "ops/backup.sh: wrote ${TARGET}"

if [[ "${KEEP_DAYS}" =~ ^[0-9]+$ ]] && [[ "$KEEP_DAYS" -gt 0 ]]; then
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+${KEEP_DAYS}" -exec rm -rf {} +
fi
