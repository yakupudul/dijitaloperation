# SEARCH CONSOLE DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR COLLECTION IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Cumulative docs base | `cursor/data-contract-ga4-ea01` @ `efe90286a2d56c1f567ef2836bf9164bee24c09d` (includes GA4 Data Contract V1; not yet on `main`) |
| Audit branch | `cursor/data-contract-gsc-ea01` |
| Runtime product code changed | **NONE** |

Future semantic changes require **v2** or an explicit amendment.

Official references (not blogs):

- [Search Analytics: query](https://developers.google.com/webmaster-tools/v1/searchanalytics/query)
- [Getting your performance data](https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data)
- [Usage Limits](https://developers.google.com/webmaster-tools/limits)
- [Sitemaps resource](https://developers.google.com/webmaster-tools/v1/sitemaps)
- [URL Inspection: index.inspect](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect)
- [UrlInspectionResult](https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult)
- [Sites API](https://developers.google.com/webmaster-tools/v1/api_reference_index)

Hard semantic boundaries for this contract:

1. **SEARCH CONSOLE OBSERVATION ≠ TOTAL MARKET DEMAND**
2. **AVERAGE POSITION ≠ EXACT SERP RANK**
3. **SEARCH ANALYTICS ≠ COMPLETE INDEXING INVENTORY**

---

## 1. Purpose

Define exactly what the frozen Search Console (GSC) operator product requires from Google Search Console APIs and from MoxDOP domains **before** any production collector, pagination strategy, warehouse table, Evidence pipeline, or Customer API call.

```text
Frozen GSC UI
  → Explicit provider requirements
  → Explicit derivations
  → Explicit limitations
  → Future normalized storage
  → Future Evidence
```

The future collector **must not invent** data requirements.

---

## 2. Frozen UI Scope

Verified from `SearchConsolePage::$allowedTabs`, views under `resources/views/livewire/demo/search-console/`, `docs/product/website/SEARCH_CONSOLE.md`, and freeze tests.

| Tab key | Label | Present |
| --- | --- | --- |
| `overview` | Overview | YES |
| `performance` | Search Performance | YES |
| `demand` | Queries & Demand | YES (Clusters · Query explorer · Momentum · Ownership) |
| `pages` | Pages | YES |
| `indexing` | Indexing | YES (Coverage · URL inspection · Sitemaps · Reconciliation) |
| `operations` | Operations | YES |

**Relationships** is embedded in Overview (legacy `tab=relationships` → overview). Period bar shown for overview/performance/demand/pages/operations; **Indexing uses snapshot semantics** (no period bar).

Legacy remaps: `queries`→demand; `countries`/`devices`→performance; `sitemaps`/`url_inspection`→indexing.

### Supporting artifacts audited

- `app/Livewire/Demo/Assets/SearchConsolePage.php`
- `app/Support/Demo/GscWorkspaceFixtures.php`
- `app-modules/website/src/Collection/SearchConsoleBoundCollector.php`
- `app/Services/Integrations/Google/Discovery/SearchConsoleDiscoverer.php`
- `app/Services/SearchConsoleConnectionProbeService.php`
- `app-modules/website/src/Opportunities/GscStrikingDistance*`
- Shared `DemoPeriod` / period-bar

---

## 3. Provider Capability Boundary

| Capability | Endpoint family | Can support |
| --- | --- | --- |
| Search Analytics | `POST .../sites/{siteUrl}/searchAnalytics/query` | clicks, impressions, ctr, position by dimensions |
| Sites / property metadata | `GET .../sites` | property URL, permission, Domain vs URL-prefix |
| Sitemaps | `GET .../sites/{siteUrl}/sitemaps` (+ get) | submitted sitemap list/status/counts |
| URL Inspection | `POST .../urlInspection/index:inspect` | **per-URL** Google index-state fields |
| **Not available via API as full web-UI Page Indexing report** | — | Exhaustive Indexed/Not indexed/Excluded site totals |

MoxDOP will **not** scrape SERPs, call DataForSEO inside this contract, or write to Search Console.

---

## 4. Source Classification

| Class | Meaning |
| --- | --- |
| `GSC_SEARCH_ANALYTICS` | Provider Search Analytics metrics/dimensions |
| `GSC_SITEMAPS_API` | Sitemaps API |
| `GSC_URL_INSPECTION_API` | URL Inspection API |
| `GSC_SITE_METADATA` | Sites / property identity |
| `MOXDOP_DERIVED` | Calculated classifications/trends/CTR reaggregation |
| `MOXDOP_MAPPING` | Brand/query→intended page / brand classification rules |
| `CROSS_ASSET` | Website inventory, GA4 page context, GBP, Findings |
| `OPERATOR_MAINTAINED` | Operator config (brand terms, intended owners) |
| `OPERATIONS_DOMAIN` | Findings / Recommendations / Work / Outcomes |
| `UNAVAILABLE` | Cannot be honestly produced |
| `DEMO_ONLY` | Fixture assumption not reproducible as stated |

---

## 5. UI Requirement Matrix

### 5.1 Shell / identity / period

| Requirement ID | Workspace | UI | Question | Semantic | Demo | Source class | Endpoint | Type | Dims | Metrics | Grain | R/O/C | Missing | Dataset | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GSC_SHELL_IDENTITY | All | Header title/brand/property | Which GSC property? | Asset + `siteUrl` + Domain/URL-prefix | `identity.*` | `GSC_SITE_METADATA`+`CROSS_ASSET` | Sites | — | — | — | Property | REQUIRED | Not connected | `gsc_property_metadata` | `sc-domain:` vs URL-prefix |
| GSC_SHELL_RELATIONSHIP | All | Observes · Website | What does GSC observe? | observes Website | relationship_line | `CROSS_ASSET` | — | — | — | — | Brand | REQUIRED | Unlinked | — | Not provider metric |
| GSC_SHELL_FRESHNESS | All | Freshness chips | How fresh? | Collection ages | freshness[] | `OPERATIONS_DOMAIN`+`CROSS_ASSET` | — | — | — | — | Run | CONDITIONAL | No collection yet | — | — |
| GSC_SHELL_PERIOD | overview/performance/demand/pages/ops | Period bar | Which window? | Inclusive dates in **Search Analytics reporting calendar (PT)** | DemoPeriod | Operator + provider date rules | — | — | `date` | — | Daily | REQUIRED | Invalid range | all SA datasets | **PT / America/Los_Angeles — not Brand TZ, not UTC invent** |
| GSC_SHELL_COMPARE | same | Compare toggle | Previous equal length? | Immediately preceding equal day count in SA date domain | previousBounds | `MOXDOP_DERIVED` | — | — | — | — | — | REQUIRED | Hide deltas | — | — |

### 5.2 Overview

| ID | Component | Source class | Provider | Notes |
| --- | --- | --- | --- | --- |
| GSC_OVERVIEW_CLICKS | KPI Clicks + relative % | `GSC_SEARCH_ANALYTICS` | clicks | Property range; daily for trend |
| GSC_OVERVIEW_IMPRESSIONS | KPI Impressions + relative % | `GSC_SEARCH_ANALYTICS` | impressions | **Observed exposure ≠ market demand** |
| GSC_OVERVIEW_CTR | KPI CTR + **pp** delta | `MOXDOP_DERIVED` (prefer) / provider ctr | clicks/impressions | Never average CTR rows |
| GSC_OVERVIEW_SEARCH_ATTENTION | KPI Search attention count | `OPERATIONS_DOMAIN` | — | Not a quality score |
| GSC_OVERVIEW_NEEDS_ATTENTION | Attention list + drawer | `OPERATIONS_DOMAIN` | Evidence deps | — |
| GSC_OVERVIEW_TREND | Chart clicks/impr/CTR/position | `GSC_SEARCH_ANALYTICS`+derived | date + metrics | Metric switcher |
| GSC_OVERVIEW_MOMENTUM | Momentum bucket counts | `MOXDOP_DERIVED` / **METHODOLOGY GAP** | query daily | Heuristic Demo |
| GSC_OVERVIEW_DISCOVERABILITY | Funnel stages | `CROSS_ASSET`+`GSC_*`+`DEMO_ONLY` | mixed | Website→sitemap→index→impr→clicks→GA4 |
| GSC_OVERVIEW_PAGE_PULSE | Page pulse table | SA page + derived + cross-asset | page | Top presentation ≠ collection limit |
| GSC_OVERVIEW_OPPORTUNITIES | Opportunity list | `OPERATIONS_DOMAIN`+derived | — | Includes striking-distance heuristic elsewhere |
| GSC_OVERVIEW_OUTCOMES | Recent outcomes | `OPERATIONS_DOMAIN` | — | Observational |
| GSC_OVERVIEW_RELATIONSHIPS | Relationship summary | `CROSS_ASSET`+site metadata | — | — |

Avg position appears in Overview Clicks secondary (`avg pos X.X`) and Performance KPI — **not** as Overview fourth KPI (fourth is Search attention).

### 5.3 Search Performance

| ID | Component | Source class | Provider dims/metrics | R/O/C |
| --- | --- | --- | --- | --- |
| GSC_PERF_CLICKS | KPI | SA | clicks | REQUIRED |
| GSC_PERF_IMPRESSIONS | KPI | SA | impressions | REQUIRED |
| GSC_PERF_CTR | KPI | Derived | clicks/impressions | REQUIRED |
| GSC_PERF_AVG_POSITION | KPI Avg position | SA | position | REQUIRED; UI already says ≠ global rank |
| GSC_PERF_TREND | Metric switcher chart | SA daily | date + 4 metrics | REQUIRED |
| GSC_PERF_DEVICE | Device breakdown | SA | device + clicks/impr/(ctr/pos) | REQUIRED |
| GSC_PERF_COUNTRY | Country table | SA | country + clicks/impr | REQUIRED (top countries presentation) |
| GSC_PERF_BRAND_NONBRAND | Brand vs non-brand | `MOXDOP_DERIVED`+`OPERATOR_MAINTAINED` | query facts + brand term map | CONDITIONAL |
| GSC_PERF_DIAGNOSIS | Diagnosis blurb | `MOXDOP_DERIVED` | aggregates | OPTIONAL interpretive text |

**No Search Appearance UI** in freeze → **NOT REQUIRED** for V1 collection.  
**No search-type selector** in freeze → collect **`type=web` only** for V1.

### 5.4 Queries & Demand

| ID | Component | Source class | Notes |
| --- | --- | --- | --- |
| GSC_DEMAND_CLUSTERS | Cluster cards | `MOXDOP_DERIVED` + SA query facts | **METHODOLOGY GAP** for clustering |
| GSC_DEMAND_QUERY_EXPLORER | Query table | `GSC_SEARCH_ANALYTICS` (+ page via q×p) | query, clicks, impr, ctr, position, page, trend |
| GSC_DEMAND_MOMENTUM | Momentum buckets | `MOXDOP_DERIVED` | growing/declining/new/lost/ctr_review/striking_distance — **METHODOLOGY GAP** except striking-distance has code defaults |
| GSC_DEMAND_OWNERSHIP | Ownership review | `MOXDOP_DERIVED`+`MOXDOP_MAPPING`+`CROSS_ASSET` | intended vs observed shares — **DERIVATION METHODOLOGY REQUIRED** |

### 5.5 Pages

| ID | Component | Source class | Notes |
| --- | --- | --- | --- |
| GSC_PAGES_DIRECTORY | Pages table | SA page + `CROSS_ASSET` | clicks; content role/offering; GA4 context; Website attention |
| GSC_PAGES_DRAWER | Page drawer | same | — |

### 5.6 Indexing

| ID | Component | Classification | Honest status |
| --- | --- | --- | --- |
| GSC_INDEX_COVERAGE_TOTALS | Indexed/Not indexed/Unknown/Excluded cards | **UNAVAILABLE** / **DEMO_ONLY** as full GSC Page Indexing report | Cannot fake complete coverage from API |
| GSC_INDEX_DISCOVERABILITY_BY_ROLE | Role × impressions × inventory | `CROSS_ASSET`+SA page | Inventory from Website; impressions from SA |
| GSC_INDEX_URL_INSPECTION_TABLE | Per-URL Google index state table | `GSC_URL_INSPECTION_API`+Website inventory | Priority/sampled URLs only |
| GSC_INDEX_SITEMAPS | Sitemaps table | `GSC_SITEMAPS_API` | path, submitted, lastDownloaded, submitted counts, warnings, errors |
| GSC_INDEX_RECONCILIATION | Website vs sitemap vs inspected | `CROSS_ASSET`+Sitemaps+Inspection | Derived gaps |

### 5.7 Operations

| ID | Source class | Provider need |
| --- | --- | --- |
| GSC_OPS_* | `OPERATIONS_DOMAIN` | Indirect Evidence only |

---

## 6. Search Analytics Metrics

| Metric | API field | Official meaning (contract) | Aggregation | Store? |
| --- | --- | --- | --- | --- |
| Clicks | `clicks` | Clicks from Google Search results for this property | Summable across compatible rows | **BASE FACT** |
| Impressions | `impressions` | **Google Search exposure observed for this property** — **NOT total market demand** | Summable | **BASE FACT** |
| CTR | `ctr` | clicks/impressions for the row (0–1) | **Do not average row CTR**; recompute `Σclicks/Σimpressions` | Prefer **DERIVED** |
| Average position | `position` | Provider average position for the row (impression-weighted semantics in GSC product) — **NOT exact SERP rank, not local pack rank, not rank tracker** | Impression-weighted when combining rows: `Σ(position×impressions)/Σimpressions` | Store provider position at grain; recompute carefully |

Missing days when grouping by `date`: omitted from response (not zero). Query anonymization may omit query rows while property totals still include those clicks/impressions (**completeness limitation**).

---

## 7. Query Requirements

| Topic | Contract |
| --- | --- |
| Required | YES — Demand Query explorer, momentum inputs, ownership, clusters |
| Grain | Prefer **`date × query`** daily facts for arbitrary ranges + trends |
| Metrics | clicks, impressions; CTR derived; position stored |
| Optional page on explorer | From **query × page** observed owner (highest impressions method — see §14) |
| Anonymized queries | Omitted from query-dimension responses; **missing query rows ≠ zero demand** |
| Completeness | Top-row / internal limits / anonymization → **API extraction success ≠ complete Google Search activity** |
| ≠ market keywords | Distinct from DataForSEO keyword universe |

---

## 8. Page Requirements

| Topic | Contract |
| --- | --- |
| Dimension | `page` (URI string returned by Search Analytics) |
| Grain | **`date × page`** |
| Metrics | clicks, impressions; CTR derived; position |
| URL semantics | Result/page URI as returned; normalize later for Website join |
| Website join | Normalized URL key (scheme/host/www/slash/query/fragment policy — future shared layer) |
| Query count per page | If shown later: **observed returned query count under extraction limits** — not all queries |

---

## 9. Query × Page Requirements

| Topic | Contract |
| --- | --- |
| Required | **YES** — Ownership, explorer page column, fragmentation, future cannibalization candidates |
| Conceptual grain | `date × query × page` |
| Purpose | Observed owner, share of impressions/clicks per query across pages |
| Cardinality | **VERY HIGH** |
| Extraction | Max **25,000 rows/request**; pagination via `startRow`; official guidance ~**50,000 rows/day/search type** ceiling; still **not guaranteed complete** |
| Completeness | Document extraction metadata: row_limit, startRow pages fetched, truncated flag |
| Do not promise | Exhaustive query×page universe |

---

## 10. Country / Device Requirements

| Dimension | Frozen use | API | Grain | V1 status |
| --- | --- | --- | --- | --- |
| `country` | Performance country table (ISO alpha-3 in API; Demo shows names) | `country` | Prefer `date × country` or range top-N with daily property retained | REQUIRED (dedicated family) |
| `device` | Performance device bars | `device` = DESKTOP/MOBILE/TABLET | `date × device` | REQUIRED |
| Interactive filters on all tables | **Not present** in freeze | — | Do not explode every fact with country×device×query | — |

---

## 11. Search Appearance Requirements

**NOT REQUIRED BY SEARCH CONSOLE DATA CONTRACT V1** — no frozen UI consumption. Compatibility with other dimensions is restricted; do not collect unless product amends.

---

## 12. Search Type Requirements

| Topic | Contract |
| --- | --- |
| API mechanism | Request field `type` (preferred; `searchType` deprecated) |
| Verified values | `web` (default), `image`, `video`, `news`, `discover`, `googleNews` |
| V1 frozen need | **`web` only** |
| Separate datasets | Only if later UI requires other types |

---

## 13. Demand Semantics

### Observed Search Demand (GSC)

Queries/impressions/clicks **observed in Search Console for this property** in the selected data window and extraction constraints.

### Market Demand (external)

DataForSEO (or similar) search volume / SERP intelligence — **out of scope for GSC provider collection**.

### Frozen Demand UI concepts

| Concept | Definition | Status |
| --- | --- | --- |
| Query explorer metrics | Provider SA query facts | Concrete |
| Topic clusters | Grouping of observed queries | **METHODOLOGY GAP** (Demo heuristic) |
| Momentum buckets | growing / declining / new / lost / ctr_review / striking_distance | **METHODOLOGY GAP** except striking-distance defaults in `GscStrikingDistanceConfig` (pos 5–20, min impr 20) |
| Demand Score | — | **FORBIDDEN** — no Demand Score / Keyword Opportunity Score |

### Trend methodology (when implemented)

| Item | Contract |
| --- | --- |
| Current / previous | Shared Date Range vs immediately preceding equal length |
| Base measures | Prefer **impressions** for “demand” trend; **clicks** for traffic trend; CTR as separate pp story |
| Labels | Explicit metric name — never unlabeled “demand score” |

---

## 14. Ownership Semantics

**Ownership is not a native GSC metric.**

| Concept | Definition | Source |
| --- | --- | --- |
| Observed Owner | Page with **highest impressions** for the query (or query cluster aggregate) in period among returned query×page rows | `MOXDOP_DERIVED` from `gsc_query_page_*` |
| Observed share | page_impressions / Σimpressions(query) across returned pages | Derived |
| Intended Owner | Website/Brand offering mapping (Demo: Brand Context · Website offering) | `MOXDOP_MAPPING` / `CROSS_ASSET` / `OPERATOR_MAINTAINED` |
| Fragmented ownership | Multiple material pages share impressions for same topic/query set | Derived classification |
| Mismatch | Intended ≠ Observed Owner | Derived |
| Cannibalization | Demo language: **“Cannibalization candidate — not proven cannibalization”** | Prefer **fragmented ownership**; **UI SEMANTIC REVIEW REQUIRED LATER** if stronger claims appear |

**DERIVATION METHODOLOGY REQUIRED BEFORE IMPLEMENTATION** for: cluster membership, “material share” threshold, fragmentation thresholds, new/lost definitions.

---

## 15. Pages Workspace Requirements

- Page clicks (SA)
- Content role / offering (`CROSS_ASSET` / operator Website)
- GA4 page context sessions/engagement/mapped actions (`CROSS_ASSET`) — **page-level only; not query→conversion**
- Website attention Findings (`OPERATIONS_DOMAIN` / Website)
- Trend on Overview page pulse: relative clicks change vs previous period (Demo)

---

## 16. Indexing Capability Matrix

| UI element | Expected meaning | Official API support | Source | Exhaustive? | Quota | Website inventory? | Honest production status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Coverage Indexed/Not indexed/Unknown/Excluded totals | Full site coverage like GSC web UI | **No complete Page Indexing report API** | — | No | — | Would still not equal GSC UI | **UNAVAILABLE / DEMO_ONLY** |
| Discoverability by role | Role inventory vs pages with impressions | Partial | Website + SA page | Inventory yes; “indexed” no | Low | YES | Produce without fake index totals |
| URL inspection table | Per-URL Google index state | URL Inspection | `indexStatusResult` fields | Only inspected URLs | **Per-site URL Inspection quotas (official limits doc)** | YES (choose URLs) | CONDITIONAL sampling/priority |
| Sitemaps table | Submitted sitemap health | Sitemaps API | list/get | Submitted feeds | Low | No | REQUIRED |
| Reconciliation cards | Website vs sitemap vs inspected | Derived | Cross-asset | Partial | — | YES | Derived; label partial |
| Discoverability funnel “Index observed” | Count indexed | Inspection sample or Demo | Mixed | No if sample | Quota | YES | Must not claim full coverage |

**Sitemap submitted URL count ≠ indexed URL count.** (`contents[].indexed` is **deprecated; do not use**.)

---

## 17. URL Inspection Requirements

| Topic | Contract |
| --- | --- |
| Endpoint | `POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect` |
| Scope | One `inspectionUrl` + `siteUrl` per call |
| Verified fields (indexStatusResult) | `verdict`, `coverageState`, `robotsTxtState`, `indexingState`, `lastCrawlTime`, `pageFetchState`, `googleCanonical`, `userCanonical`, `sitemap[]`, `referringUrls[]`, `crawledAs` |
| Live vs index | Docs: presently Google **index** status; not a substitute for unbounded live testing |
| Exhaustive crawl | **Inappropriate** under quotas — use Website inventory priority set + cache |
| No inspection record | **≠ not indexed** — state Unavailable |
| Force Index / Request Index | **Forbidden** (read-only) |

---

## 18. Sitemap Requirements

| Demo field | Official field | Notes |
| --- | --- | --- |
| path | `path` | YES |
| submitted | `lastSubmitted` | YES |
| last_downloaded | `lastDownloaded` | YES |
| discovered | `contents[].submitted` (sum by type) | YES — submitted URL counts |
| warnings / errors | `warnings` / `errors` | YES |
| status | derive from `isPending` / errors | Map carefully |
| contents[].indexed | **Deprecated** | Do **not** use as indexed count |

Child sitemap index: `isSitemapsIndex` + list with `sitemapIndex` param — support if property uses index files.

---

## 19. Website Cross-Asset Requirements

| Need | Class |
| --- | --- |
| GSC observes Website | `CROSS_ASSET` relationship |
| Property match | Domain (`sc-domain:`) vs URL-prefix coverage |
| URL inventory for inspection/reconciliation | Website Digital Asset |
| Content roles / offerings / intended pages | Website + Brand context |
| GA4 page context on Pages | Cross-asset (from GA4 contract facts) |
| GBP alignment notes | Cross-asset (local cluster) |

Do not implement binding inference or joins in this milestone.

### Normalized URL key (future requirement)

Shared Website URL identity layer must define: scheme, host, www, trailing slash, query string, fragment, case. GSC `page` values and Website inventory must map through that layer.

---

## 20. Operations-Domain Requirements

Ops UI is `OPERATIONS_DOMAIN`. Future Evidence dependencies (document only):

| Theme (Demo) | Evidence need |
| --- | --- |
| Visibility decline | query/cluster clicks & impressions trend |
| CTR opportunity | high impressions + low CTR |
| Ownership fragmented | query×page shares |
| Canonical mismatch | URL Inspection googleCanonical vs userCanonical |
| Sitemap gap | Sitemaps + Website inventory |

No metric→Finding automation in this prompt.

---

## 21. Date / Timezone Contract

| Topic | Contract |
| --- | --- |
| Search Analytics calendar | Official: dates in **PT (Pacific Time, America/Los_Angeles)** inclusive `YYYY-MM-DD` |
| Hard rule | Do **not** silently use Brand TZ or UTC as SA day boundary |
| Shared presets | last_7/14/28/30/90, this_month, last_month, custom (same bar as other assets) |
| Daily grain | REQUIRED for property/query/page/device/country where trends/recalc needed |
| Indexing | Snapshot / as-of timestamps — not forced through period bar |
| Freshness delay | Official guidance: data typically available after **~2–3 days**; discover latest available via date-grouped query |
| dataState | Default collection: **`final`** for stable facts; optional `all` for fresher incomplete (label incomplete via metadata) |

---

## 22. Previous-Period Comparison

Equal-length immediately preceding range in SA date domain.

| Metric | Comparison |
| --- | --- |
| Clicks | Relative % |
| Impressions | Relative % |
| CTR | **Percentage-point** delta (Demo) |
| Average position | **Absolute positional delta** = current − previous; **lower is better** → improvement when delta **negative**; display both raw delta and “improved/regressed” semantic — never treat like a “higher is better” KPI |

Edge cases: previous=0 → Unavailable % (never Infinity); missing → hide; incomplete fresh end → label; anonymization → do not invent zeros.

---

## 23. Missing / Zero / Unavailable Semantics

| Situation | Meaning |
| --- | --- |
| No query rows | ≠ zero demand |
| No URL Inspection | ≠ not indexed |
| No sitemap | ≠ zero indexed pages |
| No country breakdown row | ≠ zero traffic |
| No permission | ≠ zero metrics |
| No intended owner | ≠ ownership failure |
| Empty momentum bucket | Empty list OK |

---

## 24. Search Analytics Completeness Limitations

Must distinguish:

1. **API extraction completed successfully**
2. **Dataset represents all possible Google Search activity** ← often false

Causes: anonymized queries, top-row prioritization, ~50k rows/day/type ceiling, dropped data when grouping by page/query under load, pagination not finished, `final` vs `all`.

Provenance fields required on stored extractions: `type`, `dataState`, `aggregationType`, `row_limit`, `pages_fetched`, `truncated`, `extracted_at`.

**Top-N presentation ≠ collection limit.**

---

## 25. Provider Request Families

### GSC_RF_SITE_METADATA
- Endpoint: Sites list/get  
- Consumers: Shell, Relationships  
- Required: REQUIRED  
- Reason: property identity Domain vs URL-prefix  

### GSC_RF_PROPERTY_DAILY
- Endpoint: Search Analytics query  
- type: `web`  
- dimensions: `date`  
- metrics: clicks, impressions, ctr, position  
- dataState: `final` (primary)  
- grain: property×day  
- pagination: usually ≤ days in range  
- Required: REQUIRED  
- Volume: low  

### GSC_RF_PROPERTY_RANGE
- dimensions: none  
- dateRanges: current + previous  
- Required: REQUIRED for KPI totals (CTR/position consistency)

### GSC_RF_QUERY_DAILY
- dimensions: `date`,`query`  
- rowLimit 25000 + startRow pagination  
- Volume: **high**; respect 50k/day/type ceiling  
- Required: REQUIRED  
- Completeness: truncated possible  

### GSC_RF_PAGE_DAILY
- dimensions: `date`,`page`  
- Required: REQUIRED  
- Volume: high  

### GSC_RF_QUERY_PAGE_DAILY
- dimensions: `date`,`query`,`page`  
- Required: REQUIRED (ownership)  
- Volume: **very high** — may need day-sliced collection; still incomplete  
- Compatibility: standard SA dims  

### GSC_RF_DEVICE_DAILY
- dimensions: `date`,`device`  
- Required: REQUIRED  
- Volume: low  

### GSC_RF_COUNTRY_DAILY
- dimensions: `date`,`country`  
- Required: REQUIRED  
- Volume: medium  

### GSC_RF_SITEMAPS
- Endpoint: sitemaps.list (+ get)  
- Required: REQUIRED  
- Snapshot  

### GSC_RF_URL_INSPECTION
- Endpoint: urlInspection.index.inspect  
- Consumers: Indexing inspection/reconciliation  
- Required: CONDITIONAL (priority Website URLs)  
- Volume/quota: constrained — never full-site brute force  

**Search Appearance family:** not in V1.  
**Non-web types:** not in V1.

---

## 26. Pagination / Cardinality Risks

| Risk | Detail |
| --- | --- |
| rowLimit | Max **25,000** per request; default 1,000 |
| Pagination | `startRow` increments of 25,000 until 0 rows |
| Daily extraction ceiling | ~**50,000 rows per day per search type** (official how-to) |
| Query×page | Highest risk; day-slice; store truncation flags |
| Anonymized queries | Permanent incompleteness for query dims |
| Internal dropping | Official: grouping by page/query may drop data |

---

## 27. Candidate Normalized Datasets

| Dataset | Grain | Base facts | Consumers | Volume |
| --- | --- | --- | --- | --- |
| `gsc_property_metadata` | property | siteUrl, type, permission | Shell | Tiny |
| `gsc_property_daily` | property×date | clicks, impressions, position | Overview/Performance | Low |
| `gsc_query_daily` | query×date | clicks, impressions, position | Demand | High |
| `gsc_page_daily` | page×date | clicks, impressions, position | Pages/Overview | High |
| `gsc_query_page_daily` | query×page×date | clicks, impressions, position | Ownership | Very high |
| `gsc_device_daily` | device×date | clicks, impressions, position | Performance | Low |
| `gsc_country_daily` | country×date | clicks, impressions, position | Performance | Medium |
| `gsc_sitemaps_snapshot` | sitemap×as_of | path, submitted, downloaded, warnings, errors, submitted counts | Indexing | Tiny |
| `gsc_url_inspection_snapshot` | url×inspected_at | verdict, coverageState, canonicals, lastCrawlTime, … | Indexing | Medium (sampled) |
| `gsc_brand_term_map` | config | brand terms | Brand/nonbrand | Tiny |
| `gsc_intended_owner_map` | config | topic/query→intended path | Ownership | Tiny |

Persist **BASE FACTS** (counts, position). Derive CTR, shares, trends, ownership labels, momentum labels.

---

## 28. Derived Demand / Ownership Registry

| ID | UI label | Meaning | Inputs | Method | Threshold | Missing | Limitation | Provenance | Consumers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GSC_DER_CTR | CTR | clicks/impressions | counts | Σc/Σi | i=0 → Unavailable | — | — | Derived | Overview/Perf/Demand |
| GSC_DER_PCT_DELTA | Relative change | (c-p)/p | metrics | — | p=0 → Unavailable | — | — | Derived | KPIs |
| GSC_DER_PP_DELTA | CTR pp change | c_rate-p_rate | CTRs | — | null → Unavailable | — | — | Derived | CTR KPI |
| GSC_DER_POS_DELTA | Position delta | current-previous | position | Absolute; lower better | — | — | Not “rank change” | Derived | Perf |
| GSC_DER_IMPR_WEIGHTED_POS | Combined position | Σ(pos×impr)/Σimpr | rows | — | — | — | — | Derived | Aggregations |
| GSC_DER_OBSERVED_OWNER | Observed owner | Top page by impressions for query | query×page | argmax impr | no rows → Unavailable | Truncation | Derived | Ownership/explorer |
| GSC_DER_OWNER_SHARE | Observed share | page_impr/Σimpr | query×page | — | — | Truncation | Derived | Ownership |
| GSC_DER_FRAGMENTATION | Fragmented ownership | Multiple material pages | shares + threshold **TBD** | **METHODOLOGY GAP** | — | Not proven cannibalization | Derived | Demand/Ops |
| GSC_DER_CLUSTER | Topic cluster | Query grouping | queries | **METHODOLOGY GAP** | — | Demo heuristic | Derived | Demand |
| GSC_DER_MOMENTUM_* | Momentum buckets | Classification vs prior window | query daily | **METHODOLOGY GAP** | — | — | Derived | Overview/Demand |
| GSC_DER_STRIKING_DISTANCE | Striking distance | Position band opportunity | query facts | pos ∈ [5,20], impr≥20 (`GscStrikingDistanceConfig`) | — | Heuristic ≠ Google metric | Derived | Opportunities |
| GSC_DER_BRAND_SPLIT | Brand vs non-brand | Query classification | queries + brand map | contains brand terms | no map → Unavailable | Heuristic | Derived | Performance |

**No Demand Score.**

---

## 29. Historical Backfill Requirements

| Dataset | MINIMUM | RECOMMENDED | Notes |
| --- | --- | --- | --- |
| Search Analytics daily families | **180 days** (last_90 + previous 90) | 180 days | Shared Date Range driven |
| DECISION REQUIRED | >180 / up to provider retention | Options: 180 / 16 months if available / max available — **default 180** unless amended | Verify current retention at impl — **REQUIRES PROVIDER VERIFICATION** of exact max history months |
| Sitemaps | Current snapshot | Keep short history of snapshots | State |
| URL Inspection | Point-in-time cache for priority URLs | Recheck on cadence | Not historical SA |

---

## 30. Refresh / Freshness Requirements

| Dataset | Cadence | Freshness | Late-data recheck |
| --- | --- | --- | --- |
| Property/query/page/device/country daily | Daily | Expect 2–3 day lag | Recollect trailing **~7 complete final days** (settle window — refine with provider behavior) |
| Range KPIs | With daily or explicit range pull | — | — |
| Sitemaps | Daily or on-demand | Hours–day | Snapshot replace |
| URL Inspection | On-demand / weekly for priority set | Point-in-time | Cache TTL **DECISION REQUIRED** |
| Mapping configs | On save | Immediate | — |

---

## 31. DataForSEO Boundary

| GSC | DataForSEO |
| --- | --- |
| First-party observed property Search Analytics | External market / SERP / keyword intelligence |
| Impressions = observed exposure | Search volume ≈ estimated market demand |
| Average position = GSC position metric | SERP observation = external rank intelligence |
| Observed queries only | Keyword universe beyond GSC |

Future Opportunity candidates may combine GSC + Website + DataForSEO + Goals — **dependency only; not implemented here**.

---

## 32. Existing Implementation Reuse Matrix

| Component | Responsibility | Coverage | Disposition |
| --- | --- | --- | --- |
| Google OAuth / GoogleApiClient | Auth transport | Shared | KEEP |
| SearchConsoleDiscoverer | sites.list | Metadata partial | KEEP |
| SearchConsoleConnectionProbeService | Access probe | Access | KEEP / ADAPT LATER |
| SearchConsoleBoundCollector | SA summary, daily, top queries/pages, query×page≤100 | Partial | ADAPT LATER |
| ComparisonPeriod UTC | Windows | Wrong TZ for SA | ADAPT LATER → PT |
| Evidence `gsc_*` | Diagnosis payloads | Pattern | KEEP |
| GscStrikingDistance* | Heuristic opportunities | Partial derived | KEEP as derived impl seed |
| GscWorkspaceFixtures / SearchConsolePage | Demo UI | Specimens | KEEP (no redesign) |

---

## 33. Current Collector Gap Analysis

| Current | vs Contract V1 |
| --- | --- |
| Property summary current/previous | REQUIRED |
| Property daily | REQUIRED |
| Top queries (25) | PARTIAL — top-N insufficient as sole store |
| Top pages (25) | PARTIAL |
| Query×page (100) | PARTIAL — needed but limits too small for ownership completeness claims |
| `dataState=final` | REQUIRED (good) |
| Device / country | **MISSING** |
| Sitemaps / URL Inspection | **MISSING** |
| Daily query/page full pagination | **MISSING** |
| Brand map / ownership maps | **MISSING** |
| PT date domain | **SEMANTICALLY WRONG** if using UTC ComparisonPeriod |
| row_limit_note acknowledging incompleteness | USEFUL / keep and expand |
| CTR stored from provider | USEFUL; prefer also store counts for reagg |
| Position % delta in collector deltas | **SEMANTICALLY RISKY** for position (prefer absolute + direction) |

---

## 34. Unsupported / Demo-Only Concepts

| Concept | Status |
| --- | --- |
| Full Indexed/Not indexed/Excluded site totals | **UNAVAILABLE FROM CURRENT GSC API** / DEMO_ONLY |
| Exhaustive URL Inspection of all Website URLs | Inappropriate / unsupported as default |
| Topic clusters as deterministic production | DEMO_ONLY until methodology locked |
| Momentum counts without formula | DEMO_ONLY / METHODOLOGY GAP |
| Proven cannibalization | Unsupported; candidate language only |
| Search Appearance / non-web types | Not required V1 |
| Query→conversion attribution | Forbidden from GSC alone |
| Demand Score | Forbidden |

---

## 35. Decisions Required Before Collection

1. Lock **momentum / cluster / fragmentation thresholds** (methodology) or ship UI with Unavailable until locked.
2. Backfill **>180 days**? Default **no**.
3. Exact **max SA history months** verification at impl time.
4. URL Inspection **priority selection + cache TTL**.
5. Whether production Coverage cards remain as **Unavailable** honest empty vs Website+sample derived substitute (no fake GSC UI totals).
6. Adapt date helper from UTC to **PT** before production SA collection.
7. Query×page collection strategy under 50k/day ceiling (which days/dims first).

---

## 36. Definition of Done

| Check | Answer |
| --- | --- |
| Every frozen GSC component traceable? | **YES** |
| Provider facts separated from derived? | **YES** |
| Demand semantics explicit? | **YES** |
| Ownership semantics explicit? | **YES** (with methodology gaps flagged) |
| Average Position ≠ exact rank? | **YES** |
| GSC demand ≠ total market demand? | **YES** |
| Indexing capability limits explicit? | **YES** |
| URL Inspection limits explicit? | **YES** |
| Sitemap semantics explicit? | **YES** |
| Missing ≠ zero? | **YES** |
| Completeness limitations explicit? | **YES** |
| Request families explicit? | **YES** |
| Dataset candidates explicit? | **YES** |
| Future collector can implement without inventing requirements? | **YES** |

### Minimization pass

Removed: search appearance, non-web types, full-site inspection, monetary/rank-tracker concepts, inventing coverage API.

### Consolidation pass

Property daily serves Overview+Performance trends; separate dimensional families preserve SA semantics; CTR/ownership/momentum derived from base facts.

---

## Appendix — Privacy

**USER-LEVEL DATA REQUIRED: NO**  
**PII REQUIRED: NO**  
Respect provider anonymized-query behavior; do not attempt to recover suppressed queries.
