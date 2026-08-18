# MOXDOP — AUTONOMOUS E2E QA REPORT 001

Generated: 2026-08-18T12:32:55.135Z

Status: BUGFIX_BATCH_001

Playwright product failures are treated as regressions. Prior baseline findings are classified FIXED / REMAINS / DEFERRED.

## Canonical environment

- workspace: `/workspace`
- git toplevel: `/workspace`
- branch: `cursor/production-readiness-audit-ea01`
- starting SHA (task): `79c88d5eea2e5746b81439dbf8fd5fde4cebd46d`
- harness/audit SHA: `c864e8cbedc53e2a92383238e4bb508368867bbe`
- origin: `https://github.com/yakupudul/dijitaloperation`
- base URL: `http://127.0.0.1:8013`
- database: `/tmp/moxdop-final-manual-qa.sqlite` (exists: yes)
- QA email: `qa-final@moxdop.local`
- password source: `file:/tmp/moxdop-final-manual-qa-admin.secret` (value never recorded)
- auth storage: `.qa-artifacts/auth.json` (gitignored)
- Playwright HTML report: `playwright-report/` (gitignored)
- traces: `test-results/` retain-on-failure (gitignored)
- screenshots: `.qa-artifacts/screenshots/` (184 files, gitignored)

## Harness

- package: `@playwright/test`
- browser: Chromium
- config: `playwright.config.js`
- tests: `tests/e2e/`
- scripts: `npm run qa:e2e`, `qa:e2e:ui`, `qa:e2e:report`
- webServer: reuse existing isolated server only (does not boot Desktop clones)

## Automated coverage

- routes visited: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, plus create/edit/detail/specialist URLs
- primary actions tested: customer Files / Activity / Open work / Add person / Edit / Add brand; brand Edit / Business tabs / Public Discovery (truthful empty; live refresh disabled)
- CRUD workflows: Customer create/edit/reload; Brand create/edit; six Digital Asset types
- asset types tested: Website, GBP, Google Ads, Meta Ads, GA4, GSC
- integration workspaces: Google, Meta, DataForSEO, OpenAI, Anthropic, Gemini
- settings surfaces: General, Team & Access, Notifications, Operations, AI & Intelligence, Advanced
- TR surfaces: Dashboard, Customers, Customer create, Customer setup, Brands, Digital Assets, Integrations, Settings
- EN surfaces: same set
- desktop: 1440×900
- tablet: 768×1024
- mobile: 390×844

Session dataset (ephemeral):

- customer: `E2E Acceptance Customer 1787056167516` id=`7`
- brand: `E2E Acceptance Brand 1787056167516` id=`7`
- assets: website#25, google_business_profile#26, google_ads#27, meta_ads#28, ga4#29, gsc#30

## Playwright run

- expectedStatus: all tests must PASS
- stats: {"startTime":"2026-08-18T12:29:20.257Z","duration":214780.344,"expected":40,"skipped":0,"unexpected":0,"flaky":0}
- failed specs: 0

## PRIOR FINDINGS (Bugfix Batch 001)

| ID | Result |
| --- | --- |
| QA-E2E-003 | FIXED |
| QA-E2E-004 | FIXED |
| QA-E2E-005 | FIXED |
| QA-E2E-006 | FIXED |
| QA-E2E-007 | FIXED |
| QA-E2E-008 | FIXED |
| QA-MANUAL-001 | FIXED |
| QA-MANUAL-002 | FIXED |
| QA-MANUAL-003 | FIXED |
| QA-MANUAL-004 | FIXED |
| QA-MANUAL-005 | FIXED |
| QA-MANUAL-006 | DEFERRED (live Public Discovery not in this batch; truthful empty retained) |
| QA-MANUAL-007 | FIXED |

## FAILURES

### BLOCKER

count: 0

(none)

### HIGH

count: 0

(none)

### MEDIUM

count: 3

- QA-E2E-002 — Activity: "Open" [a]; "Status" [label]
- QA-E2E-004 — Public Discovery: Public Discovery subsection did not show the truthful has-not-run copy in this pass.
- QA-E2E-LAST-ADMIN-SILENT — Team & Access: Last admin remained active (protection held) but no visible last-admin error/flash was rendered.

### LOW

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



## Known manual findings

| ID | Claim | Result |
| --- | --- | --- |
| QA-MANUAL-001 | Dashboard remaining English buttons | FIXED |
| QA-MANUAL-002 | Customers forms/table/dropdowns English chrome | FIXED |
| QA-MANUAL-003 | Customer Setup substantially English | FIXED |
| QA-MANUAL-004 | Country controlled but City free text | FIXED |
| QA-MANUAL-005 | Brand Business nav hierarchy confusing | FIXED |
| QA-MANUAL-006 | Public Discovery has no run/data | DEFERRED |
| QA-MANUAL-007 | Website Open → /app/assets/website 404 | FIXED |

Evidence lives in `.qa-artifacts/screenshots/` and findings above.

## WEBSITE 404

- reproduced: NO (Open did not 404 in this run)
- clicked from: Digital Assets index Open and Brand Digital Estate Open
- generated target: `http://127.0.0.1:8013/app/assets/website/25`
- final URL: `http://127.0.0.1:8013/app/assets/website/25`
- HTTP / UI: page loaded
- exact root cause (fixed): `OperatorPortfolioPresenter` now exposes canonical `url` + `route_params` including DigitalAsset id. Production Open actions use `$asset['url']`.
- expected canonical target: `route('operator.website', ['assetId' => $asset->id])` → `/app/assets/website/{id}` (same pattern for GBP / Google Ads / Meta / GA4 / GSC)
- release blocking: NO — Open includes canonical asset id

Related Open results:

- website: href=`http://127.0.0.1:8013/app/assets/website/25` final=`http://127.0.0.1:8013/app/assets/website/25` 404=false 500=false
- website: href=`http://127.0.0.1:8013/app/assets/website/25` final=`http://127.0.0.1:8013/app/assets/website/25` 404=false 500=false
- google_business_profile: href=`http://127.0.0.1:8013/app/assets/gbp/26` final=`http://127.0.0.1:8013/app/assets/gbp/26` 404=false 500=false
- google_business_profile: href=`http://127.0.0.1:8013/app/assets/gbp/26` final=`http://127.0.0.1:8013/app/assets/gbp/26` 404=false 500=false
- google_ads: href=`http://127.0.0.1:8013/app/assets/google-ads/27` final=`http://127.0.0.1:8013/app/assets/google-ads/27` 404=false 500=false
- google_ads: href=`http://127.0.0.1:8013/app/assets/google-ads/27` final=`http://127.0.0.1:8013/app/assets/google-ads/27` 404=false 500=false
- meta_ads: href=`http://127.0.0.1:8013/app/assets/meta/28` final=`http://127.0.0.1:8013/app/assets/meta/28` 404=false 500=false
- meta_ads: href=`http://127.0.0.1:8013/app/assets/meta/28` final=`http://127.0.0.1:8013/app/assets/meta/28` 404=false 500=false
- ga4: href=`http://127.0.0.1:8013/app/assets/analytics/29` final=`http://127.0.0.1:8013/app/assets/analytics/29` 404=false 500=false
- ga4: href=`http://127.0.0.1:8013/app/assets/analytics/29` final=`http://127.0.0.1:8013/app/assets/analytics/29` 404=false 500=false
- gsc: href=`http://127.0.0.1:8013/app/assets/search-console/30` final=`http://127.0.0.1:8013/app/assets/search-console/30` 404=false 500=false
- gsc: href=`http://127.0.0.1:8013/app/assets/search-console/30` final=`http://127.0.0.1:8013/app/assets/search-console/30` 404=false 500=false

## I18N

- TR leakage count: 9
- EN leakage count: 0
- top affected TR surfaces: Findings, Recommendations, Activity
- hard-coded source copy count: 1541
- database translation duplication found: **NO** (audit did not find per-language UI chrome columns; agency/user locale is a setting, not duplicated product copy)
- recommended localization architecture: keep static operator chrome in `lang/en/operator.php` + `lang/tr/operator.php` (`__('operator.*')`). Store dynamic Customer/Brand/provider facts once. Convert remaining Blade/PHP English literals to language keys. Do not add translated DB columns for chrome.

Should static product copy be localized through language resources rather than per-language DB columns?

**YES**

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

### Source-level hard-coded copy sample

- `resources/views/livewire/demo/analytics/overview.blade.php:77` — "What happened"
- `resources/views/livewire/demo/analytics/overview.blade.php:81` — "Why this matters"
- `resources/views/livewire/demo/analytics/overview.blade.php:85` — "Recommended next action"
- `resources/views/livewire/demo/analytics/overview.blade.php:101` — "Sessions"
- `resources/views/livewire/demo/analytics/overview.blade.php:105` — "Engaged rate"
- `resources/views/livewire/demo/analytics/overview.blade.php:109` — "Mapped actions"
- `resources/views/livewire/demo/analytics/overview.blade.php:119` — "Content role"
- `resources/views/livewire/demo/analytics/overview.blade.php:125` — "Website attention"
- `resources/views/livewire/demo/analytics/overview.blade.php:154` — "Count"
- `resources/views/livewire/demo/analytics/overview.blade.php:159` — "Unavailable"
- `resources/views/livewire/demo/analytics/overview.blade.php:164` — "Mapped action"
- `resources/views/livewire/demo/analytics/overview.blade.php:180` — "Event count"
- `resources/views/livewire/demo/analytics/overview.blade.php:190` — "Mapping"
- `resources/views/livewire/demo/analytics/overview.blade.php:194` — "Role"
- `resources/views/livewire/demo/analytics/overview.blade.php:200` — "Note"
- `resources/views/livewire/demo/analytics/overview.blade.php:204` — "Open measurement mapping"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:22` — "Acquisition"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:27` — "Channels"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:30` — "Channel"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:31` — "Sessions"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:32` — "Share"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:33` — "Mapped actions"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:34` — "Related asset"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:45` — "Unavailable"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:64` — "Source / medium"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:70` — "Attention"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:90` — "Campaigns (measured)"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:93` — "Campaign"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:94` — "Source"
- `resources/views/livewire/demo/analytics/tabs/acquisition.blade.php:97` — "Paid workspace"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:8` — "Behavior"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:30` — "Landing page"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:31` — "Content role"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:32` — "Sessions"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:33` — "Engagement"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:34` — "Business actions"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:35` — "Website attention"
- `resources/views/livewire/demo/analytics/tabs/behavior.blade.php:64` — "Devices"
- `resources/views/livewire/demo/analytics/tabs/journeys.blade.php:11` — "Journeys"
- `resources/views/livewire/demo/analytics/tabs/journeys.blade.php:12` — "Aggregated paths · no PII · configured measurement only"

## BRAND BUSINESS IA

CURRENT_STRUCTURE:

- Top-level Brand tabs (role=tab): Overview, Business, Digital Estate, Growth, Operations, Value
- When Business is selected, a **second** tablist labelled "Business sections" renders Context + Public Discovery as buttons (not top-level tabs)
- Overview also exposes a "Business context" shortcut that jumps into the Business section
- Public Discovery has its own inner section nav (Overview / Observed Facts / Candidates / Conflicts / Sources & History)

EXPECTED_STRUCTURE:

- Brand
  - Overview
  - Business
    - Context
    - Public Discovery
  - Digital Estate
  - Growth
  - Operations
  - Value

ROUTE_MODEL: single Livewire BrandShow URL `/app/brands/{brand}` with query/state `tab=business` + `businessSection=context|discovery`. No separate routes required.

Visual hierarchy: Context / Public Discovery render in a nested Business subsection after the main tablist (`data-brand-business-subnav`).

## CITY FIELD

- current behavior: HQ country is a searchable controlled ISO select (`CountryOptions`). HQ city is a country-scoped searchable select from `CityOptions::optionsForCountry()` plus an explicit Other/manual escape. Classification: **SEARCHABLE_SELECT**. Helper: "Choose a city listed for the selected country. Use Other if it is missing.". Country change clears incompatible city values. The Other token is never persisted.
- existing country/city source: **YES** — `app/Support/Options/CountryOptions.php` and `app/Support/Options/CityOptions.php` (no new dataset)
- dependent City select feasible without a new truth store: **YES**
- custom free-text: explicit Other escape only — not silent allow-custom on every city

## SAFETY

- live API calls: NONE (Public Discovery refresh is disabled/relabelled; no provider run)
- paid calls: NONE
- provider credentials: NONE entered
- real mail: NONE
- destructive user actions: temporary Team Member created then deactivated; QA admin left active; no archive/disconnect/collection

Expected: NONE — met.

## Existing tests

- PHPUnit must be run with the isolated QA env **unset** (`env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact`). `phpunit.xml` sets `DB_DATABASE=:memory:` with `force="false"`, so a shell that already exported the QA sqlite path will otherwise RefreshDatabase that file.
- `tests/e2e/scripts/ensure-qa-admin.php` restores the QA operator from the local secret file if the login user is missing. It never prints the password.

## Localization architecture confirmation

Static product copy must be localized through language resources (`lang/{en,tr}/operator.php`), not per-language database columns.

## Screenshots captured

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
- `.qa-artifacts/screenshots/qa002-meta_ads-workspace.png`
- `.qa-artifacts/screenshots/qa002-opportunities.png`
- `.qa-artifacts/screenshots/qa002-public-discovery.png`
- `.qa-artifacts/screenshots/qa002-recommendations.png`
- `.qa-artifacts/screenshots/qa002-website-workspace.png`
- `.qa-artifacts/screenshots/qa002-work-detail-not-found.png`
- `.qa-artifacts/screenshots/qa002-work-orphan-capture.png`
- `.qa-artifacts/screenshots/settings-notifications.png`
- `.qa-artifacts/screenshots/settings.png`
- `.qa-artifacts/screenshots/smoke-settings.png`
- `.qa-artifacts/screenshots/specialist-ga4-11.png`
- `.qa-artifacts/screenshots/specialist-ga4-12.png`
- `.qa-artifacts/screenshots/specialist-ga4-17.png`
- `.qa-artifacts/screenshots/specialist-ga4-18.png`
- `.qa-artifacts/screenshots/specialist-ga4-23.png`
- `.qa-artifacts/screenshots/specialist-ga4-24.png`
- `.qa-artifacts/screenshots/specialist-ga4-29.png`
- `.qa-artifacts/screenshots/specialist-ga4-30.png`
- `.qa-artifacts/screenshots/specialist-ga4-35.png`
- `.qa-artifacts/screenshots/specialist-ga4-41.png`
- `.qa-artifacts/screenshots/specialist-ga4-47.png`
- `.qa-artifacts/screenshots/specialist-ga4-5.png`
- `.qa-artifacts/screenshots/specialist-google_ads-10.png`
- `.qa-artifacts/screenshots/specialist-google_ads-15.png`
- `.qa-artifacts/screenshots/specialist-google_ads-16.png`
- `.qa-artifacts/screenshots/specialist-google_ads-21.png`
- `.qa-artifacts/screenshots/specialist-google_ads-22.png`
- `.qa-artifacts/screenshots/specialist-google_ads-27.png`
- `.qa-artifacts/screenshots/specialist-google_ads-28.png`
- `.qa-artifacts/screenshots/specialist-google_ads-3.png`
- `.qa-artifacts/screenshots/specialist-google_ads-33.png`
- `.qa-artifacts/screenshots/specialist-google_ads-39.png`
- `.qa-artifacts/screenshots/specialist-google_ads-45.png`
- `.qa-artifacts/screenshots/specialist-google_ads-9.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-14.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-15.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-2.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-20.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-21.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-26.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-27.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-32.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-38.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-44.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-8.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-9.png`
- `.qa-artifacts/screenshots/specialist-gsc-12.png`
- `.qa-artifacts/screenshots/specialist-gsc-13.png`
- `.qa-artifacts/screenshots/specialist-gsc-18.png`
- `.qa-artifacts/screenshots/specialist-gsc-19.png`
- `.qa-artifacts/screenshots/specialist-gsc-24.png`
- `.qa-artifacts/screenshots/specialist-gsc-25.png`
- `.qa-artifacts/screenshots/specialist-gsc-30.png`
- `.qa-artifacts/screenshots/specialist-gsc-31.png`
- `.qa-artifacts/screenshots/specialist-gsc-36.png`
- `.qa-artifacts/screenshots/specialist-gsc-42.png`
- `.qa-artifacts/screenshots/specialist-gsc-48.png`
- `.qa-artifacts/screenshots/specialist-gsc-6.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-10.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-11.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-16.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-17.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-22.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-23.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-28.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-29.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-34.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-4.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-40.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-46.png`
- `.qa-artifacts/screenshots/specialist-website-1.png`
- `.qa-artifacts/screenshots/specialist-website-13.png`
- `.qa-artifacts/screenshots/specialist-website-14.png`
- `.qa-artifacts/screenshots/specialist-website-19.png`
- `.qa-artifacts/screenshots/specialist-website-20.png`
- `.qa-artifacts/screenshots/specialist-website-25.png`
- `.qa-artifacts/screenshots/specialist-website-26.png`
- `.qa-artifacts/screenshots/specialist-website-31.png`
- `.qa-artifacts/screenshots/specialist-website-37.png`
- `.qa-artifacts/screenshots/specialist-website-43.png`
- `.qa-artifacts/screenshots/specialist-website-7.png`
- `.qa-artifacts/screenshots/specialist-website-8.png`
- `.qa-artifacts/screenshots/tablet-brand-detail.png`
- `.qa-artifacts/screenshots/tablet-brands.png`
- `.qa-artifacts/screenshots/tablet-customer-detail.png`
- `.qa-artifacts/screenshots/tablet-customers.png`
- `.qa-artifacts/screenshots/tablet-dashboard.png`
- `.qa-artifacts/screenshots/tablet-digital-assets.png`
- `.qa-artifacts/screenshots/tablet-integrations.png`
- `.qa-artifacts/screenshots/tablet-settings.png`
- `.qa-artifacts/screenshots/tablet-website.png`
- `.qa-artifacts/screenshots/tablet-work.png`
- `.qa-artifacts/screenshots/tr-desktop-brand-detail.png`
- `.qa-artifacts/screenshots/tr-desktop-brands.png`
- `.qa-artifacts/screenshots/tr-desktop-business-context.png`
- `.qa-artifacts/screenshots/tr-desktop-customer-create.png`
- `.qa-artifacts/screenshots/tr-desktop-customer-detail.png`
- `.qa-artifacts/screenshots/tr-desktop-customers.png`
- `.qa-artifacts/screenshots/tr-desktop-dashboard.png`
- `.qa-artifacts/screenshots/tr-desktop-digital-assets.png`
- `.qa-artifacts/screenshots/tr-desktop-integrations.png`
- `.qa-artifacts/screenshots/tr-desktop-public-discovery.png`
- `.qa-artifacts/screenshots/tr-desktop-settings.png`
- `.qa-artifacts/screenshots/tr-desktop-website-workspace.png`

## Next

E2E Bugfix Batch 001 updates this report. Live Public Discovery remains deferred. Run Autonomous E2E QA 002 against the corrected build before any staging deployment.
