# SALES ASSISTANT V1 CONVERGENCE AUDIT

Status: audit only. No product code changes are proposed in this document.

> **Historical record (ADR-044):** Path labels such as `/app` in this audit are freeze-era names. Canonical operator routes are now site root; Filament is `/admin`. Legacy `/app` and `/system` return HTTP 410.

Authority order: `docs/MASTER_SPEC.md` -> accepted ADRs -> `docs/product/*` -> this audit.

Current canonical baseline:

- Branch: `cursor/production-readiness-audit-ea01`
- Starting HEAD: `7488470d598f7264abfde7b9126e32a938714f7b`
- Pilot gate: `BLOCKER=0`, `HIGH=0`

---

## EXISTING REUSABLE BACKEND

Strong reusable backend already exists for a bounded Sales Assistant V1:

- Canonical operational spine: `Customer -> Brand -> DigitalAsset -> Run -> Evidence -> Finding -> Recommendation -> Task`.
- Real outside-in public website discovery backend exists.
- Real AI route + agent + skill architecture exists.
- Real immutable report snapshot, PDF artifact, and secure share-delivery infrastructure exists.
- Real recurring automation engine exists.
- Real service catalog exists.

High-value reusable components:

- Public web read stack:
  - `MoxDop\Website\Discovery\PublicUrlSafety`
  - `MoxDop\Website\Discovery\PublicHttpFetcher`
  - `MoxDop\Website\Discovery\PublicPageExtractor`
  - `MoxDop\Website\Discovery\PublicSiteCrawler`
- Discovery processing:
  - `MoxDop\Website\Discovery\PublicDiscoveryService`
  - `MoxDop\Website\Discovery\DiscoveryCandidateBuilder`
  - `MoxDop\Website\Discovery\DiscoveryInferenceService`
  - `MoxDop\Website\Discovery\DiscoveryCandidateReviewService`
  - `MoxDop\Website\Discovery\Ai\WebsiteDiscoveryContextAgent`
- Canonical persistence and provenance:
  - `App\Models\Run`
  - `App\Models\Evidence`
  - `App\Models\DiscoveryCandidate`
  - `App\Models\BrandIntelligenceContext`
- Report and export primitives:
  - `App\Services\ClientValueStory\ClientValueStoryReadService`
  - `App\Services\ReportSnapshots\CreateReportSnapshotService`
  - `App\Services\ReportDelivery\GenerateReportPdfService`
  - `App\Services\ReportDelivery\ReportShareService`
  - `App\Services\ReportDelivery\CreateReportDeliveryService`
- Service and delivery vocabulary:
  - `App\Models\ServiceDefinition`
  - `App\Models\CustomerServiceScope`
  - `App\Models\Opportunity`
  - `App\Support\Playbooks\DefaultPlaybookCatalog`
- Scheduling and execution:
  - `App\Services\RecurringAutomation\RecurringAutomationDispatcher`
  - `App\Services\RecurringAutomation\ExecuteRecurringOccurrenceService`
  - `App\Services\RecurringAutomation\RecurringAutomationRegistry`

Bottom line:

- Reuse is real and substantial.
- The largest gap is not crawling, evidence, AI, or PDF generation.
- The largest gap is the absence of a prospect-native subject model and prospect-native persistence path.

---

## PUBLIC DISCOVERY REALITY

### Component classification

| Component | Reality |
| --- | --- |
| `PublicDiscoveryService` | REAL |
| `PublicSiteCrawler` | REAL |
| `PublicHttpFetcher` | REAL |
| `PublicPageExtractor` | REAL |
| `PublicUrlSafety` | REAL |
| `DiscoveryCandidateBuilder` | REAL |
| `DiscoveryCandidateReviewService` | REAL |
| `DiscoveryInferenceService` | REAL |
| `WebsiteDiscoveryContextAgent` | REAL |
| `PublicDiscoveryJob` | PARTIAL |
| `CompetitorDomainCollector` | PARTIAL |
| `DiscoveryCandidate` | REAL |
| `BrandIntelligenceContext` | REAL |
| `Run` | REAL |
| `Evidence` | REAL |
| `Finding` integration from discovery | UNWIRED |
| `Opportunity` integration from discovery | UNWIRED |
| `Recommendation` integration from discovery | UNWIRED |
| `/app` operator live discovery UI | DEMO-ONLY / UNWIRED |

### What is real today

Current website discovery is not fake:

- It starts from a real website `DigitalAsset`.
- It creates a real discovery `Run`.
- It fetches public pages through bounded HTTP.
- It writes real `Evidence`.
- It builds deterministic `DiscoveryCandidate` rows.
- It optionally adds AI inference candidates.
- It requires human review before mutating canonical Brand context.

### Why the operator sees `live discovery unavailable`

The backend is real, but the current `/app` operator surface is not wired to it.

Current reality:

- `/app/brands/{brand}` uses demo Livewire `BrandShow`.
- The public discovery partial renders a disabled unavailable control.
- `BrandShow::runPublicResearch()` only flashes a demo message.
- It does not queue `AsyncOperationService::queuePublicDiscovery()`.

Meanwhile, real discovery wiring exists elsewhere:

- The real trigger is on the Filament digital asset page action.
- The real review UI exists in the discovery relation manager.

### Why `PublicDiscoveryJob` is only PARTIAL

`PublicDiscoveryJob` is real queue wiring, but it wraps discovery inside an async wrapper `Run`, while `PublicDiscoveryService` creates its own discovery `Run`. That means:

- queue execution is real,
- evidence-bearing execution is real,
- but the wrapper run and the canonical discovery run are split.

This is acceptable for current discovery but would need to be cleaned up before scaling prospect research or intent radar.

### Production capability conclusion

`PublicDiscoveryService` is production-capable for its current narrow contract:

- existing `Customer`
- existing `Brand`
- existing website `DigitalAsset`
- operator-triggered use
- optional AI
- optional DataForSEO competitor enrichment

It is not prospect-capable today.

### Prospect compatibility today

Not safely compatible today.

Current discovery assumes:

- `Brand` exists,
- `Brand.customer_id` exists,
- website `DigitalAsset` exists,
- `Run.digital_asset_id` exists,
- `DiscoveryCandidate.brand_id` exists,
- `DiscoveryCandidate.digital_asset_id` exists,
- accepted candidates write into `BrandIntelligenceContext`.

Therefore Sales Assistant V1 cannot safely research a pre-customer prospect by reusing current discovery persistence as-is.

### Smallest clean change required

Reuse the public read pipeline, but do not reuse the current subject/persistence contract directly.

Smallest clean direction:

- keep the crawl/fetch/extract/infer code,
- add a prospect-native discovery subject,
- keep accepted facts separate from canonical Customer/Brand truth until conversion.

---

## PROSPECT GAP

No real `Prospect`, `Lead`, `SalesOpportunity`, or sales pipeline primitive currently exists.

Important exclusions:

- `Opportunity` means opportunity for an existing Customer/Brand relationship.
- `ClientRequest` means internal/customer-bound intake.
- `BusinessOutcome` explicitly avoids CRM-like lead/deal/pipeline semantics.

Required new canonical domain:

- `Prospect`

Recommended minimal scope for the new domain:

- intake identity,
- research state,
- qualification state,
- conversion state,
- owner,
- source,
- notes/activity,
- bounded reportability.

Recommended minimal statuses:

- `new`
- `researching`
- `qualified`
- `contacted`
- `meeting`
- `proposal`
- `won`
- `lost`

V1 note:

- If scope pressure is high, `contacted`, `meeting`, and `proposal` can be collapsed into notes/activity plus `qualified`.
- The canonical minimum remains separate from `Opportunity` and separate from `ClientRequest`.

---

## EVIDENCE / PROVENANCE

Current evidence architecture is strong and directly reusable in concept, but not yet in prospect scope.

What already exists well:

- canonical `Run`,
- canonical `Evidence`,
- evidence fingerprinting,
- normalized payloads,
- retrieval metadata such as source URL, retrieved time, normalization version, and freshness windows,
- AI-inference fallback that does not fabricate deterministic facts.

Sales Assistant V1 needs explicit provenance categories across prospect research:

- observed
- measured/provider
- derived
- AI inference
- operator
- unavailable

Recommended rule:

- never collapse observed facts, inferred needs, and service recommendations into one narrative blob.

Recommended shape:

- evidence item: source-attributed raw fact
- inference item: explicitly derived from evidence
- recommendation item: explicitly justified by evidence and inferences

Current architecture already points in this direction via:

- `Evidence`
- `DiscoveryCandidate`
- `BrandIntelligenceContext`
- agent/skill provenance

Gap:

- current `Evidence` is tied to canonical assets, so prospect research needs either:
  - a prospect-scoped evidence subject, or
  - a generalized subject reference abstraction.

Smallest clean recommendation:

- add prospect-scoped evidence/candidate persistence, mirroring the existing discovery review pattern.

---

## DIGITAL ESTATE REUSE

Current Digital Estate detection logic is partly reusable, but canonical `DigitalAsset` rows should not be created for unqualified prospects.

Reusable logic:

- public website crawl and extraction,
- social-link extraction from the website,
- domain/URL normalization,
- optional competitor enrichment,
- future-first-party binding semantics after conversion.

Do not do for V1:

- create real `DigitalAsset` rows during prospect research,
- create fake `Brand` rows only to make discovery work,
- auto-bind provider resources before conversion.

Recommended V1 representation before conversion:

- discovered website
- discovered social/profile URLs
- discovered public business identity signals
- discovered location/contact signals

These should initially live as prospect discovery evidence/candidates, not as canonical digital assets.

Only after conversion should accepted owned properties seed real `DigitalAsset` creation.

---

## AI / SALES INTELLIGENCE

Current reusable AI architecture is strong enough for Sales Assistant V1.

Already reusable:

- AI route resolution,
- provider failover,
- agent profile registry,
- Markdown skill registry,
- skill eligibility,
- bounded context assembly,
- structured output validation,
- advisory-only safety rules.

Current best reuse path:

- reuse the existing control plane,
- reuse the skill system,
- compose one bounded sales intelligence synthesis path,
- keep AI advisory only.

Recommended V1 internal Sales Intelligence structure:

- summary
- observed facts
- inferred needs
- recommended services
- services not recommended
- priority order
- why each recommendation exists
- likely objections / uncertainty
- first meeting focus
- suggested diagnostic questions
- suggested positioning
- evidence references
- confidence

What is missing:

- a prospect-native structured output contract,
- a prospect-native evidence pack,
- one composed Sales Assistant skill or agent workflow.

Recommended smallest skill strategy:

- reuse `website.brand-context-discovery`,
- optionally reuse GSC/keyword/search-term skills when first-party evidence exists,
- add one composed Sales Assistant skill rather than many new micro-skills.

Recommended smallest AI architecture:

- one prospect research / sales intelligence agent profile,
- one composed skill for prospect discovery interpretation,
- later specialized sub-skills only if operator experience proves necessary.

---

## SERVICE CATALOG

Current service-catalog reuse is strong.

What already exists:

- `service_definitions` catalog,
- `ServiceDefinition`,
- `CustomerServiceScope`,
- `Opportunity.service_definition_code`,
- service-aware playbook relevance.

This means Sales Assistant V1 does not need a new free-text-only services system.

Recommended V1 rule:

- recommended services should reference canonical `ServiceDefinition` where possible,
- explanation text can remain freeform,
- no pricing, packaging, or billing domain should be introduced.

What not to do:

- create a second service catalog,
- overload `BrandOffering` as the service recommendation list,
- make recommendations purely free-text when canonical service codes already exist.

---

## REPORT / PDF REUSE

Current report/PDF/share primitives are highly reusable as infrastructure.

Already reusable:

- immutable snapshot creation,
- checksum and idempotency,
- PDF artifact generation,
- secure OTP share delivery,
- delivery auditability.

Gap:

- current report registry is brand-only and currently centered on `client_value_story`,
- there is no current built-in split between internal and client-safe projections for one shared prospect report contract.

Recommended smallest approach:

- add a new prospect pre-analysis report type,
- generate two projections:
  - internal,
  - client-shareable.

Internal can include:

- qualification,
- sales strategy,
- service-fit reasoning,
- confidence,
- internal notes.

Client-shareable can include:

- observed situation,
- key facts,
- opportunities,
- suggested priorities,
- evidence-safe explanation.

Hard rule:

- internal strategy must never leak into the client-shareable view.

---

## PIPELINE

No existing canonical sales pipeline exists.

Smallest clean V1 pipeline:

- `new`
- `researching`
- `qualified`
- `contacted`
- `meeting`
- `proposal`
- `won`
- `lost`

Minimum supporting fields:

- owner
- source
- status
- next action
- next action date
- notes/activity

Do not build in V1:

- revenue forecasting,
- deal amount stages,
- weighted pipeline math,
- quote/pricing subsystem,
- generic CRM objects.

---

## CONVERSION

Recommended canonical path:

`Prospect -> won -> Customer -> Brand`

Smallest clean behavior:

1. prospect remains separate during research,
2. operator confirms conversion,
3. system checks duplicates,
4. create canonical `Customer`,
5. create canonical `Brand`,
6. create accepted `DigitalAsset` records only,
7. preserve historical prospect evidence,
8. allow selected facts to seed canonical Brand context and Digital Asset creation.

Recommended transferred fields:

- verified company name
- primary website/domain
- verified contact channels
- public location
- accepted owned-property URLs
- accepted research notes

Recommended preserved separately on Prospect:

- original raw intake,
- uncertain identity candidates,
- anonymous or low-confidence intent signals,
- internal sales rationale,
- historical research trail.

Duplicate safety today:

- evidence/candidate/report/binding idempotency is relatively strong,
- manual Customer/Brand creation dedupe is comparatively weak.

Therefore conversion should add explicit duplicate checks before creating Customer/Brand.

Recommended idempotency:

- one conversion command,
- one stable conversion key,
- one canonical result per prospect,
- no silent duplication.

---

## INTENT RADAR

Intent Radar does not fully exist today, but some building blocks do.

Already reusable:

- public website read,
- bounded public evidence acquisition,
- DataForSEO competitor and keyword enrichment,
- recurring automation engine,
- AI/skill interpretation patterns,
- evidence/provenance style.

Missing as a canonical domain:

- `SearchProfile`
- `IntentSignal`
- prospect-safe signal review flow
- operator-controlled source policy for paid search enrichment

Recommended minimal `SearchProfile`:

- name
- service intent
- include concepts
- exclude concepts
- location/language where useful
- active
- minimum confidence
- policy: allow paid calls or not

Recommended minimal `IntentSignal`:

- source URL
- source type
- observed snippet
- discovered/published time when known
- intent category
- intent confidence
- identity confidence
- linked prospect when known
- status
- provenance/evidence refs

V1 rule:

- anonymous public signals remain anonymous until identity is actually verified.
- search-result snippets are not canonical facts until fetched/verified.

---

## SEARCH CAPABILITIES

Capability ideas exist in docs and metadata, but a true runtime capability router does not exist yet.

Current reality:

- capability vocabulary exists,
- provider-specific adapters exist,
- eligibility and configuration guards exist,
- recurring adapter registries exist,
- but a general `public.web.search` / `public.intent.search` router is not implemented.

Smallest canonical contract for V1:

- `public.web.read`
- `public.web.search`
- `public.intent.search`

Smallest adapter shape:

- `isAvailable()`
- `health()`
- `search()`
- `read()`
- `supportsPaidCalls()`
- `supportsScheduling()`

Recommended V1 discipline:

- keep adapters small,
- keep routing explicit,
- keep unavailable state first-class,
- do not introduce marketplace/plugin complexity.

---

## DATAFORSEO / SEARCH PROVIDER

DataForSEO is the smallest existing real provider path for limited V1 intent discovery, but only with strict scope control.

What is already real:

- credential storage,
- account validation,
- endpoint allowlist,
- paid request fingerprinting,
- duplicate suppression,
- freshness reuse,
- bounded competitor and keyword calls.

What is not fully mature yet:

- no general search-provider platform,
- no universal budget ledger,
- no robust scheduled paid intent-harvesting product path,
- incomplete provider telemetry compared with the desired future state.

Safest V1 provider path:

- manual public website research,
- optional manual or explicitly policy-gated DataForSEO enrichment,
- no silent recurring paid search jobs.

V1 rule:

- paid providers must remain operator-controlled or policy-controlled,
- scheduler must not silently burn credits.

---

## AGENT-REACH ARCHITECTURAL MAPPING

Agent-Reach should be used as an architecture reference only, not a runtime dependency.

Useful ideas that map well to MoxDOP:

- capability abstraction,
- adapter-per-provider,
- fallback path,
- explicit unavailable state,
- provider health visibility.

Mapping onto current MoxDOP:

- capability abstraction -> existing capability vocabulary and skill metadata
- adapter -> existing provider-specific collectors/executors
- fallback -> AI route failover and optional provider use
- unavailable state -> current discovery and provider eligibility patterns
- provider health -> partially present observability framework

What should not be imported:

- external runtime,
- arbitrary CLI/tool bridge,
- plugin marketplace,
- unbounded agent runtime,
- scraping automation framework.

---

## SECURITY

Strong existing security foundations:

- SSRF-safe public URL validation,
- public HTTP bounds,
- no browser automation in discovery,
- no login/cookie scraping,
- encrypted credential storage,
- secret redaction,
- agent access denial for credential material,
- source provenance retention.

Material gaps for Sales Assistant V1:

- no demonstrated robots/policy enforcement hook in the public discovery crawl path,
- incomplete provider telemetry for non-Meta providers,
- no universal paid-call budget ledger or reservation system,
- no prospect-specific retention policy yet.

Security conclusions for V1:

- public intent radar must stay source-attributed,
- no private/authenticated content scraping,
- no CAPTCHA bypass,
- no login bypass,
- no anti-bot evasion,
- no autonomous outreach,
- no hidden paid-call loops.

---

## SCHEDULER REUSE

Scheduler reuse is strong.

Best reuse path:

- use the recurring automation engine,
- add a domain-owned schedule plus adapter for intent radar later.

Reusable behavior already exists:

- due discovery,
- durable occurrence ledger,
- claim/retry,
- stale-run reclaim,
- bounded misfire policies,
- idempotent occurrence keys,
- queue execution,
- observability hooks.

V1 note:

- do not implement recurring intent radar before the manual/provider policy model is clear.

---

## PRODUCT IA

Smallest recommended IA:

- one new top-level section: `Sales`
- inside it:
  - `Prospects`

Inside Prospects:

- Prospects
- Intent Radar
- Search Profiles

Inside a Prospect detail:

- Overview
- Research
- Sales Intelligence
- Activity
- Report

Why this is smallest:

- it keeps sales separate from existing Customer/Brand Operations,
- it avoids overloading current `Opportunities`,
- it adds only one new top-level sidebar category.

Do not add many separate top-level items.

---

## V1 SCOPE

Recommended `V1 — SHOULD SHIP BEFORE STAGING` only if the team deliberately decides this extension is worth doing now:

- Prospect domain
- manual prospect intake
- basic identity states: `verified`, `partial`, `unknown`
- bounded public website research
- prospect-native evidence/candidate persistence
- sales intelligence summary with evidence-linked recommendations
- canonical service-definition references in recommendations
- internal pre-analysis
- client-shareable pre-analysis
- minimal pipeline
- Prospect -> Customer + Brand conversion
- duplicate-safe conversion checks
- basic SearchProfile
- basic IntentSignal
- manual or explicitly policy-gated public intent discovery

Scope guard:

- V1 is useful only if it remains tightly bounded.

---

## V1.1

- recurring intent radar schedules
- stronger duplicate detection for conversion
- richer asset promotion review flow
- prospect report snapshot refinements
- provider-health dashboard for search providers
- better cost controls and budget visibility
- more than one intent source adapter
- post-conversion handoff polish into Operations

---

## LATER

- broader public search provider abstraction
- richer competitor comparison
- social intelligence beyond website-discovered links
- reputation/reviews/news monitoring
- generalized capability router
- multi-source intent aggregation
- semantic retrieval / memory enrichment
- sector learning from accumulated prospect research
- deeper proposal support

---

## DO NOT BUILD

- generic CRM
- deals/forecast/revenue pipeline math
- automated outreach
- email sequences
- phone enrichment marketplace
- hundreds of platform-specific scrapers
- LinkedIn automation
- Armut scraping
- Ekşi-specific scraping
- CAPTCHA bypass
- login bypass
- private-profile scraping
- autonomous recommendation approval
- autonomous task creation from sales research

---

## IMPLEMENTATION PROMPT A

Classification: MEDIUM

Exact bounded scope:

- introduce canonical `Prospect` domain and minimal statuses
- add manual prospect intake UI
- add prospect-native identity fields and owner/source/notes
- add prospect-native research subject/persistence
- reuse public web read stack for prospect research
- persist prospect research evidence/candidates with provenance
- reuse AI control plane for one structured Sales Intelligence output
- map recommendations to canonical `ServiceDefinition`
- keep all outputs advisory only

Why Prompt A is bounded:

- it reuses existing crawl, evidence, AI, and service vocabulary,
- it avoids scheduler work,
- it avoids broad provider/search expansion,
- it stops before conversion/report delivery polish if necessary.

Deliverable outcome of Prompt A:

- manual inbound prospect research works end-to-end.

---

## IMPLEMENTATION PROMPT B

Classification: MEDIUM

Exact bounded scope:

- add internal/client-shareable prospect pre-analysis report projections
- reuse PDF/share-delivery primitives for prospect report artifacts
- implement safe Prospect -> Customer -> Brand conversion flow
- add duplicate-check and idempotent conversion behavior
- add minimal SearchProfile and IntentSignal domains
- add one safest V1 intent-discovery provider path behind explicit policy
- add inspect/review path from intent signal into prospect creation

Why Prompt B is bounded:

- it builds on Prompt A’s prospect subject model,
- it reuses report/share infrastructure,
- it keeps intent discovery minimal and provider-limited,
- it avoids recurring/scheduled paid radar by default.

Deliverable outcome of Prompt B:

- operator can move from inbound or discovered prospect to research, report, and conversion.

---

## RISKS

- prospect research cannot safely reuse current Brand/DigitalAsset-bound persistence without a new subject model
- conversion may duplicate Customer/Brand unless stronger dedupe is added
- client-shareable projection must not leak internal sales strategy
- DataForSEO intent enrichment can create cost risk if scheduling or auto-refresh is introduced too early
- `/app` and real backend wiring are currently split in some discovery areas
- no robots-enforcement hook was confirmed in the current discovery crawl path
- provider telemetry is incomplete for a scaled paid-search feature

---

## FINAL VERDICT

Yes, a useful Sales Assistant V1 can be built in two focused implementation batches primarily by reusing current MoxDOP architecture, but only with clear limits.

Verdict: `YES_WITH_LIMITS`

Why:

- MoxDOP already has real reusable infrastructure for bounded public research, evidence provenance, AI reasoning, report/PDF generation, service vocabulary, and recurring automation.
- The missing core is not a giant platform capability. It is a clean prospect-native subject model and prospect-native persistence path.
- If the team keeps scope tight, avoids generic CRM expansion, avoids broad scraper ambitions, and keeps paid/provider search bounded and policy-controlled, two focused batches are realistic.
- If the team tries to ship a full multi-source intent platform, generalized search capability router, full CRM pipeline, and broad provider automation now, the scope becomes too large and breaks the current architecture boundaries.
