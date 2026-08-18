# MoxDOP backup and restore

PostgreSQL is the source of truth. Redis is **not** backed up as canonical business data.

Protected QA files that this process must never touch:

- `/tmp/moxdop-final-manual-qa.sqlite`
- `/tmp/moxdop-e2e-qa-002.sqlite`

## What to back up

| Item | Tool | Notes |
| --- | --- | --- |
| PostgreSQL | `pg_dump --format=custom` | Required |
| `storage/app/private` | tar | Report PDFs, prospect PDFs, private operator files |
| `storage/app/raw_ingestion` | tar | Provider payload objects |
| `storage/app/public` | tar | Avatars / branding |
| `.env` / `APP_KEY` | secret store | **Not** in the tar. Required to decrypt provider credentials |

Exclude: `storage/logs`, cache, sessions, `node_modules`, `vendor`, rebuildable `public/build`.

Retention: `MOXDOP_BACKUP_KEEP_DAYS` (default 14). Backup directories are `chmod 600` on dump/tar.

## Backup

On the staging host, with libpq variables in the environment (password never passed as a flag):

```bash
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=moxdop PGDATABASE=moxdop_staging
export PGPASSWORD='…'   # from secret store, not committed
export MOXDOP_BACKUP_DIR=/var/backups/moxdop
export MOXDOP_APP_ROOT=/var/www/moxdop-staging
umask 077
bash ops/backup.sh
```

`ops/backup.sh` fail-fast: missing env, empty dump, or protected database names.

Take a backup **before** risky migrations once persistent staging data exists.

## Restore runbook

Restore is destructive. It is **not** complete without this sequence.

1. Decide maintenance / read-only: `php artisan down`
2. Stop workers: `php artisan horizon:terminate` and Supervisor stop if needed
3. Restore PostgreSQL with `ops/restore.sh` (requires `MOXDOP_ALLOW_DESTRUCTIVE_RESTORE=yes`)
4. Restore persistent files (the same script extracts `files.tar.gz` when present)
5. Fix ownership: `chgrp -R www-data storage bootstrap/cache` and writable group bits — not 777
6. Clear/rebuild caches: `php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. Restart Horizon via Supervisor (`autorestart` after terminate)
8. `php artisan up`
9. Health: `/up/liveness`, `/up/readiness`
10. Validate login at `/app/login`
11. Validate Customer/Brand counts against the pre-restore record
12. Confirm `APP_KEY` matches the backup era (otherwise encrypted credentials will not decrypt)

```bash
export MOXDOP_ALLOW_DESTRUCTIVE_RESTORE=yes
export MOXDOP_BACKUP_SET=/var/backups/moxdop/20260101T000000Z
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=moxdop PGDATABASE=moxdop_staging
bash ops/restore.sh
```

Do **not** run restore against user/manual QA databases. Do not run it in this repository-prep phase against anything except a disposable proof database.

## Local disposable proof (this phase)

Isolated database: `moxdop_staging_infra_proof` on local PostgreSQL 16.

Procedure used:

1. `php artisan migrate:fresh` against that database only
2. Insert a deterministic customer row
3. `ops/backup.sh`
4. Drop/recreate the disposable database
5. `ops/restore.sh`
6. Assert the row exists

PASS/FAIL is recorded in the staging infrastructure preparation report.

## APP_KEY recovery

If `.env` is lost and no `APP_KEY` backup exists, encrypted `core_integration_credentials` / `core_connection_credentials` cannot be recovered from PostgreSQL alone. Re-OAuth is required. Treat `APP_KEY` as a first-class secret.
