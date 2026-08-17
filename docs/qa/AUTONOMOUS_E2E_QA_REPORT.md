# MOXDOP — AUTONOMOUS E2E QA REPORT 001

Generated: 2026-08-17T13:32:59.561Z

Status: AUDIT_COMPLETE

Do not treat Playwright product failures as harness blockage. This report is the baseline.

## Canonical environment

- workspace: `/workspace`
- git toplevel: `/workspace`
- branch: `cursor/production-readiness-audit-ea01`
- starting SHA (task): `03f278496e2607d4d56fda70597c5b438e3a55ce`
- harness/audit SHA: `5eef607817fe321b5b71da857644e2ffac870222`
- origin: `https://github.com/yakupudul/dijitaloperation`
- base URL: `http://127.0.0.1:8013`
- database: `/tmp/moxdop-final-manual-qa.sqlite` (exists: yes)
- QA email: `qa-final@moxdop.local`
- password source: `file:/tmp/moxdop-final-manual-qa-admin.secret` (value never recorded)
- auth storage: `.qa-artifacts/auth.json` (gitignored)
- Playwright HTML report: `playwright-report/` (gitignored)
- traces: `test-results/` retain-on-failure (gitignored)
- screenshots: `.qa-artifacts/screenshots/` (105 files, gitignored)

## Harness

- package: `@playwright/test`
- browser: Chromium
- config: `playwright.config.js`
- tests: `tests/e2e/`
- scripts: `npm run qa:e2e`, `qa:e2e:ui`, `qa:e2e:report`
- webServer: reuse existing isolated server only (does not boot Desktop clones)

## Automated coverage

- routes visited: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, plus create/edit/detail/specialist URLs
- primary actions tested: customer Files / Activity / Open work / Add person / Edit / Add brand; brand Edit / Business tabs / Public Discovery refresh (local flash only)
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

- customer: `E2E Customer 1786973281821` id=`1`
- brand: `E2E Brand 1786973281821` id=`1`
- assets: website#1, google_business_profile#2, google_ads#3, meta_ads#4, ga4#5, gsc#6

## Playwright run

- expectedStatus: product failures allowed
- stats: {"startTime":"2026-08-17T13:27:55.457Z","duration":134731.389,"expected":15,"skipped":0,"unexpected":1,"flaky":0}
- failed specs: 1

## FAILURES

### BLOCKER

count: 6

- QA-E2E-003 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/website title=Not Found 404=true 500=false
- QA-E2E-004 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/gbp title=Not Found 404=true 500=false
- QA-E2E-005 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/google-ads title=Not Found 404=true 500=false
- QA-E2E-006 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/meta title=Not Found 404=true 500=false
- QA-E2E-007 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/analytics title=Not Found 404=true 500=false
- QA-E2E-008 — Digital Assets Open: Navigated to http://127.0.0.1:8013/app/assets/search-console title=Not Found 404=true 500=false

### HIGH

count: 0

(none)

### MEDIUM

count: 13

- QA-E2E-009 — Dashboard: "Open brands" [a]; "Review findings" [a]
- QA-E2E-010 — Customers: "INDUSTRY" [button]; "BRANDS" [button]; "OPEN TASKS" [button]; "Open" [a]; "INDUSTRY" [th]; "BRANDS" [th]; "OPEN TASKS" [th]; "STATUS" [th]
- QA-E2E-011 — Customer setup: "New Customer setup" [h1]; "Back" [button]; "Customer name" [label]
- QA-E2E-012 — Brands: "OPEN TASKS" [button]; "Open" [a]; "Search" [label]; "OPEN TASKS" [th]
- QA-E2E-013 — Digital Assets: "Digital Assets" [h1]; "Needs Attention" [button]; "Open" [a]; "Responsible" [label]; "Search" [label]; "WORK" [th]; "Portfolio" [p]
- QA-E2E-014 — Integrations: "Configure" [a]
- QA-E2E-015 — dashboard: Horizontal overflow scrollWidth=860 clientWidth=768
- QA-E2E-016 — customers: Horizontal overflow scrollWidth=860 clientWidth=768
- QA-E2E-017 — digital-assets: Horizontal overflow scrollWidth=860 clientWidth=768
- QA-E2E-018 — customer-detail: Horizontal overflow scrollWidth=860 clientWidth=768
- QA-E2E-019 — brand-detail: Horizontal overflow scrollWidth=860 clientWidth=768
- QA-E2E-020 — digital-assets: Horizontal overflow scrollWidth=441 clientWidth=390
- QA-E2E-CITY-FIELD — Customer form: Country=SEARCHABLE_SELECT options=172; City helper="Search suggestions or enter a city." searchable=true allowCustom=true classified=SUSPICIOUS_FREE_TEXT

### LOW

count: 1

- QA-E2E-BRAND-IA — Brand workspace: Top tabs=["Overview","Business","Digital Estate","Growth","Operations","Value"]; Business sub-nav Context+Public Discovery visible=true; Context as peer top-tab=false

## Issue details

### QA-E2E-003

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/website

Action: Open unscoped Website specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/website title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/website/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-website.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small

Manual ID: QA-MANUAL-007

### QA-E2E-004

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/gbp

Action: Open unscoped Google Business Profile specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/gbp title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/gbp/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-google_business_profile.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small


### QA-E2E-005

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/google-ads

Action: Open unscoped Google Ads specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/google-ads title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/google-ads/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-google_ads.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small


### QA-E2E-006

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/meta

Action: Open unscoped Meta Ads specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/meta title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/meta/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-meta_ads.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small


### QA-E2E-007

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/analytics

Action: Open unscoped Google Analytics specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/analytics title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/analytics/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-ga4.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small


### QA-E2E-008

Severity: BLOCKER
Surface: Digital Assets Open
route: /app/assets/search-console

Action: Open unscoped Google Search Console specialist URL (same URL generated by route($asset['route']) without assetId)

Observed: Navigated to http://127.0.0.1:8013/app/assets/search-console title=Not Found 404=true 500=false

Expected: Specialist workspace for the persisted asset id, e.g. /app/assets/search-console/{id}

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/asset-open-unscoped-gsc.png

Likely source: OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId

Recommended fix scope: small


### QA-E2E-009

Severity: MEDIUM
Surface: Dashboard
route: http://127.0.0.1:8013/app

Action: TR localization sweep

Observed: "Open brands" [a]; "Review findings" [a]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-dashboard.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium

Manual ID: QA-MANUAL-001

### QA-E2E-010

Severity: MEDIUM
Surface: Customers
route: http://127.0.0.1:8013/app/customers

Action: TR localization sweep

Observed: "INDUSTRY" [button]; "BRANDS" [button]; "OPEN TASKS" [button]; "Open" [a]; "INDUSTRY" [th]; "BRANDS" [th]; "OPEN TASKS" [th]; "STATUS" [th]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-customers.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium

Manual ID: QA-MANUAL-002

### QA-E2E-011

Severity: MEDIUM
Surface: Customer setup
route: http://127.0.0.1:8013/app/setup?entry=customer

Action: TR localization sweep

Observed: "New Customer setup" [h1]; "Back" [button]; "Customer name" [label]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-customer-setup.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium

Manual ID: QA-MANUAL-003

### QA-E2E-012

Severity: MEDIUM
Surface: Brands
route: http://127.0.0.1:8013/app/brands

Action: TR localization sweep

Observed: "OPEN TASKS" [button]; "Open" [a]; "Search" [label]; "OPEN TASKS" [th]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-brands.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium


### QA-E2E-013

Severity: MEDIUM
Surface: Digital Assets
route: http://127.0.0.1:8013/app/assets

Action: TR localization sweep

Observed: "Digital Assets" [h1]; "Needs Attention" [button]; "Open" [a]; "Responsible" [label]; "Search" [label]; "WORK" [th]; "Portfolio" [p]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-digital-assets.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium


### QA-E2E-014

Severity: MEDIUM
Surface: Integrations
route: http://127.0.0.1:8013/app/integrations

Action: TR localization sweep

Observed: "Configure" [a]

Expected: Operator chrome from lang/tr/operator.php — no English product chrome leakage

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/i18n-tr-integrations.png

Likely source: Hard-coded Blade copy or missing __() keys

Recommended fix scope: medium


### QA-E2E-015

Severity: MEDIUM
Surface: dashboard
route: http://127.0.0.1:8013/app

Action: tablet 768x1024 overflow check

Observed: Horizontal overflow scrollWidth=860 clientWidth=768

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: tablet-dashboard.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-016

Severity: MEDIUM
Surface: customers
route: http://127.0.0.1:8013/app/customers

Action: tablet 768x1024 overflow check

Observed: Horizontal overflow scrollWidth=860 clientWidth=768

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: tablet-customers.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-017

Severity: MEDIUM
Surface: digital-assets
route: http://127.0.0.1:8013/app/assets

Action: tablet 768x1024 overflow check

Observed: Horizontal overflow scrollWidth=860 clientWidth=768

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: tablet-digital-assets.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-018

Severity: MEDIUM
Surface: customer-detail
route: http://127.0.0.1:8013/app/customers/1

Action: tablet 768x1024 overflow check

Observed: Horizontal overflow scrollWidth=860 clientWidth=768

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: tablet-customer-detail.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-019

Severity: MEDIUM
Surface: brand-detail
route: http://127.0.0.1:8013/app/brands/1

Action: tablet 768x1024 overflow check

Observed: Horizontal overflow scrollWidth=860 clientWidth=768

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: tablet-brand-detail.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-020

Severity: MEDIUM
Surface: digital-assets
route: http://127.0.0.1:8013/app/assets

Action: mobile 390x844 overflow check

Observed: Horizontal overflow scrollWidth=441 clientWidth=390

Expected: No horizontal overflow of operator chrome

Automated reproduction: YES

Evidence: mobile-digital-assets.png

Likely source: —

Recommended fix scope: medium


### QA-E2E-CITY-FIELD

Severity: MEDIUM
Surface: Customer form
route: /app/customers/create

Action: Audit HQ city widget

Observed: Country=SEARCHABLE_SELECT options=172; City helper="Search suggestions or enter a city." searchable=true allowCustom=true classified=SUSPICIOUS_FREE_TEXT

Expected: Country controlled; City should be a country-dependent controlled/searchable select when a catalog exists.

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/customer-form-selects.png

Likely source: resources/views/livewire/demo/portfolio/customer-form.blade.php + CityOptions allow-custom

Recommended fix scope: small

Manual ID: QA-MANUAL-004

### QA-E2E-BRAND-IA

Severity: LOW
Surface: Brand workspace
route: http://127.0.0.1:8013/app/brands/1?tab=business

Action: Inspect Brand / Business navigation

Observed: Top tabs=["Overview","Business","Digital Estate","Growth","Operations","Value"]; Business sub-nav Context+Public Discovery visible=true; Context as peer top-tab=false

Expected: Brand → Overview / Business (Context, Public Discovery) / Digital Estate / Growth / Operations / Value

Automated reproduction: YES

Evidence: /workspace/.qa-artifacts/screenshots/brand-business-ia.png

Likely source: resources/views/livewire/demo/portfolio/brand-show.blade.php — Context/Public Discovery are Business sub-tabs; Overview also exposes a Business context shortcut

Recommended fix scope: small

Manual ID: QA-MANUAL-005


## Known manual findings

| ID | Claim | Result |
| --- | --- | --- |
| QA-MANUAL-001 | Dashboard remaining English buttons | CONFIRMED |
| QA-MANUAL-002 | Customers forms/table/dropdowns English chrome | CONFIRMED |
| QA-MANUAL-003 | Customer Setup substantially English | CONFIRMED |
| QA-MANUAL-004 | Country controlled but City free text | CONFIRMED |
| QA-MANUAL-005 | Brand Business nav hierarchy confusing | PARTIAL |
| QA-MANUAL-006 | Public Discovery has no run/data | CONFIRMED |
| QA-MANUAL-007 | Website Open → /app/assets/website 404 | CONFIRMED |

Evidence lives in `.qa-artifacts/screenshots/` and findings above.

## WEBSITE 404

- reproduced: YES
- clicked from: `/app/assets` Open action on Website card/row
- source URL: `/app/assets`
- generated target: `/app/assets/website`
- final URL: `http://127.0.0.1:8013/app/assets/website`
- HTTP / UI: 404 Not Found UI
- exact root cause: `OperatorPortfolioPresenter` sets `'route' => self::specialistRoute($type)` (route **name** only). Blade Open buttons call `route($asset['route'])` with **no** `assetId`. Named route `operator.website` is `/assets/website/{assetId?}`, so the generated URL is `/app/assets/website`. `WebsiteOverviewPage` binds via `OperatorCanonicalAsset::require()` which **aborts 404** when `assetId` is empty/non-digit.
- expected canonical target: `route('operator.website', ['assetId' => $asset->id])` → `/app/assets/website/{id}` (same pattern for GBP / Google Ads / Meta / GA4 / GSC)
- release blocking: **YES** if Open 404 is confirmed

Related Open results:

- website: href=`/app/assets/website` final=`http://127.0.0.1:8013/app/assets/website` 404=true 500=false
- google_business_profile: href=`/app/assets/gbp` final=`http://127.0.0.1:8013/app/assets/gbp` 404=true 500=false
- google_ads: href=`/app/assets/google-ads` final=`http://127.0.0.1:8013/app/assets/google-ads` 404=true 500=false
- meta_ads: href=`/app/assets/meta` final=`http://127.0.0.1:8013/app/assets/meta` 404=true 500=false
- ga4: href=`/app/assets/analytics` final=`http://127.0.0.1:8013/app/assets/analytics` 404=true 500=false
- gsc: href=`/app/assets/search-console` final=`http://127.0.0.1:8013/app/assets/search-console` 404=true 500=false

## I18N

- TR leakage count: 25
- EN leakage count: 0
- top affected TR surfaces: Dashboard, Customers, Customer setup, Brands, Digital Assets, Integrations
- hard-coded source copy count: 1671
- database translation duplication found: **NO** (audit did not find per-language UI chrome columns; agency/user locale is a setting, not duplicated product copy)
- recommended localization architecture: keep static operator chrome in `lang/en/operator.php` + `lang/tr/operator.php` (`__('operator.*')`). Store dynamic Customer/Brand/provider facts once. Convert remaining Blade/PHP English literals to language keys. Do not add translated DB columns for chrome.

Should static product copy be localized through language resources rather than per-language DB columns?

**YES**

### TR leakage sample

- `http://127.0.0.1:8013/app` — "Open brands" (a)
- `http://127.0.0.1:8013/app` — "Review findings" (a)
- `http://127.0.0.1:8013/app/customers` — "INDUSTRY" (button)
- `http://127.0.0.1:8013/app/customers` — "BRANDS" (button)
- `http://127.0.0.1:8013/app/customers` — "OPEN TASKS" (button)
- `http://127.0.0.1:8013/app/customers` — "Open" (a)
- `http://127.0.0.1:8013/app/customers` — "INDUSTRY" (th)
- `http://127.0.0.1:8013/app/customers` — "BRANDS" (th)
- `http://127.0.0.1:8013/app/customers` — "OPEN TASKS" (th)
- `http://127.0.0.1:8013/app/customers` — "STATUS" (th)
- `http://127.0.0.1:8013/app/setup?entry=customer` — "New Customer setup" (h1)
- `http://127.0.0.1:8013/app/setup?entry=customer` — "Back" (button)
- `http://127.0.0.1:8013/app/setup?entry=customer` — "Customer name" (label)
- `http://127.0.0.1:8013/app/brands` — "OPEN TASKS" (button)
- `http://127.0.0.1:8013/app/brands` — "Open" (a)
- `http://127.0.0.1:8013/app/brands` — "Search" (label)
- `http://127.0.0.1:8013/app/brands` — "OPEN TASKS" (th)
- `http://127.0.0.1:8013/app/assets` — "Digital Assets" (h1)
- `http://127.0.0.1:8013/app/assets` — "Needs Attention" (button)
- `http://127.0.0.1:8013/app/assets` — "Open" (a)
- `http://127.0.0.1:8013/app/assets` — "Responsible" (label)
- `http://127.0.0.1:8013/app/assets` — "Search" (label)
- `http://127.0.0.1:8013/app/assets` — "WORK" (th)
- `http://127.0.0.1:8013/app/assets` — "Portfolio" (p)
- `http://127.0.0.1:8013/app/integrations` — "Configure" (a)

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

SMALLEST_SAFE_FIX: keep the data model; visually nest Context / Public Discovery under Business (indent or a labelled sub-nav, hide the Overview shortcut or rename it). Do not promote Context/Public Discovery to peer top tabs. Do not redesign in this audit.

## CITY FIELD

- current behavior: HQ country is a searchable controlled ISO select (`CountryOptions`). HQ city is `x-ta.form.select` with `allow-custom="true"` plus helper "Search suggestions or enter a city." Classification: **SUSPICIOUS_FREE_TEXT**. Helper: "Search suggestions or enter a city.". Validation is free-text `max:120`. Custom values are intentionally not cleared when they are outside `CityOptions`.
- existing country/city source: **YES** — `app/Support/Options/CountryOptions.php` (ISO catalog) and `app/Support/Options/CityOptions.php` (lightweight suggestions keyed by ISO country; not exhaustive)
- recommended behavior: keep Country controlled; make City a country-dependent searchable select using `CityOptions::optionsForCountry()` (already wired). Allow custom only as an explicit overflow, or drop custom if the product wants a closed list for known countries.
- dependent City select feasible without a new truth store: **YES**
- scope: small (remove or gate `allow-custom`, optionally hide City until Country is chosen)

## SAFETY

- live API calls: NONE (Public Discovery refresh only flashes a local "has not run" message)
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

- `.qa-artifacts/screenshots/asset-open-ga4.png`
- `.qa-artifacts/screenshots/asset-open-google_ads.png`
- `.qa-artifacts/screenshots/asset-open-google_business_profile.png`
- `.qa-artifacts/screenshots/asset-open-gsc.png`
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
- `.qa-artifacts/screenshots/mobile-customer-detail.png`
- `.qa-artifacts/screenshots/mobile-customers.png`
- `.qa-artifacts/screenshots/mobile-dashboard.png`
- `.qa-artifacts/screenshots/mobile-digital-assets.png`
- `.qa-artifacts/screenshots/settings.png`
- `.qa-artifacts/screenshots/smoke-settings.png`
- `.qa-artifacts/screenshots/specialist-ga4-12.png`
- `.qa-artifacts/screenshots/specialist-ga4-18.png`
- `.qa-artifacts/screenshots/specialist-ga4-24.png`
- `.qa-artifacts/screenshots/specialist-ga4-30.png`
- `.qa-artifacts/screenshots/specialist-ga4-5.png`
- `.qa-artifacts/screenshots/specialist-google_ads-10.png`
- `.qa-artifacts/screenshots/specialist-google_ads-16.png`
- `.qa-artifacts/screenshots/specialist-google_ads-22.png`
- `.qa-artifacts/screenshots/specialist-google_ads-28.png`
- `.qa-artifacts/screenshots/specialist-google_ads-3.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-15.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-2.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-21.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-27.png`
- `.qa-artifacts/screenshots/specialist-google_business_profile-9.png`
- `.qa-artifacts/screenshots/specialist-gsc-13.png`
- `.qa-artifacts/screenshots/specialist-gsc-19.png`
- `.qa-artifacts/screenshots/specialist-gsc-25.png`
- `.qa-artifacts/screenshots/specialist-gsc-31.png`
- `.qa-artifacts/screenshots/specialist-gsc-6.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-11.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-17.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-23.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-29.png`
- `.qa-artifacts/screenshots/specialist-meta_ads-4.png`
- `.qa-artifacts/screenshots/specialist-website-1.png`
- `.qa-artifacts/screenshots/specialist-website-14.png`
- `.qa-artifacts/screenshots/specialist-website-20.png`
- `.qa-artifacts/screenshots/specialist-website-26.png`
- `.qa-artifacts/screenshots/specialist-website-8.png`
- `.qa-artifacts/screenshots/tablet-brand-detail.png`
- `.qa-artifacts/screenshots/tablet-customer-detail.png`
- `.qa-artifacts/screenshots/tablet-customers.png`
- `.qa-artifacts/screenshots/tablet-dashboard.png`
- `.qa-artifacts/screenshots/tablet-digital-assets.png`
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

Do not fix product issues in this baseline.

Autonomous browser QA baseline is complete. Use this report to create the first E2E-driven product bugfix batch.
