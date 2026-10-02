# Production Blockers

Prompt 68 historical audit remains below. **Phase F (issue #201) does not close these host/provider gates from Cursor Cloud.** They stay explicit external release gates: target-host backup restore drill, production host `moxdop:production-check`, live Google/Meta/Website/DataForSEO UAT, live SMTP delivery.

**Prompt 68 audit STATUS:** BLOCKED (historical; SHA `0936a8dfcc5ac9aacdfe341851bf7c6a42fea6c7`)  
**Current cumulative release stack:** PR #202 → #199 → #200 → #203 → #204 → #206 → #207 → #208 → Phase F child. Do not treat the Prompt 68 SHA as the current RC.

Do not merge or deploy automatically until repository Reviewer APPROVED policy is satisfied and remaining external gates are accepted by the operator.

---

## Blocker register

| ID | Category | Title | Status | Blocks |
| --- | --- | --- | --- | --- |
| B-BACKUP-01 | BACKUP_RESTORE | PostgreSQL + object storage backup/restore not verified for target production | OPEN | Disaster recovery; cannot claim data durability or restore confidence |
| B-DEPLOY-01 | CONFIGURATION | Target production env (PG, Redis/Horizon supervisor, cron, APP_DEBUG=false, APP_KEY) not validated against a real production host by this audit | OPEN | Go-live on unvalidated infrastructure |
| B-PROVIDER-01 | MANUAL_VERIFICATION | Live Google/Meta OAuth+collection NOT_VERIFIED while in launch scope for real customer assets | OPEN | First customer integration and collection truth |
| B-MAIL-01 | REPORTING | Real SMTP not verified; log mailer cannot claim real Delivery | CONDITIONAL | Blocks only when Report **Delivery** (email) is in launch scope |

---

## B-BACKUP-01 — BACKUP_RESTORE

**Statement:** PostgreSQL database backup and object storage backup/restore procedures are **DOCUMENTED responsibilities** only. They are **NOT_VERIFIED** in this audit environment.

**Evidence gap:** No executed restore drill on target production PostgreSQL or raw-ingestion / PDF object storage.

**Remediation:**

- [ ] Define backup jobs for PostgreSQL (full + WAL/point-in-time per ops policy).
- [ ] Define backup jobs for private object storage (raw ingestion, report artifacts).
- [ ] Execute restore drill on staging mirroring production topology.
- [ ] Record PASS/FAIL, timestamps, and operator in `MANUAL_VERIFICATION_REGISTER.md`.

**RPO/RTO:** NOT_DEFINED — do not invent numeric targets in docs or runbooks until ops defines them.

---

## B-DEPLOY-01 — CONFIGURATION

**Statement:** Target production host configuration has **not** been validated by Prompt 68 against real deploy topology.

**Cloud/dev observed (this audit):**

| Setting | Observed | Production expected |
| --- | --- | --- |
| APP_ENV | local | production |
| APP_DEBUG | true | false |
| DB | sqlite | PostgreSQL preferred for data-pool |
| QUEUE | database | durable (not sync); Redis when Horizon/collection redis queue |
| MAIL | log | real SMTP when Delivery in scope |
| Cache | database | per deploy (Redis when configured) |

**Note:** `.env.example` still shows `mysql` + `APP_DEBUG=true` — production must override; example is not production truth.

**Remediation:**

- [ ] Provision target host with PostgreSQL, private storage, durable queue.
- [ ] Configure Redis + Horizon supervisor when `COLLECTION_QUEUE_CONNECTION=redis`.
- [ ] Register cron for `php artisan schedule:run` (includes observability evaluate-alerts).
- [ ] Set durable `APP_KEY`; set `APP_DEBUG=false`.
- [ ] Run `php artisan moxdop:production-check` on target — resolve all FAIL; review WARN.
- [ ] Record results in `MANUAL_VERIFICATION_REGISTER.md`.

---

## B-PROVIDER-01 — MANUAL_VERIFICATION

**Statement:** Live Google and Meta OAuth, resource discovery, binding, and collection against real customer assets are **NOT_VERIFIED** in Prompt 67/68 audit environments.

**In launch scope when:** First customer uses Google/Meta integrations and expects pool-backed specialist KPIs.

**Remediation:**

- [ ] Complete Google OAuth consent on staging/production mirror with real Cloud app credentials.
- [ ] Complete Meta OAuth on staging/production mirror with real Meta app credentials.
- [ ] Bind real external resources to production numeric Digital Assets.
- [ ] Run collection jobs; confirm DatasetRun success and pool facts materialized.
- [ ] Record PASS/FAIL per step in `MANUAL_VERIFICATION_REGISTER.md`.

**Also NOT_VERIFIED (non-blocking unless in scope):** WordPress pairing, DataForSEO paid live calls, AI provider live calls.

---

## B-MAIL-01 — REPORTING (conditional)

**Statement:** Real SMTP transport is **NOT_VERIFIED**. Current cloud/dev uses `MAIL_MAILER=log`, which cannot prove email Delivery.

**Conditional blocker rule:**

- If launch scope includes Report **Delivery** (email send) or operational email notifications → **OPEN blocker**; treat same as B-MAIL-01.
- If launch scope excludes email Delivery (PDF/share via authenticated routes only) → note as **WARN** in production-check; downgrade to tracked gap, not go-live blocker.

**Remediation (when Delivery in scope):**

- [ ] Configure production SMTP (or provider API) with secrets outside repo.
- [ ] Send test notification and test report delivery to controlled inbox.
- [ ] Confirm no mail logged-only path on production host.
- [ ] Record PASS in `MANUAL_VERIFICATION_REGISTER.md`.

---

## Non-blocking gaps (not blockers)

These are documented product/runtime gaps; they do **not** block Prompt 68 audit completion for a scoped launch that excludes them:

| Gap | Status |
| --- | --- |
| Instagram analytics | UNAVAILABLE |
| Interactive Assistant chat | UNAVAILABLE |
| GBP local rank grid | UNAVAILABLE |
| Website operator analytics shell | UNAVAILABLE |
| DataForSEO paid live | NOT_VERIFIED |
| Atlas Explicit Demo catalog | Retained for catalog string IDs only (`ga4-atlas`, …) |

---

## Exit criteria (unblock)

All **OPEN** blockers in the register above must be:

1. Remediated on staging or target production mirror.
2. Recorded in `MANUAL_VERIFICATION_REGISTER.md` with evidence (no invented VERIFIED).
3. Re-validated via `php artisan moxdop:production-check` and `RELEASE_SMOKE_TESTS.md` on target env.

Then re-run Prompt 68 audit and update Final Decision — still **do not merge/deploy automatically**.
