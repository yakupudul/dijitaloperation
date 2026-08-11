# Persistent UAT — MoxDOP

> Canonical operator-facing **persistent UAT** runtime for human acceptance.  
> Not production. Not Cursor Cloud.  
> Related: `OPERATOR_ASYNC_EXECUTION.md`, `docs/implementation/CURSOR_CLOUD_ENVIRONMENT.md`, `PROJECT_MEMORY.md`.

## Purpose

Give operators a normal-browser URL (target concept: `https://uat.dop.moximu.com`) that survives Cursor Cloud Agent sessions, so final human UAT for Async Operations (#121) and later milestones can run without port forwarding.

## Environment model

| Environment | Role | Canonical DB | Persistence |
| --- | --- | --- | --- |
| **development** | Cursor Cloud / local agent | SQLite file (`database/database.sqlite`) | Ephemeral agent VM |
| **testing** | PHPUnit | SQLite `:memory:` | Per test |
| **browser-UAT synthetic** | Disposable Filament browser checks | Separate SQLite (`database/browser-uat.sqlite`) | Disposable; never contaminate operator DB |
| **persistent UAT** | Human/operator acceptance | **MySQL 8** | Linux host + durable processes |
| **future production** | Live agency ops | MySQL 8 | Separate host/secrets; **not this doc** |

Cursor Cloud is **development/test only**. Persistent UAT is the **canonical human acceptance** environment.

## Architecture (required processes)

```text
Internet → Nginx (HTTPS) → PHP-FPM → Laravel /public
                │
                ├── MySQL 8 (app DB)
                ├── Supervisor: queue:work database
                └── cron: php artisan schedule:run (every minute)
```

Do **not** use `php artisan serve` as the permanent web process.

Do **not** introduce Redis / Kafka / Kubernetes / microservices for MVP UAT.

## Persistent APP_KEY (critical)

Persistent UAT must keep **one stable** `APP_KEY` across deploys.

Changing `APP_KEY` invalidates Laravel-encrypted provider credentials (Meta/Google/etc.) and can break Integrations until re-authorization.

- Generate once on first UAT bootstrap: `php artisan key:generate --show` (or `--force` only into server `.env`)
- Store only in server `.env` (or host secret store)
- **Never commit** the real key
- Deploys must **not** regenerate the key

## Env template

Use repo file: `.env.uat.example` → copy to server `.env` and fill secrets locally.

Key concepts (names only):

| Variable | UAT expectation |
| --- | --- |
| `APP_ENV` | `uat` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://uat.dop.moximu.com` (or operator-approved host) |
| `APP_KEY` | Stable; never rotate casually |
| `DB_CONNECTION` | `mysql` |
| `QUEUE_CONNECTION` | `database` |
| `DB_QUEUE_RETRY_AFTER` | `900` |
| `SESSION_SECURE_COOKIE` | `true` |
| `LOG_LEVEL` | `warning` or `error` |

No credentials in git.

## Deploy artifacts in this repo

| Path | Role |
| --- | --- |
| `.env.uat.example` | Env names / safe defaults |
| `deploy/uat/nginx.conf.example` | Nginx vhost → `/public` |
| `deploy/uat/supervisor-moxdop-worker.conf.example` | Persistent queue worker |
| `deploy/uat/deploy.sh` | Manual pull → install → migrate → restart worker |
| `deploy/uat/OPERATOR_BOOTSTRAP.md` | First-time host checklist (no secrets) |

## Manual deploy flow (intentionally small)

On the UAT host, after DNS + TLS + MySQL + PHP-FPM + Nginx + Supervisor exist:

```bash
# as deploy user, app root e.g. /var/www/moxdop-uat
git fetch origin
git checkout cursor/async-operations-activity-center-ea01   # or merged main later
git pull --ff-only
bash deploy/uat/deploy.sh
```

`deploy.sh` runs: `composer install --no-dev`, `npm ci && npm run build` (if Node available on host; otherwise build artifacts must be present), `migrate --force`, `config/route/view cache`, Supervisor worker restart.

**Deploy must never** auto-trigger Meta/Google collection, AI, discovery, or any provider write.

## Queue worker

Supervisor program (see example):

```text
php artisan queue:work database --sleep=1 --tries=2 --timeout=600 --max-time=3600
```

Must: start on boot, restart on crash. Bounded tries — no retry storms.

## Scheduler

Cron (as app user):

```cron
* * * * * cd /var/www/moxdop-uat && php artisan schedule:run >> /dev/null 2>&1
```

Required for `async:mark-stale-runs` (every 5 minutes via `routes/console.php`).

## Data rules

- Do **not** copy Cloud SQLite into MySQL.
- Do **not** seed `act_1001` / Lead Camp A/B / synthetic Meta fixtures.
- Recreate only: Admin · Customer/Brand/Asset · central Integration · **explicit** real binding after operator confirmation.
- Never auto-bind discovered provider accounts.

## Meta credential

Configure only via Admin Integrations / encrypted credential workflow (or secure env fallback already supported by product). Never commit, print, or paste tokens into docs/shell history.

Bind `act_744654160596455` only when the operator confirms that account for UAT.

## Security baseline

- HTTPS only (redirect HTTP → HTTPS)
- `APP_DEBUG=false`
- Secure session cookie on HTTPS
- Document root = `public/` only
- `.env`, `storage/`, `database/` not web-accessible
- Strong admin password (`php artisan dop:create-admin`)
- No directory listing

## Operator URL

Target: **https://uat.dop.moximu.com/app**

Until DNS + host exist, this URL is **not live**. Cursor port forwarding is **not** a substitute for persistent UAT acceptance.

## Human Async UAT checklist (#121)

When persistent UAT is live:

1. Meta Ads asset for `act_744654160596455` → **Collect live data** → returns immediately (“queued”) → navigate away
2. Activity → queued/running → reload persists phase → worker completes → completed/partial/failed with stages
3. Meta workspace still shows latest successful data independently of in-flight runs
4. **Generate AI Guidance** smoke → queued → Activity → result later (no auto Recommendation)
5. Synthetic failure path (isolated) → readable issue + retry eligibility
6. `async:mark-stale-runs` via scheduler on a **synthetic** running run → Needs attention
7. Worker health signal; if worker stopped for test, restart it before leaving
8. Confirm **no** Meta writes

## Backup / rollback (minimal)

- Backup: MySQL dump + preserve server `.env` (especially `APP_KEY`) + `storage/app` if used
- Rollback: `git checkout <previous-sha>` + `bash deploy/uat/deploy.sh` (migrations: prefer forward-fix; do not casual `migrate:rollback` on UAT with real bindings)

## Status of this environment (honesty)

Persistent host / DNS / SSH access are **operator-supplied**. This repository prepares config and docs; it does **not** claim production or a live UAT hostname until those steps are completed and recorded.
