# PRODUCT_CAPABILITY_LEDGER

> **Canonical product capability truth table for MoxDOP.**  
> Updated for Async Operations + Activity Center milestone (branch work; merge pending).  
> Do **not** treat “IMPLEMENTED V1” in older docs as Definition-of-Done **DONE**.  
> Persistent product direction: `PROJECT_MEMORY.md`.  
> Async operator standard: `OPERATOR_ASYNC_EXECUTION.md`.

## How to read this ledger

| Column | Meaning |
| --- | --- |
| **Code** | Meaningful product code present on **canonical main** |
| **Automated Tests** | PHPUnit coverage on main for the capability slice |
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
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Core Filament Customer → Brand CRUD / portfolio |
| Digital Assets | YES | YES | NO | YES | PARTIAL | TESTED | Long actions migrated to queue; short cross-asset checks still sync | Types include website, google_ads, gbp, meta_ads, instagram |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth requires external Google Cloud console; resource refresh sync | Agency Google Integration; ADR-039/040; Prompt 13+14 |
| Frozen Google Integration UI (backend state) | YES | YES | NO | YES | N/A | **TESTED** | Discovery/bind UX still PARTIAL (Prompts 15–16); connector pages still Demo | `GoogleIntegrationReadModel` + `GoogleConnectorRegistry`; docs: `GOOGLE_INTEGRATION_ARCHITECTURE.md` |
| Google OAuth & credential lifecycle | YES | YES | NO | YES | YES | **TESTED** | External Google Cloud verification/approval MANUAL; no live OAuth in CI | `GoogleOAuthService` + `GoogleCredentialBroker` + attempt store; docs: `GOOGLE_OAUTH_CREDENTIAL_LIFECYCLE.md` |
| Google resource discovery (GA4/GSC/Ads/GBP) | YES | YES | NO | YES | PARTIAL | **TESTED** | GBP/Ads external API access MANUAL; discovery sync on operator action; no auto bind | `DiscoverGoogleResourcesService` + four discoverers; docs: `GOOGLE_RESOURCE_DISCOVERY.md` |
| Google resource selection & asset binding | YES | YES | NO | YES | N/A | **TESTED** | Human confirmation required; no collection side effect; Filament + frozen `/app` share guards | `ConfirmGoogleResourceBindingService`; docs: `GOOGLE_RESOURCE_SELECTION_BINDING.md` |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request; frozen bind workflow Prompt 16 | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | YES | TESTED | Async via Activity Center / database queue; real Ads UAT not re-run here | GSC/GA4/Ads/GBP bound collectors via queued `CollectLiveBoundDataJob` |
| Google Ads Intelligence | YES | YES | NO | YES | YES | TESTED | Collect + AI guidance queued; Expert Workspace not redesigned | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | YES | TESTED | Refresh data + diagnosis queued | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | YES | TESTED | SEO refresh queued when provider work needed; fresh cache stays sync | Workspace V2A, SEO Light, AI guidance |
| Public Website Discovery | YES | YES | NO | YES | YES | TESTED | **Limited scope only**; discovery queued | Bounded public website/context + optional competitor candidates — **not** full web intelligence |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
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
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Ad Account ExternalResources discovered |
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
| Shared collection engine (control plane) | YES | YES | NO | NO | YES | **TESTED** | Redis/Horizon required for production collection queue | Prompt 9: `CollectionRun`→`ResourceRun`→`DatasetRun` + planner + Horizon. Docs: `docs/implementation/COLLECTION_ENGINE_ARCHITECTURE.md`. GA4/GSC/Ads/Meta/Website/DFS DatasetExecutors exist on the current release stack; that does **not** make those collectors REAL/DONE without their own UAT gates. |
| Data pool / warehouse foundation | YES | YES | NO | NO | N/A | **TESTED** | Provider population not REAL; BigQuery not implemented; SQLite proves writer semantics, PostgreSQL proves partitions | Prompt 10: raw object storage + typed PostgreSQL facts + materialization. Docs: `docs/implementation/DATA_POOL_ARCHITECTURE.md` + `MOXDOP_DATA_POOL_STORAGE_V1`. |
| Persistent collection monitoring | YES | YES | NO | YES | YES | **TESTED** | Provider collectors still fake/unimplemented; Reverb optional; polling is mandatory fallback | Prompt 11: Integrations hub `MonitoringPanel` + `CollectionRunMonitorQuery`. Docs: `docs/implementation/COLLECTION_MONITORING_UX.md`. Does **not** make provider collectors REAL. |
| GA4 production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Unmerged until this PR; CollectionRun #1 leftover 5xx rows preserved; event×source/medium + custom dims + geo + browser/OS + ecommerce are contract-deferred, not this gate | Prompt 18; staging 180d facts + event breakdowns + property-daily idempotency replay on PR #200 |
| GSC production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Unmerged until this PR; Search Appearance remains source-contract deferred; GBP is a separate external blocker | Prompt 17; staging GSC SA/sitemaps proven; URL Inspection proven on CollectionRun #4 dataset 48 (1 snapshot row) on PR #200 |
| Google Ads production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Daily facts are successful zero-row on the bound staging account; GBP not in this slice; keyword grain now includes `ad_group_id` but staging 735 remains collapsed historical inventory (`IMPLEMENTED_UNPROVEN` until staging recollection with exact-resource `current_run_grain_proven`). Cursor Cloud cannot reach staging OAuth; operator path is `moxdop:google-ads:recollect-entity-snapshot` (`docs/operations/GOOGLE_ADS_KEYWORD_GRAIN_RECOLLECTION.md`) | Prompt 19; sibling-asset eligibility + v25 GAQL + keyword ad-group grain + pre-fact-commit checksum retry + brand-scoped Google backfill/incremental due-query (`core_asset_binding_ids`); non-keyword snapshots proven on PR #200 |
| Meta production collection (contract-driven) | YES | YES | NO | YES | YES | **PARTIAL** | Unmerged stacked child of PR #200. Live Marketing API / warehouse Collection Engine UAT **not** reachable from this Cursor Cloud agent (`META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` unset; `APP_URL=http://127.0.0.1:8000`; no staging SSH/OAuth DB). Legacy Intelligence Ads Manager UAT (`act_744654160596455`) does **not** prove this warehouse path. Google Ads keyword grain remains `IMPLEMENTED_UNPROVEN` on #200. GBP isolated. No Meta specialist UX. | Prompt 24 `MetaAdsDatasetExecutor` + Prompt 25 initial backfill + Prompt 27 incremental on the shared engine. COLLECTION_READY families: `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`. Deferred (not expanded): `RF_META_ASYNC_INSIGHTS` (async is transport inside daily/breakdown). Incremental due selection uses exact preflight `core_asset_binding_ids`; `DATA CURRENT` only when every eligible Meta dataset in that binding scope is current. Google bindings never enter Meta runs. |
| Website production crawl collection | YES | YES | NO | NO | YES | **PARTIAL** | Unmerged stacked child of PR #203. Live Website crawl / staging Collection Engine UAT **not** reachable from this Cursor Cloud agent (no operator Website binding on this pod, no staging SSH). Legacy Website Evidence collectors remain; this path writes Data Pool facts only. WordPress `WEB_RF_WP_REST` stays DEFERRED. | Shared Collection Engine `WebsiteDatasetExecutor` for COLLECTION_READY families: `WEB_RF_HTTP_HTML_DIAGNOSIS`, `WEB_RF_PUBLIC_CRAWL`, `WEB_RF_DNS_TLS`, `WEB_RF_PAGESPEED`. Asset-capability planner (no External Resource binding). Google/Meta sibling bindings never enter Website runs. Snapshot `observed_at` is checkpoint-frozen. Not DONE. |
| DataForSEO production enrichment (engine-driven) | YES | YES | NO | NO | YES | **PARTIAL** | Unmerged stacked child of PR #203. Live Marketing/Labs UAT **not** reachable (`DATAFORSEO` credentials unset in this agent; no staging SSH). Paid POST is never auto-retried / never routinely scheduled. Fail-closed `paid_attempt_started` is checkpointed before the charged POST; an unresolved attempt fail-closes that DatasetRun (`CHARGE_UNKNOWN`) even if the fingerprint recomputes. A different fingerprint is allowed only on a new DatasetRun. Legacy SEO Evidence collectors unchanged. Domain intersection, relevant pages, and SERP organic stay DEFERRED. | Shared engine `DataForSeoDatasetExecutor` for COLLECTION_READY: `DFS-FREE-USER`, `DFS-FREE-MARKETS`, `DFS-RK-LIVE`, `DFS-KFS-LIVE`, `DFS-COMP-DOMAIN-LIVE`. Agency Integration credentials; facts are Website-asset scoped. Paid families require `paid_enrichment_consented`; competitors also require `public_discovery`. Missing search_volume/etv recorded as missing, never a measured zero. Not DONE. |
| Agency brain / operational synthesis (Phase C.1) | YES | YES | NO | N/A | N/A | **PARTIAL** | Canonical child is PR #206 (PR #205 superseded). Live provider/staging UAT from #200/#203/#204 remains an isolated external gate and is **not** claimed here. No BrainV2 / FindingV2 / Result entity / auto-Task / Agency Learning. Document Head charset/viewport/OG stay unevaluated when those snapshot fields were not collected. Meta primary-result rules stay unevaluated without a collected `primary_result.status`. | `EvaluateFindingsForAssetJob` runs canonical `FindingEvaluationService` then `CollectedFactsAnalysisService`. Website `DocumentHeadEvaluator` (`website_metadata_snapshot`); Google Ads `GoogleAdsPerformanceBoundEvidenceEvaluator` (`google_ads_campaign_daily` spend-with-zero-conversions); Meta Ads `MetaAdsPerformanceBoundEvidenceEvaluator` (`meta_campaign_daily` + snapshot inactive-with-spend). `FindingEvaluationService` now emits `FindingEvaluationCompleted` so Outcome V1 observes GSC/GA4 canonical evaluations. Manual `CreateTaskFromRecommendation`. Sibling Brand/provider warehouse rows never enter collected-facts runs. Not DONE. |
| Operational / settings completeness (Phase D) | YES | YES | NO | YES | N/A | **PARTIAL** | Live SMTP delivery UAT and browser/mobile push remain external/deferred. No SaaS whitelabel, no second credential screen, no SettingsV2. | Canonical operator `/settings` + `/profile` + `/integrations`. Admin/Team Member lifecycle with deactivate-not-delete. Agency timezone/locale drive operator rendering via `OperatorClock` (storage clock stays `APP_TIMEZONE`). Encrypted write-only operator SMTP overlay with env fallback and test-mail action. In-app notification preferences only; push not implemented. |
| End-to-end operator UX / QA (Phase E) | YES | YES | NO | YES | YES | **PARTIAL** | Staging/browser operator UAT not reachable from this Cursor Cloud agent (`APP_URL=http://127.0.0.1:8000`; empty `GOOGLE_CLIENT_ID` / DataForSEO / Meta secrets; no staging SSH). Live provider collect remains the isolated #200/#203/#204 gate. Collection Engine still rejects PHPUnit `sync` queue; production Website refresh surfaces that as unavailable rather than a fake success. No UI redesign, no push/PWA, no GBP collector reopen. | Canonical root journey `Login → Customer → Brand → Asset → Data Sources bind/collect → Activity → Evidence/Finding → Recommendation → manual Task → Outcome`. Production period reads use `OperatorPeriod` / `OperatorReportingPeriod` (custom dates override DemoPeriod math). Capture note/opportunity are truthful unavailable. Google Ads/Meta `runAnalysis` queues finding evaluation. Findings/Recommendations `?asset=` isolation. Activity Center lists `CollectionRun` + async `Run`. PHPUnit: `tests/Feature/PhaseE/*`. Not DONE. |

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

- Website/SEO: `website_metadata_snapshot` → existing Document Head rules (only collected dimensions)
- Google Ads: bound `google_ads_campaign_daily` → existing campaign spend-with-zero-conversions rule
- Meta Ads: bound `meta_campaign_daily` + `meta_campaign_snapshot` → existing inactive-campaign-with-spend rule

Canonical production job `EvaluateFindingsForAssetJob` now runs collected-facts adapters after `FindingEvaluationService`. `FindingEvaluationService` emits `FindingEvaluationCompleted` so Outcome V1 can observe later canonical GSC/GA4 evaluations (ported from superseded PR #205; Google Ads account `conversions-decline` Evidence definition was not copied because #206 already has a campaign-grain Ads vertical). Manual Recommendation → Task remains human. AI does not create Findings or Tasks. Live provider UAT from #200/#203/#204 is a separate external gate.

Do **not** describe this slice as “Agency brain DONE” or as proof of live Google/Meta/Website collection UAT.

### Operational / settings completeness (Phase D) — PARTIAL, not DONE

Phase D reuses the existing operator Settings/Team/Profile/Integrations surfaces. It does **not** introduce SettingsV2, UserV2, NotificationV2, SaaS whitelabel, or a second credential store.

Shipped in this slice:

- Admin-only team create/role/deactivate (no destructive delete; last admin protected)
- Operator forgot-password / reset on `/forgot-password` (inactive/unknown emails share the same success copy and receive no mail; successful reset rotates remember token and does not reactivate)
- Agency timezone/locale/default analytical range affect operator date rendering (`OperatorClock`) and session period defaults. Invalid stored timezone/locale values fall back to catalog defaults. Laravel `APP_TIMEZONE` remains the storage clock (password-reset tokens / Eloquent datetimes / queue+artisan are not rewritten per operator).
- Operator SMTP overlay: encrypted write-only password, env fallback without copying env secrets into the DB, test-mail action; invalid host/port/encryption is rejected without poisoning runtime mail config; test-mail failures log exception class only
- In-app notification preferences for existing events; browser/mobile push is **not** implemented

**Not claimed:** live SMTP provider UAT, web-push/PWA, SaaS tenant branding, or Filament as the canonical operator settings product (`/admin` remains technical).

Do **not** describe this slice as “Phase D DONE”.

### End-to-end operator UX / QA (Phase E) — PARTIAL, not DONE

Phase E is QA + narrow remediation of the canonical root operator journey. It does **not** reopen provider collection, Agency Brain analytics, Settings architecture, GBP collectors, push/PWA, or whitelabel.

Shipped in this slice:

- Production date presets/custom ranges use agency `OperatorClock` “today” (`OperatorPeriod`) and treat filled from/to as a custom range (`OperatorReportingPeriod`) so workspace period controls actually change warehouse reads
- Website overview KPIs stay `—` when the requested period does not overlap collected days (`period_has_data`); collected values outside the range are not reused as stale current KPIs. GSC queries/pages and GA4 landing/acquisition Evidence use the same requested-period overlap gate; dated rows are bounded to the selected range. Missing/uncollected detail datasets stay empty arrays, never numeric zero.
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

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async foundation (material)

Long operator actions (bound collect, Website diagnosis, public discovery, SEO refresh when not fresh, Website/Google/Meta AI guidance) queue via `AsyncOperationService` onto Laravel **database** queue. Canonical execution record remains **Run** (`queued|running|completed|partial|failed`; `cancelled` reserved). Activity Center is Filament `RunResource` (`/app/runs`) with phase progress, duplicate guards, stale detection, retry for safe failures, and in-app database notifications. **Cancellation** is intentionally **not** shipped (fragile with current job architecture). Cross-asset consistency packs and integration resource refresh remain synchronous by design for now.

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
