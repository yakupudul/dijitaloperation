# MoxDOP staging architecture

Status: repository-side contract for a **single-VPS staging** host.

Product features are frozen. This document describes deployment infrastructure only.

## Decision: traditional VPS (not Docker Compose)

**Selected architecture:** Nginx → PHP-FPM 8.3 → Laravel `/public`, with PostgreSQL 16, Redis, Supervisor-managed Horizon, and cron `schedule:run`.

**Why**

- The only existing deploy templates are VPS-shaped (`deploy/uat/*`, `docs/operations/PERSISTENT_UAT.md`).
- There is **no** application `Dockerfile` or `docker-compose` for runtime. `.cursor/Dockerfile` is Cursor Cloud agent tooling (PHP CLI, SQLite, `artisan serve`) and is **not** the staging architecture.
- Collection and Horizon already assume a long-lived Redis worker process, not `artisan serve` / `queue:listen`.

**Docker:** not used for staging. Do not adopt Compose merely because Cursor Cloud has a Dockerfile.

**Not in V1 staging:** Kubernetes, Swarm, extra app servers, Kafka, Elasticsearch, BigQuery, service mesh, Reverb (optional / unused).

This is **not** zero-downtime. A short `php artisan down` maintenance window is the honest model.

## Process model

```
Internet
  → Nginx (TLS)
    → PHP-FPM / Laravel
      → PostgreSQL 16          (source of truth)
      → Redis                  (cache, queue, Horizon meta, locks — not business truth)
      → Horizon                (queues: default + collection)
      → cron schedule:run      (every minute)
      → storage/               (private + public disks)
```

UAT historically used MySQL + `queue:work database`. Staging for the collection vision uses **PostgreSQL + Redis + Horizon**. Do not copy the UAT MySQL worker as the staging queue topology.

## PHP / Node

- PHP **8.3** with `ctype, filter, hash, mbstring, openssl, session, tokenizer, pdo, pdo_pgsql, pgsql, json, pcntl, posix, curl, zip, bcmath, intl, gd`
- Composer 2
- Node 22 for `npm ci && npm run build` (or ship `public/build` from a trusted build)
- Laravel Framework 13.x (`laravel/framework` ^13.8)
- Horizon 5.x, Predis (no `ext-redis` required)

## Queues

Two names only (see `docs/architecture/QUEUE_CAPACITY_CONTRACT.md`):

| Supervisor | Queue | Staging processes | Timeout | Tries |
| --- | --- | --- | --- | --- |
| `supervisor-1` | `default` | 2 | 300s | 1 |
| `supervisor-collection` | `collection` | 1 | 300s | 3 |

Staging `QUEUE_CONNECTION=redis` so Activity Center jobs and collection jobs are both consumed by Horizon. Do not also run `queue:work database` on the same app — that is a duplicate worker topology.

Horizon `environments` keys: `production`, `local`, **`staging`**, **`uat`**. `APP_ENV=staging` is required so supervisors actually start.

Dashboard: `/horizon`, middleware `web` + `auth`, gate `viewHorizon` = Admin role. Guests are redirected to `/app/login`.

## Scheduler

Host cron (only one):

```
* * * * * www-data cd /var/www/moxdop-staging && php artisan schedule:run
```

Commands (all `withoutOverlapping`):

- `async:mark-stale-runs`
- `reports:dispatch-due-deliveries`
- `moxdop:dispatch-due-automations` — central collection + other recurring domains
- `moxdop:ops:evaluate-alerts`
- `horizon:snapshot`

**No Sales Intent / DataForSEO paid search is scheduled.** Paid intent is `MOXDOP_SALES_INTENT_PAID_CALLS` default `false`. Page load and Prospect create do not trigger paid search.

## Central collection invariant

`CollectionSchedule` is bound to **`digital_asset_id`** (plus customer/brand lineage). The dispatcher discovers every **Active** schedule independently.

A Brand may own:

- multiple Google Ads Digital Assets
- multiple Meta Ads Digital Assets
- multiple GBP locations as separate Digital Assets
- multiple GA4 / GSC assets where valid

There is **no** one-account-per-Brand restriction in the scheduler.

Path:

1. Provider integration + resource discovery
2. Digital Asset binding (`core_asset_bindings`, active-only uniqueness)
3. Eligible/active `CollectionSchedule` rows
4. cron → `moxdop:dispatch-due-automations`
5. `CollectionScheduleAdapter` → `ExecuteCollectionLifecycleService`
6. `ExecuteDatasetRunJob` on Redis queue `collection`
7. Dataset executors persist warehouse/Evidence/freshness

The adapter **never** calls provider collectors directly.

## Manual refresh invariant

Operator **Collect live data** / bound collect (`AsyncOperationService::queueBoundCollect` → `CollectLiveBoundDataJob`) is an on-demand path for **one Digital Asset**.

Integration-level Collect uses the same `StartCollectionService` / collection Redis queue as the scheduler.

Do not add a second data pipeline. Manual refresh is asset-scoped; it does not collect every asset on the Brand.

## Storage

| Disk | Path | Public? |
| --- | --- | --- |
| `local` (default) | `storage/app/private` | No — report PDFs, prospect PDFs, private operator files |
| `raw_ingestion` | `storage/app/raw_ingestion` | No |
| `public` | `storage/app/public` | Yes via `php artisan storage:link` → `public/storage` (avatars / branding only) |

Never symlink `storage/app/private` into `public/`.

Permissions: deploy user + `www-data` group, directories `2775`, files `664`. **Do not use 777.** `storage/` and `bootstrap/cache/` must be writable.

## Logging

Staging: `LOG_STACK=daily`, 14 days. Do not log provider payloads, OAuth tokens, API keys, or private file contents.

## Reverb / broadcasting

Optional. Default `BROADCAST_CONNECTION=log`. `laravel/reverb` is not installed. Collection UI polls. Staging must boot without Reverb.

## Seeders / admin

`DatabaseSeeder` seeds roles, module registry, curated playbooks. It does **not** seed DemoCatalog, Atlas, fake customers, or fake metrics.

First admin: `php artisan dop:create-admin` (interactive; no default password; never commit credentials).

## Resource baseline (staging, conservative)

Not a production capacity claim:

- 2 vCPU, 4–8 GiB RAM, 40+ GiB disk, optional 1–2 GiB swap
- PostgreSQL 16 on the same VPS is acceptable for staging
- Redis 7+ with a password, bound to localhost
- PHP-FPM: 4–8 children
- Horizon: 3 worker processes total (2 + 1) plus 1 Horizon master

Keep HTTP workers + queue workers + scheduler well under PostgreSQL `max_connections`.

## Crash recovery

Supervisor/systemd `autorestart=true` for Horizon and PHP-FPM. Cron is persistent. After reboot, operators must **not** need to SSH daily to start workers.

`php artisan horizon:terminate` is the deploy restart. Do not `kill -9` workers.

## OAuth callback inventory (document only — no live OAuth in this phase)

| Provider | Route name | URI | Staging absolute URL |
| --- | --- | --- | --- |
| Google | `integrations.google.callback` | `GET /integrations/google/callback` | `{APP_URL}/integrations/google/callback` |
| Meta | `integrations.meta.callback` | `GET /integrations/meta/callback` | `{APP_URL}/integrations/meta/callback` |

Both require an authenticated Admin session after the IdP redirect. `APP_URL` must be stable HTTPS.

## Related files

- `.env.staging.example`
- `deploy/staging/deploy.sh`
- `deploy/staging/nginx.conf.example`
- `deploy/staging/supervisor-horizon.conf.example`
- `deploy/staging/cron.example`
- `deploy/staging/smoke.sh`
- `docs/deployment/STAGING_RUNBOOK.md`
- `docs/deployment/BACKUP_RESTORE.md`
- `docs/deployment/REAL_PROVIDER_ACCEPTANCE_PLAN.md`
