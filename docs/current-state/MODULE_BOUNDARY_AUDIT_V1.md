# Module Boundary Audit V1

> **HISTORICAL SNAPSHOT**  
> This document reflects an earlier project state and is **NOT** the canonical current product truth.  
> For current truth consult: `docs/MASTER_SPEC.md`, accepted ADRs, `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`, and `docs/PROJECT_STATUS.md` where current.


> **Milestone:** MODULE BOUNDARY + KNOWLEDGE / MEMORY ARCHITECTURE AUDIT V1  
> **Base main:** `61bbfc8` (Integrations Workspace V2 / PR #107)  
> **Status:** Architecture audit + targeted repair + enforcement  
> **Not in scope:** AI Provider Routing, Agents/Skills runtime, RAG/embeddings, Meta/GEO/GBP Reputation product expansion

Classification vocabulary:

| Code | Meaning |
| --- | --- |
| **A** | Correct Core / shared |
| **B** | Correct module |
| **C** | Legacy compatibility debt (retain; thin facade or still-used probe) |
| **D** | Clear boundary violation |
| **E** | Ambiguous — do not move yet |

Do **not** move code solely for folder purity.

---

## Locked distinctions

| Concept | Owns |
| --- | --- |
| Integration | External provider connection (how we authenticate/call) |
| Module | Business/domain capability (what the data means for product) |
| Agent | Bounded AI persona/workflow (**planned**) |
| Skill | Versioned analytical methodology (**planned**) |

Provider ≠ Module. Integration ≠ Module. Agent ≠ Module. Skill ≠ Module.

---

## Core / shared (A) — representative

- Models: Customer, Brand, DigitalAsset, Run, Evidence, Finding, Recommendation, Task
- Integration stack: `CoreIntegration*`, `ProviderRegistry`, Google/DataForSEO/OpenAI transport & credential resolvers
- Generic Finding lifecycle / bound evidence rule registry
- Auth, RBAC, Module Registry primitives
- Brand Intelligence provider (cross-module Brand Context access)
- Cross-asset consistency orchestration services/jobs (**E/A** — cross-module; retain in Core)

## Correct module ownership (B) — representative

### Website (`app-modules/website`)

- DocumentHead diagnosis
- GSC/GA4 bound collectors
- Performance Findings evaluators
- GSC striking-distance / SEO Intelligence / DataForSEO Website semantics
- Website AI recommendation orchestration (`WebsiteAiRecommendationService`, agent, grounding, fingerprint)
- Website workspace presenters

### Google Ads (`app-modules/google-ads`)

- Bound collectors / campaign performance Finding semantics

### Google Business Profile (`app-modules/google-business-profile`)

- Bound collectors / profile Finding semantics

---

## Clear boundary violations (D)

| Component | Notes | Repair in V1 |
| --- | --- | --- |
| `App\Services\WebsiteDiagnosisService` (+ Core Support parsers/catalog used only for crawl/SSL/robots/sitemap/canonical) | Large Website domain diagnosis still in Core; already delegates DocumentHead* to module | **Retained** with architecture allowlist — moving ~1.7k LOC + parser island would be a mass refactor; behavior preserved |
| `App\Services\GoogleAdsLandingFinalUrlsCollectService` | Google Ads collector path still in Core; module has bound collector path | **Retained** as legacy dual-path debt |

---

## Legacy compatibility debt (C)

| Component | Status |
| --- | --- |
| `App\Services\WebsiteAiInsightService` | **Thin facade** → `MoxDop\Website\Ai\WebsiteAiRecommendationService` — **retain** |
| `App\Ai\Agents\WebsiteFindingInsightAgent` | **Deprecated thin alias** → module `WebsiteRecommendationAgent` — **retain** |
| `App\Services\*ConnectionProbeService` | Still referenced by product UI / tests (e.g. WordPress manage path). **Compatibility/deprecation debt** — do not broad-delete |
| Core Filament Website relation managers importing module workspace/AI | Acceptable composition surfaces; listed on architecture allowlist |

---

## Ambiguous (E) — do not move yet

- Cross-asset consistency services under `app/Services` and related Jobs
- Instagram / Meta Ads collect helpers still in Core pending stronger Meta module productization
- Module → Core Filament URL helpers (e.g. Website workspace linking FindingResource)

---

## sample-module decision

| Question | Answer |
| --- | --- |
| Needed by internachi/modular packaging smoke? | Yes — Composer package + `SampleModuleLoadedTest` |
| Developer fixture/example? | Yes |
| Production operator UI? | **No** |

**V1 repair:** hide `sample-module` from operator Module Registry (`ModuleCatalog` + `ModuleRegistry::scopeOperatorVisible` + Filament `getEloquentQuery`). Row may still be seeded for packaging tests.

---

## Module Registry product semantics

Operator-visible modules:

- `website`
- `google-ads`
- `google-business-profile`

Future: `meta-ads` (when productized).

**Not modules:** OpenAI, DataForSEO, Anthropic, Gemini, OpenRouter, Google, Meta (these are Integrations / providers).

---

## External GitHub repository classification rule

When reviewing an external GitHub repository, classify first:

| External kind | MoxDOP mapping |
| --- | --- |
| Provider / external service | **Integration** |
| Business domain | **Module** |
| Analytical methodology | **Skill** |
| Reference taxonomy / rule source | Improve existing Module/Skill |
| Runtime architecture | Selectively evaluate; never adopt automatically |

Examples: OpenAI → Integration; MarketingSkills → Skill methodology/reference; Claude SEO → Skill methodology; HEAD → Website rule/Skill taxonomy; OpenSEO → Website/DataForSEO implementation reference; Meta Ads MCP → future Meta Ads Module reference (MCP/write rejected); Google Reviews Scraper Pro → GBP Review Intelligence ideas only (scraper rejected); GEO SEO Claude → future Website GEO Skill reference.

Persisted also in `docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md` and `docs/product/KNOWLEDGE_MEMORY_ARCHITECTURE.md`.

---

## Architecture regression protection

`tests/Unit/ModuleBoundaryArchitectureTest.php`:

- Core must not import module implementation namespaces outside a **small explicit allowlist**
- Modules must not import sibling module implementations
- Modules may depend on Core models / shared Integrations infrastructure
- Operator catalog excludes fixtures and provider keys
- Website AI Core facade remains a thin delegate

---

## Dependency direction

Preferred:

```text
MODULE → CORE contracts / shared infrastructure
```

Core must not depend directly on module domain implementations except documented allowlisted composition/facade paths.

---

## Remaining boundary debt (exact)

1. Move `WebsiteDiagnosisService` + dedicated parsers/catalog into `app-modules/website` behind thin Core facades (when a dedicated migration PR is justified).
2. Consolidate `GoogleAdsLandingFinalUrlsCollectService` with module bound collector; retire Core path when safe.
3. Gradually relocate still-used `*ConnectionProbeService` domain probes behind module/integration health surfaces.
4. Reduce Core Filament ↔ module coupling over time (presenters already in module; resources still Core).
5. Cross-asset orchestration ownership review (keep Core vs introduce thin coordination contract).

---

## Non-goals confirmed

- Vector RAG / embeddings: **NO**
- DB engine change: **NO**
- Migrations in this milestone: **NONE**
- ADR: **none added** (ADR-007 / ADR-009 / ADR-032 / ADR-033 / ADR-035 already cover Core/module rules)
