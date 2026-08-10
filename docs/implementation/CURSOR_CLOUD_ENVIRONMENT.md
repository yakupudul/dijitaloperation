# Cursor Cloud development environment

> Status: **CONFIGURED** (repository-managed)  
> Path: `/workspace` is the expected Cursor Cloud checkout.

## Purpose

Make MoxDOP reproducibly bootable for Cursor Cloud agents without manual machine setup each time.

This is **development/UAT infrastructure only**. It does not change product domain architecture.

## Root cause (pre-fix)

There was no committed:

- `.cursor/environment.json`
- `.cursor/Dockerfile`

Cloud agents therefore started from Cursor’s default base image **without** a repo install/start contract. Some VMs had PHP by chance / manual repair; that was not reproducible.

## Mechanism

Repository-managed Cursor Cloud environment:

| File | Role |
|------|------|
| `.cursor/environment.json` | install / start / terminals / ports |
| `.cursor/Dockerfile` | PHP 8.3 + extensions, Composer, Node 22 |
| `.cursor/cloud-agent-install.sh` | `composer install`, `npm ci`, `npm run build` |
| `.cursor/cloud-agent-start.sh` | runtime `.env`, SQLite file, `migrate`, safe seed |
| `.env.cursor-cloud.example` | non-secret Cloud defaults (never commit `.env`) |

## Stack

- PHP 8.3+
- Extensions: mbstring, xml, curl, sqlite3, zip, bcmath, intl, mysql (optional)
- Composer 2
- Node 22 / npm
- Development DB: **SQLite** file (`database/database.sqlite`)
  - Matches CI/PHPUnit smoke approach
  - Production target remains MySQL 8 — Cloud agents must not use production DBs

## Boot flow

1. Cursor builds image from `.cursor/Dockerfile` (or uses an active Build snapshot)
2. Checks out the requested revision under `/workspace`
3. Runs `install` → dependencies + frontend build
4. Runs `start` → `.env` + APP_KEY (dev-only, unlogged) + migrate + seed
5. Starts terminal: `php artisan serve --host=0.0.0.0 --port=8000`
6. Operator forwards port **8000** in Cursor Desktop (Ports / plug icon)

## Secrets

- **Never commit** `.env`, `APP_KEY`, DB passwords, or provider tokens
- Cloud `start` generates a **development-only** `APP_KEY` when missing (output discarded)
- Prefer Cursor Dashboard **Secrets** for any real provider credentials needed later
- Provider credentials are **not** required for basic boot

## Admin user

Application boot does not depend on a committed admin.

Create interactively in the Cloud terminal:

```bash
php artisan dop:create-admin
```

## Queue / scheduler

- Queue driver: database (default)
- Basic Filament `/app` boot does **not** require a queue worker or scheduler
- For jobs/UAT that need async work: `php artisan queue:work` in an extra terminal when needed

## Validation checklist

```bash
php artisan about
php artisan migrate:status
php artisan test
vendor/bin/pint --test
npm run build
curl -I http://127.0.0.1:8000/app/login
```

## Operator follow-up

1. Merge this environment PR into `main` when ready
2. Open Cloud Agents → Environments → ensure this repo uses the repository `.cursor/environment.json`
3. Enable/create a Build from the environment [Builds](https://cursor.com/dashboard/cloud-agents) tab so new agents boot from a prebuilt snapshot
4. Start a fresh Cloud agent on `main` and confirm PHP + `/app/login` without manual apt installs
