# Go-Live Checklist

Prompt 68 — pre-deploy and launch-day gates. **Audit STATUS: BLOCKED** until blockers remediated.

**Release Candidate SHA:** PLACEHOLDER_RC_SHA  
**Do not merge/deploy automatically** after checklist completion — human release decision required.

---

## Pre-flight (engineering)

- [ ] Confirm RC SHA matches deployed artifact (`PLACEHOLDER_RC_SHA` → replaced at release)
- [ ] All `PRODUCTION_BLOCKERS.md` items OPEN → remediated or explicitly NOT_IN_SCOPE with sign-off
- [ ] `MANUAL_VERIFICATION_REGISTER.md` updated for target environment (no invented VERIFIED)
- [ ] PHPUnit production readiness suite green on CI for RC SHA:
  - `tests/Feature/ProductionReadiness/GoldenPathE2ETest.php`
  - `tests/Feature/ProductionReadiness/NegativePathE2ETest.php`
  - `tests/Feature/ProductionReadiness/TenantIsolationE2ETest.php`
  - `tests/Feature/ProductionReadiness/ReportPathE2ETest.php`
  - `tests/Feature/ProductionReadiness/DemoFreeRegressionTest.php`
  - `tests/Feature/ProductionReadiness/ProductionCheckCommandTest.php`
- [ ] Prompt 67 reality unchanged: production Demo fallback **0**; unsupported REAL **0**

---

## Target host configuration

- [ ] Complete `PRODUCTION_CONFIGURATION_CHECKLIST.md`
- [ ] `php artisan moxdop:production-check` — no FAIL on target host
- [ ] Backup/restore responsibilities defined; restore drill recorded if B-BACKUP-01 closed

---

## Data migration

- [ ] `php artisan migrate --force` executed on target (only)
- [ ] Migration lock-risk indexes on large tables reviewed; PostgreSQL concurrent index rollout planned if needed
- [ ] Roles/modules/playbooks seeded; admin created via `dop:create-admin`
- [ ] No `migrate:fresh`, `db:wipe`, or destructive refresh on production

---

## Integrations (if first customer in scope)

- [ ] Google/Meta OAuth apps configured for production redirect URLs
- [ ] MV-GOOGLE-* / MV-META-* rows PASS in manual register
- [ ] Real customer Brand/Digital Assets created (not Atlas catalog ids)
- [ ] Bindings confirmed; initial collection runs SUCCESS

---

## Launch scope honesty

Confirm launch scope excludes or accepts documented gaps:

- [ ] Instagram analytics UNAVAILABLE — excluded or accepted
- [ ] Assistant chat UNAVAILABLE — excluded or accepted
- [ ] GBP local rank grid UNAVAILABLE — excluded or accepted
- [ ] Website `/app` analytics UNAVAILABLE shell — excluded or accepted
- [ ] Atlas Explicit Demo catalog retained for string ids only — not used for real customer

---

## Launch day

- [ ] Deploy RC to target host
- [ ] Run `RELEASE_SMOKE_TESTS.md`
- [ ] Monitor Horizon/queue depth and collection runs
- [ ] Monitor ops health snapshot / alerts (no invented health score)
- [ ] On failure: execute `ROLLBACK_RUNBOOK.md`

---

## Post go-live (first 24h)

- [ ] Scheduler heartbeats present
- [ ] No Demo fallback observed on numeric asset ids
- [ ] Tenant isolation spot-check (Customer A ≠ Customer B)
- [ ] Incident channel and on-call assigned

---

## Final gate

| Gate | Status |
| --- | --- |
| Prompt 68 audit Final Decision | BLOCKED (until re-audit after remediation) |
| Authorized production deploy | Human sign-off required |

**Recommended next action:** Complete `PRODUCTION_BLOCKERS` remediation + `MANUAL_VERIFICATION_REGISTER` on staging, re-run `moxdop:production-check` and release smoke on target env; do not merge/deploy automatically.
