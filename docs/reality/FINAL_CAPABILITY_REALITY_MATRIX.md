# Final Capability Reality Matrix

Prompt 67 — authoritative MoxDOP capability reality table.

**Statuses only:** REAL · PARTIAL · DEMO · UNAVAILABLE  
**Manual verification:** live OAuth / SMTP / paid APIs = **NOT_MANUALLY_VERIFIED** unless a row notes otherwise (Prompt 67 did not claim live PASS).  
**Contracts:** `docs/architecture/CAPABILITY_REALITY_CONTRACT.md`, `docs/architecture/DEMO_ISOLATION_CONTRACT.md`.

Legend for **Demo dependency:** `none` · `catalog-only` · `session-chrome` · `test/harness`.

---

## Foundation

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Auth (web guard) | REAL | Laravel session auth | `/app`, `/system` login | User credentials | Feature auth tests | none | — | — |
| Users | REAL | `users` | Filament User resources | Filament / artisan admin | Role seeder tests | none | — | — |
| Roles / permissions | REAL | Spatie tables | Middleware / policies | `RoleAndPermissionSeeder` | Permission tests | none | — | — |
| Customers CRUD | REAL | `customers` | Filament `/admin` + root `/customers` portfolio | Eloquent / Livewire create | Portfolio/feature tests | none | Formal live UAT not re-run | NOT_MANUALLY_VERIFIED ops UAT |
| Brands CRUD | REAL | `brands` | Filament `/admin` + root `/brands` | Eloquent / Livewire | Portfolio tests | none | — | — |
| Digital Assets CRUD | REAL | `digital_assets` | Filament `/admin` + root `/assets` | Eloquent / Livewire | Asset tests | none | Long actions need queue workers in deploy | Redis/Horizon optional per env |
| Module registry | REAL | modules seeder/registry | Filament Modules | Seeder | Module tests | none | — | — |
| DatabaseSeeder (no fake Customer) | REAL | `DatabaseSeeder` | n/a | roles/modules/playbooks only | `DemoRealityFinalConvergenceTest` | none | — | — |
| Frozen operator sidebar IA | REAL | Milestone 5 freeze | Livewire nav at site root | n/a (frozen) | Route tests | session-chrome | Naming debt `Livewire\Demo` | Product change requires justification |
| Filament `/admin` admin | REAL | Panel id `app` path `/admin` (ADR-044) | Filament resources | Filament | Resource tests | none | Legacy `/app` `/system` → 410 | — |

---

## Integrations

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Google OAuth lifecycle | REAL | `GoogleOAuthService` + credential broker | Integration pages | OAuth callback + encrypted store | Google OAuth tests | none | Live console verification | NOT_MANUALLY_VERIFIED; needs Google Cloud apps |
| Google resource discovery | REAL | Discoverers + ExternalResources | Integration UX | Operator-triggered sync | Discovery tests | none | Some APIs manual | NOT_MANUALLY_VERIFIED live |
| Google resource binding | REAL | `ConfirmGoogleResourceBindingService` | Bind UX | Human confirm | Binding tests | none | No auto-bind | — |
| Meta authorization / discovery | REAL | Meta Core* + discoverers | Meta Integration | Operator sync | Meta tests | none | Refresh may be sync | NOT_MANUALLY_VERIFIED in Prompt67 env |
| Meta resource binding | REAL | AssetBinding | Meta Integration | Human confirm | Binding tests | none | — | — |
| Integrations hub Google/Meta cards | REAL | Integration read models | `OperatorIntegrationsHubQuery` | n/a (display) | Hub/feature tests | none | — | — |
| Integrations hub other providers | REAL | Config presence checks | `truthfulProviderCard` | n/a | `DemoRealityFinalConvergenceTest` | none | Deep connection models not built | Shows configured/not_connected only |
| DataForSEO connector config | PARTIAL | Central integration + collectors | Hub + Website SEO paths | Credential/config | DFS tests | none | Paid API live refresh | NOT_MANUALLY_VERIFIED; cost guards |
| WordPress site connector | PARTIAL | Site connector services | `/app` site connectors | Pairing/probe | Connector tests | none | Full catalog sections deferred historically | Deploy + WP credentials |
| AI provider hub cards | PARTIAL | Env key presence | Hub truthful cards | Env | Hub test | none | Not full connection UX | Keys in environment |

---

## Collection & data plane

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Collection engine control plane | REAL | CollectionRun / ResourceRun / DatasetRun | Monitoring + Filament Runs | Planner / jobs | Collection tests | none | — | Queue workers in deploy |
| Collection scheduler / lifecycle | REAL | Scheduler + policy contracts | Due queries / commands | Scheduler ticks | Scheduler tests | none | — | `schedule:work`/cron |
| Data pool foundation | REAL | Raw object store + typed facts | Pool repositories | WarehouseWriter | Data pool tests | none | Not all providers populated | PG partitions vs SQLite |
| Collection monitoring UX | REAL | `CollectionRunMonitorQuery` | Integrations MonitoringPanel | n/a | Monitoring tests | none | Reverb optional | Polling fallback |
| GA4 production collector | REAL | GA4 datasets / executors (Prompt 18 lineage) | Pool gates | Collect jobs | GA4 collector tests | none | Live API | NOT_MANUALLY_VERIFIED |
| GSC production collector | REAL | GSC datasets | Pool gates | Collect jobs | GSC collector tests | none | Live API | NOT_MANUALLY_VERIFIED |
| Google Ads production collector | REAL | Google Ads datasets | Pool gates | Collect jobs | Ads collector tests | none | Live API | NOT_MANUALLY_VERIFIED |
| Meta Ads production collector | REAL | Meta datasets | Pool gates | Collect jobs | Meta collector tests | none | Live Graph | NOT_MANUALLY_VERIFIED in Prompt67 |
| Data freshness / incremental | PARTIAL | Freshness services | Specialist chips / ops | Collectors | Freshness tests | none | Sibling Demo chips removed; coverage still provider-dependent | Binding + collect success |
| Integrity / reconciliation | PARTIAL | Integrity services | Ops/collection state | Repair jobs | Integrity tests | none | Not all datasets | — |

---

## Specialists (sub-capabilities)

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4 workspace (bound, pool KPIs) | REAL | `Ga4SpecialistReadService` + pool | `/app` GA4 specialist | Collectors only | GA4 migration tests | catalog-only for `ga4-atlas` | Users period sum UNAVAILABLE by rule | Binding + data |
| GA4 residual cards (needs_attention, ops findings, business_actions) | UNAVAILABLE | cleared arrays / provenance | same | n/a | Convergence + GA4 tests | none on real | No specialist-local Finding feed | Wire Operations reads if desired |
| GA4 Atlas catalog workspace | DEMO | `Ga4WorkspaceFixtures` | Demo catalog asset id | DemoState/session | Demo route tests | catalog-only | — | Explicit Demo only |
| GSC workspace (bound KPIs) | REAL | `GscSpecialistReadService` | `/app` GSC | Collectors | GSC tests | catalog-only for `gsc-atlas` | Indexing totals / attention heuristics | Binding + data |
| GSC search attention / clusters / brand-nonbrand | UNAVAILABLE | cleared / unavailable notes | same | n/a | GSC Prompt67 clearing | none on real | Heuristics Demo-only | Product mapping store |
| GSC Atlas catalog workspace | DEMO | `GscWorkspaceFixtures` | catalog id | n/a | Demo tests | catalog-only | — | — |
| Google Ads workspace (bound KPIs) | REAL | `GoogleAdsSpecialistReadService` | `/app` Google Ads | Collectors | Ads tests | catalog-only for `gads-atlas` | Search clusters/inbox | Binding + data |
| Google Ads residual Demo domains | UNAVAILABLE | cleared | same | n/a | Prompt67 clearing | none on real | — | — |
| Google Ads Atlas catalog | DEMO | `GoogleAdsWorkspaceFixtures` | catalog id | n/a | Demo tests | catalog-only | — | — |
| Meta Ads workspace (bound KPIs) | REAL | `MetaAdsSpecialistReadService` | `/app` Meta | Collectors | Meta tests | catalog-only for `meta-atlas` | Reach/frequency/result mix UNAVAILABLE by contract | Binding + data |
| Meta Ads residual Demo domains | UNAVAILABLE | cleared | same | n/a | Prompt67 clearing | none on real | — | — |
| Meta Campaigns list (production) | REAL | MetaAdsSpecialistReadService | `CampaignsPage` | n/a | Meta/product tests | catalog-only alternate | Detail gating | Real campaign ids |
| Meta Campaign/Ad detail (production ids) | PARTIAL | Gated detail pages | CampaignDetail/AdDetail | n/a | Page tests | catalog-only for Atlas | Full creative taxonomy not inventable | Real snapshots |
| Website specialist analytics (production) | UNAVAILABLE | `UnavailableWorkspaceShells::website` | Website Overview | Observations not wired to `/app` shell | `DemoRealityFinalConvergenceTest` | catalog-only for `web-atlas` | Observations may exist elsewhere; `/app` still unavailable shell | Production workspace migration |
| Website Atlas catalog | DEMO | `WebsiteWorkspaceFixtures` | catalog id | n/a | Demo tests | catalog-only | — | — |
| GBP local profile (thin collector) | PARTIAL | GBP collector / binding | Integration / limited reads | Collect | GBP tests | catalog-only for `gbp-atlas` | Reputation intelligence | — |
| GBP local rank grid (production) | UNAVAILABLE | empty grid note in shell | `UnavailableWorkspaceShells::gbp` | n/a | GBP Overview gating | none on real | No fabricated ranks | Full local grid productization |
| GBP Atlas catalog | DEMO | `GbpWorkspaceFixtures` | catalog id | n/a | Demo tests | catalog-only | — | — |
| Instagram analytics (production) | UNAVAILABLE | `UnavailableWorkspaceShells::instagram` | Instagram Overview | n/a | Overview gating | none on real | No provider analytics | Provider support |
| Invented health scores | UNAVAILABLE | forbidden | n/a | n/a | Observability contracts | none | Must stay null/absent | Do not implement fake scores |

---

## Operations

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Findings persistence + fingerprint | REAL | `findings` | Filament + FindingReadService | Analyzers / services | Finding tests | none | — | — |
| FindingsIndex (`/app`) | REAL | `FindingReadService` | `FindingsIndex` Livewire | Acknowledge/resolve on model | `DemoRealityFinalConvergenceTest` | session-chrome filters/flash | — | — |
| Opportunities persistence + detection | REAL | `opportunities` | Operations index + services | Detectors | Opportunity tests | catalog fixtures non-Operations only | — | — |
| Recommendations | REAL | `recommendations` | Operations + RecommendationReadService | Services / handoff | Recommendation tests | none | — | — |
| Work / Tasks | REAL | work/task tables | Operations Livewire + Filament | Handoff / CRUD | Work-task tests | none | — | — |
| Activity center | REAL | Activity read models / Runs | Activity index + Filament Runs | Async operations | Activity tests | none | — | Queue |
| Notifications persistence | REAL | notification tables/policies | Bell / policies | Prompt47 wiring | Notification tests | none | Mail channel | SMTP config |
| Approvals / QA persistence | REAL | prior Prompt persistence | Work segments | Services | Approvals tests | catalog session states possible | — | — |
| Playbooks persistence | REAL | playbooks + seeder | Settings/ops | `SeedDefaultPlaybooks` | Playbook tests | none | — | — |
| Recurring reviews / automation | REAL | recurring engine | Schedules / occurrences | Engine adapters | Recurring tests | none | — | Scheduler |

---

## Value & reporting

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Business Outcomes | REAL | definitions/observations | Brand Value + services | Manual/CSV | Outcome tests | catalog fixtures isolated | — | — |
| Client Value Story projection | PARTIAL | `ClientValueStoryReadService` | Brand value tabs | n/a | Value story tests | catalog story fixtures retained | Dashboard home narrative removed | — |
| Dashboard recentValue | UNAVAILABLE | `[]` on Dashboard | `/app` dashboard | n/a | Convergence test | none | No Atlas narrative | Wire real projection if product wants |
| Agency awaiting_decision | REAL | RecommendationReadService | AgencyExecutionFixtures dashboard | n/a | Fixture/service tests | none | — | — |
| Agency system_exceptions / recent_outcomes | UNAVAILABLE | cleared `[]` | dashboard exec | n/a | Prompt67 clearing | none | — | — |
| Report Snapshots | REAL | snapshot tables/contracts | Report UX | Snapshot builders | Snapshot tests | none | — | Storage |
| Report PDF / share / delivery | PARTIAL | artifact + delivery contracts | Share routes | PDF/mail jobs | Delivery tests | none | Mail/storage | SMTP + disk/S3 |
| Authenticated report share | REAL | share contracts | Tokenized routes | Share create | Share tests | none | OTP/mail | Deploy secrets |

---

## AI / memory / assistant

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AI agent operational execution | PARTIAL | Agent profiles + jobs | Settings AI + guidance | Queued guidance | Agent execution tests | none | Not all specialists execute | API keys |
| Intelligence memory architecture | PARTIAL | Memory contracts/services | Context packs | Memory writers | Memory tests | none | Coverage breadth | — |
| Intelligence retrieval (Website path) | PARTIAL | Retrieval layer | Agent context | n/a | Retrieval tests | none | Other agents pending | — |
| Intelligence evaluation harness | REAL | Prompt 55 eval | CLI/harness | n/a | Eval tests | test/harness | Not operator UI | — |
| Intelligence scheduling | REAL | Trigger/DAG services | Scheduler | Triggers | Scheduling tests | none | — | Scheduler |
| Sector learning / privacy | PARTIAL | Sector contracts | Controlled reads | Artifact pipeline | Sector tests | none | Strict privacy bounds | — |
| Brand experience records | PARTIAL | Brand experience services | Brand surfaces | Services | Experience tests | none | — | — |
| Interactive Assistant chat runtime | UNAVAILABLE | Prompt 56 architecture docs only | n/a | n/a | Architecture tests if any | none | No live chat | Future runtime |
| Skill normalization catalog | PARTIAL | Skill docs + registry | Settings skills | n/a | Skill tests | none | External skill adoption gated | — |

---

## Hardening

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Credential encryption | REAL | encrypted casts + brokers | Brokers only | OAuth/credential services | Security tests | none | Key rotation ops | `APP_KEY` |
| Tenant isolation / permissions | REAL | TenantScopeGuard + Spatie | All tenant queries | n/a | Security tests | none | — | — |
| Security logging redaction | REAL | SecurityRedactor | Logs | Audit recorder | Redaction tests | none | — | — |
| Observability alert evaluation | REAL | Ops alert tables + evaluator | `/ops/health-snapshot`, widget | `evaluate-alerts` | Observability tests | none | Cron registration | Deploy schedule |
| Provider API telemetry | PARTIAL | ProviderApiCounter | Snapshot dimensions | Meta client wired; others API-ready | Observability tests | none | Remaining HTTP clients | Wire-up |
| Performance scale harness | REAL | `moxdop:performance:benchmark` | CLI | Fixtures | Perf tests | test/harness | Not prod dependency | — |
| Health score | UNAVAILABLE | forbidden (`overall_score=null`) | Snapshot | n/a | Observability contracts | none | Must remain absent | — |

---

## Explicit Demo Mode (aggregate)

| Capability | Status | Canonical source | Read path | Write path | Tests | Demo dependency | Gap | Blocker |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Atlas Demo catalog portfolio | DEMO | `DemoCatalog` | Demo portfolio routes | DemoState only | Demo product route tests | catalog-only | Not production Customers | Isolation must hold |
| DemoState session | DEMO | `DemoState` | `/app` chrome / catalog | Session | Demo reset tests | session-chrome | — | Never Findings source of truth |
