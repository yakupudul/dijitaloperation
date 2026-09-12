# PRODUCT_CAPABILITY_LEDGER

## Account admission starvation and GA4 empty landing values — 2026-09-12

Operator supplied post-deploy GA4/Ads HTML: automatic job #86 is active, but Ads shows 56
eligible accounts, two with historical facts and zero active Ads transfers. GA4 exposes a
PERSISTENCE rejection for an empty `landingPage`. HTML alone does not reveal all scheduler,
queue or account rows, so live root-cause closure is not claimed.

Fixed source-level blockers: account admission now mirrors the existing dedicated Google Ads
worker and shared non-Ads worker, with two active/planning accounts per lane (four total by
default). Each lane selects its own due candidates, so a GA4/GSC backlog cannot exhaust Ads
admission or hide Ads behind the per-tick limit. Active/planning IDs are deduplicated. Provider
worker concurrency, quotas, pauses, authentication checks and MCC exclusion remain authoritative.

GA4 landingPage empty strings remain exact provider values, distinct from `(not set)` and `/`.
The effective storage overlay explicitly allows empty text only for the landingPage dimension
in landing-page daily/event-landing daily datasets. Null/missing scope keys remain invalid.
Malformed dimension positions/types fail normalization, with no checkpoint advance.

The existing automation settings/status panel is now visible on Ads, GA4 and GSC connector
pages. Staging deployment invokes due admissions and selectively rearms enabled automations
stopped by this exact historical landing-key rejection. Scheduled ticks do not blanket-reset
attention states; paused/revoked accounts are excluded from the repair.

Verification: regression tests cover both directions of worker-lane isolation, exact failure
recovery without unpausing accounts, empty landing preservation and strict missing keys.
PHP/Pint remain unavailable; targeted PHPUnit/Pint commands could not execute. `git diff --check`
and `bash -n deploy/staging/deploy.sh` pass. Live staging/provider/UAT acceptance remains pending.

## Collection visibility and interrupted workers — 2026-09-12

Staging branch `chatgpt/search-demand-foundation`: the header lists global active jobs with
site/account, manual/automatic origin, queue/retry/stalled state, completed dataset count and
last dataset activity. It no longer presents an equal-weight aggregate as a crawl percentage.
The detail panel links to existing background operations. Successful website console counters
are explicitly dataset completion, not URL coverage. Fresh running work takes precedence over
old queued dependents when classifying stalled work.

Scheduled collection recovery now reclaims running datasets only after both activity and the
execution lease expire (at least 30 minutes). Checkpoints and completed datasets are retained;
three resumes at an unchanged checkpoint are allowed, then the dataset fails visibly. Failed
prerequisites settle their queued dependents; terminal children reconcile unfinished parents.
An executor returning after its lease was replaced cannot advance that run's checkpoint/state.
Delayed continuations persist `retry_at`, so database workers and early queue deliveries respect
provider backoff. Redispatch alone no longer records fictitious dataset progress.

WordPress reconciliation reuses a recent completed full/manual inventory with all five WP
datasets complete, without advancing pending content-event cursors. Access-role-only events
do not trigger another full inventory. Daily/three-day CMS inventory and bounded event refresh
remain; general public crawl and PageSpeed still require explicit collection. Cross-run HTTP
conditional revalidation and adaptive URL scheduling are not implemented by this change.

Verification: focused PHPUnit regressions added for recovery bounds, live leases, dependencies,
orphan parents, delayed continuation, header visibility/stall classification, inventory reuse
and access-only events. `git diff --check` passes. PHPUnit could not execute (`php` unavailable);
Pint could not execute (no vendor installation). Live provider, worker and operator UAT remain
unverified. No schema or connector package change is required.

## Automatic collection recovery — 2026-09-11

Source fixes on `chatgpt/search-demand-foundation`: paired WordPress connections initialize
inventory scheduling without an incoming heartbeat (including existing installations on the next
tick). Signed status refresh updates the installed version without inventing event receipts.
Full inventories work with V1; changed-object scopes still require 1.1.0. Pause/cursors persist.

New discovered accounts become due immediately under the existing two-slot bound. Old unused
initial delays are recovered, while pauses and error backoff remain. Terminal parents no longer
consume slots through stale resource rows; Google Ads repairs their unfinished datasets. A new
planner clears its prior run reference so the scheduler cannot mistake a previous failure for
the new attempt. Missing run references use bounded retry. Staging planning jobs target the
existing Redis/Horizon worker; non-durable queue configuration fails explicitly. Meta/GBP resume
after the required real binding appears; unbound collection is still unsupported and no binding
is invented. This is not a claim that all discovered Meta accounts can collect without mapping.

Verification: regression tests added for capacity, initial delays, paused accounts, binding recovery,
missing/previous runs, sink rejection, WordPress bootstrap and preserved cursors. `git diff --check`
passes. PHP, Composer dependencies and Pint are unavailable here; PHPUnit/Pint could not execute.
Live scheduler/worker/provider UAT and deployment remain unverified. No migration or plugin ZIP
update is required for these server-side fixes.

> Manual query clusters, 2026-09-09: source-reviewed staging implementation only. No tests, migrations, queue, build or UAT executed.

> **Canonical product capability truth table for MoxDOP.**  
> Public Discovery Stage 1 update: 2026-09-07, `chatgpt/search-demand-foundation`. 70 targeted tests / 365 assertions passed; Pint, frontend build and Blade compilation passed. Real staging PostgreSQL, worker operation and operator UAT remain unclaimed. Contract: `docs/product/DISCOVERY_INTELLIGENCE.md` (ADR-061).
> Previous Website standards update: 2026-09-06, staging work branch `chatgpt/search-demand-foundation`; main is not evaluated by this update. No PR/merge is part of this task.
> Website verification: 63 targeted tests / 851 assertions, 39 PHP syntax checks, Pint, frontend build, Blade compilation and authenticated route checks passed. Full-suite, live-model and staging PostgreSQL/operator UAT remain unclaimed; details: `docs/product/website/WEBSITE_STANDARDS_ASSESSMENT.md`.
> Do **not** treat “IMPLEMENTED V1” in older docs as Definition-of-Done **DONE**.  
> Persistent product direction: `PROJECT_MEMORY.md`.  
> Async operator standard: `OPERATOR_ASYNC_EXECUTION.md`.

## How to read this ledger

| Column | Meaning |
| --- | --- |
| **Code** | Meaningful product code present; explicit `(branch)` rows apply only to the named work branch, never implicitly to main |
| **Automated Tests** | PHPUnit coverage for the recorded capability slice on its named branch |
| **Real UAT** | Real provider / operator UAT explicitly claimed in canonical docs |
| **Operator UX** | Filament / operator surface usable for the slice |
| **Background-ready** | Long-running operator flows actually queue and return control (not merely Job classes existing) |
| **State** | Ledger state — see `PROJECT_MEMORY.md` Definition of Done |
| **Known blocker / debt** | Explicit gaps |
| **Canonical notes** | Scope boundaries and pointers |

**States used:** `PLANNED` · `IMPLEMENTING` · `CODE COMPLETE` · `TESTED` · `UAT REQUIRED` · `UAT PASS` · `PARTIAL` · `BLOCKED` · `DONE`

**Inspection rules used for this snapshot:**

- Unmerged PR code is **not** main.
- Job classes implementing `ShouldQueue` without operator `dispatch` ≠ background-ready.
- Filament actions that call `(new SomeJob(...))->handle(...)` or inline services are **synchronous**.
- “IMPLEMENTED V1” in product docs = version label / scoped slice, not automatic **DONE**.

---

## Capability ledger

| Capability | Code | Automated Tests | Real UAT | Operator UX | Background-ready | State | Known blocker / debt | Canonical notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Manual service query clusters (four-step operator roadmap) | YES (staging branch) | NO — operator instruction | NO | Bilingual tree/table, filters, bulk selection/move, central rename, child CRUD/merge, receipts/undo, CSV, Website target plans | YES — 250-row queued operations + durable progress/Activity; confirmation snapshot is one synchronous SQL statement | **CODE COMPLETE / UAT REQUIRED** | No runtime, migration, queue, load or visual validation; 100k throughput unmeasured. Existing import caps unchanged. No automatic export/receipt retention. Legacy Brand AI clusters preserved separately; downstream bridging is later scope. | Global service is root; one child level on existing query-service associations; no AI or DataForSEO call. Snapshot/revision guards protect subsequent changes. Source and scope: docs/product/SEARCH_DEMAND_INTELLIGENCE.md, manual-clusters section. |
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Operator `/customers` `/brands`; Filament `/admin` technical CRUD |
| Digital Assets | YES | YES | NO | YES | PARTIAL | TESTED | Long actions migrated to queue; short cross-asset checks still sync | Operator `/assets`; types include website, google_ads, gbp, meta_ads, instagram |
| MoxDOP Intelligence Core | YES (branch) | NO | NO | N/A | N/A | **CODE COMPLETE** | Tests and live UAT intentionally not run. Formula-to-Evidence consumers and additional provider adapters remain later milestones. | ADR-046/047; versioned registry + capability/metric contracts + Page/Search Term/Entity/Business Action identities and provenance aliases. Provider fact tables stay canonical; existing Formula/Evidence/Finding pipeline is reused. |
| Website Intelligence Projection | YES (branch) | NO | NO | PARTIAL | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Pages & Content, Technical Health, Infrastructure & WordPress and Data Sources consume projection profiles; Search Console and Google Analytics specialist drill-ins remain directly available. Remaining cross-source Website tabs do not yet consume their target projections. Live projection backfill and provider/collection UAT are required. DataForSEO, GBP and AI Search adapters remain later slices. | Rebuildable 90-complete-day Page/Search Term/Entity/Outcome profiles over Website public facts, authenticated WordPress, bound GSC and bound GA4. GSC page/query period grains and GA4 landing/event period grains are explicit contracts. External source keys longer than the storage boundary use a deterministic SHA-256 key while raw identity stays in canonical facts/aliases; failed source cards expose only safe run/error-class references. Queued and synchronous rebuilds share an asset-scoped lock to prevent concurrent identity writes. Source-keyed typed states retain period, coverage, value state and provenance. Collection completion queues a rebuild; `intelligence:website-projection:rebuild` supports backfill. No generic metric warehouse, magic score, AI Finding or provider write. |
| Website Pages & Content workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run per operator request. Requires deployed projection backfill, two post-deploy public HTML observations for semantic comparison, and operator review with real Website/WordPress/GSC/GA4 coverage. Remaining Website tabs are separate phases. | Compact projection-backed Page inventory with reconciled public/CMS/platform scopes, saved operator views, deterministic pagination Page families, source-aware missing states, meaningful-vs-raw HTML change separation, mobile cards and a right-side detail drawer. Recent stored HTML versions remain authenticated, checksum-verified and non-executable plain text. Missing remains distinct from zero; the screen presents facts, not Findings. |
| Website Technical Health workspace | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires real public crawl, targeted verification, TLS and optional PageSpeed operator review. Deterministic observations are not yet promoted to Findings in this slice. | Projection-backed HTTP reachability, redirects, crawl observation severity/counts, document-head and schema facts, TLS certificate state, PageSpeed lab LCP coverage, responsive page filters/details and source-record provenance. `Verify fix` queues the selected URL plus at most 99 same-issue URLs in the same pagination family; each URL is independently fetched, versioned and only clears when the current observation disappears. It never manually resolves observations or expands into a full crawl. No opaque health score; missing is not zero; interpretation remains in Improvements. |
| Website Infrastructure & WordPress workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires projection rebuild and operator review with a real paired WordPress site. Site Health and update facts remain observations, not Findings. | Entity-projection-backed WordPress/PHP/runtime facts, safe settings, active theme, plugin/theme inventory and updates, taxonomy/feature/SEO-provider summaries, connector state and separate external TLS/Website configuration. Credentials are never exposed; missing remains distinct from zero. |
| Website Data Sources workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires operator review against real Public Website, WordPress, PageSpeed, GSC and GA4 source states. DataForSEO, GBP and AI Search cards are intentionally deferred until source adapters exist. | Projection coverage and canonical connection health are presented separately for Public Website, WordPress Connector, PageSpeed, confirmed GSC and confirmed GA4. Shows source readiness, collected-data state, watermark, honest coverage counts, Website profile contribution and links to canonical management/collection screens without duplicating raw dataset tables. |
| Canonical operator URL architecture (ADR-044) | YES | YES | NO | YES | N/A | **TESTED** | Formal live host UAT not claimed on this PR | Operator product at `/` `/login` `/customers` `/brands` `/assets` `/integrations` `/tasks`; Filament `/admin` only; legacy `/app` `/system` → 410 |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth requires external Google Cloud console; resource refresh sync | Agency Google Integration; ADR-039/040; Prompt 13+14 |
| Frozen Google Integration UI (backend state) | YES | YES | NO | YES | N/A | **TESTED** | Discovery/bind UX still PARTIAL (Prompts 15–16); connector pages still Demo | `GoogleIntegrationReadModel` + `GoogleConnectorRegistry`; docs: `GOOGLE_INTEGRATION_ARCHITECTURE.md` |
| Google OAuth & credential lifecycle | YES | YES | NO | YES | YES | **TESTED** | External Google Cloud verification/approval MANUAL; no live OAuth in CI | `GoogleOAuthService` + `GoogleCredentialBroker` + attempt store; docs: `GOOGLE_OAUTH_CREDENTIAL_LIFECYCLE.md` |
| Google resource discovery (GA4/GSC/Ads/GBP) | YES | YES | NO | YES | PARTIAL | **TESTED** | GBP/Ads external API access MANUAL; discovery sync on operator action; no auto bind | `DiscoverGoogleResourcesService` + four discoverers; operator Data Sources refresh is Admin-gated before any provider call. Docs: `GOOGLE_RESOURCE_DISCOVERY.md` |
| Google resource selection & asset binding | YES | YES | NO | YES | N/A | **TESTED** | Human confirmation required; no collection side effect; Filament `/admin` + operator Data Sources share Confirm* guards; replacement preserves binding identity | `ConfirmGoogleResourceBindingService` (+ Meta equivalent); docs: `GOOGLE_RESOURCE_SELECTION_BINDING.md` |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request; frozen bind workflow Prompt 16 | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | YES | TESTED | Async via Activity Center / database queue; real Ads UAT not re-run here | Operator Collect Now / Collect live data for GA4/GSC/Google Ads uses Collection Engine (`ExecuteCollectionLifecycleService::runNow` → `CollectionRun` / warehouse), not BoundCollector Evidence summaries. GBP remains BoundCollectorRegistry. Queued `CollectLiveBoundDataJob` still wraps the operator trigger. |
| Google Ads Intelligence | YES | YES | NO | YES | YES | TESTED | Collect + AI guidance queued; Expert Workspace not redesigned | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | YES | TESTED | Refresh data + diagnosis queued | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | YES | TESTED | SEO refresh queued when provider work needed; fresh cache stays sync | Workspace V2A, SEO Light, AI guidance. Period presets: Demo catalog/fixtures stay on `DemoPeriod::ANCHOR_DATE`; real operator/provider reads use wall-clock / `OperatorPeriod` (not `APP_ENV`). |
| Public Website Discovery — stored Stage 1 | YES (branch) | YES — 70 targeted tests / 365 assertions including regression checks | NO | YES — TR/EN review and Integration handoff | YES — existing collection + resume + missed-event recovery | **TESTED / UAT REQUIRED** | Real PostgreSQL, queue and representative Website/operator UAT remain required. Deterministic extraction can miss unstructured services. SERP, reviews, social content and continuous monitoring remain later stages. | Reads current verified stored HTML; refreshes gaps via existing public collector. Original source dates, coverage/gap examples, identity-safe dedupe, edit/map/ignore, canonical service/area/competitor receipts and social profile handoff. No basic AI/paid calls; preserves legacy decisions. ADR-061 / `docs/product/DISCOVERY_INTELLIGENCE.md`. |
| WordPress Connector V1 | YES (branch) | YES (pending gate) | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Live disposable WordPress install, pairing, signed status/snapshot, five connector datasets, versioned public HTML collection and connector↔public parity UAT are still required. Production deploy is not claimed. | Installable read-only plugin; one-time pairing; encrypted credentials; HMAC request/response; site/content/media/taxonomy/extensions/SEO snapshots. Public Discovery remains active on paired WordPress Websites. Final visitor HTML is stored separately per URL as content-addressed compressed artifacts with current/previous hashes and explicit change state. Website integration separates discovered URLs from HTML coverage and keeps interpretation in Website analysis. |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
| Global Service Catalog + Brand Service Areas | YES (branch) | PARTIAL — Discovery transfer/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full catalog and Brand-edit workflow tests were not run in this slice. City/district values are operator-entered in this slice; a maintained geography reference import remains later. | Stable agency-wide Service IDs + aliases link to existing Brand Offering IDs. Brand form captures services, explicit priority and multiple country/city/district areas without creating service × area jobs. Structured Brand Context receives compatibility projections. |
| Search Query Library | YES (branch) | NO | NO | YES | NO | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Import runs synchronously and is bounded by upload/row limits. Brand Query Portfolio, SERP validation and URL ownership are later phases. | Manual, pasted, CSV/TSV/TXT/XLSX queries with TR-safe normalization, service association, market/language, approved semantic fields, branded exclusion state, source provenance and optional Ads/GSC/DataForSEO metrics. Missing metrics stay missing. |
| AI Search Demand Librarian | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration plus queue worker and operator review. It has no provider-spend, Finding, Task, CMS or external-write capability. | Search Intelligence Analyst + generation/classification Skills; structured query, alias, family, intent, problem, stage, location, branded-suspicion and future cluster candidates. Persistent confidence/abstention/provenance; exact agent/Skill/route/input fingerprint reuse; bulk edit/approve/reject gate. |
| Brand Query Portfolio | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Global-promotion submissions need a later library-review workflow; no provider call is triggered. | Relational global-query inheritance from active Brand services, Brand-only queries, explicit Brand overrides/exclusion, dynamic multi-area `{location}` rendering, canonical `IntelligenceSearchTermIdentity` resolution and per-Website activation. No copied global query text or persistent Service × Area expansion. |
| AI Search Demand Clustering | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration, queue worker and human review. No SERP evidence is consumed in this phase, so approval remains `ai_prediction`; locked clusters reject mutation. | Three-layer demand-family/SERP-intent/content-target clusters, representative query, content-type suggestion, confidence/rationale/uncertainty, incremental new-query runs, reviewable move/merge/split proposals, manual controls and immutable version snapshots. Exact Agent/Skill/route/input reuse; no automatic Finding, Task, content or external write. |
| Search Demand Query–URL Visibility Map | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Reads only website-active portfolio items. GSC/GA4 binding and requested-period coverage may be unavailable; provider row limits apply. Missing means unknown, never zero. | Period/comparison GSC query–URL performance joined to canonical Website Page profiles, observed HTTP/robots/HTML state and page-grain GA4 landing behavior. Query/cluster/service/area/observed filters, cluster summary, query detail and URL detail; no new metrics warehouse or query-level GA4 attribution. |
| Search Demand DataForSEO / SERP Enrichment | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires active DataForSEO credentials, configured Website SEO market/language, queue worker and explicit paid consent. Live endpoint shape, reported cost and provider UAT remain unverified. An unresolved paid attempt fails closed as `CHARGE_UNKNOWN` and is never auto-retried. | Provider-neutral adapter; service/cluster-scoped max-20 batch; desktop/mobile first 10/20 organic results, SERP features and observed Brand rank; separately labelled provider-estimated volume/CPC/competition/monthly trend; exact fingerprint freshness reuse and paid locks. Optional Keyword Ideas remain human-reviewed portfolio candidates. SERP-overlap cluster status is a persisted recommendation applied only by operator approval. No auto Brand call, URL ownership, competitor library, Finding, Task or external write. |
| Search Demand URL Ownership / Page Relevance | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a rebuilt Website Page Projection, observed page language/HTTP/head facts for the fail-closed technical gate, configured AI Integration for semantic review and a queue worker. Real GSC/SERP/operator UAT is required. | One versioned human URL-owner decision per Website + content-target cluster; bounded Page candidates from existing projection, separate GSC/SERP/current-owner/term-match provenance, deterministic technical gate and two-period wrong-URL/cannibalization candidacy. Page Relevance AI can only propose an eligible URL or abstain. Approval rechecks live eligibility; lock is human-controlled. No automatic redirect, delete, merge, page creation, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitor Library / Discovery | YES (branch) | PARTIAL — reviewed Discovery handoff/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full stored SERP import and Library workflows were not exercised in this slice. Real stored SERP/domain observations and operator review require UAT. Import is synchronous and bounded to 100 distinct domains; it performs no provider request. | Brand-scoped normalized competitor domains with pending/approved/rejected lifecycle; independent commercial/SERP/content roles and business/directory/platform/authority kind; append-only source provenance, observed URLs/queries and service/area/cluster relations. Manual approved entry plus individual/bulk candidate review. Stored DataForSEO facts can establish SERP candidacy only, never automatic commercial competition. No crawl, AI analysis, Finding, Recommendation, Task or external write. |
| Search Demand Competitor Page Collection | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a queue worker, public DNS/HTTP access and approved competitors linked to a content-target cluster. Live-site response behavior and operator UAT are unverified. | Deterministic URL-hash dedupe; max 3 URLs per competitor / 20 per run; exact-URL-only SSRF-safe Public Discovery fetch with no link following; normalized text, title/meta/H1–H6, schema, bounded links and service/location expression observations. Raw/content fingerprints append history and reuse unchanged content without duplicate parsing/storage. No AI, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitive Intelligence | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner with stored checksum-valid Website HTML, successful Phase 10 observations, configured AI Integration and queue worker. Real operator/model UAT remains unverified. | Dedicated Competitive Intelligence Analyst + Skill + route; exact-fingerprint reuse; bounded stored-evidence comparison over max 8 competitor pages; proposed competitor kind/roles, page intent, topics/questions, structure, local trust, missing user needs, do-not-copy cautions and differentiation. Canonical Activity plus separate run/page proposal records and human accept/reject. Review never mutates competitor truth or URL ownership; no browsing, Finding, Recommendation, Task, publication or external write. |
| Search Demand Finding / Recommendation Planning | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner and readable own-page HTML, enabled standards, configured AI Integration and queue worker. Phase 11 context is optional (ADR-060); see tested Website standards rows below. Migration, queue/model behavior and operator promotion require staging UAT. | Deterministic technical and evidence-bounded Website Improvement AI semantic proposals; exact Agent/Skill/route/input fingerprint reuse; action taxonomy, content brief, evidence IDs, confidence, rationale, verification and abstention. Explicit operator acceptance publishes canonical Evidence → Finding evaluation → existing Finding → existing Recommendation. Rejection changes only review state; insufficient evidence cannot be promoted; Task remains a separate manual action. No browsing, content publication, Website mutation or external write. |
| Website Standards Library (phases 14–16, staging) | YES (branch) | YES (targeted) | NO | YES | N/A | **TESTED / UAT REQUIRED** | Admin catalogue controls and all Skill definitions tested locally; manual visual/operator acceptance is pending. | 26 versioned definitions, preserving 17 diagnosis IDs; groups, applicability, evidence, source, action, verification; admin toggles and up to 30 expert criteria. Existing Library records and collection/binding flow retained. |
| Independent Website Standards Assessment (staging) | YES (branch) | YES (targeted) | NO | YES | YES | **TESTED / UAT REQUIRED** | SQLite tests and route renders; live PostgreSQL/storage/worker/operator UAT pending. Limits: 500 profiles, 3,000 active queries, 100 clusters, 20 candidates/cluster. No full-site completeness claim beyond those limits. | Queue-based stored-data evaluation with zero AI/provider calls; applicability/unknown/advisory/verified outcomes, explicit technical priorities and grouped affected URLs; source/freshness reuse and changed HTML invalidation; current-evidence human promotion to canonical Website Findings/Recommendations. Existing service/query/owner coverage preserves blocked candidates and human locks. |
| Shared Website Content / Competitor Criteria (staging) | YES (branch) | YES (targeted, fake model) | NO | YES | YES | **TESTED / UAT REQUIRED** | Live model advice quality and real competitor comparison require UAT. Own page must have readable HTML within 30 days. No automatic new-page/merge decision, external publication or Task creation; global technical proposals excluded from cluster-specific Phase 13. | Selected-page standards review works without competitors; approved comparable rival context is optional. Exact criterion IDs/own-rival excerpts, non-actionable and fabricated-output rejection, source changes invalidate approval, unchanged semantic content reuse; scoped Library links preserve selection. |
| Search Demand Change / Outcome Tracking | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a completed Task from an approved Phase 12 proposal, stored pre-change HTML, a queue worker, configured AI Integration, post-change targeted crawl and real GSC/GA4/SERP coverage for full comparison. Migration, storage checksum, queue/model and operator acceptance require staging UAT. | Applied-change provenance with affected URLs/clusters and old/new HTML fingerprints; bounded exact-URL + page-family Public Crawl; deterministic technical recheck; review-only stored-evidence semantic AI; explicit GSC/GA4 periods and stored SERP comparison. Human acceptance writes the existing Task Outcome and optionally appends resolved/reconfirmed Finding evaluation. No Result entity, causal claim, automatic DataForSEO spend, publication, redirect/delete or external write. |
| DataForSEO | YES | YES | NO | YES | YES | TESTED | Paid refresh queued when not fresh; cost/freshness guards remain | Central Integration + Website SEO collectors |
| AI Control Plane | PARTIAL | YES | NO | YES | PARTIAL | PARTIAL | Capability Router / Playbooks / RAG still PLANNED; long AI guidance queued | AI Router + Agent Profiles + Skill Library V1 present |
| Website Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; no tools/MCP/Capability Router | Website SEO Analyst + Brand Discovery Analyst |
| Google Ads Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; real Ads UAT not claimed PASS | Second operational Agent after Website |
| Recommendation | YES | YES | NO | YES | N/A | TESTED | AI drafts only; humans create Recommendations | Finding → Recommendation gate |
| Tasks | YES | YES | NO | YES | N/A | TESTED | Snapshot immutability (ADR-029) | Manual Recommendation → Task |
| Outcome Loop | YES | YES | NO | YES | N/A | TESTED | Metric Outcomes / Learning Candidates not in V1 | Task outcome signals + Finding re-eval; no Result entity |
| Business Outcome aggregates (Prompt 57) | YES | YES | NO | YES | N/A | **TESTED** | Client Value Story / Report Snapshots not yet; CRM out of scope | Definition + Observation + Revision + Manual/CSV; Brand Value outcomes cards use Read Service |
| Client Value Story (Prompt 58) | YES | YES | NO | YES | N/A | **TESTED** | Report Snapshots / PDF / share not yet; Demo catalog story fixtures retained | Deterministic read projection over Findings/Opportunities/Work/Outcomes; no attribution/AI |
| Meta central Integration | YES | YES | YES | YES | NO | UAT PASS | Resource refresh still sync | Agency Meta Integration; product docs claim real UAT PASS |
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Canonical `DiscoverMetaResourcesService` (selected Business context). Operator Data Sources Meta refresh uses `refreshInventory`, not broad `me/adaccounts` enumeration. Admin-gated. |
| Meta binding | YES | YES | YES | YES | N/A | UAT PASS | Collect live data hidden without collector | Meta Ads Digital Asset ↔ AssetBinding |
| Meta Ads Intelligence | YES | YES | YES | YES (interim specialist UX) | YES | **UAT PASS / ACCEPTED — NOT DONE** | Collect + AI guidance **queued** (async foundation). Professional Meta Expert Workspace **NOT IMPLEMENTED**. Real async Meta collect UAT tracked on Async Operations PR. | Read-only Intelligence engine on main after PR #119. Ads Manager spot-check PASS retained. |
| Professional Operator Workspace (Meta Ads) | NO | NO | NO | NO | N/A | PLANNED / BLUEPRINTED | Blueprint only — no final dashboard/charts/filters built; depends on Operational Data Foundation after async | Canonical blueprints: `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` + `docs/product/META_ADS_EXPERT_WORKSPACE.md` |
| Async execution | YES | YES | YES (Cloud Meta async smoke) | YES | YES | **TESTED / ACCEPTED** | Cancellation future; cross-asset still sync; persistent public host **deferred** (templates only) | Async implementation accepted on #121 (queue + Activity + Cloud Meta smoke). Persistent deployment ≠ required for this acceptance. |
| Historical performance memory | PARTIAL | PARTIAL | NO | PARTIAL | NO | PARTIAL | No dedicated historical warehouse / backfill / incremental store | Run/Evidence history exists; Historical Performance Store **PLANNED** |
| Operational Taxonomy | NO | NO | NO | NO | N/A | PLANNED | Do not invent taxonomy module yet | Direction in `PROJECT_MEMORY.md` |
| Marketing Initiative | NO | NO | NO | NO | N/A | PLANNED | No model/service on main | Brand-level commercial effort grouping — future |
| Benchmark Cohorts | NO | NO | NO | NO | N/A | PLANNED | No cohort objects on main | Compatible taxonomy dimensions required first |
| Cross-Asset Analyst | PARTIAL | YES | NO | YES | NO | PARTIAL | Deterministic packs TESTED; Analyst persona PLANNED; jobs invoked sync | Consistency packs in Core; Digital Operations Analyst future |
| Agency Learning | NO | NO | NO | NO | N/A | PLANNED | No Learning Candidate pipeline | Human-reviewed Agency Knowledge only — future |
| Platform Engineer | NO | NO | NO | NO | N/A | PLANNED | Research reference only (e.g. OpenHands) | Not a customer-analysis runtime |
| Google Business Profile | PARTIAL | YES | NO | PARTIAL | NO | PARTIAL | Reputation Intelligence PLANNED; thin workspace vs Website/Ads | Location profile collector present |
| Finding lifecycle / fingerprint | YES | YES | NO | YES | N/A | TESTED | Unique `(digital_asset_id, fingerprint)` | Persistent Findings; ADR-034 |
| Evidence / Run model | YES | YES | NO | YES | N/A | TESTED | Foundational model; not a historical warehouse | Evidence bound to Run; no separate Result entity |
| Shared collection engine (control plane) | YES | YES | NO | NO | YES | **TESTED** | Redis/Horizon required for production collection queue | Prompt 9: `CollectionRun`→`ResourceRun`→`DatasetRun` + planner + Horizon. Docs: `docs/implementation/COLLECTION_ENGINE_ARCHITECTURE.md`. Operator Collect Now for GA4/GSC/Google Ads/Meta Ads starts this engine (not specialist Evidence collectors). GA4/GSC/Ads/Meta/Website/DFS DatasetExecutors exist on the current release stack; that does **not** make those collectors REAL/DONE without their own UAT gates. |
| Data pool / warehouse foundation | YES | YES | NO | NO | N/A | **TESTED** | Provider population not REAL; BigQuery not implemented; SQLite proves writer semantics, PostgreSQL proves partitions | Prompt 10: raw object storage + typed PostgreSQL facts + materialization. Docs: `docs/implementation/DATA_POOL_ARCHITECTURE.md` + `MOXDOP_DATA_POOL_STORAGE_V1`. |
| Persistent collection monitoring | YES | YES | NO | YES | YES | **TESTED** | Provider collectors still fake/unimplemented; Reverb optional; polling is mandatory fallback | Prompt 11: Integrations hub `MonitoringPanel` + `CollectionRunMonitorQuery`. Docs: `docs/implementation/COLLECTION_MONITORING_UX.md`. Does **not** make provider collectors REAL. |
| GA4 production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs GA4 UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period GA4`). Unique users stay non-additive. Property retention may truncate 16m. Canonical `/assets/analytics/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month backfill token, optional `newUsers`/`conversions`/`keyEvents`/`totalRevenue` on `ga4_property_daily`, YoY period compare, pool-backed Analytics screen |
| GSC production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs Search Console UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period SEARCH_CONSOLE`). Search Appearance still deferred. Canonical `/assets/search-console/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month recommended backfill, pool-backed Search Console screen, previous+YoY compare, missing≠zero |
| Google Ads production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Daily facts are successful zero-row on the bound staging account; GBP not in this slice; keyword grain now includes `ad_group_id` but staging 735 remains collapsed historical inventory (`IMPLEMENTED_UNPROVEN` until staging recollection with exact-resource `current_run_grain_proven`). Cursor Cloud cannot reach staging OAuth; operator path is `moxdop:google-ads:recollect-entity-snapshot` (`docs/operations/GOOGLE_ADS_KEYWORD_GRAIN_RECOLLECTION.md`) | Prompt 19; sibling-asset eligibility + v25 GAQL + keyword ad-group grain + pre-fact-commit checksum retry + brand-scoped Google backfill/incremental due-query (`core_asset_binding_ids`); non-keyword snapshots proven on PR #200 |
| Meta production collection (contract-driven) | YES | YES | NO | YES | YES | **PARTIAL** | Unmerged stacked child of PR #200. Live Marketing API / warehouse Collection Engine UAT **not** reachable from this Cursor Cloud agent (`META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` unset; `APP_URL=http://127.0.0.1:8000`; no staging SSH/OAuth DB). Legacy Intelligence Ads Manager UAT (`act_744654160596455`) does **not** prove this warehouse path. Google Ads keyword grain remains `IMPLEMENTED_UNPROVEN` on #200. GBP isolated. No Meta specialist UX. | Prompt 24 `MetaAdsDatasetExecutor` + Prompt 25 initial backfill + Prompt 27 incremental on the shared engine. COLLECTION_READY families: `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`. Deferred (not expanded): `RF_META_ASYNC_INSIGHTS` (async is transport inside daily/breakdown). Incremental due selection uses exact preflight `core_asset_binding_ids`; `DATA CURRENT` only when every eligible Meta dataset in that binding scope is current. Google bindings never enter Meta runs. |
| Website production crawl collection | YES | YES | NO | YES | YES | **PARTIAL / UAT REQUIRED** | Live Website crawl and authenticated WordPress Connector Collection Engine UAT remain external gates. Public collection truthfulness, per-URL idempotency, HTML artifact recovery and change detection require live UAT. Connector is not a substitute for external crawl; no production deployment is claimed. | Shared engine public families remain `WEB_RF_HTTP_HTML_DIAGNOSIS`, `WEB_RF_PUBLIC_CRAWL`, `WEB_RF_DNS_TLS`, `WEB_RF_PAGESPEED`. The resumable crawl seeds from sitemap, existing URL inventory and published connector permalinks, follows same-site links and is bounded at 5,000 pages / 2 GB. `website_html_snapshot` retains every observation while unchanged bodies reuse a content-addressed private artifact. A paired WordPress Website additionally plans `WEB_RF_WP_REST`; non-WordPress sites stay public-only. Integration shows required progress and discovered-vs-captured HTML coverage; Website analysis owns interpretations. Not DONE. |
| DataForSEO production enrichment (engine-driven) | YES | YES | NO | NO | YES | **PARTIAL** | Unmerged stacked child of PR #203. Live Marketing/Labs UAT **not** reachable (`DATAFORSEO` credentials unset in this agent; no staging SSH). Paid POST is never auto-retried / never routinely scheduled. Fail-closed `paid_attempt_started` is checkpointed before the charged POST; an unresolved attempt fail-closes that DatasetRun (`CHARGE_UNKNOWN`) even if the fingerprint recomputes. Paid request fingerprint remains the lock/cost provenance key **and** the `HIT_FRESH` pool key (asset + dataset + fingerprint, including `target` / `location_code` / `language_code`); a market change is a cache miss and POSTs again. Warehouse write idempotency is unique per DatasetRun + batch so a sibling asset or force-refresh cannot reuse another run’s committed receipt. A different fingerprint is allowed only on a new DatasetRun. Legacy SEO Evidence collectors unchanged. Domain intersection, relevant pages, and SERP organic stay DEFERRED. | Shared engine `DataForSeoDatasetExecutor` for COLLECTION_READY: `DFS-FREE-USER`, `DFS-FREE-MARKETS`, `DFS-RK-LIVE`, `DFS-KFS-LIVE`, `DFS-COMP-DOMAIN-LIVE`. Agency Integration credentials; facts are Website-asset scoped. Paid families require `paid_enrichment_consented`; competitors also require `public_discovery`. Missing search_volume/etv recorded as missing, never a measured zero. Not DONE. |
| Agency brain / operational synthesis (Phase C.1) | YES | YES | NO | N/A | N/A | **PARTIAL** | Live provider/WordPress UAT remains external and is not claimed. No BrainV2 / FindingV2 / Result entity / auto-Task / Agency Learning. Document Head only evaluates collected public dimensions on a proven homepage. WordPress maintenance and parity rules only evaluate completed connector/public DatasetRuns; update availability never implies a vulnerability. Meta/Google coverage safeguards remain unchanged. | `EvaluateFindingsForAssetJob` runs canonical `FindingEvaluationService` then `CollectedFactsAnalysisService`. Website combines `DocumentHeadEvaluator` with `WordPressCollectedFactsEvaluator` for core/plugin/theme update state, REST/cron state and connector↔published title/description/canonical parity. Google Ads and Meta adapters remain unchanged. Recommendations are deterministic; Task creation remains manual. Not DONE. |
| Operational / settings completeness (Phase D) | YES | YES | NO | YES | N/A | **PARTIAL** | Live SMTP delivery UAT and browser/mobile push remain external/deferred. No SaaS whitelabel, no second credential screen, no SettingsV2. | Canonical operator `/settings` + `/profile` + `/integrations`. Admin/Team Member lifecycle with deactivate-not-delete. Agency timezone/locale drive operator rendering via `OperatorClock` (storage clock stays `APP_TIMEZONE`); dashboard greeting/date use `OperatorClock::now(auth()->user())`. Encrypted write-only operator SMTP overlay with env fallback and test-mail action. `SendReportDeliveryJob` reloads the persisted overlay and purges the mailer at the queued send boundary so long-lived workers honor settings changes/clears. In-app notification preferences only; push not implemented. |
| End-to-end operator UX / QA (Phase E) | YES | YES | NO | YES | YES | **PARTIAL** | Staging/browser operator UAT not reachable from this Cursor Cloud agent (`APP_URL=http://127.0.0.1:8000`; empty `GOOGLE_CLIENT_ID` / DataForSEO / Meta secrets; no staging SSH). Live provider collect remains the isolated #200/#203/#204 gate. Collection Engine still rejects PHPUnit `sync` queue; production Website refresh surfaces that as unavailable rather than a fake success. No UI redesign, no push/PWA, no GBP collector reopen. | Canonical root journey `Login → Customer → Brand → Asset → Data Sources bind/collect → Activity → Evidence/Finding → Recommendation → manual Task → Outcome`. Data Sources Collect Now for GA4/GSC/Ads/Meta creates `CollectionRun` (no specialist Evidence summaries); GBP stays on BoundCollectorRegistry. Production period reads use `OperatorPeriod` / `OperatorReportingPeriod` (custom dates override DemoPeriod math). Capture note/opportunity are truthful unavailable. Google Ads/Meta `runAnalysis` queues finding evaluation. Findings/Recommendations `?asset=` isolation. Activity Center lists `CollectionRun` + async `Run`. PHPUnit: `tests/Feature/PhaseE/*`. Not DONE. |
| Production readiness / release (Phase F) | YES | YES | NO | YES | YES | **PARTIAL** | **RELEASE-CANDIDATE CODE READY WITH EXTERNAL GATES** on the dedicated RC integration branch that actually contains #202 + #199 + #200-downstream (#203→#204→#206→#207→#208→#209). PR #209 alone is **not** that cumulative ancestry. Remaining external gates: Reviewer APPROVED, SSH/deploy of this RC SHA, Google Ads keyword recollection, GBP official API, Meta/Website/DataForSEO shared-engine live UAT, live SMTP, browser push/PWA (not implemented). Do not treat Demo fixtures as production truth. GitHub `verify`/`postgres` ran on the Collect Now SHA; `gate` failed because the RC PR body quoted the Autopilot product-PR HTML marker (substring match). This RC PR is not Autopilot and must not contain that marker. | Hardening only: controller-backed retired `/app` `/system` 410 routes so `route:cache` stays deploy-safe; `moxdop:production-check` HTTPS + OAuth-callback + redacted failures; canonical docs/ADR-044 match root operator + Filament `/admin`. Operator Data Sources bind through Confirm*; Google/Meta resource refresh on that page is Admin-only and Meta uses selected-Business `refreshInventory`. PHPUnit: `tests/Feature/PhaseF/*`. Not DONE. |

---

## Critical clarifications

### Meta Ads Intelligence — UAT PASS / ACCEPTED, not DONE

PR [#119](https://github.com/yakupudul/dijitaloperation/pull/119) (*Meta Ads Intelligence + Analyst V1*) merges the **read-only Meta Ads Intelligence engine** onto main.

Accurate multidimensional state:

> **UAT PASS / ACCEPTED — NOT DONE**

Accepted operator UAT (Ads Manager manual spot-check **PASS**):

| Field | Value |
| --- | --- |
| Meta Ad Account | Obezite ve Estetik (`act_744654160596455`) |
| Campaign | `09 \| Diaspora TR \| Form - Mox` |
| Period | `2026-07-14` → `2026-08-10` |
| Result | DOP metrics matched Meta Ads Manager |

Also accepted on this slice: hierarchy collection, provider-ID joins, missing≠zero, click/result metric semantics, synthetic UAT isolation, read-only Meta client (GET only).

**Explicitly still NOT DONE / not claimable as finished Meta product:**

- **Background-ready: YES** for collect + AI guidance (queued) — Activity Center persists progress. Real async Meta collect UAT is on the Async Operations PR.
- **Professional Operator Workspace: BLUEPRINTED / PLANNED, NOT IMPLEMENTED** — current Overview/Performance is an interim UAT surface; target IA is `docs/product/META_ADS_EXPERT_WORKSPACE.md` (+ global `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Historical arbitrary querying / performance warehouse: **NO**

Do **not** describe this merge as “Meta Ads complete”, “Meta module finished”, or “Meta workspace done”.

Main also continues to include Meta central Integration + discovery + binding (connection layer), with prior product-doc real UAT PASS for that scoped slice.

### Meta production collection (contract-driven) — PARTIAL, not DONE

Contract-driven Meta Ads collection already uses the shared Collection Engine and Data Pool (`MetaAdsDatasetExecutor`, `MetaInitialBackfillOrchestrator`, `MetaIncrementalCollectionOrchestrator`). This stacked child closes the contract-to-runtime loop for COLLECTION_READY `META_ADS` families (catalog + executor kind + PHYSICAL_TABLE natural keys + freshness/backfill policy), bounded 180d historical slices with exact asset/resource provenance, DatasetWritePipeline grain/idempotency on the nine Meta physical tables, checkpoint resume for entity snapshot `step_index` and insights `work_index`, and incremental `DATA CURRENT` only when every eligible Meta dataset in the exact preflight binding scope is current.

**COLLECTION_READY families:** `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`.

**Deferred (not expanded):** `RF_META_ASYNC_INSIGHTS` — async Insights is transport inside daily/breakdown, not a separate collector family.

**UAT REQUIRED / PARTIAL:** this Cursor Cloud agent cannot reach the already UAT-proven Meta Integration OAuth path. Exact missing capability: no `META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` in process or `.env`, `APP_URL` is `http://127.0.0.1:8000`, no `.env.staging` / production secrets, no staging SSH or operator SQLite with a live Marketing API token. Do not treat PR #119 Ads Manager Intelligence UAT (`act_744654160596455`) as proof of this shared-engine warehouse path.

**Not claimed:** live Marketing API warehouse CollectionRun, professional Meta Expert Workspace, Google Ads keyword-grain staging proof (still `IMPLEMENTED_UNPROVEN` on PR #200), or GBP.

Do **not** describe this slice as “Meta collection DONE”.

### Agency brain / operational synthesis (Phase C.1) — PARTIAL, not DONE

Phase C.1 wires already-supported deterministic analyzers to collected Data Pool facts instead of Demo fixtures or live provider HTTP:

- Website/SEO: `website_metadata_snapshot` → existing Document Head rules (only collected dimensions) on the proven homepage URL (`primary_url` slash variants or the HTTP snapshot redirect target of that request). Homepage metadata, redirect HTTP, and schema rows must belong to a completed DatasetRun for this asset; running/failed crawls skip as `unproven_website_homepage_snapshot`. Multi-page crawls with a shared checkpoint `observed_at` never fall back to a higher-ID sibling such as `/contact`.
- Google Ads: bound `google_ads_campaign_daily` → existing campaign spend-with-zero-conversions rule, only from a non-partial 28-day window whose coverage dates are attributed to completed DatasetRuns (warehouse facts from those runs plus completed zero-row dates); failed paged-run slices in merged materialization metadata cannot prove the window
- Meta Ads: bound `meta_campaign_daily` + `meta_campaign_snapshot` → existing inactive-campaign-with-spend rule; daily window uses the same completed-run coverage attribution. Snapshot status is taken only from the materialization's latest successful `meta_campaign_snapshot` DatasetRun (stale entities from older completed refreshes, and running/failed/partial entity snapshots, are never current / `response_ok=true`)

Canonical production job `EvaluateFindingsForAssetJob` now runs collected-facts adapters after `FindingEvaluationService`. `FindingEvaluationService` emits `FindingEvaluationCompleted` so Outcome V1 can observe later canonical GSC/GA4 evaluations (ported from superseded PR #205; Google Ads account `conversions-decline` Evidence definition was not copied because #206 already has a campaign-grain Ads vertical). A thrown rule after eligibility emits `evaluationSuccessful: false` for that module so a crashed follow-up cannot classify `IMPROVEMENT_OBSERVED` from a stale Finding. Manual Recommendation → Task remains human. AI does not create Findings or Tasks. Live provider UAT from #200/#203/#204 is a separate external gate.

Do **not** describe this slice as “Agency brain DONE” or as proof of live Google/Meta/Website collection UAT.

### Operational / settings completeness (Phase D) — PARTIAL, not DONE

Phase D reuses the existing operator Settings/Team/Profile/Integrations surfaces. It does **not** introduce SettingsV2, UserV2, NotificationV2, SaaS whitelabel, or a second credential store.

Shipped in this slice:

- Admin-only team create/role/deactivate (no destructive delete; last admin protected)
- Operator forgot-password / reset on `/forgot-password` (inactive/unknown emails share the same success copy and receive no mail; successful reset rotates remember token and does not reactivate). Reset mail locale is set on the Notification at dispatch and in explicit `__()` calls; `MailMessage::locale()` is not used.
- Agency timezone/locale/default analytical range affect operator date rendering (`OperatorClock`) and session period defaults, including dashboard greeting/date via `OperatorClock::now(auth()->user())`. Invalid stored timezone/locale values fall back to catalog defaults. Laravel `APP_TIMEZONE` remains the storage clock (password-reset tokens / Eloquent datetimes / queue+artisan are not rewritten per operator).
- Operator SMTP overlay: encrypted write-only password, env fallback without copying env secrets into the DB, test-mail action; invalid host/port/encryption is rejected without poisoning runtime mail config; test-mail failures log exception class only; queued report send reloads the overlay and purges the resolved mailer so Horizon/worker processes do not keep a stale transport after Settings change or clear
- In-app notification preferences for existing events; browser/mobile push is **not** implemented

**Not claimed:** live SMTP provider UAT, web-push/PWA, SaaS tenant branding, or Filament as the canonical operator settings product (`/admin` remains technical).

Do **not** describe this slice as “Phase D DONE”.

### End-to-end operator UX / QA (Phase E) — PARTIAL, not DONE

Phase E is QA + narrow remediation of the canonical root operator journey. It does **not** reopen provider collection, Agency Brain analytics, Settings architecture, GBP collectors, push/PWA, or whitelabel.

Shipped in this slice:

- Production date presets/custom ranges use agency `OperatorClock` “today” (`OperatorPeriod`) and treat filled from/to as a custom range (`OperatorReportingPeriod`) so workspace period controls actually change warehouse reads
- Website overview KPIs stay `—` when the requested period does not overlap collected days (`period_has_data`); collected values outside the range are not reused as stale current KPIs. KPI summaries and `gsc_daily` still use overlap / per-day slice semantics. GSC queries/pages and GA4 landing/acquisition **undated aggregates** require an exact `requested_period` match; dated detail rows may be sliced when the Evidence period overlaps. A wider aggregate is not shown under a narrower 7-day/custom selection and is never prorated. Missing/uncollected detail datasets stay empty arrays, never numeric zero.
- Capture `note` / `opportunity` are unavailable (no DemoState persistence); `client_request` and `task` still persist
- Google Ads `createRecommendation` / `markClusterReviewed` no longer flash a fake success
- Google Ads and Meta Ads `runAnalysis` queue `FINDING_EVALUATION` instead of AI guidance
- Findings and Recommendations indexes honor `?asset=`
- Activity Center lists Collection Engine runs plus async `Run` rows (`metadata.async`)
- Website `refreshData` starts production collection when possible and surfaces Collection Engine `sync`/Redis unavailability instead of a fake refresh
- Deterministic PHPUnit journey: Customer → Brand → Website asset → GA4 bind → `Http::fake` collect → Evidence + Document Head Finding → grounded Recommendation → manual Task → later `improvement_observed`
- Demo catalog IDs remain 404 on operator routes; Atlas copy is not rendered on production website/GA4 surfaces

**Not claimed:** staging browser smoke, live provider collect UAT, Collection Engine on PHPUnit `sync` queue, or Phase E DONE.

Do **not** describe this slice as “Phase E DONE”.

### Production readiness / release (Phase F) — PARTIAL, not DONE

Phase F is release convergence/hardening. The dedicated RC integration branch is the first tested head that actually contains **#202 + #199 + #200-downstream**. PR #209’s own ancestry does **not** include #202 or #199. This slice does **not** reopen collection, Agency Brain, Settings, GBP, push/PWA, or whitelabel.

Shipped in this slice:

- Retired `/app/*` and `/system/*` 410 responses are controller-backed so staging `route:cache` remains a real deploy step
- `moxdop:production-check` verifies HTTPS/`APP_FORCE_HTTPS`/secure cookies on staging/production, canonical Google/Meta callback paths, and does not print `APP_KEY` or exception secrets
- Canonical docs (MASTER_SPEC §6/§12, ADR-044, AGENTS.md, PROJECT_MEMORY, deploy/rollback/backup/smoke) match the root operator + Filament `/admin` contract
- Focused PHPUnit: `tests/Feature/PhaseF/PhaseFReleaseReadinessTest.php`

**External gates (not claimed here):** live staging deploy from this agent (public HTTPS login is reachable at `app.moximu.com` but there is no SSH, no host `.env` access, and no ability to `git checkout` the RC SHA); Google Ads keyword exact-resource recollection; GBP official API; Meta/Website/DataForSEO shared-engine live UAT; live SMTP delivery; browser/mobile push (not implemented); Prompt 68 host backup restore drill; repository Reviewer APPROVED + stacked merge.

Do **not** describe this slice as “Phase F DONE” or as production-deployed.

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async foundation (material)

Long operator actions (bound collect, Website diagnosis, public discovery, SEO refresh when not fresh, Website/Google/Meta AI guidance) queue via `AsyncOperationService` onto Laravel **database** queue (Redis/Horizon on staging/production). Canonical execution record remains **Run** (`queued|running|completed|partial|failed`; `cancelled` reserved). Operator Activity Center is the root Livewire surface `/activity` (`operator.activity`) with phase progress, duplicate guards, stale detection, retry for safe failures, and in-app database notifications. Filament `RunResource` at `/admin/runs` remains technical/admin tooling only (ADR-044). **Cancellation** is intentionally **not** shipped (fragile with current job architecture). Cross-asset consistency packs and integration resource refresh remain synchronous by design for now.

### Async is not fully universal

On main after Async Operations merge:

- Migrated Digital Asset long actions **dispatch** queue jobs (not `(new Job)->handle()`)
- Cross-asset consistency checks may still call `->handle()` in-request (short/safe)
- Integration resource discovery refresh may still be sync
- Cancellation of in-flight provider work is **future**

Track readiness per capability in this ledger’s **Background-ready** column — do not mark Historical Store / Expert Workspace DONE because async landed.

### “IMPLEMENTED V1” ≠ DONE

Many product docs and `docs/PROJECT_STATUS.md` use **IMPLEMENTED V1** / **COMPLETED** for scoped milestones. This ledger intentionally separates:

- version / milestone labels
- Definition-of-Done **DONE**

Most coded capabilities on main are **TESTED** or **UAT PASS** (Meta connection slice) or **PARTIAL**, not **DONE**, especially while async debt remains for long-running flows.

---

## Module inventory (main)

| Module | On main | Notes |
| --- | --- | --- |
| `website` | YES | Collection, intelligence, Discovery, analysts, skills |
| `google-ads` | YES | Collector, Findings, Analyst, skills, workspace |
| `google-business-profile` | YES | First-module collector; Reputation not present |
| `meta-ads` | YES | Insights collector + Intelligence + Analyst on main after #119; async collect/AI via Core queue jobs |
| `sample-module` | YES (fixture) | Not an operator product capability |

Core owns Customer/Brand/DigitalAsset, Integrations, Run/Evidence/Finding/Recommendation/Task, and cross-asset packs.

---

## Maintenance rule

When a PR changes capability behavior or readiness:

1. Update **this ledger in the same PR**
2. Update `PROJECT_MEMORY.md` if the change is a material product / architecture decision
3. Do not mark DONE without reconciling code, tests, real UAT, operator UX, async requirement, blockers, and docs

---

## Snapshot provenance

| Field | Value |
| --- | --- |
| Base | `origin/main` (updated at PR #119 acceptance) |
| PR #119 | Ads Manager operator spot-check **PASS** — merge acceptance for Intelligence engine |
| Accepted UAT | `act_744654160596455` / `09 \| Diaspora TR \| Form - Mox` / `2026-07-14`→`2026-08-10` |
| Method | Code / test / Filament invocation / operator Ads Manager comparison |
| Guessing | Forbidden — unknown real UAT recorded as **NO** unless docs claim PASS |


## Global services management — 2026-09-07

Operator-authorized scope: Library navigation contains only Services, Search Queries, Query Clusters and Competitors. Other routes remain available to existing deep links. Services is the agency-wide editing surface: paginated searchable table, sector filter, edit drawer, sector CRUD and reversible deletion.

Global catalogue names are authoritative. A rename synchronizes linked Brand Offering primary names in one transaction, preserving offering IDs, priorities and goal/query relationships. A conflicting name aborts the entire edit. Brand-local rename of linked services directs the operator to Library. New Brand Offerings resolve the global catalogue, and the additive migration links legacy unlinked offerings without fuzzy matching or silently merging conflicts.

Service deletion is soft deletion: current catalogue/name/brand-offering reads hide the deleted identity, while historical foreign keys and observations remain. Restore brings the same identity and its links back. Current Brand Intelligence products/services and priority projections refresh on rename, deletion and restoration. Category keys remain stable on rename; deleting a category clears the service category, never deletes its services.

Validation: operator explicitly requested direct GitHub edits, no cloning and no tests. No test, build, browser or staging database verification is claimed. Deployment and operator acceptance remain required.


## Library imports and central locations — 2026-09-08

Scope: operator-authorized direct edits on staging work branch `chatgpt/search-demand-foundation`. Library navigation remains Services, Queries, Query Clusters, Competitors.

- Services supports bulk lines (up to 2,000), optional `service | phrase, phrase` syntax, and editable matching expressions. Expressions have service-local uniqueness; shared expressions can match multiple services. Existing global identity aliases remain separate.
- Queries supports paste, XLSX/CSV/TSV/TXT and selected stored Google Ads / Search Console resources over an explicit date range. Only existing canonical provider fact tables are read. No provider collection/API/spend follows import, and provider metrics are not copied or aggregated as query performance.
- A valid sector is mandatory. Optional service selections must be active and belong to that sector. Whole-phrase matching uses selected service expressions. Unmatched queries remain available through the sector-aware Unassigned filter. Operators can bulk assign up to 500 selected queries and create a sector/service inline.
- The agency Library identity for new writes is the location-free canonical text, independent of account, sector and market. Existing exact canonical items are reused. Multiple sectors attach to one item rather than creating another identity; the legacy scalar sector remains the primary compatibility value. Provider Intelligence identities and provider facts are unchanged. Existing historical query variants with locations are not destructively merged or rewritten by this migration.
- Country/province/district names are stripped at whole-expression boundaries, longest first; Turkish/ASCII spelling is folded only for location and phrase recognition. Original query spelling and removed expressions are visible in source records. Ambiguous names such as Of/Kale are also stripped under the operator's literal rule; the import panel says so. Location-only rows fail explicitly. Source repetition does not add identical source rows.
- The central bundled catalog has 249 ISO country/territory labels, 81 Turkish provinces and 973 districts with parent IDs and available dataset details. Original MIT files, licenses and pinned provenance live under resources/data/locations. No runtime downloads, new provider, new dependencies or Locations navigation item. CountryOptions and CityOptions delegate to the central catalog; Brand, customer HQ, prospect, public-discovery and search-profile location inputs use it. Non-Turkey free-form existing city data remains possible; no additional foreign subdivision catalog is introduced. Provider-specific geotarget IDs retain their own provider contract.
- Imports and bulk assignment use durable SearchQueryLibraryImport records and 100-row queued chunks, with bounded inputs, progress, first 20 row errors and terminal cleanup of temporary source files. Activity links to the relevant Library screen. Unknown failure never reports completion; reimport is safe. XLSX expansion is bounded to 32 MiB, 256 columns and 10,000 query rows; XML external loading and DTD/entity declarations are not accepted.
- Migration adds matching expressions, query-sector relations and import payload storage; backfills sector relations while preserving IDs and historical records.

Verification truth: source was reviewed without cloning, installing, running tests, Pint, build, browser acceptance or staging execution, as explicitly requested. Code is saved for deployment; migration, workers, runtime and operator acceptance are unverified. Not a DONE/UAT claim.


## Brand edit hotfix — 2026-09-08

Source review found that `Brand.offerings` is both a legacy text attribute and a HasMany relationship. `fillCommercialContext()` called collection methods on the shadowing text/null attribute during Brand edit mount. It now explicitly reads the loaded relationship through `getRelation('offerings')` for selected services and priorities. Legacy text and all database identities remain unchanged.

Direct staging hotfix; no clone, tests, build or server execution, per operator instruction. This resolves the identified code defect; the reported production HTTP 500 has not been correlated with server logs or verified after deployment.


## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.


## Brand detail mount hotfix — 2026-09-08

The routed Operator BrandShow adapter still called map() on Brand's legacy offerings text attribute when a BrandIntelligenceContext existed. The prior edit-form fix did not cover this separate mount path. Read the explicitly eager-loaded offerings relation with getRelation('offerings') when constructing the business-context service list. Existing filtering, order, labels and stored data are unchanged.

Source-reviewed direct staging patch; no clone, tests, build or server execution, per operator instruction. This fixes an identified fatal code path; the reported HTTP 500 remains unverified against server logs and post-deployment runtime.


## Brand service bulk selection — 2026-09-08

The shared Brand create/edit form adds Select all shown and Deselect all shown. Actions use the same server-derived serviceOptions as rendering, respecting selected sectors, active status, search text and selected-only filtering. Selection is deduplicated and preserves other selections and existing priorities. Deselection removes priorities only for deselected services. These actions change form state; persistence still requires Save. Empty/inapplicable buttons are disabled; TR/EN labels and visible count are provided.

Direct staging change, source-reviewed only. No cloning, tests, build or runtime/UAT verification, per operator instruction.


## Query Library daily management — 2026-09-08

Operator-authorized scope: inline query-name editing, download of filtered/all queries, immediate removal and related list usability, on `chatgpt/search-demand-foundation`.

- Hover/focus pencil opens the row editor; mobile keeps it visible. Enter saves, Escape cancels, inline errors retain input. Rename applies the same location stripping and canonical normalization as import, checks existing/deleted identities and rejects stale concurrent edits. ID and service/sector links persist. Original observations remain; a manual source record records previous/new input, actor and stripped locations. Linked Brand portfolio entries without text overrides refresh their Intelligence identity through the existing resolver; provider fact identities are not rewritten.
- Soft removal uses an additive deleted_at column. Removed items leave normal Library reads; Deleted supports individual/bulk restore, and the last individual removal has Undo. Imports refuse to recreate/resurrect removed identities. Existing Brand portfolio references explicitly retain their Library relation (withTrashed); removal from Library does not silently remove previously applied Brand queries.
- The default unfiltered list includes all nondeleted statuses. Status, sector, service, source, unassigned and literal folded search use one model query scope shared with authenticated CSV export. Export downloads all matching records, independent of pagination or row selection, in stable selected ordering. It is a direct streamed download with 500-row eager-loaded batches, UTF-8 BOM, semicolon delimiter and formula-cell neutralization; no Livewire base64/full-memory download. A concurrent writer can change records during a long export: no point-in-time snapshot guarantee.
- Service filter, clear-filters action, status labels, newest/A–Z/Z–A sorting, 25/50/100 page size and page correction after removal are exposed. Selection actions preserve the current filter scope and have a 500-selected-row mutation limit. Selected rows can be activated, excluded, removed or restored. Existing assignment/import jobs remain queued. New bounded status CRUD and rename are synchronous; export is a streamed read, not provider or AI work.

Verification: source inspection only. Per operator instruction no clone, tests, dependency install, formatter, build, browser or server execution. Migration, runtime, CSV opening and UI acceptance are unverified; no DONE claim.


## Query exclusion list — 2026-09-08

Operator approved the proposed exclusion-list workflow, then requested continuation. Scope remains direct staging branch `chatgpt/search-demand-foundation`; no main, PR or server deployment.

- Sorgular embeds a collapsible Exclusion list with bulk expression entry (1–2,000 lines per save), normalized deduplication, search, edit, enable/disable and deletion. Matching is whole folded word/phrase, case/Turkish-ASCII tolerant, never substring or fuzzy typo guessing. Deleting a rule never restores previously removed queries.
- Existing cleanup is a durable queued preview over all nondeleted Library queries through the start-time maximum ID, independent of current list filters. It checks canonical text and retained original source texts, showing the actual matching text/expression. All matches are paginated and initially selected; operators may uncheck individual rows or select/deselect the full preview before approval.
- Only the creator can approve their ready run. Run-row locking serializes approval and preview selection changes. Current rule fingerprint must match the preview; changed rules require a new preview. Apply processes only approved selected rows, rechecks item version, matching text and exceptions, and soft-removes with the existing model. Changed/protected/deleted rows are skipped, never silently replaced by unseen candidates. No Brand portfolio or provider fact deletion follows Library removal.
- Scan/apply execute in 100-item queued chunks with durable cursor/results/counts, overlap protection, terminal failure history and no automatic restart after partial failure. History resumes from the Sorgular panel after navigation/reload. It is a dedicated Library maintenance history, not a new provider collection or Finding/Run engine.
- New writes through SearchQueryLibraryService consult active exclusions before location stripping, preserving the existing store return contract through a specific validation exception. The main paste/Excel/CSV/stored Ads/GSC LibraryImportWorkflow catches it as a separately counted excluded row, storing every raw row and matched rule snapshot for paginated inspection. These are neither accepted queries nor generic errors/duplicates. Older callers of the shared writer also respect screening and receive the explicit validation reason.
- Individual and bulk restore expose an enabled-by-default protection checkbox. Protection stores a canonical query exception used by both preview/apply and future imports. Exceptions can be removed from their own list; removing protection does not immediately delete the query. Import raw text is checked first; only exemption identity resolution uses the location-free canonical text.
- Rule changes during processing are checked between rows; execution is not an all-or-nothing global transaction. Completed removal receipts persist and removed queries remain recoverable. Preview membership is a snapshot; new/changed data can be covered by a new scan.

Verification truth: code inspected only; operator forbade cloning and tests. No tests, formatter, build, browser or staging DB/queue execution ran. New migration, worker runtime and human acceptance remain unverified. No DONE/UAT claim.

## Automatic resource collection → Query Library — staging source, 2026-09-09

| Capability | Code | Operator UX | Async | Tests / UAT | Remaining scope / limits |
| --- | --- | --- | --- | --- | --- |
| Discovered-account cadence and smart continuation | Added on staging work branch | Google/Meta integration account controls; daily/3-day/pause/update now, status and actionable errors | Scheduler + existing central/bound collectors; two account slots | NOT RUN by operator instruction; live runtime unverified | Google Ads/GSC/GA4 resource-first; Meta/GBP require real existing binding; no Website/paid-provider auto enablement |
| Completed-data automatic Ads/GSC query imports | Added on staging work branch | Persistent sector/service mapping, progress, observations, resume/close, Activity | Four admitted account imports, 100-row steps, indexed dataset receipts | NOT RUN; no 100k benchmark | Initial successful stored history included; counters describe source rows; no provider-metric aggregation |
| Preserve manual decisions and recheck unmatched queries | Added on staging work branch | Sticky query deletion/rename aliases, removable service chips and automatic-match blocks, confirmed recheck | Recheck queued; small manual edits synchronous | NOT RUN; migration/UAT required | Existing service/child placements preserved; changed account mapping does not retroactively reclassify existing queries |

See PROJECT_MEMORY automatic account section for full contract, worker/scheduler prerequisites and explicit unimplemented resource-first Meta/GBP scope. No main or server deployment is claimed.


## Connector activity and standards extension — 2026-09-09

Operator-approved sequence: connector → integration ingestion/UX → deterministic standards.
The existing pairing and read endpoints remain compatible; downloadable connector version is 1.1.0.
No clone, dependency install, tests, formatter, build, browser or host execution was performed.
Source implementation is not deployment, runtime acceptance, or DONE.

Implemented:
- WordPress local non-autoloaded outbox table, 50-event signed POST batches through WP-Cron every
  five minutes, signed per-ID acknowledgements, replay protection, retry/backoff and 10,000-event cap.
  Save hooks never perform HTTP. Events in one request coalesce by type/object; separate editor
  requests remain separate audit records. Missing queue writes/overflow report a persistent gap.
- Content publish/update/status/delete, allowlisted SEO/business metadata, selected settings,
  theme/plugin maintenance and role-change events. Acting WP user ID/display name are included;
  no passwords, form entries, arbitrary metadata values, visitor clicks or binary media.
  Public content types are inventoried; internal submission/order stores are excluded.
- Safe cached/runtime health, delayed cron count, module presence, debug/registration policies,
  cache flags, adapter versions and allowlisted branch fields extend existing CMS metadata.
- Additive receiving tables; connection/installation-bound HMAC, timestamp/nonce verification,
  credential recheck under connection lock, deduplication and transactional acknowledgement.
- Website Integration Activity tab: period/type/actor filters, 25-row pagination, delivery age,
  pending count and coverage-gap warning. Global Activity merges the same stored events.
- Scheduler admits at most two event reconciliation collections, at most 50 events/changed object
  IDs per incremental scope, and defers while the asset has an active collection. Successful
  completion advances the receipt watermark; failed/partial collections do not. The existing
  CMS snapshot writer retains untouched objects on incremental refresh and removes only scoped
  missing objects. The connector must echo the exact object scope before writes are accepted.
- Daily CMS inventory reconciliation and global-settings changes use full CMS inventory; ordinary
  content changes use scoped content/media/SEO reads. Site/extensions/taxonomies are still full
  lightweight snapshots. Relevant known public URLs use existing bounded targeted crawl (max 100).
  No daily all-page browser run, paid provider or AI call is introduced.
- Standards categories: Website, Google Ads, Meta Ads. Ads categories are placeholders with explicit
  empty-state copy; their criteria are not shipped. Website definitions distinguish general vs
  WordPress inheritance. Existing expert criteria are retained but inactive; their add form is
  removed. Length heuristics default inactive. Historical evidence is preserved.
- 25 additional deterministic/advisory checks cover duplicate/multiple metadata, H1/language,
  internal target status, canonical/hreflang target status, content fingerprint matches, image
  attributes, mixed resources, WordPress debug/registration/visibility/cron/update/health policies
  and delivery freshness. Existing 500-profile assessment bound remains visible. Missing target
  HTTP, stale evidence, unsupported connector fields and truncated scans do not become passes.
  Inventory, HTML and cached Site Health observations remain distinct.

Not implemented in this delivery:
- Remote SEOPress/LiteSpeed write controls, installations, automatic plugin/theme/core updates,
  image optimization or rollback. management_enabled=false; no advertised executable action.
- Complete malware scans, backup/SMTP provider adapters, visitors/session recording, historical
  activity reconstruction, all-page browser checks or automatic whole-site standards after each event.
- Full planned SEO catalogue (e.g. complete sitemap membership, reciprocal hreflang, orphan/depth
  graph, GSC URL Inspection/cluster comparison) is not yet implemented by the new checks.
- Continuous CMS delta cursor independent of events; daily inventory is the recovery mechanism.
  Low traffic or disabled WP-Cron needs host scheduling. A coverage gap cannot reconstruct history.

Deployment: normal staging script installs additive Laravel tables and restarts workers.
Then download connector 1.1.0 from the existing connector screen and replace the installed plugin.
Existing pairing remains; WordPress init creates the local outbox. First successful heartbeat
enables reconciliation. Scheduler and collection workers must run. Verify live pairing, event
redelivery, scoped deletion, filtered history, and standards before operational acceptance.

## Website integration collection controls — 2026-09-10

Implemented in staging source; no clone, tests, formatter, build, browser UAT or deployment run,
as explicitly requested by the operator. This is not a verified runtime/DONE claim.

- Website integration offers General (public HTML/TLS + paired WordPress), Public, WordPress full
  inventory, and PageSpeed scopes. PageSpeed is explicit in this screen; other existing callers'
  family defaults are unchanged. Missing CMS/PageSpeed connections are checked on the server.
- Connector 1.1.0+ delivery state exposes daily/three-day full inventory cadence and pause/resume.
  Event-driven refresh remains in bounded batches between inventories. Pause affects future
  admissions, not an active run or receipt/audit recording; manual collection remains available.
- Display last receipt, automatic reconciliation/full inventory, central pending event count,
  stale receipt warning and retry errors. Pending count is stored unprocessed events, not the
  sender's last-reported outbox size. Automatic inventory timestamps exclude manual collections.
- Source statuses use latest dataset attempts per source, independent of the most recent overall
  run. Latest-run counters remain explicitly latest-run counters. Queries no longer hydrate the
  entire collection history each poll; idle screen refreshes every 30 seconds.
- Collection console/history show scope and automatic trigger. PageSpeed-only runs have a valid
  progress denominator. Admission through the Website orchestrator rejects another active run
  under a short per-asset cache lock; reconciliation ticks also use a shared lock.
- Fix full-inventory reconciliation advancing past the first 50 events: only the processed event
  batch advances the cursor after success, retaining later URL refresh work. No incoming history
  is deleted. Failed/partial work retains its cursor, retrying later. At most two automatic
  reconciliation runs remain active; busy/unsupported candidates are deferred.
- A missing recent heartbeat no longer silently suppresses periodic recovery inventory after the
  first supported delivery. Disconnected/unpaired sources are excluded; old plugin versions wait.
- New additive preference columns/indexes require the normal staging migration. Shared cache,
  scheduler and collection workers are required. Keep Connector 1.1.0+ installed and WP-Cron or
  host cron running. General public crawl/PageSpeed are not automatically run daily. No remote
  CMS mutation, paid enrichment or AI request is introduced.

Remaining acceptance: migrate staging, exercise scope selection and pause/resume, confirm delayed
event batches/cursor recovery and source timestamps against a real paired site. No such acceptance
has been performed in this change.

## Standards workspace revision — 2026-09-10

Operator requested standards after the Connector and Website integration updates.

- Deterministic catalogue now has 52 visible definitions (37 general, 15 WordPress); 7 expert
  criteria remain archived. Eight additions cover HTML canonical-target noindex, hreflang
  self/return links, WP HTTPS home setting, plain permalinks, cache declaration, outbox setup
  declaration and post-gap inventory recovery. Two existing length heuristics remain disabled
  by default. Existing enabled/disabled overlays are retained; no catalogue seed is required.
- Website / Google Ads / Meta Ads navigation, populated category counts, platform/status/search
  filters and 20-row pagination replace the unbounded card list. Ads categories remain explicitly
  empty. Active administrators can change enabled state and low/medium/high severity, and restore
  a definition's shipped defaults. Existing settings JSON stores only validated severity overrides;
  arbitrary executable checks cannot be submitted. Historical runs remain unchanged.
- Current completed raw TLS and robots.txt collection rows feed site-level assessment, preferring
  newer evidence. The TLS check is explicitly certificate expiry only, not trust-chain/host
  verification. HTTP-to-HTTPS and sitemap still require their existing diagnosis evidence; missing
  observations stay unknown. No fresh network request or paid data is started by assessment.
- Hreflang inspection reports truncation and resolves against the HTML base URL. Return-link
  checks require fresh full target HTML and observed successful HTTP; external/uncollected targets
  are unknown. This is HTML-only coverage, not sitemap or HTTP-header hreflang validation.
  Canonical noindex covers HTML robots/googlebot directives, not X-Robots-Tag or actual indexing.
- WP inventory freshness is four days, supporting the three-day inventory option; update-cache
  freshness remains two days and delivery review remains 30 minutes. Invalid/future timestamps
  and absent extension inventory cannot become passes. WP freshness state participates in cached
  result identity. Missing stored HTML contributes to incomplete coverage.
- Cache declaration is a review signal, not measured speed or cache-hit proof. Outbox setup checks
  the connector's declared installation marker, not a database write probe. Gap recovery cannot
  reconstruct lost audit events. No automatic site mutations or plugin installs are implemented.
- Assessment rows open 25-row result details with state filters, URLs, reasons and observed values,
  including unknown/pass/not-applicable outcomes. Site checks are stored explicitly on new runs;
  old reports without these details request reassessment. Proposed actions for site checks use
  site-level wording. Saved severity applies to new proposals, with verified target blockers still
  forced high. Existing human approval/Findings/Recommendations pipeline remains in place.
- Existing 500-profile, 5 MB HTML and scoped evidence bounds remain. No all-page scale claim.
  Broader sitemap graph/orphan analysis, full PHP support/vulnerability feeds, GSC URL Inspection,
  browser rendering, remote speed repairs and Ads standards are outside this change.

Verification: direct GitHub source inspection only. No clone, tests, formatter, build, browser
acceptance or server execution performed, per operator instruction. No new migration required
beyond the already committed integration migrations. Live rendering, stored-fact compatibility,
queue execution and real operator acceptance remain unverified.


## 2026-09-10 — WhatsApp reply assistant (staging only)

Owner-authorized simple Sales menu `/whatsapp`: conversations, received/sent message history,
Turkish AI reply/wait/clarify and copy. Direct Meta adapter with signature-verified durable receipts,
fixed WABA/phone binding, encrypted central credentials/message bodies, paginated inbox and own
persistent async statuses. Existing Laravel AI provider credentials and a dedicated sales.whatsapp_reply
route are reused. New message/settings changes invalidate old drafts; context is capped and labelled.
Active Admin-only access. No WhatsApp send endpoint, task creation or external mutation.
Additive migration + existing minute scheduler/default Redis Horizon required. Initial API setup,
webhook subscription and Coexistence eligibility remain external. History/echo support only covers
received provider events; no guarantee of complete phone history. Media not interpreted; delivery,
edits/deletions, global Activity and full Agent/Skill execution ledger not implemented in this slice.
Contract: docs/product/WHATSAPP_ASSISTANT.md. Source reviewed only; no tests/build/formatter, dependency
installation, runtime/migration execution, live Meta/AI UAT or host deployment. Not DONE/live accepted.
All changes are on chatgpt/search-demand-foundation; no main edits and no PR.


## WhatsApp credential form correction — 2026-09-10

Failed settings saves previously cleared all three password inputs via finally/dehydrate and showed
an unnamed first-missing error. Password inputs now stay browser-local (wire:ignore, DOM refs), are
submitted only as action arguments, and clear only on explicit successful save. Validation failures
retain unsaved inputs in the current open form, not in server snapshots or persistent browser storage.
Field-specific messages list every missing credential; server-derived presence flags distinguish
stored credentials from blank edits. Reads use a fresh provider-credential relation query. Existing
stored values remain write-only and blank submissions preserve them. Stale save errors reset before
new save attempts. No credential/account data migration or provider mutation is performed.
Source-reviewed fix only: no tests, build, formatter or live deployment run. Supersedes earlier
notes about clearing fields after failed saves. Number mismatch remains a separate configuration issue.



## WhatsApp setup and action feedback correction — 2026-09-10

Initial WABA/phone binding corrections are now allowed only with no conversations and no
non-completed receipts. Completed ignored receipts are retained. IDs compare as trimmed strings;
existing history continues to block rebinding with field-specific explanations. Receipt signature
validation/persistence and settings changes serialize on the integration row. No data is deleted.
The form distinguishes stored IDs, stored secret presence and unsaved secret edits, offers visibility
for newly typed secrets, and shows saving/success/failure feedback beside the actions. Failed saves
preserve input; controls are disabled during save. API checks use persisted queued/error/result
states, timestamps, duplicate-click suppression and automatic result refresh. Request IDs prevent
an older result overwriting settings saved during the HTTP call. Two-minute queue delays show a
retry/help message. Separate WhatsApp App Secret guidance leaves Meta Ads credentials untouched.
Added PHPUnit coverage for initial correction, receipt/history guards, blank credential preservation
and stale API results. Execution was attempted but unavailable: this workspace has no PHP executable
or installed vendor/Pint. No PHP/Blade compilation, full browser UAT, Meta verification or server deploy
was performed. The actual form submit handler passed isolated JavaScript checks for success,
validation rejection and network failure; this is not browser UAT. Source reviewed; runtime
acceptance remains pending, not DONE.
