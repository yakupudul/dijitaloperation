# Rollback Runbook

Prompt 68 — deploy rollback procedures. Backup/restore is **NOT_VERIFIED** in this audit; database rollback assumes ops-defined backups exist.

**RPO/RTO:** NOT_DEFINED  
**Release Candidate SHA:** PLACEHOLDER_RC_SHA

---

## When to rollback

Trigger rollback if any of:

- [ ] Application error rate breaks operator workflows after deploy
- [ ] `moxdop:production-check` FAIL on production post-deploy
- [ ] Collection or queue workers fail persistently after deploy
- [ ] Tenant isolation or auth regression detected
- [ ] Demo fallback observed on production numeric asset ids (regression vs Prompt 67)

---

## Decision

| Step | Action | Owner |
| --- | --- | --- |
| 1 | Declare incident; freeze further deploys | On-call / release captain |
| 2 | Capture RC SHA, previous SHA, symptom, time | Engineering |
| 3 | Choose rollback path (A or B below) | Engineering + ops |

---

## Path A — Application rollback (preferred when DB compatible)

Use when new migrations are backward-compatible with previous app version **or** no migrations ran yet.

- [ ] Put app in maintenance mode if needed: `php artisan down --secret=<token>`
- [ ] Redeploy **previous known-good artifact** (git SHA tag recorded before deploy)
- [ ] Restart PHP-FPM / Octane / container workload
- [ ] Restart Horizon / queue workers
- [ ] Run smoke tests from `RELEASE_SMOKE_TESTS.md` (subset: auth, portfolio, production-check)
- [ ] `php artisan up`
- [ ] Document incident; root-cause before re-attempting RC

**Do not run** `migrate:rollback` across many steps in production without ops review and backup.

---

## Path B — Database restore (when migrations broke compatibility)

Use only when Path A insufficient and **verified backup** exists (B-BACKUP-01 remediation).

- [ ] Stop workers and scheduler traffic to app (maintenance mode)
- [ ] Restore PostgreSQL from last verified backup (ops runbook — NOT_VERIFIED in Prompt 68)
- [ ] Restore object storage artifacts if deploy corrupted raw/PDF paths
- [ ] Redeploy previous application SHA
- [ ] `php artisan migrate --force` only if restored DB schema behind app (ops decision)
- [ ] Re-seed **only** if restore policy allows (roles/modules/playbooks — never fake Customer seed)
- [ ] Re-create admin if user table restored without expected admin (`dop:create-admin`)
- [ ] Verify MV-BACKUP-* evidence still valid post-incident

---

## Path C — Forward fix (hotfix)

Use when rollback risk exceeds fix risk (small targeted patch).

- [ ] Branch hotfix from previous good SHA or RC
- [ ] Minimal fix + targeted PHPUnit
- [ ] Deploy hotfix SHA; re-run smoke tests
- [ ] Update blocker/register docs if configuration changed

---

## Post-rollback verification

- [ ] `php artisan moxdop:production-check` — no FAIL
- [ ] Login `/app` and `/system` (Filament panel id `app`, path `/system`)
- [ ] Numeric Digital Asset opens specialist without Demo catalog mode
- [ ] Queue processing resumes
- [ ] No secrets or tokens logged

---

## Communication template

```
Rollback executed.
Previous SHA: <sha>
Failed RC: PLACEHOLDER_RC_SHA
Reason: <symptom>
Path: A | B | C
Customer impact: <scope>
Next: root cause + re-audit before redeploy
```

---

## Prohibited in production rollback panic

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- Seeding fake Customer via `DatabaseSeeder`
- Inventing VERIFIED in manual register without evidence
