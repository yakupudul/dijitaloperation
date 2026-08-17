# MOXDOP — AUTONOMOUS E2E QA 002
## FINAL PRE-STAGING ACCEPTANCE

Generated: 2026-08-17T16:53:42.475Z

STATUS: AUDIT_COMPLETE

PILOT DECISION: NOT_PILOT_READY

This is an **audit-only** report. Product defects were not fixed in this task.

## CANONICAL

- workspace: `/workspace`
- starting SHA: `82391004840b3718d544fafb1a22454d4e919290`
- final SHA: `393574274e085e2cd288a5865c7f2af904723266`
- branch: `cursor/production-readiness-audit-ea01`
- origin: `https://github.com/yakupudul/dijitaloperation`
- pushed: yes (`cursor/production-readiness-audit-ea01`)
- PR #197 Draft: remains Draft
- base URL: `http://127.0.0.1:8014`
- database: `/tmp/moxdop-e2e-qa-002.sqlite` (exists: yes)
- QA email: `qa-final@moxdop.local`
- password source: `file:/tmp/moxdop-final-manual-qa-admin.secret` (value never recorded)

## AUTOMATION

- Playwright tests: 29
- passed: 29
- failed: 0
- skipped: 0
- routes: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, Profile, specialist workspaces, /system, /admin
- actions: Capture, upload, download, settings write/restore, team create/deactivate, locale switch, specialist tabs, customer/brand tabs
- CRUD workflows: Customer create/edit/reload; Brand create/edit; six Digital Assets; Business Context; Files upload; Work capture attempt
- desktop: 1440×900
- tablet: 768×1024
- mobile: 390×844

Session dataset (ephemeral isolated QA 002):

- customer: `E2E Acceptance Customer 1786985430319` id=`4`
- brand: `E2E Acceptance Brand 1786985430319` id=`4`
- assets: website#19, google_business_profile#20, google_ads#21, meta_ads#22, ga4#23, gsc#24

Failed specs: (none)

## BLOCKERS

count: 0

(none)

## HIGH

count: 3

- QA-E2E-LOGOUT-NESTED-FORM — Profile / logout: Visible Sign out control did not navigate to /app/login (stayed http://127.0.0.1:8014/app/profile; started http://127.0.0.1:8014/app/profile). Nested logout form sits inside the Livewire profile save form, so the browser submits Save instead of POST /app/logout.
- QA-E2E-WORK-CAPTURE-CUSTOMER — Work: Header + Capture dispatches open-capture with no customer/brand. Direct Task save flashes that a production Customer is required and does not persist a Task. Capture modal has no Customer/Brand picker.
- QA-E2E-WORK-DETAIL-NOT-FOUND — Work: Task "E2E Acceptance Work 1786985612318" persisted in SQLite (id=3) but Work/Task show renders "Work item not found." TaskShow redirects numeric ids to /app/work/{id}; WorkShow defaults type to client_request and does not read ?type=task, so production Tasks are unresolved.

## MEDIUM

count: 3

- QA-E2E-002 — Activity: "Open" [a]; "Status" [label]
- QA-E2E-005 — Public Discovery: Public Discovery subsection did not show the truthful has-not-run copy in this pass.
- QA-E2E-LAST-ADMIN-SILENT — Team & Access: Last admin remained active (protection held) but no visible last-admin error/flash was rendered.

## LOW

count: 2

- QA-E2E-008 — accessibility: /app/settings: 2 unlabeled controls; /app/customers/4: 3 unlabeled controls
- QA-E2E-TR-POLISH-GROUPED — TR chrome: Findings: Findings | Operations || Recommendations: Recommendations | Operations

## Issue details

### QA-E2E-LOGOUT-NESTED-FORM

Severity: HIGH
Surface: Profile / logout
route: /app/profile

Action: Click Sign out on Profile

Observed: Visible Sign out control did not navigate to /app/login (stayed http://127.0.0.1:8014/app/profile; started http://127.0.0.1:8014/app/profile). Nested logout form sits inside the Livewire profile save form, so the browser submits Save instead of POST /app/logout.

Expected: Sign out must POST /app/logout and land on /app/login.

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/qa002-logout-nested-form.png

Likely source: resources/views/livewire/demo/profile.blade.php nested form

Recommended fix scope: small

### QA-E2E-WORK-CAPTURE-CUSTOMER

Severity: HIGH
Surface: Work
route: /app

Action: Create Task from global Capture without customer context

Observed: Header + Capture dispatches open-capture with no customer/brand. Direct Task save flashes that a production Customer is required and does not persist a Task. Capture modal has no Customer/Brand picker.

Expected: Pilot-critical Work create from the primary Capture CTA must bind a Customer (picker or page context) and persist a Task.

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/qa002-work-orphan-capture.png

Likely source: CaptureModal::saveDirectTask + header Livewire.dispatch without customer

Recommended fix scope: small

### QA-E2E-WORK-DETAIL-NOT-FOUND

Severity: HIGH
Surface: Work
route: http://127.0.0.1:8014/app/work/3?type=task

Action: Open captured Task #3

Observed: Task "E2E Acceptance Work 1786985612318" persisted in SQLite (id=3) but Work/Task show renders "Work item not found." TaskShow redirects numeric ids to /app/work/{id}; WorkShow defaults type to client_request and does not read ?type=task, so production Tasks are unresolved.

Expected: Opening a captured Task shows the execution record and status transitions.

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/qa002-work-detail-not-found.png

Likely source: WorkShow::$type default client_request; TaskShow redirect does not bind type

Recommended fix scope: small

### QA-E2E-002

Severity: MEDIUM
Surface: Activity
route: http://127.0.0.1:8014/app/activity

Action: TR localization sweep

Observed: "Open" [a]; "Status" [label]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-activity.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium

### QA-E2E-005

Severity: MEDIUM
Surface: Public Discovery
route: http://127.0.0.1:8014/app/brands/4?tab=business

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

### QA-E2E-008

Severity: LOW
Surface: accessibility
route: /app/settings

Action: Bounded label/name check on primary workflows

Observed: /app/settings: 2 unlabeled controls; /app/customers/4: 3 unlabeled controls

Expected: Important form controls and destructive actions have accessible names

Automated reproduction: YES

Evidence: —

Likely source: missing label/aria-label on operator forms

Recommended fix scope: small

### QA-E2E-TR-POLISH-GROUPED

Severity: LOW
Surface: TR chrome
route: http://127.0.0.1:8014/app/findings, http://127.0.0.1:8014/app/recommendations

Action: TR polish chrome inventory (grouped)

Observed: Findings: Findings | Operations || Recommendations: Recommendations | Operations

Expected: Isolated English helper subtitles are POLISH_LANGUAGE backlog, not blocking.

Automated reproduction: YES

Evidence: —

Likely source: Untranslated helper subtitle or secondary chrome

Recommended fix scope: small


## CORE WORKFLOWS

- Login: PASS — Session persisted after logout/login at http://127.0.0.1:8014
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
- Work: FAIL — Task persists but Work detail is not found; header Capture cannot create without customer
- Activity: PASS — Rows present
- Requests: TRUTHFUL_EMPTY — No dedicated Requests create UI; Capture Client request also requires Customer+Brand prefill
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

- website via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/website/19` final=`http://127.0.0.1:8014/app/assets/website/19` 404=false 500=false
- website via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/website/19` final=`http://127.0.0.1:8014/app/assets/website/19` 404=false 500=false
- google_business_profile via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/gbp/20` final=`http://127.0.0.1:8014/app/assets/gbp/20` 404=false 500=false
- google_business_profile via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/gbp/20` final=`http://127.0.0.1:8014/app/assets/gbp/20` 404=false 500=false
- google_ads via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/google-ads/21` final=`http://127.0.0.1:8014/app/assets/google-ads/21` 404=false 500=false
- google_ads via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/google-ads/21` final=`http://127.0.0.1:8014/app/assets/google-ads/21` 404=false 500=false
- meta_ads via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/meta/22` final=`http://127.0.0.1:8014/app/assets/meta/22` 404=false 500=false
- meta_ads via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/meta/22` final=`http://127.0.0.1:8014/app/assets/meta/22` 404=false 500=false
- ga4 via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/analytics/23` final=`http://127.0.0.1:8014/app/assets/analytics/23` 404=false 500=false
- ga4 via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/analytics/23` final=`http://127.0.0.1:8014/app/assets/analytics/23` 404=false 500=false
- gsc via digital-assets-open: href=`http://127.0.0.1:8014/app/assets/search-console/24` final=`http://127.0.0.1:8014/app/assets/search-console/24` 404=false 500=false
- gsc via brand-estate-open: href=`http://127.0.0.1:8014/app/assets/search-console/24` final=`http://127.0.0.1:8014/app/assets/search-console/24` 404=false 500=false

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

- `http://127.0.0.1:8014/app/findings` — "Findings" (h1)
- `http://127.0.0.1:8014/app/findings` — "Operations" (p)
- `http://127.0.0.1:8014/app/recommendations` — "Recommendations" (h1)
- `http://127.0.0.1:8014/app/recommendations` — "Operations" (p)
- `http://127.0.0.1:8014/app/activity` — "Activity" (h1)
- `http://127.0.0.1:8014/app/activity` — "Open" (a)
- `http://127.0.0.1:8014/app/activity` — "Status" (label)
- `http://127.0.0.1:8014/app/activity` — "status open" (p)
- `http://127.0.0.1:8014/app/activity` — "Operations" (p)

### EN leakage sample

- (none)

Hard-coded source copy candidates: 1531

## RESPONSIVE

- desktop: 1440×900 screenshots captured
- tablet: 768×1024 overflow asserted
- mobile: 390×844 overflow asserted
- blocking overflow: none recorded

## DEFERRED / NOT BLOCKING

- Public Discovery: DEFERRED PRODUCT FEATURE (golden path 02 asserted truthful “has not run” / live refresh disabled). QA-E2E-005 is a 09 click-order miss after locale switching, not a product lie.
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

- PHPUnit passed: 1791
- PHPUnit failed: 0
- skipped: 2
- npm build: PASS
- git diff --check: PASS

Isolated PHPUnit invocation: `env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact`. Manual QA sqlite `/tmp/moxdop-final-manual-qa.sqlite` was not migrated or RefreshDatabase'd (mtime remained 16:13 UTC).

## PILOT DECISION RATIONALE

Not ready for an initial pilot until HIGH/BLOCKER items are resolved. 3 HIGH and 0 BLOCKER finding(s) affect core daily use. Do not treat deferred provider/live-collection features as the reason — only current operator-code defects listed above.

## NEXT

Smallest blocking bugfix batch before staging:
- QA-E2E-LOGOUT-NESTED-FORM (HIGH) Profile / logout: Visible Sign out control did not navigate to /app/login (stayed http://127.0.0.1:8014/app/profile; started http://127.0.0.1:8014/app/profile). Nested logout form sits inside the Livewire profile save form, so the browser submits Save instead of POST /app/logout.
- QA-E2E-WORK-CAPTURE-CUSTOMER (HIGH) Work: Header + Capture dispatches open-capture with no customer/brand. Direct Task save flashes that a production Customer is required and does not persist a Task. Capture modal has no Customer/Brand picker.
- QA-E2E-WORK-DETAIL-NOT-FOUND (HIGH) Work: Task "E2E Acceptance Work 1786985612318" persisted in SQLite (id=3) but Work/Task show renders "Work item not found." TaskShow redirects numeric ids to /app/work/{id}; WorkShow defaults type to client_request and does not read ?type=task, so production Tasks are unresolved.

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
- `.qa-artifacts/screenshots/qa002-meta_ads-workspace.png`
