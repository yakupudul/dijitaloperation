# MOXDOP — FINAL AUTONOMOUS RELEASE SMOKE

STATUS: AUDIT_COMPLETE

> **Historical record (ADR-044):** This smoke report is not an operational runbook. Canonical operator routes are now site root (`/login`, …); Filament is `/admin`. Observed `/app` and `/system` URLs below are from the audit date and are retired (HTTP 410).

RELEASE DECISION: **STAGING_READY**

RELEASE DECISION: **STAGING_READY**

Generated: 2026-08-18

This is an **audit-only** report. Product features were not implemented. MEDIUM/LOW backlog was not fixed.

Feature freeze remains in force. Only BLOCKER/HIGH may stop staging. None were found.

---

## CANONICAL

| Check | Required | Observed |
| --- | --- | --- |
| workspace | `/workspace` | `/workspace` |
| git toplevel | `/workspace` | `/workspace` |
| branch | `cursor/production-readiness-audit-ea01` | `cursor/production-readiness-audit-ea01` |
| starting HEAD | `c6c9b91ef020af3863640ab543494810ddc743da` | match |
| origin | `yakupudul/dijitaloperation` | `https://github.com/yakupudul/dijitaloperation` |
| working tree at start | clean | clean |
| `main` | not touched | not touched |

Bookkeeping after this smoke (report + bounded Playwright spec + isolated DB bootstrap helper):

- final SHA: recorded at commit time on this branch
- pushed: `cursor/production-readiness-audit-ea01` only
- PR #197: remains **Draft**

Protected databases were not wiped or migrated:

| Path | Size | mtime (epoch) |
| --- | --- | --- |
| `/tmp/moxdop-final-manual-qa.sqlite` | 3366912 | 1787056374 |
| `/tmp/moxdop-e2e-qa-002.sqlite` | 3588096 | 1787056129 |

Isolated smoke database: `/tmp/moxdop-final-release-smoke.sqlite`

Bootstrap: `tests/e2e/scripts/bootstrap-final-release-smoke-db.sh` (refuses the two protected paths).

PHPUnit always used:

```bash
env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact
```

Playwright used:

- `MOXDOP_E2E_DATABASE=/tmp/moxdop-final-release-smoke.sqlite`
- `MOXDOP_E2E_PORT=8016`
- `MOXDOP_E2E_BASE_URL=http://127.0.0.1:8016`
- `MOXDOP_PROSPECT_RESEARCH_FIXTURES=true`
- `MOXDOP_INTENT_SEARCH_FIXTURES=true`
- `MOXDOP_SALES_INTENT_PAID_CALLS=false`

No real OAuth. No paid DataForSEO. No SMTP send. No WhatsApp send.

---

## RELEASE DECISION

**STAGING_READY**

| Gate | Count |
| --- | --- |
| Application BLOCKER | 0 |
| Application HIGH | 0 |
| Sales Assistant BLOCKER | 0 |
| Sales Assistant HIGH | 0 |
| Security HIGH/BLOCKER | 0 |
| Application MEDIUM (known backlog) | 3 |
| Application LOW (known backlog) | 2 |
| Sales Assistant MEDIUM | 0 |
| Sales Assistant LOW | 0 |

MoxDOP V1 and Sales Assistant V1 have passed the cumulative autonomous application release gate. Product features are frozen. Proceed to staging infrastructure, deployment validation, real provider smoke tests, and the one-customer pilot.

---

## APPLICATION GATE

### BLOCKER

count: 0

(none)

### HIGH

count: 0

(none)

### MEDIUM

count: 3 — carried from prior QA; not release-stopping; not fixed in this audit

- QA-E2E-002 — Activity TR chrome leaks "Open" / "Status"
- QA-E2E-004 — Brand Public Discovery truthful empty copy polish
- QA-E2E-LAST-ADMIN-SILENT — last-admin protection holds; flash/copy is unpolished

### LOW

count: 2 — carried from prior QA

- QA-E2E-005 — unlabeled controls on Settings / Customer detail
- QA-E2E-TR-POLISH-GROUPED — Findings / Recommendations grouped TR chrome

No new MEDIUM/LOW findings were opened by this smoke.

---

## SALES ASSISTANT GATE

### BLOCKER

count: 0

### HIGH

count: 0

### MEDIUM

count: 0

### LOW

count: 0

---

## CORE

All core surfaces were exercised on the isolated smoke database via the frozen Playwright suite (`01`–`10`) plus this smoke’s guest/responsive crawl (`13`). Unique operator names used `Final Smoke * <timestamp>` on the Sales path; portfolio CRUD used the existing golden-path unique `E2E Acceptance *` names on the same isolated DB (not old QA records).

| Surface | Result |
| --- | --- |
| Auth | PASS — `/app/login`, login, logout, login again, guest protected-route redirect, no auth loop. Legacy `/system/login` → `/app/login`, `/system` → `/app`. `/admin` remains a separate technical surface. |
| Customer | PASS — create, edit, reload. Conversion also created `Final Smoke Customer <stamp>` with persisted IDs. |
| Brand | PASS — create, edit, reload. Conversion created matching `Final Smoke Brand <stamp>`. |
| Digital Assets | PASS — six types registered (Website, GBP, Google Ads, Meta Ads, GA4, GSC). Smoke DB counts after the green run: website 4, gbp 4, google_ads 4, meta_ads 4, ga4 4, gsc 4. Open from global Digital Assets and Brand Digital Estate: no 404/500. Unconfigured state is truthful. No Demo fallback. |
| Specialists | PASS — Website / GBP / Google Ads / Meta Ads / GA4 / GSC tabs render without provider credentials; unavailable/unconfigured copy; no fake metrics. |
| Files | PASS — isolated upload, list, authorized download/open; guest denial. No public leakage in this smoke. |
| Work | PASS — Dashboard Capture → Customer (optional Brand) → Task → Work list → typed Task URL → status transition → reload. Customer-scoped Work holds. No "Work item not found". |
| Activity | PASS for data truth (recent actions where architecture records them; no fixture activity). Known TR polish remains MEDIUM backlog (QA-E2E-002). Not a release stop. |
| Settings | PASS — General, Team & Access, Notifications, Operations, AI & Intelligence, Advanced. One General write/restore persisted. |
| Team | PASS — isolated member listing/role/deactivate. Last-admin protection holds (unpolished flash is known MEDIUM). |
| Integrations | PASS — Google, Meta, DataForSEO, OpenAI, Anthropic, Gemini configuration UI without credentials. Write-only secrets. Truthful status. No secret exposure. No fake resources. |

---

## SALES

Fresh names on the last green spec-13 run (timestamp `1787057968253`):

- Prospect: `Final Smoke Prospect 1787057968253` (source WhatsApp)
- Customer after convert: `Final Smoke Customer 1787057968253`
- Brand after convert: `Final Smoke Brand 1787057968253`
- Search Profile: `Final Smoke Website Intent 1787057968253`

| Surface | Result |
| --- | --- |
| Prospect | PASS — persists; creating a Prospect does **not** create Customer / Brand / DigitalAsset. Inquiry: "Web sitesi ve Google reklamları konusunda destek arıyoruz." |
| Research | PASS — Research Prospect with deterministic fixtures. Research state Completed/Partial. Observed evidence (public page). No fake production fallback. |
| Evidence | PASS — observed evidence + provenance visible; not substituted as metrics. |
| Sales Intelligence | PASS — structured result with recommended services mapping to canonical ServiceDefinition (Website Design, Google Ads Management). No email-sequence / outreach automation. No autonomous status change. No autonomous Customer creation. |
| Internal report | PASS — Internal Pre-Analysis generated. Snapshot immutable architecture reused. |
| Client report | PASS — Client Pre-Analysis generated. Client projection does **not** contain `INTERNAL_SMOKE_NOTES_DO_NOT_SHARE`. |
| Share | PASS — valid token shows client-safe snapshot only. Invalid token denied/not found. Guest share cannot open internal Prospect workspace. |
| Conversion | PASS — explicit convert creates Customer + Brand; Prospect remains; `converted_customer_id`, `converted_brand_id`, `converted_at` stored; CTA becomes Open Customer / Open Brand. Second conversion does not duplicate. |
| Duplicate safety | PASS — Batch B spec plus conversion confirmation: warning / reuse path; no silent duplicate; no fuzzy-name merge. |
| Search Profile | PASS — `Final Smoke Website Intent <stamp>` persists with include/exclude concepts. Creating a profile does **not** emit IntentSignals. |
| Intent Radar | PASS — fixtures only. Provider reality `PARTIAL (test fixtures)`. A ("Web sitesi yaptırmak için bir ajans arıyoruz.") high purchase intent. B ("Web sitesi nasıl yapılır?") informational / lower. Signal rows include source URL, provider, snippet, verification/confidence/provenance fields. |
| Signal → Prospect | PASS — explicit Create Prospect from high-intent signal; observed/known fields only; source `intent_radar`; no automatic outreach. Research Prospect reuses Batch A pipeline. |
| Paid-call safety | PASS — `config('moxdop.sales_intent_discovery.paid_calls_enabled')` is **false**. Page load, Prospect create, Search Profile create, and scheduler do not trigger DataForSEO paid intent discovery. Scheduler has no Intent Radar job. No paid external call in this smoke. |

---

## SECURITY

| Boundary | Result |
| --- | --- |
| Guest | PASS — `/app/prospects` and `/app/prospects/1/convert` redirect to `/app/login`. |
| Internal / client report | PASS — allowlisted projections; internal notes absent from client UI and guest share. |
| File boundary | PASS — authorized download; guest denied. |
| Share boundary | PASS — client-safe content only; invalid token denied; Convert CTA absent on share. |
| Conversion authorization | PASS — guest cannot convert or edit Prospects. |
| Paid calls | PASS — default OFF; fixtures path used; no real DataForSEO. |
| Secrets | PASS — integration workspaces write-only; no secret echo in this smoke. |
| SSRF | PASS at unit/feature level (`ProspectWebsiteValidator`, `PublicUrlSafety`); no new HIGH/BLOCKER. Real-site SSRF remains a staging/provider concern, not an application-gate failure. |

---

## DATA TRUTH

| Claim | Result |
| --- | --- |
| Atlas | not used as production fallback |
| DemoCatalog | not used as production fallback |
| Production fixtures | smoke used **explicit** non-production research/intent fixtures only |
| Fake metrics | not observed on specialist or sales surfaces |
| Provenance | observed vs inference vs recommendation remain distinguishable |
| Wrong-scope leakage | not observed (Customer/Brand/Prospect IDs matched conversion) |
| missing→zero | not observed |

---

## I18N

Representative TR and EN chrome was smoked on Dashboard, Customers, Digital Assets, Work, Prospects, Prospect detail, Intent Radar, Search Profiles, Settings.

- TR: PASS for operability (known Activity / grouped chrome polish remains MEDIUM/LOW backlog)
- EN: PASS
- Blocking language issues: **none**

Dynamic operator/external data is not translated and is not classified as a defect.

---

## RESPONSIVE

Document-level overflow checks at 1440 / 768 / 390 on:

Dashboard, Customers, Brands, Digital Assets, Work, Prospects, Intent Radar, Search Profiles, Settings.

| Viewport | Result |
| --- | --- |
| desktop 1440 | PASS — no document-level horizontal overflow |
| tablet 768 | PASS |
| mobile 390 | PASS |
| blocking overflow | none |

Contained table scrolling remains acceptable. Primary CTAs on these pages remained reachable in the crawl.

---

## SAFE ROUTE / ACTION CRAWL

Bounded crawler (`06-action-crawler.spec.js`) plus sidebar smoke (`01-smoke-nav.spec.js`) plus spec-13 guest/responsive routes:

- no 404/500/419 on frozen production operator routes in this run
- no broken Livewire on the smoked paths
- no dead core CTA on the golden path
- no destructive deletes, OAuth, paid APIs, mail, or WhatsApp

---

## PLAYWRIGHT

Command: `npm run qa:e2e` against the isolated smoke DB on `127.0.0.1:8016`.

| Metric | Value |
| --- | --- |
| tests | 43 (40 frozen regression + 3 final-release smoke) |
| passed | 43 |
| failed | 0 |
| skipped | 0 |
| duration | 3.8m |

New bounded spec: `tests/e2e/13-final-release-smoke.spec.js` (deterministic fixtures only).

The generator also rewrote `docs/qa/AUTONOMOUS_E2E_QA_REPORT.md` and `docs/qa/AUTONOMOUS_E2E_QA_002_REPORT.md`; those files were restored from git so this audit keeps a single canonical document: this file.

---

## PHPUNIT

Command:

```bash
env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact
```

| Metric | Value |
| --- | --- |
| passed | 1837 |
| failed | 0 |
| skipped | 2 |
| assertions | 12273 |
| duration_ms | 72088 |

---

## BUILD

| Check | Result |
| --- | --- |
| `npm run build` | PASS |
| `git diff --check` | PASS |

---

## DEFERRED / BACKLOG (non-blocking)

Do not reopen product development for these before staging:

- Brand Public Discovery live `/app` execution (truthful empty retained)
- Recurring Intent Radar / scheduler attachment
- Additional intent providers/adapters
- Native LinkedIn / Armut / Ekşi adapters
- Mobile push
- Full SMTP UI
- Instagram deep integration
- Assistant expansion
- Theme engine
- Provider-health dashboard
- Sector learning
- Proposal automation
- Activity TR polish (QA-E2E-002)
- Last-admin rejection flash polish (QA-E2E-LAST-ADMIN-SILENT)
- Unlabeled control accessibility polish (QA-E2E-005)
- Grouped TR chrome polish (QA-E2E-TR-POLISH-GROUPED)

---

## NEXT (staging workstream — not product features)

1. Staging deployment architecture
2. PostgreSQL
3. Redis
4. Horizon
5. Scheduler
6. Secure environment configuration
7. Backup/restore verification
8. SMTP delivery smoke
9. Real Google OAuth / GA4 / GSC / Google Ads smoke
10. Real Meta OAuth/data smoke
11. One real Customer pilot

Do **not** start another product feature batch.
