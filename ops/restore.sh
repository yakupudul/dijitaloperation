#!/usr/bin/env bash
# MoxDOP restore — DISPOSABLE / explicit only.
# Requires MOXDOP_ALLOW_DESTRUCTIVE_RESTORE=yes
# Never restore onto protected QA databases.
#
# Required:
#   PGDATABASE PGUSER PGHOST PGPORT [PGPASSWORD]
#   MOXDOP_BACKUP_SET  (directory produced by ops/backup.sh)
set -euo pipefail
umask 077

die() { printf 'ops/restore.sh: %s\n' "$*" >&2; exit 1; }

[[ "${MOXDOP_ALLOW_DESTRUCTIVE_RESTORE:-}" == "yes" ]] || die "set MOXDOP_ALLOW_DESTRUCTIVE_RESTORE=yes"

: "${PGDATABASE:?PGDATABASE is required}"
: "${PGUSER:?PGUSER is required}"
: "${MOXDOP_BACKUP_SET:?MOXDOP_BACKUP_SET is required (backup directory)}"
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGDATABASE PGUSER

case "$PGDATABASE" in
  *moxdop-final-manual-qa*|*moxdop-e2e-qa-002*|postgres|template0|template1)
    die "refusing restore target ${PGDATABASE}"
    ;;
esac

DUMP="${MOXDOP_BACKUP_SET}/postgres.dump"
[[ -s "$DUMP" ]] || die "missing ${DUMP}"

echo "ops/restore.sh: restoring ${DUMP} into ${PGDATABASE}"
pg_restore --clean --if-exists --no-owner --no-acl --dbname="$PGDATABASE" "$DUMP"

FILES="${MOXDOP_BACKUP_SET}/files.tar.gz"
APP_ROOT="${MOXDOP_APP_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ -s "$FILES" ]]; then
  echo "ops/restore.sh: extracting persistent files into ${APP_ROOT}"
  tar -C "$APP_ROOT" -xzf "$FILES"
fi

echo "ops/restore.sh: done — restart Horizon/PHP-FPM and run health checks (see docs/deployment/BACKUP_RESTORE.md)"
