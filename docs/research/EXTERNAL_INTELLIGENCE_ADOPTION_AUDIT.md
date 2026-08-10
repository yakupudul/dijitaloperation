# DOP External Intelligence Adoption Audit

> **Status:** Living research audit (concepts + adoption decisions)  
> **Last reviewed:** 2026-08-10  
> **Branch context at update:** Agent Reach + Capability Layer + Outside-in Discovery docs V1 (base `ec31bde`)  
> **Scope:** Track external reference repositories; record what MoxDOP may adapt vs reject.  
> **Non-goals of this file:** Runtime implementation, package installs, MCP servers, migrations, or wholesale source copies.

**Authority reminder**

| Layer | Role |
| --- | --- |
| `docs/MASTER_SPEC.md` + accepted ADRs | Source of truth for MoxDOP architecture |
| Official provider / framework docs | Source of truth for API/runtime facts |
| This audit | Reference registry for external repos — **not** architecture |

External repositories are references. They do **not** override MASTER_SPEC, ADRs, or official provider documentation.

### Classification rule (before adoption)

When an external GitHub repository is reviewed, classify it first:

| External kind | MoxDOP mapping |
| --- | --- |
| Provider / external service | **Integration** |
| Business domain | **Module** |
| Analytical methodology | **Skill** |
| Capability / adapter routing architecture | **Capability / Adapter** (planned shared layer — ≠ Integration ≠ Module ≠ Agent ≠ Skill) |
| Reference taxonomy / rule source | Improve existing Module/Skill |
| Runtime architecture | Selectively evaluate; never adopt automatically |

Examples:

| Repository / concept | Classification |
| --- | --- |
| OpenAI | Integration |
| MarketingSkills | Skill methodology/reference |
| Claude SEO | Skill methodology |
| HEAD | Website rule/Skill taxonomy |
| OpenSEO | Website/DataForSEO implementation reference |
| Meta Ads MCP | Future Meta Ads Module reference (MCP/write **REJECTED RUNTIME**) |
| Google Reviews Scraper Pro | GBP Review Intelligence ideas only; scraper **REJECTED RUNTIME** |
| GEO SEO Claude | Future Website GEO Skill reference |
| Agent Reach | Runtime architecture + Skill/Capability reference (**PLANNED REFERENCE**; direct runtime **NO**) |

Do **not** create one Module per GitHub repository. Integration ≠ Module ≠ Agent ≠ Skill ≠ Capability ≠ Adapter.

---

## Tracked External Reference Repositories

When future work touches SEO, AI Skills, Meta, GBP reviews, GEO, structured data, **capability routing**, or **outside-in Discovery**, **consult this audit first** — do not ask the operator to re-supply this list.

| # | Repository | Canonical URL |
| --- | --- | --- |
| 1 | `coreyhaines31/marketingskills` | https://github.com/coreyhaines31/marketingskills |
| 2 | `joshbuchea/HEAD` | https://github.com/joshbuchea/HEAD |
| 3 | `AgriciDaniel/claude-seo` | https://github.com/AgriciDaniel/claude-seo |
| 4 | `every-app/open-seo` | https://github.com/every-app/open-seo |
| 5 | `zubair-trabzada/geo-seo-claude` | https://github.com/zubair-trabzada/geo-seo-claude |
| 6 | `garmeeh/next-seo` | https://github.com/garmeeh/next-seo |
| 7 | `pipeboard-co/meta-ads-mcp` | https://github.com/pipeboard-co/meta-ads-mcp |
| 8 | `georgekhananaev/google-reviews-scraper-pro` | https://github.com/georgekhananaev/google-reviews-scraper-pro |
| 9 | `Panniantong/Agent-Reach` | https://github.com/Panniantong/Agent-Reach |

### Adoption status vocabulary

| Status | Meaning |
| --- | --- |
| **ADOPTED** | Concrete MoxDOP product behavior shipped using adapted concepts |
| **PARTIALLY ADOPTED** | Some concepts already influence shipped product; more selective adaptation remains |
| **PLANNED REFERENCE** | Intentional future reference for a named milestone; **NOT IMPLEMENTED** |
| **REJECTED RUNTIME** | Useful concepts may exist, but runtime/installation path is rejected |
| **RESEARCH ONLY** | Taxonomy/inspiration only; no near-term product commitment |
| **PRODUCT-CONCEPT REFERENCE ONLY** | Product ideas allowed; scraper/runtime explicitly rejected |

### Maintenance policy

When an external repository is re-reviewed:

1. Update **this same file** (do not create a new audit file for the same repos).
2. Refresh `Last reviewed` / per-entry `reviewed_at`.
3. Note notable upstream changes.
4. State whether the MoxDOP adoption decision changed.
5. Keep URLs in the registry above exactly once.

---

## Adoption matrix (canonical)

| Repository | Status | Category | Current MoxDOP influence | Future target |
| --- | --- | --- | --- | --- |
| MarketingSkills | **PARTIALLY ADOPTED** | Skill methodology | Brand Intelligence Context concepts | Agent Profiles + Skill Library (**PLANNED**) |
| HEAD | **PARTIALLY ADOPTED** | Taxonomy | Website Document Head Diagnosis subjects | Canonical / hreflang / social / head expansion |
| Claude SEO | **PARTIALLY ADOPTED** | Skill methodology | Recommendation methodology (observation → action → signals) | Curated Skill methodology (**PLANNED**) |
| OpenSEO | **PARTIALLY ADOPTED** | Implementation reference | DataForSEO Integration + cost/freshness + GSC opportunities + SEO Intelligence Light | Selective domain/competitor/backlink/rank/AI visibility |
| GEO SEO Claude | **PLANNED REFERENCE** | Skill / GEO | — | AI Search / GEO Intelligence Skill (**NOT IMPLEMENTED**) |
| Next SEO | **RESEARCH ONLY** | Taxonomy | — | Structured Data Intelligence V2 taxonomy (**NOT IMPLEMENTED**) |
| Meta Ads MCP | **PLANNED REFERENCE** | Module surface (MCP rejected) | — | Meta Ads **read-only** module concepts; MCP/write **REJECTED RUNTIME** |
| Google Reviews Scraper Pro | **PRODUCT-CONCEPT REFERENCE ONLY** | Product concepts (scraper rejected) | — | GBP Reputation Intelligence via official APIs; scraper **REJECTED RUNTIME** |
| **Agent Reach** | **PLANNED REFERENCE** | **Runtime Architecture + Skill/Capability Reference** | Conceptual only | Capability Registry/Router; Discovery Intelligence; Skill trigger/standing-rule design — **direct runtime adoption NO** |

### Agent Reach adoption summary (quick)

| Field | Decision |
| --- | --- |
| STATUS | **PLANNED REFERENCE** |
| CATEGORY | Runtime Architecture + Skill/Capability Reference |
| CURRENT ADOPTION | Conceptual only |
| VALUE | **VERY HIGH** |
| DIRECT RUNTIME ADOPTION | **NO** |
| USEFUL FOR | Capability abstraction; ordered adapters; health probing; backend routing; Skill trigger / standing-rule design |
| REJECTED | Direct runtime dependency; arbitrary CLI execution; browser-cookie scraping as default; MCP as Core; agent-direct external data bypassing Evidence |
| FUTURE | Agent/Skills contracts; Capability Registry; Discovery Intelligence |

---

## Hard MoxDOP constraints (unchanged)

1. Adapt concepts **to** MoxDOP — do not redesign Core around external apps.  
2. Agency-internal ops only; **no SaaS / client portal**.  
3. External integrations remain **read-only** (no campaign/budget/ad/creative writes).  
4. Prefer **Run / Evidence** provenance over parallel cache DBs.  
5. Deterministic catalog rules ≠ AI judgment ≠ blog heuristics.  
6. No MCP as product core; no unbounded multi-agent autonomy (MASTER_SPEC §11 / ADR-030 path; ADR-041 OpenAI Integration).  
7. AI is advisory: drafts Recommendations; does not auto-create Findings/Tasks; does not silently overwrite deterministic Recommendations.  
8. Agents / Modules should eventually depend on **Capabilities**, not hardcode concrete adapters where avoidable (**PLANNED** — see Capability notes below and `docs/product/DISCOVERY_INTELLIGENCE.md`).

---

## Capability layer lesson (from Agent Reach — conceptual only)

**STATUS: PLANNED PRODUCT DIRECTION — NOT IMPLEMENTED**

Agent Reach positions itself as a **capability layer** rather than merely one tool. Useful architectural concepts to adapt into MoxDOP product language:

- capability-oriented abstraction
- ordered backend / adapter candidates
- primary + fallback backends
- active backend selection
- health probing / “doctor” concept
- backend implementation can change without changing agent intent
- trigger-oriented Skill instructions (`WHEN TO USE` / `NOT FOR`)
- standing rules + fallback/retry rules
- safe credential boundaries
- adapters/backends evolve independently of Agent/Skill intent

### Preferred future MoxDOP adaptation

```text
BAD (tight coupling):
Website SEO Agent → call DataForSEO

PREFERRED FUTURE DIRECTION:
Website SEO Agent
  → requires keyword-data.read
  → Capability Router
  → DataForSEO Adapter
  → (future alternative Adapter)
```

### Explicitly reject from Agent Reach as canonical MoxDOP runtime

- Making Agent Reach itself a MoxDOP runtime dependency
- Shell / CLI execution as Core architecture
- Arbitrary upstream CLI invocation
- Browser-cookie scraping / account-session automation as default
- Anti-block / anti-detection techniques
- MCP as Core architecture
- Direct Agent → external tool execution that bypasses normalization + Run / Evidence

MoxDOP external data must remain: **auditable · bounded · normalized · provenance-aware**.

Canonical future flow (**PLANNED**):

```text
Agent / Module
  ↓ requests Capability
Capability Registry / Router
  ↓ selects Adapter
Adapter / Integration
  ↓ external source
NORMALIZATION
  ↓
Run → Evidence → Findings / Agent analysis
```

Agents must not silently place arbitrary external content into reasoning context without provenance.

### Capability health / doctor (**PLANNED** reference)

A provider being configured does **not** automatically mean a Capability is usable.

Future Capability health may conceptually distinguish: Healthy · Configured · Unavailable · Broken · Timeout · Needs attention.

Do **not** implement these states in this docs milestone. Prefer lightweight real health checks over merely checking whether credentials exist, when runtime work eventually begins. Reuse existing MoxDOP health concepts where practical.

---

## Repository profiles

### 1. coreyhaines31/marketingskills

| Field | Record |
| --- | --- |
| Repository | https://github.com/coreyhaines31/marketingskills |
| Primary purpose | Agent Skills library for marketing workflows (shared product/marketing context pattern) |
| License | **MIT** (verified previously via LICENSE) |
| Value | **VERY HIGH** conceptual reference |
| Relevant concepts | Skill taxonomy; reusable workflows; shared Brand/Product context; SEO / paid ads / analytics / CRO / competitor / AI-search-GEO workflows; future skill versioning/evaluation methodology |
| May adapt | Curated Skill *contracts* rewritten around Evidence, Findings, Brand Context, bounded inputs, grounded outputs |
| Explicitly reject | Importing the complete runtime; installing every skill automatically; copying dozens of skills verbatim; arbitrary third-party skill code execution; turning MoxDOP into a generic agent framework |
| Current adoption | **PARTIALLY ADOPTED** — influenced Brand Intelligence Context |
| Target future | Agent Profiles + Skill Library V1 (**PLANNED / NOT IMPLEMENTED**) |
| Caveats | Skills in MoxDOP must be curated product methodologies, not executable repo clones |
| reviewed_at | 2026-08-09 |

---

### 2. joshbuchea/HEAD

| Field | Record |
| --- | --- |
| Repository | https://github.com/joshbuchea/HEAD |
| Primary purpose | Living HTML `<head>` element taxonomy |
| License | **CC0 1.0** (declared in README; no LICENSE file — prefer subject list over verbatim copy) |
| Value | **HIGH** technical taxonomy reference |
| Relevant concepts | charset, viewport, title, meta description, robots, canonical, hreflang/language, Open Graph, structured data, link/meta relationships |
| May adapt | Diagnosis catalog subjects / check inventory |
| Explicitly reject | Treating every SEO recommendation in the repo as authoritative Google policy |
| Current adoption | **PARTIALLY ADOPTED** — influenced Website Document Head Diagnosis |
| Target future | Head/canonical/hreflang/social expansion in Diagnosis catalog |
| Caveats | Where technical/SEO truth matters, verify against WHATWG / Google Search Central / Schema.org primary sources |
| reviewed_at | 2026-08-09 |

---

### 3. AgriciDaniel/claude-seo

| Field | Record |
| --- | --- |
| Repository | https://github.com/AgriciDaniel/claude-seo |
| Primary purpose | Claude Code SEO skills/agents + methodology references |
| License | **MIT** |
| Value | **VERY HIGH** methodology reference |
| Relevant concepts | Operational/falsifiable recommendations; Observation → Why it matters → Action → Dependencies → Success signal → Failure signal → Watch metrics; technical SEO / schema / local / DataForSEO / GEO / E-E-A-T-style review methodology |
| May adapt | Recommendation/Skill methodology language; selective taxonomy |
| Explicitly reject | Multi-agent runtime copy; specialist-agent orchestration wholesale; AI-derived E-E-A-T as deterministic Finding truth |
| Current adoption | **PARTIALLY ADOPTED** — recommendation methodology influence (AI Recommendation Intelligence V1 / catalog prose) |
| Target future | Curated MoxDOP Skill methodology (**PLANNED**) |
| Caveats | Subjective expert guidance stays AI/advisory, not catalog-as-fact |
| reviewed_at | 2026-08-09 |

---

### 4. every-app/open-seo

| Field | Record |
| --- | --- |
| Repository | https://github.com/every-app/open-seo |
| Primary purpose | Full-stack SEO product (DataForSEO + GSC + audits + rank + AI visibility patterns) |
| License | **MIT** |
| Value | **VERY HIGH** implementation reference |
| Relevant concepts | DataForSEO client/envelope/cost patterns; freshness/cost guard thinking; GSC striking-distance; domain/competitor/backlink/rank/AI-visibility workflows |
| May adapt | Algorithms/patterns into MoxDOP Integrations + Website Evidence collectors |
| Explicitly reject | SPA architecture copy; MCP runtime; Cloudflare/R2 as canonical MoxDOP storage; external billing/subscription architecture |
| Current adoption | **PARTIALLY ADOPTED** — DataForSEO central Integration + cost/freshness guard; GSC opportunities; SEO Intelligence Light |
| Target future | Selective domain/competitor/backlink/rank/AI visibility (**UNCOMMITTED** until value review) |
| Caveats | MoxDOP Run/Evidence remains canonical provenance |
| reviewed_at | 2026-08-09 |

---

### 5. zubair-trabzada/geo-seo-claude

| Field | Record |
| --- | --- |
| Repository | https://github.com/zubair-trabzada/geo-seo-claude |
| Primary purpose | GEO / AI-search SEO guidance for Claude-style workflows |
| License | Not permanently verified in-repo for this update — treat as **unverified until next deep review**; do not vendor code |
| Value | **SELECTIVE / HIGH** for future AI search intelligence |
| Relevant concepts | AI crawler accessibility; AI search readiness; brand/entity signals; citation-friendly content; structured data; AI visibility; brand mentions |
| May adapt | Future heuristic/advisory Skill checks with explicit uncertainty labels |
| Explicitly reject | Blind composite GEO scores; treating repo thresholds as industry standards; unsupported claims about OpenAI/Google citation behavior |
| Current adoption | **PLANNED REFERENCE** — **NOT IMPLEMENTED** |
| Target future | AI Search / GEO Intelligence Skill candidate |
| Caveats | Many checks remain heuristic/advisory unless primary evidence supports stronger status |
| reviewed_at | 2026-08-09 |

---

### 6. garmeeh/next-seo

| Field | Record |
| --- | --- |
| Repository | https://github.com/garmeeh/next-seo |
| Primary purpose | Next.js SEO / JSON-LD helpers and patterns |
| License | Commonly MIT in ecosystem; confirm LICENSE on next deep review before any substantial text adaptation |
| Value | **REFERENCE ONLY** |
| Relevant concepts | Structured-data / JSON-LD taxonomy; implementation/test case patterns |
| May adapt | Selective taxonomy/test ideas for Structured Data Intelligence |
| Explicitly reject | Next.js runtime/package dependency; unnecessary framework-specific ports |
| Current adoption | **RESEARCH ONLY** |
| Target future | Structured Data Intelligence V2 (**NOT IMPLEMENTED**) |
| Caveats | Not a Laravel/MoxDOP runtime candidate |
| reviewed_at | 2026-08-09 |

---

### 7. pipeboard-co/meta-ads-mcp

| Field | Record |
| --- | --- |
| Repository | https://github.com/pipeboard-co/meta-ads-mcp |
| Primary purpose | MCP server exposing Meta Ads account/campaign/insights tooling |
| License | **BUSL-1.1** (Business Source License; README states Apache 2.0 conversion planned 2029) — **do not vendor as MoxDOP runtime** |
| Value | **VERY HIGH** research reference for future Meta module surface |
| Relevant concepts | Ad accounts, campaigns, ad sets, ads, creatives, insights, attribution, breakdowns, targeting, performance hierarchy |
| May adapt | Normalized Meta Evidence shapes + read-only collector scope design |
| Explicitly reject | MCP as product runtime; write ops (create/modify campaigns, budgets, ads, creatives); autonomous external actions |
| Current adoption | **PLANNED REFERENCE** — Meta write/MCP **REJECTED RUNTIME** |
| Target future | Meta Ads **read-only** intelligence module via **official Meta Graph/Marketing API** |
| Caveats | BUSL competing-service restrictions; prefer official Meta docs for API facts |
| reviewed_at | 2026-08-09 |

---

### 8. georgekhananaev/google-reviews-scraper-pro

| Field | Record |
| --- | --- |
| Repository | https://github.com/georgekhananaev/google-reviews-scraper-pro |
| Primary purpose | Google Maps review scraping (Selenium-based) with incremental/storage helpers |
| License | **MIT** (also author terms-of-usage guidance for ethical scraping — not architecture authority) |
| Value | Product **concepts** only |
| Relevant concepts | Review snapshots; incremental/new review detection; rating distribution; review velocity; response coverage; themes; sentiment; change detection |
| May adapt | GBP Reputation Intelligence product model (Evidence types / Skill methodology) |
| Explicitly reject | Selenium scraping runtime; anti-detection/bypass mechanisms; scraping Google Maps as MoxDOP integration path |
| Current adoption | **PRODUCT-CONCEPT REFERENCE ONLY** — scraper **REJECTED RUNTIME** |
| Target future | GBP Reputation Analyst / Review Intelligence via **official Google Business Profile APIs** (agency OAuth already exists) |
| Caveats | Legal/ToS risk if scraping; official APIs preferred wherever available |
| reviewed_at | 2026-08-09 |

---

### 9. Panniantong/Agent-Reach

| Field | Record |
| --- | --- |
| Repository | https://github.com/Panniantong/Agent-Reach |
| Primary purpose | Capability layer for AI agents: select/install/health-check/route internet backends (web read, search, social, video, etc.) |
| License | **MIT** (verified 2026-08-10 via https://raw.githubusercontent.com/Panniantong/Agent-Reach/main/LICENSE) |
| Classification | **RUNTIME ARCHITECTURE + SKILL / CAPABILITY REFERENCE** |
| Status | **PLANNED REFERENCE** |
| Value | **VERY HIGH** |
| Direct runtime adoption | **NO** |
| Relevant concepts | Capability-oriented abstraction; ordered backend candidates (primary + fallback); active backend; health probing / `doctor`; backend swap without changing agent intent; trigger-oriented Skill instructions (`WHEN TO USE` / `NOT FOR`); standing rules; fallback/retry rules; local credential boundaries; independently evolving adapters |
| May adapt | MoxDOP Capability Registry / Router product model; Skill `required_capabilities` / `optional_capabilities`; Discovery / outside-in public intelligence direction; lightweight capability health checks; Skill standing-rule design language |
| Explicitly reject | Installing Agent Reach as MoxDOP dependency; Python/CLI runtime; arbitrary upstream CLI invocation; browser-cookie / session scraping as default; anti-detection / bypass stacks; MCP as Core; Agent-direct external tool execution bypassing Run/Evidence/normalization; claiming universal social-platform access |
| Current adoption | Conceptual documentation only (`AI_CONTROL_PLANE.md`, `DISCOVERY_INTELLIGENCE.md`, `MODULE_ARCHITECTURE.md`, `KNOWLEDGE_MEMORY_ARCHITECTURE.md`) |
| Target future | Influence Agent Profiles + Skill Library contracts; later candidate milestones: Capability Registry / Routing V1; Discovery Intelligence V1 (**UNCOMMITTED timing**) |
| Caveats | Agent Reach often lets an Agent invoke upstream implementations directly — MoxDOP must keep Adapter → Normalization → Run → Evidence. Agent Reach is a **REFERENCE**, not MoxDOP architecture source-of-truth. Official provider/platform docs remain authoritative for API/security/runtime facts. |
| reviewed_at | 2026-08-10 |
| adoption decision changed? | **New entry** — first tracking of this repository |

---

## Historical notes (still useful)

Earlier deep dives (2026-08-08) on OpenSEO paths (`dataforseo/core|client|envelope`, GSC striking-distance), Claude SEO thinking-framework, MarketingSkills product-marketing context, and HEAD subjects remain valid as implementation footnotes. Prefer rewriting concepts into MoxDOP catalog/Evidence rather than vendoring TypeScript/Python.

Agent Reach review (2026-08-10) adds the Capability / Adapter / Discovery product direction. Do **not** create a separate Agent Reach research file — keep concepts in this audit + `DISCOVERY_INTELLIGENCE.md`.

### License summary

| Repository | License (as verified) | Direct code adaptation |
| --- | --- | --- |
| marketingskills | MIT | Concept/schema preferred |
| HEAD | CC0 1.0 (README) | Subject list preferred |
| claude-seo | MIT | Taxonomy/methodology rewrite preferred |
| open-seo | MIT | Algorithm/pattern adaptation preferred |
| geo-seo-claude | Unverified here | No vendoring until license confirmed |
| next-seo | Confirm on deep review | Taxonomy only |
| meta-ads-mcp | BUSL-1.1 | **Do not adopt as runtime**; concepts only |
| google-reviews-scraper-pro | MIT | Concepts only; scraper rejected |
| Agent-Reach | **MIT** (verified 2026-08-10) | Concepts only; **no runtime dependency** |

If substantial third-party code is later adapted, add `THIRD_PARTY_NOTICES.md`. Concept-only adoption does not require that file.

---

## Explicit reject list (runtime)

- Multi-agent orchestration runtimes from Claude SEO / generic agent packs  
- MCP as MoxDOP core architecture (including Meta Ads MCP server)  
- OpenSEO SPA / Cloudflare / R2-as-canonical-store / Autumn billing copy  
- Importing all MarketingSkills  
- Google Maps Selenium scraper / anti-bot bypass stacks  
- External write actions on Google / Meta / any ad platform  
- Composite GEO scores presented as industry standards without primary evidence  
- Claiming future Skills/Agents/providers/Discovery/Capability Router are implemented today  
- **Agent Reach CLI / Python package as MoxDOP dependency**  
- Arbitrary shell/CLI execution and browser-cookie scraping as canonical MoxDOP access  
- Agent-direct external data into reasoning without Run / Evidence provenance  

---

## Related product direction

- Planned AI Control Plane (providers, routes, failover, Agent Profiles, Skill Library, Capability Router distinction):  
  → [`docs/product/AI_CONTROL_PLANE.md`](../product/AI_CONTROL_PLANE.md)  
- Outside-in Discovery / Public Intelligence (**PLANNED / NOT IMPLEMENTED**):  
  → [`docs/product/DISCOVERY_INTELLIGENCE.md`](../product/DISCOVERY_INTELLIGENCE.md)  
- Knowledge / Memory (RAG decision unchanged — Capability routing ≠ RAG):  
  → [`docs/product/KNOWLEDGE_MEMORY_ARCHITECTURE.md`](../product/KNOWLEDGE_MEMORY_ARCHITECTURE.md)
