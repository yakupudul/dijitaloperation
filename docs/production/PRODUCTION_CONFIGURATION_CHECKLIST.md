# Production Configuration Checklist

Prompt 68 — target host configuration. `.env.example` is **not** production truth (still shows `mysql` + `APP_DEBUG=true`).

**Release Candidate SHA:** 0936a8dfcc5ac9aacdfe341851bf7c6a42fea6c7  
**Validate with:** `php artisan moxdop:production-check` (read-only; PASS/WARN/FAIL per check; no numeric score)

---

## Core application

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` (FAIL in production-check if true)
- [ ] `APP_KEY` set to durable value (not empty, not placeholder)
- [ ] `APP_URL` matches public HTTPS URL
- [ ] `APP_FORCE_HTTPS=true`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `TRUSTED_PROXIES` set for the TLS-terminating proxy
- [ ] `APP_PREVIOUS_KEYS` set only during documented key rotation window

---

## Database

- [ ] `DB_CONNECTION=pgsql` (preferred for data-pool production contract)
- [ ] PostgreSQL connectivity verified (`DATABASE` check PASS)
- [ ] SQLite **not** used on production data-pool host (FAIL if `APP_ENV=production` + sqlite)
- [ ] Migrations applied: `php artisan migrate --force` **only** — never `migrate:fresh` / `db:wipe` in production
- [ ] ~73 additive Laravel migrations present in `migrations` table

---

## Queue & workers

- [ ] `QUEUE_CONNECTION` is durable (`database` or `redis`) — not `sync` in production (FAIL)
- [ ] Queue workers running (database worker and/or Horizon)
- [ ] When `COLLECTION_QUEUE_CONNECTION=redis`: Redis reachable (`REDIS` check PASS)
- [ ] Horizon package loaded; **supervisor** processes verified on host (`MANUAL_VERIFICATION_REGISTER` MV-HORIZON-01)

---

## Scheduler

- [ ] System cron (or systemd timer) runs `php artisan schedule:run` every minute
- [ ] Includes observability evaluate-alerts and collection/intelligence schedules
- [ ] Dispatcher heartbeat observable when table populated (`SCHEDULER` check)

---

## Cache & session

- [ ] `CACHE_STORE` appropriate for deploy (database or redis)
- [ ] `SESSION_DRIVER` durable (database/redis) for multi-worker installs

---

## Mail (conditional)

- [ ] If Report Delivery or email notifications in launch scope: real SMTP/API mailer configured
- [ ] `MAIL_MAILER` not `log` or `array` on production when Delivery in scope (WARN otherwise)
- [ ] `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` set for agency

---

## Storage

- [ ] Default filesystem private (no public ACL on raw ingestion)
- [ ] `MOXDOP_RAW_INGESTION_*` points to private disk (local path or S3-compatible)
- [ ] Report PDF artifacts path writable / bucket configured
- [ ] `storage/app` writable (`PRIVATE_STORAGE` check)

---

## Integrations (application credentials — not tenant tokens)

- [ ] Google: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, redirect URI, Ads developer token as needed
- [ ] Meta: `META_APP_ID`, `META_APP_SECRET`, redirect URI, login configuration
- [ ] DataForSEO: credentials if Website SEO in scope
- [ ] AI: `OPENAI_API_KEY` / others if agent execution in scope
- [ ] Never commit real secrets; use host secret store

---

## Security & observability

- [ ] `MOXDOP_SECURITY_HARDENING_ENABLED=true`
- [ ] `MOXDOP_OBSERVABILITY_ENABLED=true`
- [ ] `MOXDOP_OPS_EXPECTED_SUPERVISORS` set when Horizon topology known

---

## Bootstrap (one-time per environment)

- [ ] `php artisan migrate --force`
- [ ] Seed roles/modules/playbooks only (`RoleAndPermissionSeeder`, module seeders, playbook seeder) — **no fake Customer** in `DatabaseSeeder`
- [ ] `php artisan dop:create-admin` — interactive; no default password
- [ ] Do **not** rely on `DatabaseSeeder` for Customer/Brand demo data

---

## Post-config verification

- [ ] `php artisan moxdop:production-check` — resolve FAIL; document WARN
- [ ] Run `RELEASE_SMOKE_TESTS.md` on target host
- [ ] Update `MANUAL_VERIFICATION_REGISTER.md` for any manual steps
