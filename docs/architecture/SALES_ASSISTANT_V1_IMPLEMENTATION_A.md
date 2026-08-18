# SALES ASSISTANT V1 — IMPLEMENTATION BATCH A

Status: **PASS** (Prospect domain + manual intake + public research + sales intelligence)

Authority: `docs/architecture/SALES_ASSISTANT_V1_CONVERGENCE_AUDIT.md`

---

## Prospect domain

- Canonical model: `App\Models\Prospect`
- Pipeline statuses: `new`, `researching`, `qualified`, `contacted`, `meeting`, `proposal`, `won`, `lost`
- Identity states: `verified`, `partial`, `unknown` (manual entry defaults to `unknown`)
- Owner: nullable `owner_user_id` → `User` (same semantics as Customer responsibility)
- Source: controlled enum (`whatsapp`, `phone`, `email`, `referral`, `website`, `manual`, `other`)
- Conversion placeholders only: `converted_customer_id`, `converted_brand_id`, `converted_at` (Batch B)

**Customer / Brand / DigitalAsset pollution:** none. Research and intake do not create operational spine entities.

---

## Research persistence architecture

Prospect research reuses the **existing public read pipeline** without mutating Brand-bound tables:

| Reused | New (prospect-native) |
| --- | --- |
| `PublicSiteCrawler` | `prospect_research_runs` |
| `PublicHttpFetcher` / `PublicUrlSafety` | `prospect_evidence` |
| `PublicPageExtractor` | `prospect_discovery_candidates` |
| `DiscoveryCandidateBuilder` (fact shaping) | `prospect_sales_intelligence` |
| `PublicUrlNormalizer` | `prospect_activities` |

**Why not polymorphic `Evidence`?** Existing `evidence` / `runs` require `digital_asset_id` and Brand context. Broad polymorphism would risk regressions on production discovery. Prospect-native tables mirror the discovery pattern with a smaller blast radius.

**Run model:** `ProspectResearchRun` (single run per execution — no async wrapper/canonical split).

**Idempotency:** `prospect_evidence.fingerprint` (SHA-256 of module + type + source URL) and `prospect_discovery_candidates.fingerprint` (prospect-scoped candidate identity).

**Missing website:** research completes as `partial` with message “Website not provided / not discovered”; observed evidence may be empty; sales intelligence still attempts advisory output from inquiry (fixture/AI).

**AI unavailable:** observed research may still complete; `prospect_sales_intelligence.status = unavailable` with truthful reason (no fake recommendations).

---

## Crawler reuse

- `App\Services\Prospects\ProspectResearchService` orchestrates crawl → evidence → candidates → intelligence.
- `App\Jobs\Prospects\ProspectResearchJob` executes queued runs (sync in PHPUnit / E2E).
- PHPUnit/E2E: `MOXDOP_PROSPECT_RESEARCH_FIXTURES=true` or fixture host `prospect-fixture.moxdop-e2e.test` — no live external HTTP.

---

## Evidence / provenance

Canonical labels on `prospect_evidence` / candidates:

- `observed` — direct public page extraction
- `derived` — crawl summaries
- `ai_inference` — reserved for future inference rows (Batch B+)
- `operator` — manual operator facts (future)
- `unavailable` — explicit missing state

AI inference is never stored as observed fact.

---

## AI Control Plane

| Item | Value |
| --- | --- |
| Route key | `sales.prospect_intelligence` (`AiRouteKeys::SALES_PROSPECT_INTELLIGENCE`) |
| Agent | `sales.prospect_intelligence_analyst` |
| Skill | `prospect-sales-intelligence` (`resources/skills/prospect-sales-intelligence/SKILL.md`) |
| Provider wiring | `SalesServiceProvider` → `AiRouteRegistry` + `AgentProfileRegistry` + `SkillRegistry` |
| Invocation | `ProspectSalesIntelligenceService` via `SalesProspectIntelligenceAgent` (structured output) |

**ServiceDefinition mapping:** recommendations use `service_definition_code` validated against `ServiceDefinition` + `AgencyServiceOptions`. Unknown codes are dropped; unmapped ideas belong in `uncertainties`.

**Human control:** AI does not change status, create Customers, or send outreach. Operators control pipeline and identity.

---

## Activity

`BrandContextActivity` / `DomainEvent` remain Customer–Brand bound. Prospect uses **`prospect_activities`** via `ProspectActivityRecorder` (created, updated, research started/completed/failed, status changed, intelligence generated).

---

## Operator UI

| Surface | Route |
| --- | --- |
| List | `/app/prospects` |
| Create | `/app/prospects/create` |
| Detail | `/app/prospects/{id}` tabs: Overview, Research, Sales Intelligence, Activity |

Sidebar: **Sales → Prospects** (`DemoMenu`).

TR/EN: `lang/*/operator.php` → `operator.prospects.*`

---

## Security

- Public HTTP(S) only; `PublicUrlSafety` on real URLs
- Fixture host allowed only when `MOXDOP_PROSPECT_RESEARCH_FIXTURES=true`
- No localhost/private network crawl in production path
- Operator auth required (`auth` + `EnsureDemoAppAccess`)

---

## Batch B gaps (deferred)

- Prospect report + client-shareable PDF
- Prospect → Customer/Brand conversion (duplicate-safe)
- SearchProfile / IntentSignal / public intent discovery
- Brand Public Discovery convergence (optional reuse of prospect architecture)

---

## Key files

```
app/Models/Prospect*.php
app/Services/Prospects/*
app/Jobs/Prospects/ProspectResearchJob.php
app/Providers/SalesServiceProvider.php
app/Livewire/Demo/Sales/*
resources/views/livewire/demo/sales/*
tests/Feature/Prospects/ProspectSalesAssistantBatchATest.php
tests/e2e/11-sales-prospect.spec.js
```
