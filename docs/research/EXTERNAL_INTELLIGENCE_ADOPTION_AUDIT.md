# DOP External Intelligence Adoption Audit

> **Status:** Research complete — no runtime implementation  
> **Date:** 2026-08-08  
> **Branch base:** `main` after PR #100 merge (`77af336`)  
> **Scope:** Inspect four external reference repositories; adapt concepts to MoxDOP modular monolith.  
> **Non-goals:** No new product code, migrations, dependencies, modules, or Core refactors in this task.

---

## 0. Phase 0 — PR #100 preservation

| Check | Result |
| --- | --- |
| PR #100 CI (`DOP PR Gate`) | Green |
| Merge into `main` | Fast-forward + push; GitHub state **MERGED** at `77af336` |
| Website workspace present | Overview / Performance / Health / Connections / Activity / Settings |
| Refresh data workflow | Present (`ViewDigitalAsset::refreshData`) |
| Human-readable Run UX | Present (`website::workspace.run-detail`, Activity nav) |
| Capability-scoped Google bindings | Present (`WebsiteConnectionsRelationManager`) |
| WordPress cleaned UX | Present (field form; no Credentials JSON) |
| PHPUnit on `main` | **422 passed** |
| Pint | Passed |
| Vite build | Passed |

---

## 1. Canonical MoxDOP architecture reviewed

Primary sources read before judging external repos:

| Document | Takeaway for adoption |
| --- | --- |
| `docs/MASTER_SPEC.md` | Agency-internal ops; read-only integrations; flow Customer→Brand→Digital Asset→Connection/Binding→Run→Evidence→Finding→Recommendation→Task |
| `docs/foundation/MODULE_ARCHITECTURE.md` | Modular monolith `app-modules/*`; no marketplace/plugin install runtime |
| `docs/foundation/MODULE_CONTRACT.md` / `CORE_RESPONSIBILITIES.md` | Core owns lifecycle entities; modules own domain rules |
| `docs/foundation/DOMAIN_MODEL.md` / `DECISION_LOG.md` | ADR-031 catalog gate; ADR-034 Finding fingerprint; ADR-039/040 central Integrations |
| `docs/current-state/INTEGRATIONS.md` | Agency Integration → External Resource → Asset Binding → collectors; DataForSEO already named in `ProviderRegistry` |
| `docs/product/website/*` + `docs/website/DIAGNOSIS_CATALOG.md` | Diagnosis-first; catalog draft has reachability/TLS/redirects/robots/sitemap/canonical; title/meta/schema still open |
| `docs/IMPLEMENTATION_ROADMAP.md` | Canonical steps 10–17 still govern Website diagnosis → connectors → AI |
| PR #100 workspace | Professional tabs; do not invent endless SEO top-level tabs |

**Hard constraints external repos must obey**

1. Adapt **to** MoxDOP — do not redesign Core around OpenSEO/Claude SEO.  
2. No microservices, no client SaaS, no external writes.  
3. Provider auth may be shared (agency Integration); domain collection/analysis stays in modules.  
4. Prefer Run/Evidence over parallel “API cache tables” as the product provenance model.  
5. Deterministic catalog rules ≠ AI judgment ≠ blog heuristics.

---

## 2. Repository findings

### 2.1 every-app/open-seo (highest-priority implementation reference)

| Field | Finding |
| --- | --- |
| Purpose | Full-stack SEO SaaS (TanStack Start + Cloudflare) metering DataForSEO + GSC + audits + rank tracking + MCP |
| Architecture | Feature services + Drizzle schemas + Workflows + R2/KV cache + Autumn billing — **not** a modular Laravel monolith |
| License | **MIT** (`LICENSE`, © 2026 Ben Senescu) |
| Activity | Active 2026 product codebase |

**DataForSEO (exact paths under `/tmp/ext-intel-audit/open-seo/`)**

| Concern | Path | Notes |
| --- | --- | --- |
| Metered client facade | `src/server/lib/dataforseo/client.ts` | `createDataforseoClient`, `meter`, `meterDataforseoCall` |
| Auth + HTTP + retry | `src/server/lib/dataforseo/core.ts` | Basic auth via `DATAFORSEO_API_KEY`; `DATAFORSEO_MAX_RETRIES=2`; 5xx backoff |
| Envelope / billing parse | `src/server/lib/dataforseo/envelope.ts` | `assertOk`, `buildTaskBilling`, task item parsing |
| Labs / keywords | `labs.ts`, `keyword-metrics.ts`, `google-ads.ts` | suggestions, ideas, domain rank, ranked keywords, SERP competitors; Ads volume fallback by country |
| SERP + rank poll | `serp.ts`, `src/server/workflows/rankCheckPaths.ts`, `RankCheckWorkflow.ts` | `task_post` → poll intervals → live fallback |
| Backlinks | `backlinks.ts` + `BacklinksService.ts` | summary/rows/referring domains/history live endpoints |
| AI/LLM visibility | `ai.ts` | LLM mentions / aggregated metrics (GEO-adjacent) |
| Country/language | `src/shared/keyword-locations.ts`, `src/server/lib/market.ts` | Labs vs Ads routing; location/language guards |
| Cost mapping | `src/shared/billing-credit-features.ts`, `dataforseoBillingClassification.ts` | path→credit feature |
| Response cache | `src/server/lib/r2-cache.ts` | keyed cache for shaped domain results — **not** Evidence |

**GSC / SEO product ideas**

| Idea | Path | MoxDOP mapping |
| --- | --- | --- |
| Striking-distance queries (pos 5–20, collapse query→best page, sort impressions, limit 100) | `src/server/features/gsc/searchPerformanceReport.ts` → `buildStrikingDistanceRows` | **Website module** over existing `gsc_query_performance` / page×query Evidence — **A** |
| Rank tracking runs/snapshots | `src/db/app.schema.ts`, `features/rank-tracking/`, workflows | Website + future DFS Integration — **B** (cost + scheduler) |
| Site audit crawl + issue taxonomy | `src/shared/audit-issues.ts`, `SiteAuditWorkflow.ts`, `page-analyzer.ts` | Overlaps Diagnosis catalog — take taxonomy ideas only — **C** / selective **B** |
| Backlinks views | `features/backlinks/` | Website + DFS — **B** |
| Competitor / domain labs | `labs.ts` `fetchSerpCompetitors`, domain overview | Website — **B** |
| AI prompt / LLM visibility | `features/ai-search/` | future AI layer — **B/C** |
| MCP tool surface | `src/server/mcp/` | **D** for MoxDOP runtime |
| Autumn/BYOK SaaS billing | `billing/` | **D** (agency Integration credentials already exist) |
| Full frontend SPA architecture | `src/client/` | **D** — keep Filament/Livewire |

**Cache / cost-control recommendation (MoxDOP-native)**

Do **not** copy R2 cache as a second analytics DB.

Prefer:

1. **Agency DataForSEO Integration** credentials (Settings → Integrations).  
2. Collectors write **Run + Evidence** with normalized payloads + request fingerprint (endpoint, params, location, language, device).  
3. **Cost guard before call:** refuse/skip when identical fingerprint Evidence younger than TTL (per capability, e.g. keyword overview 7–30d; SERP live shorter).  
4. Store DFS cost/units in Run `metadata` (OpenSEO `envelope` inspiration).  
5. Manual Refresh first; scheduler later.  
6. Catalog/use-case allowlist of endpoints — no “endpoint tourism” (`docs/product/website/DATAFORSEO.md`).

---

### 2.2 AgriciDaniel/claude-seo (domain intelligence)

| Field | Finding |
| --- | --- |
| Purpose | Claude Code plugin: markdown skills + subagents + Python check scripts |
| Architecture | Multi-agent orchestration (`agents/*`, `skills/seo-audit`) — **do not adopt runtime** |
| License | **MIT** (`LICENSE`, © 2026 agricidaniel) |
| Value | Taxonomies, quality gates, recommendation methodology |

**Most useful paths**

| Path | Concept |
| --- | --- |
| `skills/seo/references/thinking-framework.md` | 10 principles; recommendations need observation, dependency, failure signal, leading indicator |
| `skills/seo/references/quality-gates.md` | Hard/soft gates; FAQ/HowTo caution |
| `skills/seo/references/eeat-framework.md` | E-E-A-T — judgment, not deterministic |
| `skills/seo/references/cwv-thresholds.md` | Align later with web.dev, not blog copies |
| `skills/seo/references/schema-types.md` | Schema taxonomy seed |
| `skills/seo/references/local-seo-signals.md` | Local SEO signals |
| `skills/seo-technical/SKILL.md`, `skills/seo-content/`, `skills/seo-geo/` | Category maps |
| `scripts/*.py` (`parse_html.py`, `pagespeed_check.py`, `schema_*_validate.py`, …) | Objective check inspiration |

**Classification of Claude SEO “rules”**

| Class | Examples | MoxDOP use |
| --- | --- | --- |
| Objective technical | HTTP status, robots, canonical parse, schema JSON parse | Diagnosis catalog / collectors |
| Deterministic heuristics | Title length bands, thin-content word counts | Catalog with explicit heuristic label + primary-source check |
| Subjective expert guidance | E-E-A-T narratives, content rewrites | AI Insights / playbooks — not Finding detectors |
| AI-only evaluation | Multi-agent synthesis, persona scoring | Future AI layer only |

**Recommendation enrichment (no schema change now)**

Current `recommendations` columns: `title`, `action`, `rationale`, `priority`, `effort`, `status` (+ finding/asset links).

| Desired concept | Fits today? | Future minimal addition |
| --- | --- | --- |
| Why it matters | `rationale` | — |
| Suggested action | `action` | — |
| Dependencies | No | optional JSON `dependencies` later |
| Success / failure signal | No | optional text/JSON later |
| Metric to watch | No | optional `watch_metric` later |

Until schema expands, put success/failure/watch language into `rationale`/`action` templates from the catalog — do not invent columns in this audit phase.

---

### 2.3 coreyhaines31/marketingskills (marketing context)

| Field | Finding |
| --- | --- |
| Purpose | Agent Skills library (~49 skills); no app runtime |
| License | **MIT** (`LICENSE`, © 2025 Corey Haines) |
| Key pattern | Shared product/marketing context file |

**Shared context**

- Canonical skill: `skills/product-marketing/SKILL.md`  
- On-disk doc: `.agents/product-marketing.md`  
- Sections: product overview, audience/personas, problems, competitors, differentiation, objections, JTBD forces, customer language, brand voice, proof points, goals + changelog  
- Nearly every other skill instructs: read that file before asking again  

**Small high-value playbook subset for MoxDOP**

1. `product-marketing` — Brand Intelligence Context schema  
2. `competitor-profiling` — structured competitor facts  
3. `competitors` — positioning framing  
4. `customer-research` — ICP / VOC language  
5. Optionally thin: `content-strategy`, `schema`, `seo-audit`, `ai-seo` (taxonomy only; overlap with other repos)

**Reject:** importing all 40+ skills; ads/SMS/email/revops/plugin CLIs; agent/plugin runtime.

**Brand Intelligence Context (future)** — factual Brand-level fields (business model, priority services, audiences, markets, goals, competitors, constraints). Used as **bounded context** for AI Recommendations: Evidence + Finding + Brand Context + playbook → recommendation. AI must not invent facts missing from context/Evidence.

---

### 2.4 joshbuchea/HEAD (document head reference)

| Field | Finding |
| --- | --- |
| Purpose | Living HTML `<head>` taxonomy (README) |
| License | **CC0 1.0** (declared in README; no LICENSE file) |
| Use | Audit **subjects** for Diagnosis V2 Document Head — not large text copy |

**Deterministic check candidates (PRIMARY VERIFIED / HEURISTIC)**

| Subject | Class | Primary sources to encode against |
| --- | --- | --- |
| charset / viewport / title presence | Deterministic | WHATWG HTML; Google Search Central |
| meta description presence/length | Heuristic bands + presence deterministic | Google Search Central (description optional for ranking; snippet use) |
| robots / googlebot meta | Deterministic | [Google meta tags](https://developers.google.com/search/docs/crawling-indexing/special-tags) |
| canonical | Already in catalog | RFC 6596, WHATWG, Google canonicalization |
| hreflang alternate | Deterministic structure when multi-locale | Google hreflang docs |
| Open Graph core tags | Best-practice / advisory Finding severity info–low | ogp.me (not Google ranking) |
| JSON-LD parse | Deterministic parse; validity vs Schema.org/Google | Schema.org + Google rich results |
| Resource hints / theme-color / IndieWeb | Best-practice only / reject as Critical | — |

---

## 3. License assessment

| Repository | License | Direct code adaptation | Attribution | Notes |
| --- | --- | --- | --- | --- |
| every-app/open-seo | MIT | Permitted | Keep copyright notice if substantial code adapted | Prefer algorithm/pattern adaptation over vendoring TS into PHP |
| AgriciDaniel/claude-seo | MIT | Permitted for scripts/skills text | Attribution if substantial | Prefer taxonomy rewrite into DIAGNOSIS_CATALOG |
| coreyhaines31/marketingskills | MIT | Permitted | Attribution if substantial | Concept/schema adaptation preferred |
| joshbuchea/HEAD | CC0 1.0 (README) | Permitted; no attribution required | Optional credit | No LICENSE file — rely on README CC0 declaration; uncertainty: confirm before large verbatim copy; prefer subject list only |

**Strategy:** Concept/algorithm/taxonomy adaptation first. If later adapting substantial OpenSEO PHP-port code or Claude scripts, add minimal `THIRD_PARTY_NOTICES.md`. Do not create license obligations for concept-only adoption.

---

## 4. Explicit YES / LATER / NO decisions

| Idea | Decision | Reasoning |
| --- | --- | --- |
| DataForSEO central Integration | **YES** (soon after Diagnosis V2 / GSC ops) | Matches ADR-039 agency Integration; already in ProviderRegistry; avoid per-site JSON credentials |
| DataForSEO API response cache/cost guard | **YES** | Required for paid API; implement as Evidence TTL + Run metadata, not OpenSEO R2 clone |
| Keyword Research | **LATER** | Needs DFS Integration + country routing |
| Rank Tracking | **LATER** | Needs DFS + scheduler + cost controls; high value but not next |
| Competitor/domain intelligence | **LATER** | Paid Labs; after cost guard |
| Backlinks | **LATER** | Costly; Health/Overview enrichment later |
| Site Audit expansion | **YES** via Diagnosis catalog | Prefer MoxDOP catalog over OpenSEO crawl product |
| GSC striking-distance queries | **YES** | Pure existing Evidence; tiny algorithm; high operator value |
| Content gap opportunities | **LATER** | Needs keyword/SERP DFS or richer GSC |
| Document Head diagnosis catalog | **YES** | Natural Diagnosis V2; HEAD + Google/WHATWG |
| Structured Data diagnosis | **YES** (catalog phase) | Deterministic parse first; rich-result judgment careful |
| E-E-A-T assessment | **LATER / AI-only** | Not deterministic Finding rules |
| Local SEO intelligence | **LATER** | Overlaps GBP module + local signals |
| International/hreflang | **YES** (catalog when multi-locale sites matter) | Deterministic structure checks |
| AI/GEO visibility | **LATER** | DFS AI endpoints / AI Insights |
| Brand Intelligence Context | **LATER** (before AI Insights) | MarketingSkills pattern; Brand-level facts |
| Intelligence/playbook layer for AI | **LATER** | Small subset only; not 49 skills |
| Recommendation success/failure signals | **LATER** | Encode in catalog prose now; schema later |
| Recommendation watch metrics | **LATER** | Same |
| Multi-agent orchestration | **NO** | Conflicts with modular monolith + ADR AI path |
| MCP runtime | **NO** | Not MoxDOP product surface |
| External repo plugin systems | **NO** | ADR-032/033 |
| Import all MarketingSkills | **NO** | Noise |
| Import Claude SEO agents directly | **NO** | Runtime reject; taxonomy yes |
| Copy OpenSEO frontend/app architecture | **NO** | Filament/Livewire + Website workspace stay |

---

## 5. Candidate matrix (meaningful items only)

| Repository | Source path | Feature / concept | Problem solved | Current MoxDOP equivalent | Target layer | Class | Reuse type | Primary verify? | License | Dependency | Complexity | User value | Risks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| open-seo | `.../gsc/searchPerformanceReport.ts` `buildStrikingDistanceRows` | Striking-distance queries | Surface near-page-1 opportunities from GSC | `gsc_query_performance` Evidence; Performance tab tables | `app-modules/website` | **A** | algorithm + UX | Optional (heuristic band 5–20) | MIT | GSC Evidence already collected | small | very high | Band is heuristic; document as such |
| HEAD + Google | HEAD README; Google special-tags | Document Head catalog items | Missing title/robots/viewport/description | Partial `page_html` + canonical item | Website Diagnosis catalog + website module | **A** | taxonomy | **Yes** | CC0 / n/a | `page_html` evidence enrichment | medium | high | Don’t over-severity OG tags |
| claude-seo | `skills/seo-schema/`, `schema-types.md` | Structured data catalog seeds | Invalid/missing JSON-LD | Diagnosis backlog | Website Diagnosis | **A** | taxonomy | **Yes** (Schema.org + Google) | MIT | HTML/JSON-LD evidence | medium | high | Google rich-result rules change |
| open-seo | `src/server/lib/dataforseo/{core,client,envelope}.ts` | DFS client patterns | Auth, retry, cost envelope | `DataForSeoConnectionProbeService` (legacy site-scoped) | Core Integration + shared DFS client | **A** | algorithm adaptation | Yes (DFS official docs) | MIT | Settings Integrations UI | medium | high | Don’t port Autumn metering |
| open-seo | `r2-cache.ts` + metering | Cost guard / dedupe | Prevent repeated paid calls | Run/Evidence (no TTL guard yet) | Core guard + Website collectors | **A** | concept → MoxDOP design | Yes | MIT | DFS Integration | medium | high | Mis-caching stale SERP |
| claude-seo | `thinking-framework.md` | Rec dependency + failure + watch | Actionable recommendations | `action`/`rationale` only | Catalog templates now; schema later | **A** (templates) / **B** (schema) | concept | No | MIT | Diagnosis/AI | small→medium | high | Don’t fake determinism |
| marketingskills | `product-marketing/SKILL.md` | Brand Intelligence Context | Shared factual marketing context | Brand model fields limited | Brand + future AI | **B** | concept / taxonomy | No | MIT | AI Insights | medium | high | Stale context invents bad AI advice |
| open-seo | `labs.ts` keyword/domain | Keyword research | Volume/ideas/gaps | None productized | Website + DFS | **B** | algorithm | Yes | MIT | DFS + cost guard | medium | high | Cost |
| open-seo | rank-tracking + workflows | Rank tracking | Track keyword positions | None | Website + DFS + scheduler | **B** | algorithm + UX | Yes | MIT | DFS + scheduler | large | high | Cost/polling complexity |
| open-seo | `backlinks.ts` | Backlink intelligence | Authority/ref domains | None | Website + DFS | **B** | algorithm | Yes | MIT | DFS + cost guard | medium | medium | Expensive; noisy |
| open-seo | `audit-issues.ts` | Audit issue taxonomy | Crawl issue dictionary | DIAGNOSIS_CATALOG + open-seo overlap | Website Diagnosis | **C**→selective **B** | taxonomy | Yes | MIT | Diagnosis V2 | medium | medium | Duplicate OpenSEO crawl product |
| claude-seo | `eeat-framework.md` | E-E-A-T | Trust assessment | None | AI Insights | **C** | concept | Expert guidance | MIT | Brand context + AI | medium | medium | Subjective |
| claude-seo | `local-seo-signals.md` | Local SEO | Local pack readiness | GBP module partial | Website + GBP | **B** | taxonomy | Yes | MIT | GBP binding | medium | medium | Scope creep |
| open-seo | `ai.ts` / ai-search | GEO / AI visibility | LLM mention tracking | None | AI + DFS | **B** | concept | Yes | MIT | DFS AI endpoints | large | medium | Immature product area |
| open-seo | `src/server/mcp/` | MCP tools | Agent API | Out of scope | — | **D** | — | — | MIT | — | — | — | Wrong product shape |
| open-seo | `src/client/` SPA | OpenSEO UI | Full SEO app UX | Filament Website workspace | — | **D** | — | — | MIT | — | — | — | Destroys PR #100 direction |
| claude-seo | `agents/*` | Multi-agent runtime | Parallel SEO agents | laravel/ai path (future) | — | **D** | — | — | MIT | — | — | — | Complexity / isolation risk |
| marketingskills | 40+ non-core skills | Full skill pack | Broad marketing ops | — | — | **D** | — | — | MIT | — | — | — | Noise |

---

## 6. Target architecture mapping

```text
CORE
  - Agency Integration (DataForSEO credentials) — like Google
  - Generic Run / Evidence / Finding / Recommendation / Task
  - Optional paid-API cost-guard helpers (fingerprint TTL) — provider-agnostic hooks only

Shared DFS client (app/Services or Support/Integrations/DataForSEO)
  - Auth, HTTP, retry, envelope cost metadata
  - NO Website metric semantics

app-modules/website
  - Diagnosis catalog + collectors/rules (Document Head, schema, hreflang, …)
  - GSC opportunity intelligence (striking distance) from Evidence
  - Future DFS-backed keyword/SERP/backlink collectors + Findings
  - Workspace presenters (Overview / Performance / Health)

app-modules/google-ads
  - Unchanged by this audit (Ads findings stay here)

Brand (Core model + future fields/context doc)
  - Brand Intelligence Context (factual)

AI Insights (website module later)
  - Playbooks + Brand Context + Evidence-bound generation
  - No multi-agent / MCP runtime
```

**Do not** create micro-modules per external repo feature.

---

## 7. Website workspace impact (PR #100)

Keep tabs: **Overview · Performance · Health · Connections · Activity · Settings**.

| Future feature | Where it appears | Avoid |
| --- | --- | --- |
| Striking-distance / GSC opportunities | **Performance** (primary); compact “Opportunities” strip on **Overview** (top 3–5) | Dumping full query tables on Overview |
| Diagnosis Document Head / schema Findings | **Health**; diagnosis summary on Overview | New “SEO Audit” top tab |
| Recommendations enrichment | **Health** + existing Recommendations nav | Separate advice silo |
| DataForSEO Integration config | **Settings → Integrations** (agency); Website **Connections** only shows bind/status if needed | Per-site Credentials JSON |
| Keyword research / rank tracking (later) | Prefer **Performance** sub-sections or future **Intelligence** sub-nav **only when** real DFS data volume justifies it | Endless top-level tabs |
| Brand context | Brand settings / Brand workspace — not Website Overview | Crowding Website Overview |
| Activity | DFS/Diagnosis Runs continue as human-readable Activity rows | Raw JSON primary |

Overview stays: what is happening + what needs attention — not every SEO tool surface.

---

## 8. Prioritized implementation roadmap

Recommended sequence **from current main** (post–PR #100). This **differs** from a blind “DFS first” sequence: unlock free GSC + diagnosis value before paid APIs.

| # | Milestone | Goal | Why now | External refs | Modules | Dependencies | Non-goals | Operator benefit |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | **Website Diagnosis V2 — Document Head & on-page catalog** | Expand `DIAGNOSIS_CATALOG` + implement checks using `page_html` (title, description, robots, viewport/charset, OG advisory, JSON-LD parse starters) | Catalog is the product gate; Health tab ready; no paid API | HEAD README subjects; Claude SEO technical/schema taxonomies; Google Search Central / WHATWG | `docs/website`, `app-modules/website`, Core Evidence types if needed | Existing diagnosis run pipeline | Full OpenSEO crawler; E-E-A-T scoring | Stronger Health findings with primary-source rules |
| 2 | **GSC Opportunity Intelligence** | Striking-distance + opportunity Findings from existing GSC Evidence | Evidence already collected; tiny algorithm; Performance tab hungry for insight | open-seo `buildStrikingDistanceRows` | `app-modules/website` | GSC bindings/Evidence | Rank tracking; DFS | Immediate SEO ops value on Performance/Overview |
| 3 | **DataForSEO Central Integration + cost guard** | Agency Integration, credentials, probe/test, Evidence TTL cost guard, endpoint allowlist | Unlocks paid SEO without site-scoped JSON | open-seo `core/client/envelope`; MoxDOP Google Integration UX | Core Integrations + Website | Settings Integrations patterns | Full keyword suite day-one | Safe paid API foundation |
| 4 | **SEO Intelligence (DFS light)** | Keyword overview/suggestions + optional SERP snapshot for priority terms | After cost guard | open-seo `labs.ts`, `keyword-metrics.ts`, country routing | Website | Milestone 3 | Rank tracker fleet; backlink graphs | Research without leaving MoxDOP |
| 5 | **Brand Intelligence Context** | Factual Brand context document/fields | Before AI Insights | marketingskills `product-marketing` | Core Brand + docs | Stable Website Findings | 49 skills | Better later AI recommendations |
| 6 | **AI Recommendation Intelligence** | Evidence+Finding+Brand Context+small playbooks | After context + rich Findings | Claude thinking-framework; tiny MarketingSkills subset | Website AI Insights | laravel/ai; no MCP/agents | Inventing facts; multi-agent | Actionable recommendations |
| 7 | **Rank tracking / backlinks / competitor (selective)** | Paid tracking surfaces | After cost guard + real demand | open-seo rank/backlinks/labs | Website | Scheduler + DFS | Copy OpenSEO SPA | Competitive monitoring |
| 8 | **Task workspace / Scheduler / Meta** | Ops closing of the loop | After recommendation quality | — | Core + modules | Prior milestones | — | Agency execution |

---

## 9. SINGLE recommended next milestone

### Website Diagnosis V2 — Document Head & On-Page Catalog Expansion

**Exact scope (docs + implementation in a *future* task — not this audit):**

1. Extend `docs/website/DIAGNOSIS_CATALOG.md` with PRIMARY-VERIFIED items:  
   - title presence/empty  
   - meta robots/googlebot indexability  
   - meta description presence (length bands labeled HEURISTIC)  
   - charset / viewport minimum  
   - JSON-LD block presence/parse errors (validity deep-dive can phase)  
   - optional info-severity Open Graph completeness  
2. Extend `page_html` normalization only as needed for those fields (still no full HTML dumps).  
3. Wire deterministic Finding/Recommendation upserts via existing diagnosis pipeline.  
4. Surface results in **Health** (and concise Overview technical health) — no new top-level tab.  
5. For each rule: cite WHATWG / Google Search Central / Schema.org as appropriate; mark HEURISTIC vs PRIMARY VERIFIED.

**Explicit non-goals for that milestone:** DataForSEO, rank tracking, E-E-A-T scores, OpenSEO crawler port, AI agents, schema migrations for recommendation watch metrics, MarketingSkills import.

**Immediate follow-on (can be the milestone after, or a thin parallel PR):** GSC striking-distance on Performance from existing Evidence.

---

## 10. Primary-source verification concerns

| Claim area | Risk if we trust repos blindly | Required authority |
| --- | --- | --- |
| Meta robots / googlebot | Claude/HEAD may list obsolete directives | Google Search Central special-tags |
| Title/description length “rules” | Heuristic folklore | Google: no fixed pixel ranking rule — treat as HEURISTIC |
| Canonical | Already solid in catalog | Keep RFC/WHATWG/Google |
| CWV thresholds | Claude `cwv-thresholds.md` may lag | web.dev / Chrome |
| Schema rich results | Google eligibility ≠ Schema.org validity | Both |
| DFS endpoints/cost | OpenSEO wrappers may lag API | DataForSEO official docs at implementation time |
| Striking-distance 5–20 | Product heuristic, not Google doctrine | Label HEURISTIC |

---

## 11. Runtime code changed

**NO** — this audit only adds this documentation file.

---

## Appendix A — Top 10 A — ADAPT SOON

1. GSC striking-distance algorithm (open-seo)  
2. Document Head diagnosis subjects (HEAD) verified vs Google/WHATWG  
3. Structured data parse/catalog seeds (claude-seo + Schema.org/Google)  
4. DataForSEO agency Integration (MoxDOP architecture + open-seo client patterns)  
5. DFS cost guard via Evidence fingerprint TTL (open-seo metering concept → MoxDOP)  
6. Recommendation failure/watch language in catalog templates (claude-seo thinking-framework)  
7. robots/googlebot meta diagnosis (HEAD + Google special-tags)  
8. Title/description diagnosis (HEAD subjects; heuristic lengths)  
9. Country/language parameter discipline for future DFS (open-seo `keyword-locations.ts`)  
10. OpenSEO audit-issue names as **catalog brainstorming only** (not crawl port)

## Appendix B — B — ADAPT LATER

Keyword research, rank tracking, backlinks, competitor/domain labs, content gaps, Brand Intelligence Context, AI playbooks, GEO/LLM visibility, local SEO packs, recommendation schema fields (dependencies/success/failure/watch), hreflang deep multi-locale, selective OpenSEO multipage audit checks.

## Appendix C — REJECT

Multi-agent runtime; MCP runtime; OpenSEO SPA/Cloudflare app architecture; Autumn SaaS billing copy; importing all MarketingSkills; importing Claude agents; per-site DataForSEO credential JSON as normal UX; treating R2 cache as Evidence replacement; E-E-A-T as deterministic Finding scores; endless Website top-level SEO tabs.
