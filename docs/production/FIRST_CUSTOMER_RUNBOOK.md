# First Customer Runbook

Prompt 68 — onboard the first real MoxDOP customer (internal agency operator). Assumes launch scope includes Google and/or Meta; excludes UNAVAILABLE surfaces unless explicitly accepted.

**Prerequisites:** `GO_LIVE_CHECKLIST.md` gates passed on target host; blockers B-DEPLOY-01 and B-PROVIDER-01 closed for in-scope integrations.

**Release Candidate SHA:** PLACEHOLDER_RC_SHA

---

## Phase 0 — Scope confirmation

- [ ] Confirm customer is **not** Atlas Demo catalog (`ga4-atlas`, `web-atlas`, … are Explicit Demo only)
- [ ] Confirm excluded capabilities documented:
  - Instagram analytics UNAVAILABLE
  - Assistant chat UNAVAILABLE
  - GBP local rank grid UNAVAILABLE
  - Website `/app` production analytics UNAVAILABLE shell
- [ ] Confirm Report Delivery email requirement → if yes, B-MAIL-01 must be closed

---

## Phase 1 — Admin & access

- [ ] Admin exists: `php artisan dop:create-admin` (no default password)
- [ ] Operator user has appropriate Spatie role (Admin / specialist roles per policy)
- [ ] Login `/app` (web guard) succeeds
- [ ] Filament `/system` accessible for technical admin tasks

---

## Phase 2 — Portfolio setup (REAL CRUD)

- [ ] Create **Customer** (real name — not seeded fake)
- [ ] Create **Brand** under Customer
- [ ] Create **Digital Assets** with numeric ids (GA4, GSC, Google Ads, Meta Ads as contracted)
- [ ] Verify assets appear in frozen `/app` sidebar IA
- [ ] Record MV-PORTFOLIO-01 when formal UAT PASS

---

## Phase 3 — Google integration (if in scope)

- [ ] Configure production Google Cloud OAuth app + redirect URI
- [ ] Operator: Integrations → Connect Google → consent
- [ ] MV-GOOGLE-01 PASS
- [ ] Trigger resource discovery
- [ ] MV-GOOGLE-02 PASS
- [ ] Bind discovered resources to correct Digital Assets (human confirm)
- [ ] MV-GOOGLE-03 PASS
- [ ] Trigger or wait for collection scheduler; verify CollectionRun / DatasetRun SUCCESS
- [ ] MV-GOOGLE-04 PASS
- [ ] Open GA4/GSC/Google Ads specialist — KPIs from pool or honest UNAVAILABLE (not Demo)

---

## Phase 4 — Meta integration (if in scope)

- [ ] Configure Meta app + Business Login configuration
- [ ] Operator: Connect Meta → consent
- [ ] MV-META-01 PASS
- [ ] Discovery sync
- [ ] MV-META-02 PASS
- [ ] Bind Meta ad account to Meta Ads Digital Asset
- [ ] MV-META-03 PASS
- [ ] Collection run SUCCESS; Meta specialist shows pool-backed KPIs where gated
- [ ] MV-META-04 PASS
- [ ] Meta Campaigns list uses production read service; detail pages gated for production ids

---

## Phase 5 — Operations truth

- [ ] FindingsIndex shows DB findings via `FindingReadService` (empty if none — not DemoState)
- [ ] Opportunities / Recommendations / Tasks usable on real data
- [ ] Activity center shows async operations when jobs run
- [ ] Dashboard `recentValue` empty (no Atlas narrative injection)
- [ ] Agency awaiting_decision from `RecommendationReadService`

---

## Phase 6 — Value & reporting (if in scope)

- [ ] Business Outcomes definitions/observations for Brand
- [ ] Client Value Story tabs (PARTIAL where documented)
- [ ] Report Snapshot generation
- [ ] PDF artifact stored on private disk
- [ ] Authenticated share link works
- [ ] If email Delivery in scope: MV-SMTP-02 PASS

---

## Phase 7 — Ongoing operations

- [ ] Cron + Horizon verified (MV-SCHED-01, MV-HORIZON-01)
- [ ] `php artisan moxdop:production-check` scheduled or run after config changes
- [ ] Observability alerts evaluated (`moxdop:ops:evaluate-alerts` on schedule)
- [ ] Backup jobs monitored (when B-BACKUP-01 remediated)

---

## Failure handling

| Symptom | Action |
| --- | --- |
| Empty specialist with bound asset | Check collection runs, bindings, credentials — not Demo fallback |
| Hub shows not_connected | Expected when credentials missing — configure, do not expect Demo Connected |
| Queue backlog | Verify workers/Horizon |
| Wrong tenant data | Stop; investigate tenant scope — see `TenantIsolationE2ETest` patterns |
| Post-deploy regression | `ROLLBACK_RUNBOOK.md` |

---

## Sign-off

| Milestone | Status | Date | Operator |
| --- | --- | --- | --- |
| Portfolio live | | | |
| Integrations live | | | |
| Collection producing pool facts | | | |
| First report delivered | | | |

**Note:** Demo catalog portfolio remains available for sales/training under string asset ids — do not use for this customer's production truth.
