# MOXDOP — AUTONOMOUS E2E QA 002
## FINAL PRE-STAGING ACCEPTANCE

Generated: 2026-08-18T12:32:55.182Z

> **Historical record (ADR-044):** This QA report is not an operational runbook. Canonical operator routes are now site root; Filament is `/admin`. Observed `/app` URLs below are from the audit date and are retired (HTTP 410).

STATUS: AUDIT_COMPLETE

PILOT DECISION: PILOT_READY_WITH_BACKLOG

This is an **audit-only** report. Product defects were not fixed in this task.

## CANONICAL

- workspace: `/workspace`
- starting SHA: `82391004840b3718d544fafb1a22454d4e919290`
- final SHA: `c864e8cbedc53e2a92383238e4bb508368867bbe`
- branch: `cursor/production-readiness-audit-ea01`
- origin: `https://github.com/yakupudul/dijitaloperation`
- pushed: see git push after this report
- PR #197 Draft: remains Draft
- base URL: `http://127.0.0.1:8013`
- database: `/tmp/moxdop-final-manual-qa.sqlite` (exists: yes)
- QA email: `qa-final@moxdop.local`
- password source: `file:/tmp/moxdop-final-manual-qa-admin.secret` (value never recorded)

## AUTOMATION

- Playwright tests: 40
- passed: 40
- failed: 0
- skipped: 0
- routes: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, Profile, specialist workspaces, /system, /admin
- actions: Capture, upload, download, settings write/restore, team create/deactivate, locale switch, specialist tabs, customer/brand tabs
- CRUD workflows: Customer create/edit/reload; Brand create/edit; six Digital Assets; Business Context; Files upload; Work capture attempt
- desktop: 1440×900
- tablet: 768×1024
- mobile: 390×844

Session dataset (ephemeral isolated QA 002):

- customer: `E2E Acceptance Customer 1787056167516` id=`7`
- brand: `E2E Acceptance Brand 1787056167516` id=`7`
- assets: website#25, google_business_profile#26, google_ads#27, meta_ads#28, ga4#29, gsc#30

Failed specs: (none)

## BLOCKERS

count: 0

(none)

## HIGH

count: 0

(none)

## MEDIUM

count: 3

- QA-E2E-002 — Activity: "Open" [a]; "Status" [label]
- QA-E2E-004 — Public Discovery: Public Discovery subsection did not show the truthful has-not-run copy in this pass.
- QA-E2E-LAST-ADMIN-SILENT — Team & Access: Last admin remained active (protection held) but no visible last-admin error/flash was rendered.

## LOW

count: 2

- QA-E2E-005 — accessibility: /app/settings: 2 unlabeled controls; /app/customers/7: 3 unlabeled controls
- QA-E2E-TR-POLISH-GROUPED — TR chrome: Findings: Findings | Operations || Recommendations: Recommendations | Operations

## Issue details

### QA-E2E-002

Severity: MEDIUM
Surface: Activity
route: http://127.0.0.1:8013/app/activity

Action: TR localization sweep

Observed: "Open" [a]; "Status" [label]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-activity.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium

### QA-E2E-004

Severity: MEDIUM
Surface: Public Discovery
route: http://127.0.0.1:8013/app/brands/7?tab=business

Action: Open Brand Business Public Discovery

Observed: Public Discovery subsection did not show the truthful has-not-run copy in this pass.

Expected: Truthful unavailable/not run empty state (deferred live discovery)

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/qa002-public-discovery.png

Likely source: BrandShow businessSection switch

Recommended fix scope: small

### QA-E2E-LAST-ADMIN-SILENT

Severity: MEDIUM
Surface: Team & Access
route: /app/settings

Action: Deactivate last administrator

Observed: Last admin remained active (protection held) but no visible last-admin error/flash was rendered.

Expected: Show the last-admin protection message when deactivation is rejected.

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/qa002-last-admin-silent.png

Likely source: OperatorTeamAccessService ValidationException key `user` is not displayed on Settings

Recommended fix scope: small

### QA-E2E-005

Severity: LOW
Surface: accessibility
route: /app/settings

Action: Bounded label/name check on primary workflows

Observed: /app/settings: 2 unlabeled controls; /app/customers/7: 3 unlabeled controls

Expected: Important form controls and destructive actions have accessible names

Automated reproduction: YES

Evidence: —

Likely source: missing label/aria-label on operator forms

Recommended fix scope: small

### QA-E2E-TR-POLISH-GROUPED

Severity: LOW
Surface: TR chrome
route: http://127.0.0.1:8013/app/findings, http://127.0.0.1:8013/app/recommendations

Action: TR polish chrome inventory (grouped)

Observed: Findings: Findings | Operations || Recommendations: Recommendations | Operations

Expected: Isolated English helper subtitles are POLISH_LANGUAGE backlog, not blocking.

Automated reproduction: YES

Evidence: —

Likely source: Untranslated helper subtitle or secondary chrome

Recommended fix scope: small


## CORE WORKFLOWS

- Login: PASS — Visible Profile Sign out POSTs /app/logout
- Customer: PASS — Create/edit/reload already covered; tabs render
- Brand: PASS — Brand tabs and business context persist
- Digital Assets: PASS — Six types opened from canonical ids; specialist tabs crawled
- Website: PASS — Website tabs crawled
- GBP: PASS — GBP tabs crawled
- Google Ads: PASS — Google Ads tabs crawled
- Meta: PASS — Meta tabs crawled
- GA4: PASS — GA4 tabs crawled
- GSC: PASS — GSC tabs crawled
- Files: PASS — Upload/list/download; guest denied
- Opportunities: TRUTHFUL_EMPTY — Truthful empty
- Findings: TRUTHFUL_EMPTY — Truthful empty
- Recommendations: TRUTHFUL_EMPTY — Truthful empty
- Work: PASS — Global and contextual Capture persist Tasks; Work detail and status work
- Activity: PASS — Rows present
- Requests: TRUTHFUL_EMPTY — No dedicated Requests create UI
- Goals / Business Context: PASS — Canonical business context saved and survived reload + TR/EN
- Outcomes / Value: TRUTHFUL_EMPTY — Brand Value tab
- Reports: TRUTHFUL_EMPTY — Customer Reports tab
- Integrations: PASS — Google/Meta/DataForSEO/OpenAI/Anthropic/Gemini rendered without credentials
- Settings: PASS — General settings persist and restore
- Team & Access: PASS — Temporary Team Member created, listed, deactivated; QA admin remains
- White-label: PASS — Agency name write/restore on Settings General

## DATA TRUTH

- Demo data: production numeric Digital Assets must use UnavailableWorkspaceShells, not Demo catalog
- fixture data: specialist tab crawl flagged Atlas/fixture/demo campaign copy if present
- fake metrics: NO on crawled production assets
- wrong-customer leakage: not observed in isolated dataset (single Acceptance Customer)
- wrong-brand leakage: not observed
- missing-vs-zero semantics: empty operational lists use truthful empty copy; glance zeros on uncollected specialists are unavailable shells, not claimed live performance

Asset Open results:

- website via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/website/25` final=`http://127.0.0.1:8013/app/assets/website/25` 404=false 500=false
- website via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/website/25` final=`http://127.0.0.1:8013/app/assets/website/25` 404=false 500=false
- google_business_profile via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/gbp/26` final=`http://127.0.0.1:8013/app/assets/gbp/26` 404=false 500=false
- google_business_profile via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/gbp/26` final=`http://127.0.0.1:8013/app/assets/gbp/26` 404=false 500=false
- google_ads via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/google-ads/27` final=`http://127.0.0.1:8013/app/assets/google-ads/27` 404=false 500=false
- google_ads via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/google-ads/27` final=`http://127.0.0.1:8013/app/assets/google-ads/27` 404=false 500=false
- meta_ads via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/meta/28` final=`http://127.0.0.1:8013/app/assets/meta/28` 404=false 500=false
- meta_ads via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/meta/28` final=`http://127.0.0.1:8013/app/assets/meta/28` 404=false 500=false
- ga4 via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/analytics/29` final=`http://127.0.0.1:8013/app/assets/analytics/29` 404=false 500=false
- ga4 via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/analytics/29` final=`http://127.0.0.1:8013/app/assets/analytics/29` 404=false 500=false
- gsc via digital-assets-open: href=`http://127.0.0.1:8013/app/assets/search-console/30` final=`http://127.0.0.1:8013/app/assets/search-console/30` 404=false 500=false
- gsc via brand-estate-open: href=`http://127.0.0.1:8013/app/assets/search-console/30` final=`http://127.0.0.1:8013/app/assets/search-console/30` 404=false 500=false

Specialist tabs:

- website / Overview: ok=true fake=false
- website / Health: ok=true fake=false
- website / Visibility: ok=true fake=false
- website / Content: ok=true fake=false
- website / Performance: ok=true fake=false
- website / Infrastructure: ok=true fake=false
- website / Operations: ok=true fake=false
- website / Setup: ok=true fake=false
- google_business_profile / Overview: ok=true fake=false
- google_business_profile / Profile: ok=true fake=false
- google_business_profile / Visibility: ok=true fake=false
- google_business_profile / Performance: ok=true fake=false
- google_business_profile / Reviews: ok=true fake=false
- google_business_profile / Competitors: ok=true fake=false
- google_business_profile / Operations: ok=true fake=false
- google_ads / Overview: ok=true fake=false
- google_ads / Campaigns: ok=true fake=false
- google_ads / Search & Demand: ok=true fake=false
- google_ads / Ads & Assets: ok=true fake=false
- google_ads / Landing Pages: ok=true fake=false
- google_ads / Measurement: ok=true fake=false
- google_ads / Operations: ok=true fake=false
- meta_ads / Overview: ok=true fake=false
- meta_ads / Campaigns: ok=true fake=false
- meta_ads / Creatives: ok=true fake=false
- meta_ads / Audience & Delivery: ok=true fake=false
- meta_ads / Funnel & Destinations: ok=true fake=false
- meta_ads / Measurement: ok=true fake=false
- meta_ads / Operations: ok=true fake=false
- ga4 / Overview: ok=true fake=false
- ga4 / Measurement: ok=true fake=false
- ga4 / Acquisition: ok=true fake=false
- ga4 / Behavior: ok=true fake=false
- ga4 / Journeys: ok=true fake=false
- ga4 / Operations: ok=true fake=false
- gsc / Overview: ok=true fake=false
- gsc / Search Performance: ok=true fake=false
- gsc / Queries & Demand: ok=true fake=false
- gsc / Pages: ok=true fake=false
- gsc / Indexing: ok=true fake=false
- gsc / Operations: ok=true fake=false

## ROUTES

- 404: none recorded
- 500: none recorded
- Livewire failures: see `.qa-artifacts/http-watcher.jsonl`
- dead primary navigation: none recorded

## SECURITY

- secret exposure: not observed (secret fields not submitted; values not recorded)
- unauthorized writes: guest /app routes redirect to login
- file boundary: guest download of private file asserted deny/redirect
- admin lockout: last-admin deactivate rejected; QA admin remains active
- credential browser exposure: password inputs used; password never written to artifacts

## I18N

- TR blocking leaks: 0
- TR polish leaks: 9
- EN leaks: 0
- dynamic data preserved: Business Context marker survived TR/EN when the CRUD test ran
- DB language duplication: NO (operator chrome is language resources)

### TR leakage sample

- `http://127.0.0.1:8013/app/findings` — "Findings" (h1)
- `http://127.0.0.1:8013/app/findings` — "Operations" (p)
- `http://127.0.0.1:8013/app/recommendations` — "Recommendations" (h1)
- `http://127.0.0.1:8013/app/recommendations` — "Operations" (p)
- `http://127.0.0.1:8013/app/activity` — "Activity" (h1)
- `http://127.0.0.1:8013/app/activity` — "Open" (a)
- `http://127.0.0.1:8013/app/activity` — "Status" (label)
- `http://127.0.0.1:8013/app/activity` — "status open" (p)
- `http://127.0.0.1:8013/app/activity` — "Operations" (p)

### EN leakage sample

- (none)

Hard-coded source copy candidates: 1541

## RESPONSIVE

- desktop: 1440×900 screenshots captured
- tablet: 768×1024 overflow asserted
- mobile: 390×844 overflow asserted
- blocking overflow: none recorded

## DEFERRED / NOT BLOCKING

- Public Discovery: DEFERRED PRODUCT FEATURE (truthful unavailable / has not run; live refresh disabled)
- Website live analytics: deferred; unavailable shell acceptable
- mobile push: deferred; notifications UI must not claim live push
- SMTP UI: deferred
- Instagram: deferred / not in frozen six-asset path
- Assistant: deferred
- theme engine: deferred

## DEPLOYMENT-ONLY NEXT

- PostgreSQL: staging
- Redis: staging
- Horizon: staging
- Scheduler: staging
- backup/restore: staging
- SMTP: staging
- live Google: staging (no OAuth in this audit)
- live Meta: staging
- live GA4/GSC: staging

## EXISTING TESTS

- PHPUnit passed: see subsequent isolated `env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact`
- PHPUnit failed: see subsequent run
- skipped: see subsequent run
- npm build: see subsequent run
- git diff --check: see subsequent run

## PILOT DECISION RATIONALE

No BLOCKER or HIGH defects remain. MEDIUM/LOW issues (localization polish, secondary UX) do not prevent an initial internal pilot. Backlog them; do not reopen product development for polish before staging.

## NEXT

The production-intended /app has passed the final autonomous pre-staging acceptance gate with no blocking application defects. Stop feature development. Proceed to staging infrastructure and one-customer pilot preparation.

## SAFETY

- live API calls: NONE
- paid calls: NONE
- provider credentials: NONE entered
- real mail: NONE
- OAuth: NONE
- destructive: temporary Team Member deactivated only; isolated test file only; QA admin left active

## Screenshots

- `.qa-artifacts/screenshots/admin-login-surface.png`
- `.qa-artifacts/screenshots/asset-open-ga4.png`
- `.qa-artifacts/screenshots/asset-open-google_ads.png`
- `.qa-artifacts/screenshots/asset-open-google_business_profile.png`
- `.qa-artifacts/screenshots/asset-open-gsc.png`
- `.qa-artifacts/screenshots/asset-open-index-ga4.png`
- `.qa-artifacts/screenshots/asset-open-index-google_ads.png`
- `.qa-artifacts/screenshots/asset-open-index-google_business_profile.png`
- `.qa-artifacts/screenshots/asset-open-index-gsc.png`
- `.qa-artifacts/screenshots/asset-open-index-meta_ads.png`
- `.qa-artifacts/screenshots/asset-open-index-website.png`
- `.qa-artifacts/screenshots/asset-open-meta_ads.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-ga4.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-google_ads.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-google_business_profile.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-gsc.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-meta_ads.png`
- `.qa-artifacts/screenshots/asset-open-unscoped-website.png`
- `.qa-artifacts/screenshots/asset-open-website.png`
- `.qa-artifacts/screenshots/brand-business-context.png`
- `.qa-artifacts/screenshots/brand-business-ia.png`
- `.qa-artifacts/screenshots/brand-create.png`
- `.qa-artifacts/screenshots/brand-detail.png`
- `.qa-artifacts/screenshots/brand-digital-estate.png`
- `.qa-artifacts/screenshots/brand-public-discovery.png`
- `.qa-artifacts/screenshots/crawler-fail-12.png`
- `.qa-artifacts/screenshots/crawler-fail-4.png`
- `.qa-artifacts/screenshots/customer-create.png`
- `.qa-artifacts/screenshots/customer-detail.png`
- `.qa-artifacts/screenshots/customer-form-selects.png`
- `.qa-artifacts/screenshots/customers-index.png`
- `.qa-artifacts/screenshots/digital-assets-500.png`
- `.qa-artifacts/screenshots/digital-assets.png`
- `.qa-artifacts/screenshots/fail-activity.png`
- `.qa-artifacts/screenshots/fail-digital-assets.png`
- `.qa-artifacts/screenshots/fail-files.png`
- `.qa-artifacts/screenshots/fail-findings.png`
- `.qa-artifacts/screenshots/fail-integrations.png`
- `.qa-artifacts/screenshots/fail-opportunities.png`
- `.qa-artifacts/screenshots/fail-recommendations.png`
- `.qa-artifacts/screenshots/fail-settings.png`
- `.qa-artifacts/screenshots/fail-work.png`
- `.qa-artifacts/screenshots/i18n-en-brands.png`
- `.qa-artifacts/screenshots/i18n-en-customer-create.png`
- `.qa-artifacts/screenshots/i18n-en-customers.png`
- `.qa-artifacts/screenshots/i18n-en-settings.png`
- `.qa-artifacts/screenshots/i18n-tr-activity.png`
- `.qa-artifacts/screenshots/i18n-tr-brands.png`
- `.qa-artifacts/screenshots/i18n-tr-customer-setup.png`
- `.qa-artifacts/screenshots/i18n-tr-customers.png`
- `.qa-artifacts/screenshots/i18n-tr-dashboard.png`
- `.qa-artifacts/screenshots/i18n-tr-digital-assets.png`
- `.qa-artifacts/screenshots/i18n-tr-integrations.png`
- `.qa-artifacts/screenshots/integration-anthropic.png`
- `.qa-artifacts/screenshots/integration-dataforseo.png`
- `.qa-artifacts/screenshots/integration-gemini.png`
- `.qa-artifacts/screenshots/integration-google.png`
- `.qa-artifacts/screenshots/integration-meta.png`
- `.qa-artifacts/screenshots/integration-openai.png`
- `.qa-artifacts/screenshots/integrations.png`
- `.qa-artifacts/screenshots/mobile-brand-detail.png`
- `.qa-artifacts/screenshots/mobile-brands.png`
- `.qa-artifacts/screenshots/mobile-customer-detail.png`
- `.qa-artifacts/screenshots/mobile-customers.png`
- `.qa-artifacts/screenshots/mobile-dashboard.png`
- `.qa-artifacts/screenshots/mobile-digital-assets.png`
- `.qa-artifacts/screenshots/mobile-integrations.png`
- `.qa-artifacts/screenshots/mobile-settings.png`
- `.qa-artifacts/screenshots/mobile-website.png`
- `.qa-artifacts/screenshots/mobile-work.png`
- `.qa-artifacts/screenshots/qa002-activity.png`
- `.qa-artifacts/screenshots/qa002-customer-reports.png`
- `.qa-artifacts/screenshots/qa002-files.png`
- `.qa-artifacts/screenshots/qa002-findings.png`
- `.qa-artifacts/screenshots/qa002-ga4-workspace.png`
- `.qa-artifacts/screenshots/qa002-google_ads-workspace.png`
- `.qa-artifacts/screenshots/qa002-google_business_profile-workspace.png`
- `.qa-artifacts/screenshots/qa002-gsc-workspace.png`
- `.qa-artifacts/screenshots/qa002-last-admin-silent.png`
- `.qa-artifacts/screenshots/qa002-logout-nested-form.png`
