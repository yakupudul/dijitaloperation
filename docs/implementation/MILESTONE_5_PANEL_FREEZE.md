# Milestone 5 — Panel Design Freeze & Post-Freeze Backend Roadmap

Status reference for the frozen `/app` operator panel. UI work after this document requires product-level justification.

## Capability Reality Matrix

| Capability | Classification | Notes |
|---|---|---|
| Customer | PARTIAL | Demo portfolio + session state; relationship/services/requests demo-backed |
| Brand | PARTIAL | Demo catalog + business context session overrides |
| Brand Context | DEMO | Offerings/audiences/markets/goals in fixtures |
| Public Discovery | DEMO | Deterministic discovery candidates |
| Files | REAL | OperatorFile persistence, upload/download/auth |
| Website | PARTIAL | Specialist workspace + demo health/content; Site Connector pairing demo |
| Website Infrastructure | DEMO | Domain/DNS/hosting/CDN presented as Website sections |
| WordPress Site Connector | PARTIAL | Demo package + pairing UX; not production-installable |
| GBP | DEMO | Local visibility/reviews/competitors fixtures |
| Google Ads | DEMO / PARTIAL | Specialist panels remain Demo (Prompt 30); Prompt 19 production collector writes real Ads pool facts |
| Meta Ads | PARTIAL | Specialist UI REAL/PARTIAL (Prompt 31); Integration auth/discovery/binding real (21–23); Prompt 24 collector + Prompt 25 initial backfill write real Meta pool facts |
| Meta Integration hub/detail (`/app/integrations` Meta) | REAL (backend state) | Prompt 21–23: Core* + read model; Connect / discover / select / human-confirmed Binding; no Graph on render |
| Canonical META provider / Meta Ads Connector foundation | REAL | provider=`meta`, connector=`meta_ads`; app config ≠ tenant token |
| Meta Business container ExternalResource | REAL | `meta_business` non-bindable container; not Customer/DigitalAsset |
| Meta Ad Account ExternalResource | REAL | `meta_ads` / META_AD_ACCOUNT; canonical `act_*` identity |
| Meta credential/token ownership | REAL | Integration-owned encrypted credential; App Secret deployment-only |
| Production Meta authorization (Login for Business) | REAL CODE PATH | Prompt 22: OAuth attempt/state, code exchange, long-lived exchange, debug_token |
| Meta permission coverage | REAL | requested ≠ granted; ads_management not requested |
| Business discovery + selection context | REAL | paginated me/businesses; discovery_context ≠ Binding |
| Owned + client Ad Account discovery | REAL | owned_ad_accounts + client_ad_accounts; act_ dedupe |
| Meta App external approval/readiness | UNKNOWN / MANUAL | App Review / Advanced Access / verification — dashboard |
| Meta resource selection & Binding workflow | REAL | Prompt 23: human-confirmed META_AD_ACCOUNT ↔ Meta Ads DigitalAsset via CoreAssetBinding |
| Meta Ads Production Collector (Collection Engine) | REAL | Prompt 24: RF_META_* → MetaAdsDatasetExecutor → WarehouseWriter → Meta normalized pool; sync + async Insights |
| Meta Ad Account / Campaign / Ad Set / Creative metadata collection | REAL | Contract entity snapshot + account meta; human-confirmed Binding required |
| Meta Campaign / Ad Set / Ad daily Insights | REAL | Daily grain; account TZ; clicks≠link≠outbound; reach non-additive |
| Meta Typed Actions / Action Values | REAL | action_type preserved; no generic Results; no Business Outcome mapping |
| Meta Sync / Async Insights transport | REAL | MetaInsightsRetrievalStrategy; async AdReportRun POST is read-only report creation |
| Meta specialist real-data UI | NOT YET | Frozen Demo specialist until dedicated migration |
| Meta Initial Backfill Preflight / Planner | REAL | Prompt 25: control-plane preflight; Registry-driven multi-account plan; materialization-aware |
| Meta Initial Backfill Execution | REAL | One CollectionRun → independent META_AD_ACCOUNT ResourceRuns/DatasetRuns via shared engine |
| Meta Initial Backfill UX | REAL | Collect Data on frozen Meta Integration + Prompt 11 MonitoringPanel; no new nav |
| Browser-independent Meta import | REAL | Persist + queue; close browser; reconstruct from DB; async Insights independent of browser |
| Partial-failure Meta import | REAL | Dataset/account isolation; PARTIAL aggregation; successful facts preserved |
| Persistent Meta Collection History | REAL | Prompt 11 history; trigger Initial Meta Ads Collection |
| Meta Initial Backfill | REAL | Prompt 25 |
| Recurring Meta collection | REAL foundation | Manual/system incremental via `StartIncrementalCollectionService`; automatic scheduler NOT YET (Prompt 62) |
| Meta incremental freshness | REAL | Prompt 27 policy + planner + orchestrator |
| Data Pool Integrity Framework | REAL | Prompt 26: Integrity Registry + auditor + audit persistence + readiness gate |
| Natural-Key / Duplicate Detection | REAL | SQL grouped NK scans; no auto-delete |
| Coverage / Gap / Zero-row Semantics | REAL | CoverageIntervalSet; not fact-row presence; not min/max-only |
| Pagination Completeness Verification | REAL | GSC/GA4/Ads/Meta sync+async rules; async 100% ≠ complete |
| Raw→Normalized / WriteReceipt Accounting | REAL | ONE_TO_ONE + typed-action expansion; unexplained loss FAIL |
| Timezone / Currency Validation | REAL | resource provenance; no FX; no rebucket |
| Non-Additive Metric Protection | REAL | Reach/Frequency/GA4 users cannot be sum-reconciled |
| Migration Readiness Gate | REAL | READY_* / BLOCKED_* / UNVERIFIED; no score |
| Actual Real-Pool Verification | NOT EXECUTED | Framework real; populated pool audit pending in this env |
| Recurring / incremental collection | REAL | Manual/system callable (`StartIncrementalCollectionService`, due query); automatic scheduler NOT YET |
| Data Freshness Policy Registry | REAL | Prompt 27: `MOXDOP_DATA_FRESHNESS_POLICY_V1` |
| Incremental Coverage Planner | REAL | Provider-neutral gap/catch-up/reprocess planning |
| Due Collection Query | REAL | DB/policy driven; zero provider HTTP |
| Start Incremental Collection | REAL | `CollectionTriggerType::Incremental`; idempotent fingerprint |
| GA4/GSC/Ads/Meta real-data UI | PARTIAL | GA4/GSC/Ads/Meta specialists REAL/PARTIAL (Prompts 28–31) |
| GA4 | PARTIAL | Specialist UI reads real GA4 pool (Prompt 28): sessions/acquisition/behavior/events/streams/ops collection_state REAL; users Unavailable (non-additive); business actions, needs_attention, journeys, findings/recommendations/tasks/outcomes residual Demo |
| Search Console | PARTIAL | Specialist UI reads real GSC pool (Prompt 29): property glance/trend/devices/countries/queries/pages/sitemaps/inspection sample/ops collection_state REAL; site-wide indexing coverage/reconciliation Unavailable; clusters/momentum/brand/diagnosis/attention/findings residual Demo |
| Google Ads | PARTIAL | Specialist UI reads real Ads pool (Prompt 30): account spend/conversions/trend/campaigns/keywords/search terms/landing/conversion actions/ops collection_state REAL; CPA/pacing/offering Unavailable; intent/inbox/ops narrative residual Demo |
| Meta Ads | PARTIAL | Specialist UI reads real Meta pool (Prompt 31): campaign spend/trend/campaigns/creatives/typed actions/age-gender-platform breakdowns/ops collection_state REAL; period Reach/Frequency/generic Results/country/pacing Unavailable; fatigue/CRM/ops narrative residual Demo |
| Instagram | DEMO | Lightweight Overview/Profile/Operations/Setup |
| Service Scope | DEMO | Session/fixture commercial scope |
| Goals | DEMO | Primary/conversion goals in fixtures |
| Opportunities | CONVERGED / REAL | Prompt 40: canonical `opportunities` + `opportunity_evaluations`; rule-backed production Opportunities from canonical Evidence + Findings (3 GSC/GA4 rules); Demo Atlas `OpportunityFixtures` remains Demo-only for non-Operations surfaces; `/app` Operations Opportunities index is DB-backed with no Demo fallback |
| Findings | CONVERGED / REAL | Prompt 39: one canonical `findings` table; rule-backed production Findings from canonical Evidence; Demo Atlas catalog remains Demo-only Livewire; Filament `/app/findings` is DB-backed |
| Recommendations | DEMO | Fixture recommendations + accept/create-task |
| Work | DEMO | Tasks/requests/reviews/approvals/QA via DemoState |
| Client Requests | DEMO | Customer Requests workspace + Work views |
| Approvals | DEMO | Approval states in session |
| Playbooks | DEMO | Catalog + knowledge fields; Settings → Operations |
| Recurring Reviews | DEMO | Due list + completion actions |
| QA | DEMO | QA required / approve flows |
| Capacity | DEMO | Transparent thresholds, not a score |
| Activity | DEMO | Timeline fixtures + scoped filters |
| Operational Outcomes | DEMO | Observed after work — no automatic causation |
| Business Outcomes | DEMO | Brand aggregate outcomes + Demo overrides |
| Reports / Value Story | DEMO | Deterministic assembly; no PDF/email delivery |
| AI Control Plane | PARTIAL | Route provider order editable under `/app`; credentials in Integrations |
| Agents | PARTIAL | Full read-only catalog under `/app/settings/ai/agents` (code registry) |
| Skills | PARTIAL | Full read-only catalog under `/app/settings/ai/skills` (code registry) |
| Notifications | DEMO | Deterministic bell when DB empty; preferences in Settings |
| Google Integration hub/detail (`/app/integrations`) | REAL (backend state) | Prompt 13–20: Core* + OAuth + discovery + human binding + collectors + initial backfill Collect Data |
| Google connector workspaces | PARTIAL | GSC/GA4/Ads pools REAL via Prompt 17–19; GA4 specialist UI REAL/PARTIAL (Prompt 28); GSC specialist UI REAL/PARTIAL (Prompt 29); Ads specialist UI Demo until Prompt 30 |
| Google Initial Backfill Planner | REAL | Registry-driven multi-connector plan; dataset-specific historical depth; materialization-aware |
| Google Initial Backfill Execution | REAL | One CollectionRun → independent GSC/GA4/Ads ResourceRuns/DatasetRuns via shared engine |
| Google Initial Backfill UX | REAL | Collect Data on frozen Google Integration + Prompt 11 MonitoringPanel; no new nav |
| Browser-independent Google import | REAL | Persist + queue; close browser; reconstruct from DB |
| Partial-failure Google import | REAL | Provider/dataset isolation; PARTIAL aggregation; successful facts preserved |
| Persistent Google Collection History | REAL | Prompt 11 history; trigger Initial Google Collection |
| GBP analytical pool | NOT YET | Discovery/binding may exist; no production GBP analytical collector / no fake DatasetRuns |
| Recurring Google collection | REAL foundation | Manual/system incremental via orchestrators; automatic scheduler NOT YET (Prompt 62) |
| Incremental freshness | REAL | Prompt 27 per-dataset watermark + evaluator + materialization metadata |
| Automatic Recurring Scheduler | NOT YET | Prompt 61/62 — no `Schedule::daily` collection in Prompt 27 |
| Collection Scheduler | NOT YET | Prompt 62 consumes `DueCollectionQueryService` |
| GSC Production Collector | REAL | Contract request families → WarehouseWriter → GSC normalized pool |
| GSC Search Analytics / Sitemaps / Controlled URL Inspection | REAL | Read-only; appearance not collected (source V1) |
| GSC specialist real-data UI | PARTIAL | Prompt 29: pool read layer + formulas + gates; site-wide indexing Unavailable; residual Demo clusters/momentum/ops findings |
| GA4 Production Collector | REAL | Contract request families → WarehouseWriter → GA4 normalized pool |
| GA4 Metadata / Compatibility | REAL | getMetadata + checkCompatibility with property-scoped cache |
| GA4 Property Daily / Acquisition / Behavior / Landing / Events | REAL | Session-scoped acquisition; no firstUser*; event facts as BA inputs only |
| GA4 Business Action mapping | SEPARATE | Not applied inside production collector |
| GA4 specialist real-data UI | PARTIAL | Prompt 28: pool read layer + formulas + gates; residual Demo mapping/attention/journeys/findings |
| Google Ads Production Collector | REAL | Contract GADS_RF_* → WarehouseWriter → Ads normalized pool |
| Google Ads Customer Metadata / Campaigns / Ad Groups / Keywords / Search Terms / PMax Terms / Ads / Assets / Landing / Conversion Actions / Typed Conversions | REAL | Session/account TZ + currency preserved; managers not performance roots |
| Google Ads specialist real-data UI | PARTIAL | Prompt 30: pool read layer + formulas + gates; CPA/pacing Unavailable; residual Demo intent/ops narrative |
| Meta Ads Production Collector | REAL | Contract RF_META_* → WarehouseWriter → Meta normalized pool; sync + async Insights |
| Meta Ads specialist real-data UI | PARTIAL | Prompt 31: pool read layer + formulas + gates; period Reach/Frequency/generic Results/country Unavailable; residual Demo fatigue/CRM/ops narrative |

## Post-Freeze Backend Roadmap

### P0 — required to operate for real

1. **Core domain persistence (Customer / Brand / Digital Asset / Service Scope / Goals)**  
   - current state: PARTIAL/DEMO  
   - frozen UI: Portfolio + Brand Business  
   - missing: durable models beyond demo fixtures  
   - dependency: Eloquent schemas aligned to frozen IA  
   - why: everything else hangs off stable identities  
   - order: first

2. **Agency operations persistence (Findings, Opportunities, Recommendations, Work, Requests, Approvals, QA, Reviews)**  
   - current state: DEMO  
   - frozen UI: Operations + Work + Brand Operations  
   - missing: tables + fingerprint + ownership + due dates  
   - dependency: core domain IDs  
   - why: daily operator loop cannot stay session-only  
   - order: immediately after core domain

3. **Provider collection for scoped assets (read-only)**  
   - current state: DEMO/PARTIAL  
   - frozen UI: specialist Digital Asset workspaces  
   - missing: scheduled collection into Evidence  
   - dependency: Integrations bindings + credentials  
   - why: Evidence → Finding/Opportunity must be real  
   - order: after bindings are durable

### P1 — high-value operational capability

4. **Website / Site Connector production path**  
   - current: PARTIAL demo package  
   - frozen UI: Website Setup + Integrations Site Connectors  
   - missing: signed install, collection jobs, CMS evidence  
   - why: Website is central estate asset

5. **AI execution behind Control Plane / Agents / Skills**  
   - current: registries + Demo outputs  
   - frozen UI: Settings → AI & Intelligence  
   - missing: grounded runs, eligibility, fallback logging  
   - why: Growth synthesis without inventing omniscient agents

6. **Activity + Notifications persistence**  
   - current: DEMO  
   - frozen UI: Activity + bell  
   - missing: meaningful event stream, preference-driven delivery  
   - why: scale without noise

### P2 — meaningful expansion

7. **Reporting / export** — snapshot persistence, PDF, authenticated share (no fake Send today)  
8. **Business outcome imports** — manual/CSV/CRM normalize (not a CRM product)  
9. **Automation / scheduling** — recurring reviews, collection, notification cadence with human control

### P3 — optional / later

10. Production hardening (audit, rate limits, observability)  
11. Deeper Instagram provider capability only if real API support exists  
12. Write-capable provider actions remain out of default product posture

## Provider capability matrix (summary)

| Asset | Already real | Partial | Demo | Needed API | Default posture | Evidence produced |
|---|---|---|---|---|---|---|
| Website | Files/auth patterns | Connector pairing UX | Health/content/perf | Site Connector + crawlers | Read | Technical/content checks |
| GBP | — | — | Full specialist IA | Google Business Profile | Read | Profile, reviews, local visibility |
| Google Ads | Integration scaffolding | Import UX | Metrics/campaigns | Google Ads API | Read | Campaign/search/asset evidence |
| Meta Ads | Integration scaffolding | Import UX | Creatives/funnel | Meta Marketing API | Read | Delivery/creative evidence |
| GA4 | Integration scaffolding | Binding UX | Measurement/journeys | Google Analytics Data API | Read | Events/acquisition evidence |
| GSC | Integration scaffolding | Binding UX | Queries/indexing | Search Console API | Read | Query/page/index evidence |
| Instagram | — | — | Lightweight | Instagram Graph (later) | Read | Profile/ops only |

## AI capability matrix (summary)

- Agents / Skills / Routes / Allowed Evidence / Allowed & Forbidden Operations / Output Contract / Success Criteria / Eligibility / Fallback / Grounding are preserved in registries.
- `/app` exposes Control Plane editing + full Agent/Skill browse detail.
- Routine operator AI administration must not require `/system`.
- Execution remains non-autonomous for provider writes.

## Post-freeze product backlog (not required for completeness)

| Idea | Potential value | Why deferred |
|---|---|---|
| Global Approvals sidebar | Faster triage for large teams | Work segments already cover Approvals |
| Brand-level Files primary tab | Familiarity | Canonical Files + scoped action is enough |
| Numeric Brand Health Score | Quick scan | Opaque ranking violates product truth |
| Autonomous campaign pause/publish | Ops speed | External write posture forbidden by default |
| Full CRM / Billing modules | Commercial expansion | Outside agency digital operations north star |

## Freeze statement

The current MoxDOP operator panel information architecture and core product workflows are frozen. New operator features should require a clear product-level justification. The next development phase should primarily implement and harden the real backend capabilities behind the frozen product surfaces.
