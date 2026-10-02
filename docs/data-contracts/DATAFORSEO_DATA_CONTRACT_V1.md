# DATAFORSEO DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR ENRICHMENT IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Canonical product branch | `main` |
| Cumulative base HEAD | `23f9f26525b25a4d1b9c76b016a435bcc74ef432` (`cursor/data-contract-website-ea01`) |
| Audit branch | `cursor/data-contract-dataforseo-ea01` |
| Scope | Cost-controlled external SEO / search-market intelligence enrichment for frozen Website / Brand / Opportunity consumers |
| Runtime product code changed by this milestone | **NONE** |
| Paid DataForSEO requests executed by this audit | **NONE** |

Future semantic changes require **v2** or an explicitly documented contract amendment. Do not silently mutate meaning.

### Prior contracts (must remain present)

| Contract | Path |
| --- | --- |
| GA4 | `docs/data-contracts/GA4_DATA_CONTRACT_V1.md` |
| Search Console | `docs/data-contracts/SEARCH_CONSOLE_DATA_CONTRACT_V1.md` |
| Google Ads | `docs/data-contracts/GOOGLE_ADS_DATA_CONTRACT_V1.md` |
| Meta Ads | `docs/data-contracts/META_ADS_DATA_CONTRACT_V1.md` |
| Website | `docs/data-contracts/WEBSITE_DATA_CONTRACT_V1.md` |

### Official provider references (verified 2026-08-13)

- Labs Ranked Keywords Live: https://docs.dataforseo.com/v3/dataforseo_labs-google-ranked_keywords-live/
- Labs Keywords For Site Live: https://docs.dataforseo.com/v3/dataforseo_labs-google-keywords_for_site-live/
- Labs Competitors Domain Live: https://docs.dataforseo.com/v3/dataforseo_labs-google-competitors_domain-live/
- Labs Relevant Pages Live: https://docs.dataforseo.com/v3/dataforseo_labs-google-relevant_pages-live/
- Labs Domain Intersection Live: https://docs.dataforseo.com/v3/dataforseo_labs-google-domain_intersection-live/
- Labs Locations & Languages: https://docs.dataforseo.com/v3/dataforseo_labs/locations_and_languages/
- Labs Google API pricing: https://dataforseo.com/pricing/dataforseo-labs/dataforseo-google-api
- Google Organic SERP pricing: https://dataforseo.com/pricing/serp/google-organic-serp-api
- Product docs (repo): `docs/product/integrations/DATAFORSEO.md`, `docs/product/website/DATAFORSEO.md`, `docs/product/website/SEO_INTELLIGENCE.md`, `docs/product/DISCOVERY_INTELLIGENCE.md`

---

## 1. Purpose

Determine **exactly** how DataForSEO should enrich the frozen MoxDOP product:

```text
FROZEN PRODUCT NEED
  → ELIGIBLE DATAFORSEO CAPABILITY
  → EXPLICIT REQUEST CONTRACT
  → COST / CACHE / FRESHNESS POLICY
  → DATAFORSEO DATA CONTRACT V1
```

This contract binds Website Visibility, Website Content (conditional), Brand Growth (as Opportunity input), Discovery competitor candidates, and future Opportunity Evidence to **capability-driven** paid enrichment — **not** wholesale provider mirroring.

**Hard boundary of this milestone:** audit + documentation only. No production paid calls, collectors expansion, migrations, analytical tables, schedulers, UI redesign, Opportunity detectors, Finding rules, or SEO scores.

---

## 2. Architectural Role

DataForSEO is:

# EXTERNAL SEO / SEARCH MARKET INTELLIGENCE ENRICHMENT

It is **not**:

- a customer analytics account
- a Website Digital Asset
- a Search Console replacement
- a keyword database copied wholesale into MoxDOP
- an automatic always-on crawler
- an AI provider
- a Business Outcome source
- a main account-ingestion provider (Google / Meta class)

```text
Website / Brand context
  ↓
MoxDOP capability needs external intelligence
  ↓
Eligibility check (domain + SEO market + credential + freshness)
  ↓
Cost / cache check
  ↓
DataForSEO request when justified
  ↓
Raw provider response (+ normalized enrichment / Evidence)
  ↓
Frozen consumer (Visibility / Growth / Opportunity input / Discovery candidates)
```

---

## 3. Non-Ingestion Provider Boundary

| Anti-pattern | Status |
| --- | --- |
| Connect DataForSEO → discover customer accounts → select → import all | **FORBIDDEN** |
| DataForSEO as selectable Digital Asset type | **FORBIDDEN** |
| ExternalResource binding like GA4/GSC/Ads | **FORBIDDEN** (unless future provider model unexpectedly requires it — not justified today) |
| Per-Customer / per-Brand / per-Website credentials as normal UX | **FORBIDDEN** |
| Render-triggered paid calls | **FORBIDDEN** |

**Allowed:** agency-level Integration credentials (`provider = dataforseo`) + optional env fallback for ops — see `docs/product/integrations/DATAFORSEO.md`.

Consumption is always from a capability (SEO Intelligence refresh, Discovery competitor candidates, future Growth enrichment workflow) with Website/Brand scope.

---

## 4. Frozen Product Consumers

| Consumer | Workspace | DFS role | Freeze evidence |
| --- | --- | --- | --- |
| Website → Visibility · Organic · DataForSEO panel | Website | **Required** summary: ranked keywords count, keywords-for-site count, opportunities count; estimated label; paid-refresh guard | `visibility.blade.php` + `WebsiteWorkspaceFixtures` |
| Website → Overview search snapshot | Website | **Required** opportunity count · estimated | Overview fixture |
| Website → Content opportunities | Website | **Conditional** demand/context enrichment only; freeze sources are Brand · GSC · Content inventory | Content gaps fixtures |
| Brand → Growth | Brand | **Conditional** Opportunity Evidence input; Growth UI lists Opportunities + AI brief (Demo) — no direct DFS panel | `brand-show.blade.php` Growth tab |
| Opportunity detection (future) | Operations | **Input only** — not auto Opportunity | `OpportunityFixtures` concepts (organic gap, content coverage) |
| Discovery · competitor candidates | Website Discovery | **Conditional** organic-search competitor candidates | `CompetitorDomainCollector` + `DISCOVERY_INTELLIGENCE.md` |
| Brand → Business · known competitors | Brand | **Operator-maintained** — not DFS | Brand Intelligence Context |

---

## 5. Source / Provenance Classification

### Source types

| Code | Meaning |
| --- | --- |
| `DATAFORSEO_LABS` | DataForSEO Labs Google endpoints |
| `DATAFORSEO_SERP` | SERP API organic observations |
| `DATAFORSEO_KEYWORD_DATA` | Keyword Data / related standalone keyword APIs |
| `DATAFORSEO_OTHER_VERIFIED` | Free appendix / Labs directory (verified) |
| `MOXDOP_DERIVED` | Cross-source heuristics (e.g. CrossSourceKeywordOpportunities) |
| `MOXDOP_MAPPING` | Operator/Brand mapping |
| `CROSS_ASSET` | Joins to GSC / Website / Brand |
| `OPERATOR_MAINTAINED` | Human Brand Context |
| `UNAVAILABLE` | Not collectable / not configured |
| `DEMO_ONLY` | Fixture illustration only |

### Provenance labels (provider semantics)

| Label | Use for |
| --- | --- |
| `PROVIDER_ESTIMATED` | search_volume, etv, estimated_paid_traffic_cost, traffic value, difficulty/competition when provider Ads-derived |
| `PROVIDER_OBSERVED` | Labs ranked position snapshots from SERP database checks; SERP API live result rows when used |
| `PROVIDER_DERIVED` | Provider-computed relevance sort, organic distribution buckets, intersection counts |
| `MOXDOP_DERIVED` | Opportunity categories / priority labels |

**Hard rule:** never present DataForSEO values as first-party measurement. UI pattern already frozen: `DataForSEO · estimated`.

---

## 6. Ranked Keywords Requirements

### Requirement

**Required** by Website → Visibility (and Overview counts). Supports Opportunity “visible but weak” heuristics.

### Official semantics (verified 2026-08-13)

| Item | Value |
| --- | --- |
| API family | DataForSEO Labs · Google |
| Endpoint | `POST /v3/dataforseo_labs/google/ranked_keywords/live` |
| Mode | **Live** only for this endpoint family (real-time Labs POST) |
| Datasource freshness | Provider Labs data updated **weekly** (Status endpoint) |
| Target | domain, subdomain, or page URL (domain without `https://`/`www.`; page URL with `https://` or `www.`) |
| Max rows / call | `limit` default 100, max **1000**; `offset` pagination |
| Rate | up to 2000 calls/min; 1 task per Live call; ≤30 simultaneous |

### Required product fields (retain)

| Field | Provenance | Notes |
| --- | --- | --- |
| target | request | Domain from Website asset |
| location_code / language_code (+ names) | request | SEO market — mandatory |
| keyword | PROVIDER_OBSERVED/DB | |
| rank_group / rank_absolute | PROVIDER_OBSERVED | Labs SERP element — **≠ GSC average position** |
| ranking URL / relative_url | PROVIDER_OBSERVED | Join to Website URL identity later |
| search_volume | PROVIDER_ESTIMATED | Ads-derived approximate monthly volume |
| cpc / competition | PROVIDER_ESTIMATED | Optional display |
| etv (row + summary organic) | PROVIDER_ESTIMATED | Label “Estimated organic traffic” |
| organic distribution buckets | PROVIDER_DERIVED | pos_1 … counts |
| total_count | PROVIDER_DERIVED | Universe size vs bounded rows |
| retrieved_at / provider cost | ops | |

### Explicit exclusions (V1)

- clickstream (`include_clickstream_data=false` — doubles cost)
- paid SERP item types (organic only for Visibility)
- historical time-series rank tracking
- full universe import (thousands–millions)

### GSC distinction

| Concept | GSC | Ranked Keywords |
| --- | --- | --- |
| Position | Property-observed avg position in Search Analytics window | Labs rank in provider SERP dataset |
| Demand proxy | Impressions | search_volume (estimated) |
| Scope | Verified property | Any target domain (external) |

**Do not merge.**

---

## 7. Keywords for Site Requirements

### Requirement

**Required** by Website → Visibility opportunity counts and cross-source opportunities. Conditional input for Content / Growth.

### Official semantics

| Item | Value |
| --- | --- |
| Endpoint | `POST /v3/dataforseo_labs/google/keywords_for_site/live` |
| Meaning | Keywords **relevant** to the target domain (category / relevance search) — **not** current rankings |
| Datasource | Keyword DB segmented by relevant domains from Google Ads API + SERP DB |
| Location | **Required** (`location_code` or `location_name`) |
| Language | Optional at provider; **Required by MoxDOP** (no silent default) |
| `relevance` | Provider internal sort key — **not returned** in result rows; **≠ Brand strategic priority** |
| Limit | default 100, max 1000 |

### Distinction vs Ranked Keywords

| | Ranked Keywords | Keywords for Site |
| --- | --- | --- |
| Question | What do we rank for? | What keywords are relevant to this site? |
| Rank present? | Yes | No (ideas / relevance universe) |
| Primary freeze use | Visibility ranked count + weak-rank band | Opportunity discovery |

---

## 8. Relevant Pages Requirements

### Decision: **CONDITIONAL — not Required by Contract V1 freeze UI**

Frozen Content directory/opportunities use Brand · GSC · Website inventory — **not** DataForSEO Relevant Pages.

| Item | Value |
| --- | --- |
| Endpoint (if later enabled) | `POST /v3/dataforseo_labs/google/relevant_pages/live` |
| Semantics | Pages of target domain with ranking distribution + **estimated** traffic — **not** “best page” / priority page |
| Join | Provider page URL → Website canonical URL identity (Website contract) |
| Cost class | LOW–MEDIUM (Labs all-other pricing × rows) |
| Trigger | Explicit enrichment only |

**Do not** interpret as content recommendation.

---

## 9. Competitor Domain Requirements

### Decision: **CONDITIONAL Required for Discovery; Optional for Visibility competitor context**

| Item | Value |
| --- | --- |
| Endpoint | `POST /v3/dataforseo_labs/google/competitors_domain/live` |
| Semantics | Domains overlapping organically with target in Labs SERP dataset |
| Classification | **ORGANIC SEARCH COMPETITOR** / competitor **candidate** |
| ≠ | Confirmed Brand / commercial competitor |
| Retain | domain, intersections, avg_position (intersected), optional organic metrics subset |
| Existing code | `CompetitorDomainCollector` limit **10**, TTL **14 days**, organic only |
| Promotion | Operator Accept only (`discovery_candidates`) — **never auto** |

---

## 10. Intersection Requirements

### Decision: **CONDITIONAL**

Use Labs `domain_intersection/live` when operator requests gap analysis vs a **selected** organic or confirmed competitor.

| Mode | Meaning |
| --- | --- |
| `intersections: true` | Keywords both domains rank for |
| `intersections: false` | Keywords target1 ranks for that target2 does not (gap input) |

**Intersection ≠ Opportunity.** Gap rows are Evidence inputs only after Brand Goal / Offering / Market / Website / GSC context.

**Not required** for Visibility panel V1 counts.

---

## 11. Search Volume / Keyword Context

| Decision | Detail |
| --- | --- |
| Required? | **Yes** — as fields **inside** Ranked Keywords + Keywords for Site (not separate Keyword Data API for V1) |
| Standalone Keyword Data / keyword_overview | **Not required** by freeze |
| Provenance | `PROVIDER_ESTIMATED` (approximate monthly searches) |
| ≠ GSC impressions | Different question — no substitute |

Location × language always part of keyword identity.

---

## 12. SERP Observation Requirements

### Decision: **NOT REQUIRED BY CONTRACT V1** (remain **CONDITIONAL**)

Labs Ranked Keywords already embeds SERP element context for ranked rows. Frozen Visibility does not need live SERP snapshots.

SERP API (`serp/google/organic/...`) may be justified later only when:

- Labs insufficient for a specific operator question (e.g. live SERP feature verification)
- explicit on-demand workflow + cost preview

| Mode | Verified pricing (2026-08-13) |
| --- | --- |
| Standard | $0.0006 / SERP (10 results) |
| Priority | $0.0012 |
| Live | $0.002 |

**Cost sensitivity: HIGH** if looped per keyword. Prefer Labs bulk endpoints.

SERP observation ≠ GSC average position.

---

## 13. Website → Visibility Dependencies

| Component | Capability | Inputs | Outputs | Freshness | Cost | Cache | Provenance |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DataForSEO panel counts | RK + KFS + MOXDOP_DERIVED opps | domain, SEO market | ranked_keywords, keywords_for_site, opportunities | RK 5d / KFS 7d (product TTL) | LOW–MED | fingerprint Evidence | estimated |
| Organic GSC KPIs | SEARCH_CONSOLE | property | clicks, impr, CTR, avg pos | GSC contract | free quota | GSC | measured |
| Local / AI lenses | Website / GBP / GA4 / Demo | — | non-DFS | — | — | — | mixed |

**No** `Organic Visibility = 82%` score.

---

## 14. Website → Content Dependencies

| Need | DFS? | Notes |
| --- | --- | --- |
| Inventory / titles / H1 / roles | NO | Website / WP |
| Content opportunities (freeze) | CONDITIONAL | Freeze sources Brand·GSC·inventory; DFS volume may enrich later |
| Decay | NO (GSC + CMS dates) | |
| Trends fixture | DEMO_ONLY | No pytrends / no DFS Trends |

Provider data ≠ rewrite/create/merge/delete decisions.

---

## 15. Brand → Growth Dependencies

| Component | DFS dependency |
| --- | --- |
| Growth context (Goal · services) | Brand Context only |
| Growth Opportunities list | May cite DFS Evidence as **input** later — not auto-created here |
| Growth observations / AI brief | Demo / AI — DFS may be listed as available source later |

External SEO without Offering / Goal / Market is **not** automatically a Growth Opportunity.

---

## 16. Opportunity Input Dependencies

Documented inputs only — **no detectors in this contract**.

| Concept (frozen Demo) | Possible DFS input | Also needs |
| --- | --- | --- |
| High paid demand + weak organic | Ranked KW / KFS volume + weak rank | Ads, GSC, Website, Goal, Offering |
| Content coverage gap | KFS keywords without page | Website inventory, Brand Offering, GSC |
| Competitor visibility gap | Competitors Domain + Intersection | Operator competitor confirmation, Market |

**Provider fact alone sufficient: NO.**  
**No SEO Opportunity Score / Keyword Opportunity Score.**

---

## 17. Competitor Context Semantics

| Class | Source | Auto Brand competitor? |
| --- | --- | --- |
| OPERATOR/BRAND CONFIRMED | Brand Intelligence `known_competitors` | Already confirmed |
| DATAFORSEO ORGANIC SEARCH COMPETITOR | Labs competitors_domain | **NO** — candidate only |

Promotion requires explicit MoxDOP Accept relationship.

---

## 18. Location / Language Contract

| Rule | Detail |
| --- | --- |
| Market source order | Website SEO market config (required for paid Labs) → Brand target market (guidance) → operator selection |
| Language source | Website SEO language (required) → Brand language → operator |
| Silent US/EN default | **FORBIDDEN** |
| Multi-market | Separate enrichment contexts: `keyword × location_code × language_code`; do not flatten |
| Free directory | `GET dataforseo_labs/locations_and_languages` for picker |

Missing market → state **NOT ELIGIBLE / CONFIGURATION REQUIRED** (existing collectors already throw).

---

## 19. GSC / DataForSEO Boundary

See mandatory matrix §101 below. Substitution of GSC metrics by DFS: **NO**.

---

## 20. Website / DataForSEO Boundary

| Website | DataForSEO |
| --- | --- |
| What site contains / serves / HTTP / CMS | External search intelligence about domain/keywords/pages |
| Technical truth | Not technical truth |

URL join uses Website contract normalization. Provider does not generate content recommendations.

---

## 21. Brand Context Boundary

| Provider relevance / volume | Brand strategic relevance |
| --- | --- |
| Labs relevance sort / search_volume | Offering · Goal · Audience · Market |

High volume ≠ priority.

---

## 22. Provider Estimate / Observation Semantics

| Metric | Label |
| --- | --- |
| search_volume, etv, estimated_paid_traffic_cost | PROVIDER_ESTIMATED — UI: `DataForSEO · estimated` |
| rank_group / ranked URL from Labs | PROVIDER_OBSERVED (Labs methodology) — still **not** GSC |
| relevance (sort only) | PROVIDER_DERIVED |
| CrossSource categories | MOXDOP_DERIVED |

---

## 23. Request Family Contract

See §104 Request Family Matrix. Families:

| ID | Status |
| --- | --- |
| `DFS-FREE-USER` | Required (config/health) |
| `DFS-FREE-MARKETS` | Required (SEO market picker) |
| `DFS-RK-LIVE` | **Required** |
| `DFS-KFS-LIVE` | **Required** |
| `DFS-COMP-DOMAIN-LIVE` | Conditional (Discovery / competitor context) |
| `DFS-DOMAIN-INTERSECT-LIVE` | Conditional (gap analysis) |
| `DFS-RELEVANT-PAGES-LIVE` | Conditional (page-level external) |
| `DFS-SERP-ORGANIC` | Conditional — **not V1 default** |
| `DFS-OPP-CROSS` | Required derived (no provider call) |

---

## 24. Eligibility Rules

| Family | Eligibility |
| --- | --- |
| DFS-RK / DFS-KFS | Website Digital Asset · domain resolvable · SEO market+language configured · DataForSEO Integration configured · not fresh-cache (unless force) · explicit refresh / approved workflow |
| DFS-COMP-DOMAIN | Same + Discovery / competitor analysis requested |
| DFS-DOMAIN-INTERSECT | Same + competitor target selected · analysis requested |
| DFS-RELEVANT-PAGES | Same + page-level enrichment requested |
| DFS-SERP | Explicit justification + cost preview · never default |

Ineligible → **do not call** with guessed market.

---

## 25. Cost Model

### Verified pricing (2026-08-13)

**Source:** https://dataforseo.com/pricing/dataforseo-labs/dataforseo-google-api

| Family | Billing model | Verified figures |
| --- | --- | --- |
| Labs “All Other Endpoints” (RK, KFS, Competitors Domain, Relevant Pages, Domain Intersection, etc.) | Per task + per item | **$0.012 / task** + **$0.00012 / item** |
| Clickstream flag | Multiplier | **×2** if `include_clickstream_data=true` |
| SERP Google Organic | Per SERP (10 results) | Live **$0.002**; Priority $0.0012; Standard $0.0006 |
| Free | `appendix/user_data`, `locations_and_languages` | $0 |

**Example estimate (not a hardcode in code):** RK Live limit=100 → ≈ `$0.012 + 100×$0.00012 = $0.024` (before clickstream).

Provider response `cost` field = source of truth for actual spend (existing client stores it).

### Cost classes (MoxDOP operational)

| Class | Methodology |
| --- | --- |
| LOW | Free endpoints; or single Labs task ≤~100 items |
| MEDIUM | Labs 100–1000 items; Discovery competitors limit 10 |
| HIGH | SERP loops; multi-page depth; clickstream; multi-market fan-out without bounds |
| VARIABLE | Cost scales with `limit` / items / SERP depth |
| UNKNOWN | Unverified endpoint |

### Budget guard (future control-plane — do not implement here)

- daily spend soft/hard limit
- per-request `estimated_cost` before dispatch when formula known
- optional monthly budget
- usage visibility from provider `cost` + Integration balance snapshot
- This is **provider-cost control**, not MoxDOP customer billing

### Runaway prevention

- Prefer domain-level Labs endpoints over per-keyword SERP
- Bound `limit` (product defaults 100 / Discovery 10)
- Cache-first + single-flight fingerprint lock
- No refresh of fresh Evidence
- No render / mount / browser-refresh triggers
- No unbounded competitor pagination

---

## 26. Cost Safety Matrix

| Capability | Endpoint | Mode | Billing | Verified | Class | Est. before dispatch? | Cache-first? | Manual approval? | Schedule? | Max scope | Risks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ranked Keywords | ranked_keywords/live | Live | task+item | 2026-08-13 Labs page | LOW–MED | YES (approx) | YES | YES on MISS | Optional later | limit≤100 V1 (max 1000) | Pagination cost; clickstream ×2 |
| Keywords for Site | keywords_for_site/live | Live | task+item | same | LOW–MED | YES | YES | YES | Optional | limit≤100 | Offset tourism |
| Competitors Domain | competitors_domain/live | Live | task+item | same | LOW | YES | YES | YES (Discovery) | No V1 | limit≤10 | Misread as Brand competitor |
| Domain Intersection | domain_intersection/live | Live | task+item | same | MED | YES | YES | YES | No | limit≤100; selected pair | Gap≠Opportunity |
| Relevant Pages | relevant_pages/live | Live | task+item | same | LOW–MED | YES | YES | YES | No | limit≤100 | Not “best pages” |
| SERP Organic | serp/... | Live/Std | per SERP | SERP pricing page | HIGH/VAR | YES | YES | YES | No | Explicit keywords only | Loop explosion |
| Markets dir | locations_and_languages | GET | free | docs | LOW | n/a | YES (TTL config) | No | Yes | full dir | — |
| user_data | appendix/user_data | GET | free | docs | LOW | n/a | health | Test connection | — | — | — |

---

## 27. Cache Strategy

**CACHE-FIRST is mandatory** for all paid families.

| Layer | Role |
| --- | --- |
| Provider response / Evidence payload cache | Replay, audit, avoid repeat spend (`request_fingerprint` + `fresh_until`) |
| Normalized enrichment | Product queries (future datasets / Evidence types) |

### Cache key inputs (minimum)

`provider` · `use_case` · `endpoint` · `target` · `location_code` · `language_code` · result-affecting params (`limit`, filters, `item_types`, `include_clickstream_data`, competitor target, `intersections` flag) · contract version (future)

**Incomplete keys that drop market are forbidden.**

Existing: `PaidRequestFingerprint` + `EvidenceFreshnessGuard` + `PaidRequestExecutor` lock.

---

## 28. Freshness Contract

| Capability | Expectation | Recommended TTL / staleness | Manual refresh | Scheduled | Stale fallback |
| --- | --- | --- | --- | --- | --- |
| Ranked Keywords | Labs weekly | **5 days** (existing product) | Confirm on MISS | Optional later | Show stale + label |
| Keywords for Site | Slower change | **7 days** | Confirm | Optional | Stale OK |
| Competitors Domain | Slower | **14 days** (existing) | Confirm | No V1 | Stale OK |
| Domain Intersection | With competitor pair | **7–14 days** | Confirm | No | Stale OK |
| Relevant Pages | Weekly Labs | **5–7 days** | Confirm | No | Stale OK |
| Search volume (embedded) | Follow parent | Parent TTL | — | — | — |
| SERP snapshot | Point-in-time | Hours–1 day if used | Confirm | No | Optional |
| Markets directory | Slow | 86400s config | Background OK | Yes | Use last |

**No universal TTL.**

Future freshness states: `FRESH` · `STALE` · `NOT_COLLECTED` · `COLLECTING` · `FAILED` · `UNAVAILABLE` — never map stale to zero.

---

## 29. Request Trigger Contract

| Trigger | Paid Labs/SERP |
| --- | --- |
| MANUAL (Refresh SEO intelligence / Discovery) | **Allowed** |
| ON_DEMAND confirmed workflow | Allowed |
| SCHEDULED | Conditional later — not Light V1 default |
| EVENT_DRIVEN | Conditional (e.g. market change invalidates cache) |
| **PAGE_RENDER / component mount / browser refresh** | **FORBIDDEN** |

Existing Demo copy: “Paid refresh requires confirmation · never on page render”.

---

## 30. Pagination / Volume

| Rule | Detail |
| --- | --- |
| Provider | `limit`/`offset` (KFS also `offset_token` for >10k) |
| UI Top-N | Presentation only — ≠ collection limit |
| Collection V1 | RK/KFS **100**; opportunities view **40**; competitors **10** |
| Full universe | **Unnecessary** for freeze — retain `total_count` for honesty |
| Analysis depth | Bounded 100 is minimum useful for Growth/Opportunity heuristics V1; expand only with cost review |

---

## 31. Candidate Normalized Datasets

Do **not** create tables in this milestone.

| ID | Status |
| --- | --- |
| `dataforseo_ranked_keyword_snapshot` | **Required candidate** |
| `dataforseo_keyword_site_snapshot` | **Required candidate** |
| `dataforseo_competitor_domain_snapshot` | Conditional candidate |
| `dataforseo_domain_intersection_snapshot` | Conditional |
| `dataforseo_relevant_page_snapshot` | Conditional |
| `dataforseo_keyword_metric_snapshot` | **Not separate** — metrics live on RK/KFS rows |
| `dataforseo_serp_observation` | Conditional / not V1 default |

See §105.

---

## 32. Raw Response Retention

| Recommendation | Detail |
| --- | --- |
| Retain raw / Evidence payload | **YES** for paid responses (audit, re-normalization, avoid re-spend) |
| Retention window | Align with Evidence lifecycle; prefer ≥ longest TTL + reprocess buffer |
| Object storage | Future — not now |
| Contract versioning | Raw enables re-normalize without re-pay when mapping changes |

---

## 33. History Requirements

| Dataset | History |
| --- | --- |
| Ranked Keywords | **Snapshot** on each paid refresh; no continuous rank-tracking time-series V1 |
| Keywords for Site | Latest valid snapshot sufficient |
| Competitors Domain | Latest snapshot |
| Intersection / Relevant Pages | Latest when collected |
| SERP | Point-in-time if ever collected |

Infinite history without product need: **NO**.

---

## 34. Failure / Stale Fallback Semantics

| State | Behavior |
| --- | --- |
| Provider timeout / error | FAILED — distinct from empty result |
| Insufficient balance | FAILED / UNAVAILABLE — surface operator message |
| Invalid credentials | FAILED |
| Invalid location/language | NOT ELIGIBLE / FAILED |
| Rate limited | FAILED / retry policy for safe reads only; **never auto-retry paid POST** (existing client) |
| Partial result | PARTIAL + counts |
| No result | Empty with `response_ok` + zero rows ≠ failure |
| Stale cache on refresh fail | May display stale with `STALE` provenance |

---

## 35. Existing Implementation Reuse Matrix

| Component | Capability | Endpoint | Paid? | Trigger | Cache | Consumer | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `DataForSeoApiClient` | Shared client | allowlisted | mixed | callers | — | all | **KEEP** |
| `DataForSeoEndpointAllowlist` | Guard | — | — | — | — | client | **KEEP** (+ add families when approved) |
| `DataForSeoCredentialResolver` / ProviderCredentialService | Creds | — | — | Settings | — | — | **KEEP** |
| `DataForSeoAccountService` / ConnectionProbe | Health | user_data | free | Test connection | snapshot | Settings | **KEEP** / legacy probe **ADAPT LATER** |
| `DataForSeoLabsMarketDirectory` | Markets | locations_and_languages | free | picker | config TTL | Website Settings | **KEEP** |
| `RankedKeywordsCollector` + Normalizer | RK | ranked_keywords/live | paid | Refresh SEO | fingerprint | Website Performance/Visibility | **KEEP** |
| `KeywordsForSiteCollector` + Normalizer | KFS | keywords_for_site/live | paid | Refresh SEO | fingerprint | same + opportunities | **KEEP** |
| `SeoIntelligenceRefreshService` | Orchestration | both | paid | Action + confirm | preview | Filament ViewDigitalAsset | **KEEP** |
| `CrossSourceKeywordOpportunities` | Derived | — | no | read Evidence | — | Performance | **KEEP** (MOXDOP_DERIVED) |
| `CompetitorDomainCollector` | Competitors | competitors_domain/live | paid | Discovery | fingerprint | Discovery candidates | **KEEP** |
| `SeoIntelligenceRefreshJob` / AsyncOperationService | Async | — | paid | queued after confirm | — | Activity | **KEEP** |
| `PaidRequestFingerprint` / FreshnessGuard / Executor | Cost guard | — | — | — | yes | paid | **KEEP** |
| Tests (`WebsiteSeoIntelligenceLightTest`, `DataForSeoCostGuardTest`, …) | Safety | fakes | no live spend | PHPUnit | — | CI | **KEEP** |
| SERP in fingerprint **tests only** | — | serp/... | — | — | — | tests | **UNKNOWN** for product — endpoint **not allowlisted** |
| Demo Visibility DFS panel | UI | — | no | render fixtures | — | Demo | **KEEP** (fixtures) |

---

## 36. Current Paid-Request Safety Audit

| Path | Can charge? |
| --- | --- |
| Demo page render / Livewire mount | **NO** (fixtures) |
| Browser refresh on Demo | **NO** |
| Filament Performance view alone | **NO** — requires Refresh SEO Intelligence action |
| Preview before refresh | **NO** provider call |
| Cache HIT refresh | **NO** paid call |
| Force refresh | **YES** — intentional |
| Async `SeoIntelligenceRefreshJob` | **YES** — only after queued from confirmed action |
| Discovery competitor collect | **YES** — when Discovery run executes collector |
| PHPUnit | **NO** (HTTP fakes; policy forbids live credits) |
| Scheduled task auto paid | **Not** Light V1 product default |

**Risk notes (document only — do not fix here):**

- Competitors Domain is allowlisted and callable from Discovery — ensure Discovery UI never auto-fires on page view (product docs: operator-triggered Discover).
- Cost-guard tests reference SERP fingerprints for generic fingerprint behavior — SERP not production-allowlisted.

---

## 37. Current Capability Gap Analysis

| Capability | Status vs Contract V1 |
| --- | --- |
| Ranked Keywords | **Present** — aligns Required |
| Keywords for Site | **Present** — aligns Required |
| Cross-source opportunities | **Present** (derived) |
| Competitors Domain | **Partial** — collector exists; Visibility freeze shows known competitors string, not live DFS list |
| Domain Intersection | **Missing** (Conditional — OK absent until gap workflow) |
| Relevant Pages | **Missing** (Conditional — OK) |
| Search Volume standalone | **Not required** — embedded OK |
| SERP | **Not required** / not allowlisted |
| Multi-market fan-out | **Partial** — single Website SEO market |
| Budget hard limits | **Missing** (control-plane later) |
| Semantically wrong | None critical; ensure competitor ≠ Brand competitor in all UX copy |

---

## 38. Cross-Brand Intelligence Boundary

| Rule | Status |
| --- | --- |
| Raw customer-scoped DFS results shared across Brands | **NO** |
| Aggregated / anonymized Sector Memory later | POSSIBLE LATER |
| Implement Intelligence Memory now | **NO** |

---

## 39. Privacy / Data Minimization

| Question | Answer |
| --- | --- |
| USER-LEVEL DATA REQUIRED | **NO** |
| PII REQUIRED | **NO** |
| Expected payloads | domains, keywords, URLs, estimated metrics, SERP features |

Incidental public business strings on ranking pages are public web content — not a PII pipeline.

**Minimization pass:** Contract V1 buys only RK + KFS (+ free config) by default; competitors/intersection/relevant pages/SERP only when a frozen/approved capability justifies them. Does **not** mirror full DataForSEO catalog.

---

## 40. Decisions Required Before Production Enrichment

1. Confirm V1 collection limits (100 / 10) vs any Growth analysis expansion.  
2. Whether scheduled SEO refresh is ever allowed (Light V1 docs say no).  
3. Whether Relevant Pages / Intersection enter a named workflow before Prompt 7 registry.  
4. Agency daily/monthly DFS spend guards.  
5. Multi-market: one Website SEO market vs Brand multi-market fan-out policy.  
6. Raw Evidence retention duration.

---

## 41. Definition of Done

| Check | Status |
| --- | --- |
| DataForSEO role = enrichment | YES |
| Account-ingestion model rejected | YES |
| No DataForSEO Digital Asset | YES |
| Central credentials allowed | YES |
| Capability-driven consumption | YES |
| Website Visibility requirements explicit | YES |
| Website Content dependencies explicit | YES |
| Brand Growth dependencies explicit | YES |
| Opportunity inputs explicit (no detectors) | YES |
| Competitor semantics explicit | YES |
| Ranked Keywords / KFS / Relevant Pages / Competitors / Intersections / Volume / SERP explicit | YES |
| GSC / Website / Brand boundaries explicit | YES |
| Cost model + verified pricing date | YES |
| Cache-first + per-capability freshness (no universal TTL) | YES |
| Estimate provenance + no invisible paid calls | YES |
| Eligibility + market/language (no silent defaults) | YES |
| Dataset candidates + reuse/gap explicit | YES |
| Future enrichment can run without inventing what/when/how much | YES |

**CONTRACT COMPLETENESS: PASS**

---

## CONSUMER REQUIREMENT MATRIX (§99)

| Req ID | Consumer | Workspace | UI / capability | Operator question | DFS capability | Endpoint | Inputs | Outputs | Market | Lang | Provenance | Est/Obs | R/O/C | Cost | Freshness | Cache | Cross-asset | Dataset | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DFS_VIS_RK | Website Visibility | Website | organic.dataforseo ranked count | Ext. ranked KW? | Ranked Keywords | ranked_keywords/live | domain, loc, lang, limit | count, rows, etv | SEO market | SEO lang | DATAFORSEO_LABS | est+obs | Required | LOW–MED | 5d | YES | Website domain | ranked_keyword_snapshot | ≠ GSC |
| DFS_VIS_KFS | Website Visibility | Website | keywords_for_site count | Relevant KW universe? | Keywords for Site | keywords_for_site/live | domain, loc, lang, min vol | count, rows | SEO market | SEO lang | DATAFORSEO_LABS | estimated | Required | LOW–MED | 7d | YES | — | keyword_site_snapshot | ≠ rankings |
| DFS_VIS_OPP | Website Visibility | Website | opportunities count | Ext. opps? | Cross-source | derived | KFS×GSC×RK | categories | inherited | inherited | MOXDOP_DERIVED | derived | Required | none | parent | Evidence | GSC | — | heuristic |
| DFS_OVERVIEW | Website Overview | Website | search_snapshot | DFS opps glance? | same | derived | — | count | — | — | MOXDOP_DERIVED | estimated label | Required | none | — | — | — | — | |
| DFS_CONTENT | Website Content | Website | content opportunities | Demand context? | KFS (opt) | keywords_for_site/live | — | volume context | SEO market | SEO lang | DATAFORSEO_LABS | estimated | Conditional | LOW–MED | 7d | YES | Brand Offering | keyword_site | Freeze sources Brand·GSC |
| DFS_GROWTH | Brand Growth | Brand | Growth opportunities | Growth intel? | RK/KFS/comp as Evidence | various | Brand+Website | inputs only | Brand markets | Brand langs | mixed | mixed | Conditional | — | — | — | Goal/Offering | — | fact≠Opportunity |
| DFS_OPP_GAP | Opportunity | Ops | organic/content gap concepts | Gap evidence? | KFS/RK/intersect | various | — | Evidence | market | lang | Labs | est | Conditional | — | — | — | Ads/GSC/Web | — | no auto create |
| DFS_DISC_COMP | Discovery | Website | competitor candidates | Organic competitors? | Competitors Domain | competitors_domain/live | domain, loc, lang | domains, intersections | SEO market | SEO lang | DATAFORSEO_LABS | derived/obs | Conditional | LOW | 14d | YES | Brand Accept | competitor_domain_snapshot | ≠ business competitor |
| DFS_CFG_MKT | Website Setup | Website | Search market | Which market? | Locations & Languages | locations_and_languages | — | directory | — | — | DATAFORSEO_OTHER | — | Required | free | 1d cache | YES | — | — | |
| DFS_CFG_AUTH | Settings | Agency | Test connection | Creds OK? | user_data | appendix/user_data | creds | balance snapshot | — | — | OTHER | — | Required | free | health | — | — | — | |

---

## PROVIDER CAPABILITY MATRIX (§100)

| Capability | Required by freeze? | API family | Endpoint | Modes | Inputs | Outputs | Loc? | Lang? | Pagination | Max scope | Cost model | Semantic type | Est/Obs | Consumers | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ranked Keywords | YES | Labs Google | ranked_keywords/live | Live | target, loc, lang, filters | items+metrics | recommended/MoxDOP req | MoxDOP req | limit/offset | 100 V1 | task+item | ranking intel | mixed | Visibility | **USE** |
| Keywords for Site | YES | Labs Google | keywords_for_site/live | Live | target, loc, lang | keyword ideas | **required** | MoxDOP req | limit/offset/token | 100 V1 | task+item | relevance universe | estimated | Visibility/Opp | **USE** |
| Competitors Domain | Conditional | Labs Google | competitors_domain/live | Live | target, loc, lang | competitor domains | MoxDOP req | MoxDOP req | limit | 10 V1 | task+item | organic competitors | derived | Discovery | **USE when Discovery** |
| Relevant Pages | Conditional | Labs Google | relevant_pages/live | Live | target, loc, lang | pages+etv | opt/MoxDOP | MoxDOP | limit/offset | 100 | task+item | page traffic est | estimated | future Vis/Content | **DEFER** |
| Domain Intersection | Conditional | Labs Google | domain_intersection/live | Live | target1/2, loc, lang, intersections | keyword overlap/gap | MoxDOP | MoxDOP | limit | 100 | task+item | overlap/gap | mixed | Opp input | **DEFER until workflow** |
| Search Volume standalone | NO | Keyword/Labs | various | — | keywords | volume | yes | yes | — | — | varies | demand est | estimated | — | **Covered via RK/KFS** |
| SERP Organic | NO (cond) | SERP | serp/google/organic/* | Live/Std | keyword, loc, lang | SERP rows | yes | yes | depth | bounded | per SERP | live observation | observed | rare | **NOT V1** |
| Locations & Languages | YES (config) | Labs | locations_and_languages | GET | — | directory | — | — | — | full | free | metadata | — | Settings | **USE** |
| user_data | YES (config) | Appendix | appendix/user_data | GET | — | account | — | — | — | — | free | health | — | Settings | **USE** |

---

## GSC VS DATAFORSEO MATRIX (§101)

| Concept | GSC | DataForSEO | Same semantic? | Substitute? | Compare? | Join? | Provenance warning |
| --- | --- | --- | --- | --- | --- | --- | --- |
| query / keyword | Search Analytics query | Labs keyword string | Similar string | NO | careful string match only | exact/case-norm key | Different universes |
| impressions | Property-observed exposure | — | NO | NO | NO | NO | ≠ search_volume |
| search volume | — | Estimated monthly searches | NO | NO | NO as substitute | optional context | PROVIDER_ESTIMATED |
| position / rank | avg position in window | Labs rank_group / SERP | NO | NO | NO as one field | side-by-side only | Never merge |
| page | GSC page URL | ranking / relevant page URL | joinable | NO | — | YES via Website URL ID | Normalize first |
| demand | impressions proxy | search_volume | NO | NO | interpretive only | — | Label both |
| visibility | clicks/impr/CTR | ranked count / etv | NO | NO | NO score merge | — | No Visibility % |
| competitors | not provided | organic competitor domains | NO | NO | — | optional to Brand | ≠ business competitor |

---

## COST MATRIX (§102)

| Request Family | Endpoint | Mode | Billing unit | Verified source | Class | Est. request cost | Cache-first | Min freshness | Manual | Schedule | Runaway risk |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DFS-RK-LIVE | ranked_keywords/live | Live | task+item | Labs pricing 2026-08-13 | LOW–MED | ≈$0.024 @100 items | YES | 5d | YES | later opt | high limit / clickstream |
| DFS-KFS-LIVE | keywords_for_site/live | Live | task+item | same | LOW–MED | ≈$0.024 @100 | YES | 7d | YES | later | offset loops |
| DFS-COMP-DOMAIN-LIVE | competitors_domain/live | Live | task+item | same | LOW | ≈$0.0132 @10 | YES | 14d | YES | NO | unbounded limit |
| DFS-DOMAIN-INTERSECT-LIVE | domain_intersection/live | Live | task+item | same | MED | ≈$0.024 @100 | YES | 7–14d | YES | NO | many competitor pairs |
| DFS-RELEVANT-PAGES-LIVE | relevant_pages/live | Live | task+item | same | LOW–MED | ≈$0.024 @100 | YES | 5–7d | YES | NO | full site pages |
| DFS-SERP-ORGANIC | serp/... | Live/Std | per SERP | SERP pricing | HIGH/VAR | Live $0.002×N | YES | short | YES | NO | per-keyword loops |
| DFS-FREE-* | user_data / markets | GET | free | docs | LOW | $0 | YES | health/1d | Test/auto dir | YES dir | — |

---

## CACHE / FRESHNESS MATRIX (§103)

| Capability | Cache key | Raw cache? | Normalized? | Freshness | TTL / staleness | Manual | Scheduled | Stale fallback | Single-flight |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ranked Keywords | provider+usecase+endpoint+target+loc+lang+params | Evidence payload | summary+rows | weekly Labs | 5d | YES | later | YES + label | YES |
| Keywords for Site | same pattern | YES | rows | slower | 7d | YES | later | YES | YES |
| Competitors Domain | +limit+item_types | YES | candidates | slower | 14d | YES | NO | YES | YES |
| Domain Intersection | +target1+target2+intersections flag | YES | rows | — | 7–14d | YES | NO | YES | YES |
| Relevant Pages | target+market+params | YES | pages | weekly | 5–7d | YES | NO | YES | YES |
| Search volume | via parent | — | on rows | parent | parent | — | — | — | — |
| SERP | keyword+loc+lang+depth | YES | observation | live | hours–1d | YES | NO | optional | YES |
| Markets | endpoint | config cache | directory | slow | 86400s | — | YES | YES | — |

---

## REQUEST FAMILY MATRIX (§104)

| ID | Consumer | Endpoint | Mode | Target | Loc | Lang | Params | Pagination | Expected rows | Cost | Cache | Freshness | Trigger | Dataset | Required | Eligibility |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DFS-FREE-USER | Settings | appendix/user_data | GET | — | — | — | — | — | 1 | free | health | on test | MANUAL | — | Required | Integration exists |
| DFS-FREE-MARKETS | Website Setup | locations_and_languages | GET | — | — | — | — | — | dir | free | TTL | 1d | ON_DEMAND/sched | — | Required | Creds |
| DFS-RK-LIVE | Visibility | ranked_keywords/live | Live | domain | req | req | organic, no clickstream, limit 100 | offset | ≤100 | LOW–MED | FP | 5d | MANUAL confirm | ranked_keyword_snapshot | Required | domain+market+creds+stale |
| DFS-KFS-LIVE | Visibility/Opp | keywords_for_site/live | Live | domain | req | req | no serp_info, min vol filter, limit 100 | offset | ≤100 | LOW–MED | FP | 7d | MANUAL | keyword_site_snapshot | Required | same |
| DFS-OPP-CROSS | Visibility | — | — | — | — | — | GSC+RK+KFS | — | ≤40 | none | Evidence | parent | READ | — | Required | Evidence present |
| DFS-COMP-DOMAIN-LIVE | Discovery | competitors_domain/live | Live | domain | req | req | organic, limit 10 | — | ≤10 | LOW | FP | 14d | MANUAL Discovery | competitor_domain_snapshot | Conditional | Discovery+market |
| DFS-DOMAIN-INTERSECT-LIVE | Opp input | domain_intersection/live | Live | pair | req | req | intersections T/F, organic | offset | ≤100 | MED | FP | 7–14d | ON_DEMAND | domain_intersection_snapshot | Conditional | competitor selected |
| DFS-RELEVANT-PAGES-LIVE | future Vis | relevant_pages/live | Live | domain | req | req | organic bias, limit 100 | offset | ≤100 | LOW–MED | FP | 5–7d | ON_DEMAND | relevant_page_snapshot | Conditional | enrichment asked |
| DFS-SERP-ORGANIC | rare | serp/google/organic/* | Live/Std | keyword | req | req | depth bounded | — | 10×pages | HIGH | FP | short | ON_DEMAND | serp_observation | Conditional | Labs insufficient |

---

## DATASET MATRIX (§105)

| ID | Capability | Scope | Grain | Keys | Loc/lang | Provider facts | Est/Obs | Snap/TS | Consumers | History | Freshness | Cost | Volume | Cross-asset | Limits |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| dataforseo_ranked_keyword_snapshot | RK | Website×market | keyword×target×market×retrieved_at | target, keyword, loc, lang | yes | rank, url, volume, etv | mixed | snapshot | Visibility, Opp | refresh snaps | 5d | LOW–MED | ≤100 rows + total_count | Website URL | partial universe |
| dataforseo_keyword_site_snapshot | KFS | Website×market | keyword×target×market×retrieved_at | target, keyword, loc, lang | yes | volume, cpc, difficulty | estimated | snapshot | Visibility, Content cond, Opp | latest | 7d | LOW–MED | ≤100 | Brand filter later | relevance≠strategy |
| dataforseo_competitor_domain_snapshot | Comp domain | Website×market | competitor_domain×target×market | domains, loc, lang | yes | intersections, avg_pos | derived | snapshot | Discovery | latest | 14d | LOW | ≤10 | Brand Accept | organic only |
| dataforseo_domain_intersection_snapshot | Intersection | pair×market | keyword×pair×market | targets, keyword | yes | ranks both/gap | mixed | snapshot | Opp input | latest | 7–14d | MED | ≤100 | Brand competitor | ≠ Opportunity |
| dataforseo_relevant_page_snapshot | Relevant pages | Website×market | page×market | page url, target | yes | distribution, etv | estimated | snapshot | future Vis/Content | latest | 5–7d | LOW–MED | ≤100 | Website URL | ≠ best page |
| dataforseo_serp_observation | SERP | keyword×market | serp×retrieved_at | keyword, loc, lang | yes | organic rows | observed | point | rare | optional | short | HIGH | tiny | — | not V1 |

---

## COMPETITOR SEMANTICS MATRIX (§106)

| Provider discovered domain | Search overlap meaning | Confirmed business competitor? | Operator confirmation? | Brand competitor relation? | Consumers | Limitations |
| --- | --- | --- | --- | --- | --- | --- |
| Labs competitors_domain item | Shares organic keyword SERPs with target | **NO** | **YES** to promote | Only after Accept | Discovery, optional Vis | May include publishers/directories; use exclude_top_domains when productized |
| Brand known_competitors | Operator knowledge | YES | already | Brand Context | Business, Growth | May not overlap search |
| Intersection pair | Keyword overlap/gap | only if confirmed | YES | optional | Opp Evidence | Gap≠Opportunity |

**Rule:** provider-discovered organic competitor ≠ automatically confirmed business competitor. **Automatic promotion: NO.**

---

## OPPORTUNITY INPUT MATRIX (§107)

| Opportunity concept | DFS input | GSC input | Website input | Brand context | Provider fact alone sufficient? |
| --- | --- | --- | --- | --- | --- |
| High paid demand + weak organic | RK weak rank / KFS volume | impressions/pos | page depth | Goal, Offering, Market | **NO** |
| Content coverage gap | KFS keywords w/o page | zero-landing queries | inventory | Offering, Audience | **NO** |
| Competitor visibility gap | Comp domain + intersection | optional | optional | confirmed competitor, Goal | **NO** |
| Strong page expansion | Relevant pages etv (cond) | page metrics | page content | Offering | **NO** |

---

## EXISTING IMPLEMENTATION MATRIX (§108)

| Component | Current capability | Endpoint | Paid? | Trigger | Cache | Consumer | Contract coverage | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DataForSeoApiClient | HTTP client | allowlist | mixed | — | — | all | yes | KEEP |
| EndpointAllowlist | RK, KFS, Comp, free | — | — | — | — | client | yes | KEEP |
| Credential stack | agency creds | — | — | Settings | — | — | yes | KEEP |
| RankedKeywordsCollector | RK | ranked_keywords/live | Y | Refresh SEO | FP | Website | Required | KEEP |
| KeywordsForSiteCollector | KFS | keywords_for_site/live | Y | Refresh SEO | FP | Website | Required | KEEP |
| SeoIntelligenceRefreshService | orchestrate | both | Y | Action | preview | Filament | Required | KEEP |
| CrossSourceKeywordOpportunities | derived opps | — | N | read | — | Performance | Required derived | KEEP |
| CompetitorDomainCollector | organic competitors | competitors_domain/live | Y | Discovery | FP | Discovery | Conditional | KEEP |
| Async SeoIntelligenceRefreshJob | queue | — | Y | after confirm | — | Activity | yes | KEEP |
| EvidenceFreshnessGuard / PaidRequestExecutor | cost guard | — | — | — | yes | paid | yes | KEEP |
| DataForSeoConnectionProbeService | legacy site probe | user_data | free | legacy | — | transitional | debt | ADAPT LATER |
| SERP allowlist | — | — | — | — | — | tests only | not V1 | UNKNOWN / do not enable casually |
| Demo fixtures DFS panel | UI | — | N | render | — | Demo | yes | KEEP |

---

## Normalized identities (summary)

| Identity | Keys |
| --- | --- |
| Keyword | normalized text × language_code × location_code × provider |
| Domain | registrable domain / subdomain / provider target mode — map from WebsiteDomainTarget |
| Page | Website URL identity (Website contract) ← provider absolute/relative URL |

---

## Live vs Standard / Task-based (mode principle)

Labs Google endpoints used here are **Live** POST (task fee + items). There is no separate cheaper Standard mode for these Labs live endpoints. SERP offers Standard/Priority/Live — prefer Standard/Priority if SERP ever required for bulk; Live only for explicit one-off.

Prefer asynchronous MoxDOP jobs (`SeoIntelligenceRefreshJob`) for operator UX even when provider mode is Live.

---

## End state

```text
DATAFORSEO DATA CONTRACT V1 DEFINES DATAFORSEO AS
A COST-CONTROLLED EXTERNAL INTELLIGENCE CAPABILITY,
NOT AS A GENERIC DATA INGESTION PIPELINE.

Website / Brand need
  → Eligibility
  → Target Market + Language
  → Freshness / Cache check
  → Cost-aware request decision
  → DataForSEO capability
  → Raw provider response
  → Normalized enrichment
  → Explicit provider provenance
  → Future Evidence / Growth Intelligence
```

No hidden paid requests. No automatic account import. No DataForSEO Digital Asset. No guessed country/language. No provider estimate as first-party measurement. No organic competitor auto-treated as commercial competitor.
