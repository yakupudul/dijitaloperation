# Manual Verification Register

Prompt 68 — record **only** checks executed by a human operator on a named environment. Do not mark VERIFIED without evidence.

**Statuses:** NOT_VERIFIED · PASS · FAIL · NOT_IN_SCOPE  
**Default for Prompt 67/68 audit:** NOT_VERIFIED (no live OAuth/SMTP/paid API PASS claimed in Prompt 67)

| Field | Value |
| --- | --- |
| Release Candidate SHA | PLACEHOLDER_RC_SHA |
| Base HEAD (Prompt 67) | `ff7b648179af235a9d63ecae5454171b44dbb4ec` |
| Audit branch | `cursor/production-readiness-audit-ea01` |

---

## How to use

1. Pick environment: `staging` or `production` (hostname + date).
2. Execute checklist item; attach evidence reference (ticket, screenshot path, log excerpt — not committed secrets).
3. Set status to PASS or FAIL only after execution.
4. Link blocker IDs from `PRODUCTION_BLOCKERS.md` when remediation closes a blocker.

---

## Register

| ID | Area | Check | Environment | Status | Evidence / notes | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| MV-GOOGLE-01 | Google OAuth | Cloud app + consent flow completes; tokens stored encrypted | — | NOT_VERIFIED | Prompt 67 did not run live console UAT | B-PROVIDER-01 |
| MV-GOOGLE-02 | Google discovery | Operator-triggered discovery returns real resources | — | NOT_VERIFIED | Code REAL; live API not exercised | B-PROVIDER-01 |
| MV-GOOGLE-03 | Google binding | Human confirm binds resource to numeric Digital Asset | — | NOT_VERIFIED | — | B-PROVIDER-01 |
| MV-GOOGLE-04 | Google collection | GA4/GSC/Google Ads collectors succeed against bound assets | — | NOT_VERIFIED | — | B-PROVIDER-01 |
| MV-META-01 | Meta OAuth | Meta app + consent flow completes | — | NOT_VERIFIED | Prior ledger UAT not re-asserted in Prompt 67 | B-PROVIDER-01 |
| MV-META-02 | Meta discovery | Discovery sync returns ad accounts/pages in scope | — | NOT_VERIFIED | — | B-PROVIDER-01 |
| MV-META-03 | Meta binding | Human confirm binds Meta resource | — | NOT_VERIFIED | — | B-PROVIDER-01 |
| MV-META-04 | Meta collection | Meta Ads collector succeeds; pool facts present | — | NOT_VERIFIED | — | B-PROVIDER-01 |
| MV-WORDPRESS-01 | WordPress connector | Site pairing/probe against real WP site | — | NOT_VERIFIED | PARTIAL capability | — |
| MV-DATAFORSEO-01 | DataForSEO | Paid live endpoint refresh with cost guards | — | NOT_VERIFIED | PARTIAL capability | — |
| MV-AI-01 | AI providers | Live guidance job with env/API key | — | NOT_VERIFIED | Keys env-dependent | — |
| MV-SMTP-01 | Mail / SMTP | Real notification email delivered | — | NOT_VERIFIED | Cloud uses log mailer | B-MAIL-01 |
| MV-SMTP-02 | Report Delivery | Report delivery email reaches inbox | — | NOT_VERIFIED | Conditional on launch scope | B-MAIL-01 |
| MV-SCHED-01 | Scheduler | External cron runs `schedule:run`; heartbeat row updates | — | NOT_VERIFIED | Heartbeat table exists; external cron not proven | B-DEPLOY-01 |
| MV-HORIZON-01 | Horizon | Supervisor processes running; jobs consumed | — | NOT_VERIFIED | Package present; supervisor not verified on target host | B-DEPLOY-01 |
| MV-BACKUP-01 | PostgreSQL backup | Backup job completes on schedule | — | NOT_VERIFIED | Responsibilities documented only | B-BACKUP-01 |
| MV-BACKUP-02 | PostgreSQL restore | Restore drill to clean instance succeeds | — | NOT_VERIFIED | — | B-BACKUP-01 |
| MV-BACKUP-03 | Object storage backup | Raw ingestion / PDF artifact backup | — | NOT_VERIFIED | — | B-BACKUP-01 |
| MV-BACKUP-04 | Object storage restore | Restore drill for sample objects | — | NOT_VERIFIED | — | B-BACKUP-01 |
| MV-PORTFOLIO-01 | Operator UAT | Formal PASS for Customer/Brand/Asset CRUD flows | — | NOT_VERIFIED | Code-tested only in Prompt 67 | — |
| MV-PRODCHECK-01 | Production check | `php artisan moxdop:production-check` on target host | — | NOT_VERIFIED | Run on staging/production mirror before go-live | B-DEPLOY-01 |

---

## Sign-off (after remediation)

| Role | Name | Date | Environment | Notes |
| --- | --- | --- | --- | --- |
| Operator | | | | |
| Engineering | | | | |

**Rule:** Empty sign-off = audit remains **BLOCKED** for production deploy.
