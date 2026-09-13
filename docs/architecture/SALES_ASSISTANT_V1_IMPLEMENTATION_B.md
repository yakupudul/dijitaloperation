# SALES ASSISTANT V1 — IMPLEMENTATION BATCH B

Status: **Historical V1; free monitoring extension below is CODED / UAT REQUIRED** (pre-analysis reporting + conversion + Intent Radar)

Authority: `docs/architecture/SALES_ASSISTANT_V1_CONVERGENCE_AUDIT.md`, Batch A `docs/architecture/SALES_ASSISTANT_V1_IMPLEMENTATION_A.md`


## 2026-09-13 — Free public-source Intent Radar (staging)

Owner authorization: implement the agreed fastest no-paid-API/no-AI source-monitoring slice;
write directly to chatgpt/search-demand-foundation, no main/PR/clone/test/server deployment.
The current free path supersedes the paid-only UI described in the historical Batch B below.

- Existing SalesSearchProfile/Signal/RadarRun and sales activity history are reused. Profiles
  may bind the global ServiceCatalogItem and its live names/matching keywords, with optional
  additional/excluded terms and a location expression. Built-in aliases cover website, SEO,
  Google Ads and social advertising. Rule scores are heuristic, never purchase probabilities.
- FreeIntentRadar + RunFreeIntentRadar use the existing default worker; scheduler every five
  minutes admits one agency-wide job. Default per-profile cadence is hourly or daily. No
  DataForSEO, AI, Google scraping, browser service, new dependency or outbound message.
- Migration seeds four public category URLs: WM Aracı (job requests, Ads, SEO) and
  R10 software/web job requests, not sample leads. Operators can add same-origin RSS/Atom or public HTML lists, capped at 20 sources.
  This is bounded source monitoring, not automatic whole-web/source discovery.
- Shared source/page cache prevents per-service repeated fetches. A job reads at most two
  due lists and two due detail pages; robots is cached separately for one hour. Lists yield
  at most 100 candidates, matching examines the latest 500 candidates. Remaining due work
  gets another scheduled pass. HTML discovery requires a demand-bearing link title.
- PublicHttpFetcher/PublicUrlSafety are reused. Robots disallow fails closed; unavailable
  robots, source errors and unrecognized content are shown rather than reported as no demand.
  Same-host page identity preserves URL path/query. No login/cookie/CAPTCHA bypass.
- Detail extraction uses primary structured article/discussion data or known first-post
  markup; it does not classify forum replies/navigation as the original request. Missing
  content/date remains review-only. Known publication older than 30 days is excluded.
  Unknown market remains review-only. Cached details refresh daily, bounded by the queue.
- Profile+URL fingerprint is stable across edited text. Rediscovery preserves review,
  dismissal and prospect conversion. Historical paid/AI signals remain visible and labelled.
  Prospect handoff is the existing explicit conversion/research workflow.
- Source failures back off 1h to 24h. Interrupted queued/running sales runs are failed by
  the watchdog after 20 minutes; the next due profile is eligible again. Active owner
  and application access are rechecked before automatic work.
- Tests, PHP lint, formatter/build and live operator UAT were NOT run at owner request.
  Source URLs were inspected through public web research, not fetched successfully from
  this execution environment. Real staging robots/HTML/queue/parser behavior remains UAT.
  Especially missing publication markup, body-only requests, large/paginated archives and
  rapid deletions between list reads limit recall. This slice does not fix broader brand
  Public Discovery or add paid/semantic search.


---

## Report projection safety

Prospect pre-analysis uses **two strictly separated projections**, never one template with CSS hiding:

| Projection | Class / value | Shareable |
| --- | --- | --- |
| Internal | `ProspectReportProjection::Internal` (`internal`) | No |
| Client-shareable | `ProspectReportProjection::ClientShareable` (`client_shareable`) | Yes |

Builder: `App\Services\Prospects\ProspectReportProjectionService`

- Internal allowlist includes identity, inquiry, contacts, recommended **and** not-recommended services, confidence, first-meeting focus, diagnostic questions, positioning, uncertainties, operator notes, evidence refs.
- Client allowlist is explicit: company name, analysis date, website, public digital situation, observed findings, opportunities, recommended priorities, next steps, source references.
- `CLIENT_FORBIDDEN_KEYS` is asserted before a client snapshot is persisted (`assertClientSafe()`). Creating a share grant on an internal snapshot fails.

Operator UI: Prospect **Report** tab (`Overview / Research / Sales Intelligence / Report / Activity`).

---

## Snapshot reuse

Brand `ReportSnapshot` is customer/brand-owned and cannot host Prospect reports without a polymorphic expansion. Batch B therefore adds **prospect-native** snapshot tables while reusing:

- `ReportSnapshotChecksum` / canonical JSON hashing
- immutability (`ProspectReportSnapshot::save()` rejects dirty updates)
- `barryvdh/laravel-dompdf` via `ProspectReportPdfRenderer` (`prospect_pre_analysis_pdf_v1`)
- `SecretHasher` for share locator tokens

Tables: `prospect_report_snapshots`, `prospect_report_artifacts`, `prospect_report_share_grants`.

Historical snapshots are frozen. Later research does not mutate an already generated checksummed payload. Generate Client Pre-Analysis is operator-explicit. No automatic email or WhatsApp.

Public share routes (no operator session):

- `GET /prospect-reports/share/{token}`
- `GET /prospect-reports/share/{token}/pdf`

Share HTML/PDF render **only** the client snapshot payload.

---

## Conversion semantics

CTA: **Convert to Customer** (`/app/prospects/{id}/convert`).

- Setting Prospect status to `won` does **not** create Customer/Brand.
- Conversion writes canonical `Customer` → `Brand` (and optional `DigitalAsset` / `BrandIntelligenceContext`) using existing Eloquent invariants.
- Success stamps `converted_customer_id`, `converted_brand_id`, `converted_at`.
- Second convert returns the existing links (idempotent). CTA becomes **Open Customer** / **Open Brand**.
- Prospect, research runs, evidence, sales intelligence, report snapshots, and activities remain historically readable.

---

## Duplicate rules

`ProspectDuplicateDetector` uses **strong signals only** (no fuzzy merge):

- exact normalized company / legal / brand name
- exact normalized contact email
- exact normalized phone
- exact normalized website domain on `DigitalAsset` type `website`

Matching Digital Assets also surface their owning Brand and Customer so the operator can reuse them.

If duplicates exist, conversion requires:

- select existing Customer and/or Brand, or
- explicit `confirm_create_despite_duplicates`

---

## Asset / accepted-fact promotion

During conversion, **owned** properties may be offered:

- official website (supported)
- Instagram when discovered as `social_links` (supported)
- Facebook / LinkedIn / YouTube — shown as **unsupported** (not in `DigitalAssetTypes`); not created

Only operator-selected, supported, non-duplicate assets are inserted.

Optional `promote_observed_summary` writes `BrandIntelligenceContext.business_summary` with `SOURCE_PUBLIC_DISCOVERY` when no BIC row exists. Prospect evidence stays on the Prospect.

---

## SearchProfile

Model: `SalesSearchProfile` (`sales_search_profiles`)

Fields: name, `service_definition_code`, language, country, location, include/exclude concepts, `minimum_intent_confidence`, active, `owner_user_id`.

Operator examples (not a system catalog): “Web Sitesi Arayan İşletmeler”, “Google Ads Ajansı Arayanlar”.

IA: Sales subnav — Prospects | Intent Radar | Search Profiles. No extra top-level sidebar group.

---

## IntentSignal

Model: `SalesIntentSignal` (`sales_intent_signals`)

Statuses: `new`, `reviewed`, `converted_to_prospect`, `dismissed`.

Always stores:

- search snippet (`observed_snippet`) separately from fetched excerpt (`fetched_source_excerpt`)
- `source_verification_state`: `unverified` | `verified` | `unreachable`
- fingerprint (profile + service + normalized URL + snippet hash)
- provenance (provider, capability, query, retrieval method)
- identity may be unknown / anonymous

Rediscovery updates `last_seen_at` instead of inserting a duplicate row.

---

## Intent classification

Capability / AI:

| Item | Value |
| --- | --- |
| Capability | `public.intent.search` |
| AI route | `sales.intent_classification` |
| Agent | `sales.intent_classification_analyst` |
| Skill | `intent-sales-qualification` |

Structured fields: `purchase_stage` (`high_intent` / `informational` / `unknown`), confidence, catalog `service_definition_code`, reason, negative signals, identity. AI must not invent source text or create ServiceDefinitions. Without AI, classification status is `unavailable` (no fake scores). Fixture mode classifies the two deterministic observations for PHPUnit/Playwright.

---

## Provider architecture

One V1 path: **DataForSEO** `serp/google/organic/live/regular` (`DataForSeoIntentSearchAdapter`).

- `serp/google/organic/live/advanced` remains **rejected**.
- Bounded query plan: include phrases capped at 5 (`IntentQueryPlanner`).
- One failed query does not wipe sibling results (`partial` run).
- Provider reality: `real` | `partial` (fixtures) | `unavailable`.
- Production does **not** fall back to demo SERP results.

---

## Paid-call policy

Config `moxdop.sales_intent_discovery`:

- `paid_calls_enabled` ← `MOXDOP_SALES_INTENT_PAID_CALLS` **default false**
- `fixtures` ← `MOXDOP_INTENT_SEARCH_FIXTURES` (non-production only)
- hard caps: max 5 queries / 10 results

No page-load, Prospect create, or scheduler may trigger paid search. Operator must click **Run Search**. If paid policy is on, UI shows a credit warning and requires `paid_consent`. Cost is persisted only when the provider returns `cost`.

V1.1 may attach recurring SearchProfile runs to the existing scheduler. **Not now.**

---

## Source provenance

Every signal keeps source URL, provider, discovered time, retrieval method, and verification state. Fetch uses `PublicUrlSafety` + `PublicHttpFetcher`. No CAPTCHA/login bypass, no proxy rendering of remote HTML. **Open Source** is a normal `target=_blank` link (`rel="noopener noreferrer"`).

No native LinkedIn / Armut / Ekşi / Facebook / Instagram / Reddit scrapers.

---

## Signal → Prospect handoff

Explicit **Create Prospect**:

- prefills company only when detected; otherwise anonymous name
- website only when a domain was actually identified
- inquiry = observed snippet + source URL
- source = `intent_radar`
- links `intent_signal.prospect_id`
- Prospect Activity `prospect.created_from_intent_signal`
- Research / Sales Intelligence reuse Batch A (`ProspectResearchService`) — no second pipeline
- no automatic outreach

---

## Known V1.1 / later gaps

V1.1:

- recurring SearchProfile schedules
- additional intent source adapters
- provider health dashboard
- richer asset promotion (GBP / additional owned properties)
- stronger fuzzy duplicate review
- cost/budget refinements
- post-conversion handoff polish

Later:

- native platform-specific source adapters
- broader reputation / social / news intelligence
- generalized capability router
- automated sector learning
- deeper proposal assistance
