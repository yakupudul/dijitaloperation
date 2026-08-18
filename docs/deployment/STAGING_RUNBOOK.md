# MoxDOP staging runbook

Product features are frozen. Deploy an **exact Git SHA**. Do not deploy “whatever is on the branch” without recording the SHA.

This is a **single-VPS** procedure with a short maintenance window. It is **not** zero-downtime.

## Never

- `php artisan migrate:fresh` / `migrate:refresh` / `db:wipe` on staging
- regenerate `APP_KEY` after encrypted Google/Meta credentials exist
- commit secrets
- enable `APP_DEBUG=true`
- enable `MOXDOP_SALES_INTENT_PAID_CALLS` unless explicitly intended
- run a second scheduler
- `kill -9` Horizon workers
- auto-seed Demo/Atlas/fake customers

Use `php artisan migrate --force` only.

## One-time host bootstrap

1. Ubuntu 24.04 (or equivalent) with PHP 8.3 FPM, Nginx, PostgreSQL 16, Redis, Supervisor, cron, Node 22, Composer 2.
2. Create PostgreSQL role/database `moxdop_staging`.
3. Create Redis with a password; bind to localhost; use `REDIS_PREFIX` / `HORIZON_PREFIX` unique to staging.
4. Create `/var/www/moxdop-staging` owned by the deploy user, group `www-data`.
5. Copy `.env.staging.example` → `.env`. Fill required placeholders only.
6. Generate APP_KEY **once**: `php artisan key:generate`. Record the key in the secret store. Back it up. Never rotate casually (encrypted provider payloads become unreadable).
7. `composer install --no-dev --optimize-autoloader`
8. `npm ci && npm run build` (or copy `public/build`)
9. `php artisan migrate --force`
10. `php artisan db:seed --force` **once** (roles/modules/playbooks only)
11. `php artisan dop:create-admin` (interactive strong password)
12. `php artisan storage:link`
13. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
14. Install `deploy/staging/nginx.conf.example`, TLS, DNS.
15. Install `deploy/staging/supervisor-horizon.conf.example` and start Horizon.
16. Install `deploy/staging/cron.example`.
17. `php artisan up` if previously down.
18. Run `deploy/staging/smoke.sh`.

Directory permissions: `storage/` and `bootstrap/cache/` writable by `www-data` (for example `chgrp -R www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache`). Not 777.

## Recurring deploy (known-good SHA)

```bash
git fetch origin
git checkout --detach <RELEASE_SHA>
# backup first if the release includes migrations — see BACKUP_RESTORE.md
bash deploy/staging/deploy.sh
```

`deploy/staging/deploy.sh` order:

1. Refuse sqlite / missing APP_KEY / `APP_DEBUG=true` / non-pgsql / non-staging env
2. Record SHA
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. `php artisan down`
6. `php artisan migrate --force`
7. `php artisan storage:link`
8. `config:cache` `route:cache` `view:cache`
9. `php artisan horizon:terminate` (Supervisor restarts Horizon)
10. `php artisan up`
11. `php artisan about`

Route caching is compatible with this app (no closure routes in HTTP that block `route:cache`). If `route:cache` ever fails, skip it and record the error; do not invent a workaround that weakens CSRF.

## Health

| Path | Auth | Purpose |
| --- | --- | --- |
| `/up` | public | Laravel framework health |
| `/up/liveness` | public | process alive — no DB |
| `/up/readiness` | public | DB + storage; Redis when queue/cache is redis. No hosts, credentials, or traces |
| `/ops/health-snapshot` | authenticated operator | internal diagnostics |
| `/horizon` | authenticated Admin | queue dashboard |

## Encrypted credentials / APP_KEY

MoxDOP stores recoverable provider secrets with Laravel `encrypted:array` (AES-256-CBC via `APP_KEY`).

- Changing `APP_KEY` without `APP_PREVIOUS_KEYS` makes Google/Meta/AI payloads unreadable.
- Back up `.env` (secret store), especially `APP_KEY`.
- Rotation: `php artisan moxdop:security:reencrypt-credentials` with previous keys — not a routine deploy step.

Never log decrypted credentials.

## Mail

Staging may keep `MAIL_MAILER=log` until SMTP is configured. Real SMTP smoke is a later phase. Do not send mail from automated validation.

## Rollback

**Code rollback:** check out the previous known-good SHA and run `deploy/staging/deploy.sh` **without** assuming migrations reverse.

**Database rollback:** do **not** blindly `php artisan migrate:rollback` after arbitrary staging migrations.

Safe approach:

1. Backup (`ops/backup.sh`) before risky migrations
2. Prefer forward-fix
3. Restore (`ops/restore.sh`) only when the backup is understood and `MOXDOP_ALLOW_DESTRUCTIVE_RESTORE=yes`

## Crash recovery expectations

| Event | Expected |
| --- | --- |
| Horizon process dies | Supervisor restarts `artisan horizon` |
| Redis restarts | Horizon reconnects; sessions remain (database driver) |
| PHP-FPM restarts | Nginx continues; in-flight requests fail briefly |
| Server reboot | PHP-FPM, Nginx, PostgreSQL, Redis, Supervisor, cron start via systemd |

## HTTPS / domain

Required before OAuth: DNS A/AAAA, TLS certificate, HTTP→HTTPS redirect, `APP_URL=https://…`, `APP_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` set for Nginx.

## Provider configuration order (later phases)

1. Google application credentials
2. Google OAuth
3. Google resource discovery
4. Resource binding
5. Collection
6. Meta application credentials
7. Meta OAuth
8. Meta resource discovery
9. Resource binding
10. Collection
11. AI providers (optional to boot)
12. DataForSEO paid policy only if explicitly desired (`MOXDOP_SALES_INTENT_PAID_CALLS`)

AI keys are **not** a deploy prerequisite. Sales Intelligence stays truthful unavailable when unconfigured.

## Staging smoke

```bash
MOXDOP_STAGING_BASE_URL=https://staging.example.com bash deploy/staging/smoke.sh
```

No provider calls, no paid DataForSEO, no mail.

## Application QA (unchanged)

```bash
env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact
npm run qa:e2e
npm run build
```

Do not point PHPUnit at the staging database. Do not wipe `/tmp/moxdop-final-manual-qa.sqlite` or `/tmp/moxdop-e2e-qa-002.sqlite`.
