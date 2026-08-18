# WEBSITE DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR COLLECTION IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Cumulative docs base | `cursor/data-contract-meta-ads-ea01` @ `7f774c96f15b6a22af03d0fa30459bd1e786c496` (includes GA4 + GSC + Google Ads + Meta Ads contracts; not yet on `main`) |
| Audit branch | `cursor/data-contract-website-ea01` |
| Runtime product code changed | **NONE** |

Future semantic changes require **v2** or an explicit amendment.

Related contracts (do **not** redefine):

- `docs/data-contracts/GA4_DATA_CONTRACT_V1.md`
- `docs/data-contracts/SEARCH_CONSOLE_DATA_CONTRACT_V1.md`
- DataForSEO exact provider semantics → **Prompt 6** (dependency only here)

Official / primary references for external semantics:

- WordPress REST API (Site Connector / probe)
- [PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started)
- Lighthouse / CrUX field vs lab distinctions
- Search Console & GA4 contracts above
- Existing in-repo diagnosis parsers (`WebsiteDiagnosisService`, `PublicSiteCrawler`, sitemap/robots/canonical parsers)

Hard semantic boundaries:

1. **WEBSITE DIGITAL ASSET ≠ WORDPRESS INSTALLATION**
2. **URL DISCOVERY ≠ CANONICAL URL IDENTITY**
3. **SITEMAP URL ≠ INDEXED URL**
4. **HTTP 200 ≠ HEALTHY PAGE**
5. **TECHNICAL OBSERVATION ≠ FINDING**
6. **LAB PERFORMANCE ≠ FIELD PERFORMANCE**
7. **GSC AVERAGE POSITION ≠ EXACT RANK**
8. **DATAFORSEO ESTIMATE ≠ FIRST-PARTY MEASUREMENT**
9. **GA4 SESSION DATA ≠ WEBSITE CRAWL DATA**
10. **DOMAIN / HOSTING ARE WEBSITE INFRASTRUCTURE — NOT STANDALONE DIGITAL ASSETS**
11. **NO ARBITRARY WEBSITE HEALTH SCORE**

---

## 1. Purpose

Define **exactly** what the frozen Website operator workspace requires from composed sources **before** any production crawler expansion, WordPress Site Connector productionization, PageSpeed warehouse, DataForSEO enrichment expansion, analytical tables, Evidence pipeline, or UI migration.

```text
Website Digital Asset
  → Canonical URL identity
  → URL inventory (multi-source)
  → Direct public observations
  + CMS / Site Connector truth
  + GA4 measurement
  + GSC search observation
  + DataForSEO external intelligence
  + PageSpeed / technical measurement
  + Infrastructure observations
  → MoxDOP derived conditions
  → Future Evidence
```

The future Website collector/connector **must not invent** data requirements.

**Hard boundary of this milestone:** audit + documentation only.

---

## 2. Frozen UI Scope

### Verified primary IA

Source: `App\Livewire\Demo\Website\OverviewPage::$allowedTabs`, views under `resources/views/livewire/demo/website/`, `WebsiteWorkspaceFixtures`, `ConnectorWorkspaceFixtures::websiteInfrastructure`, product docs under `docs/product/website/`.

| Tab key | Operator label | Present |
| --- | --- | --- |
| `overview` | Overview | YES |
| `health` | Health | YES |
| `visibility` | Visibility | YES (lenses: organic · local · AI) |
| `content` | Content | YES |
| `performance` | Performance | YES (search · acquisition · landing · conversions · outcome + vitals) |
| `infrastructure` | Infrastructure | YES (Domain/DNS/Hosting/CDN/SSL/CMS — **inside Website**) |
| `operations` | Operations | YES |
| `setup` | Setup | YES (connection · configuration) |

Legacy remaps: `technical`→health; `search`→visibility; `pages`/`conversions`→performance; `domain`/`hosting`→infrastructure; `connections`/`settings`/`lifecycle`→setup; `activity`→operations.

Period bar applies to overview / visibility / content / performance / operations — **not** to Health or Infrastructure (snapshot semantics) or Setup.

### Supporting artifacts audited

- Fixtures: `WebsiteWorkspaceFixtures`, `DemoCatalog::websiteOverview` / `websitePerformance`, `ConnectorWorkspaceFixtures`
- Demo Site Connector: `SiteConnectorFixtures`, `SiteConnectorShow`
- Production: `WebsiteDiagnosisService`, `PublicDiscoveryService` / `PublicSiteCrawler`, WordPress probe, PageSpeed probe, GSC/GA4 bound collectors, DataForSEO SEO Intelligence collectors, Filament `Website*` relation managers

### Explicit non-goals

- No Website Health Score (0–100)
- No WordPress / hosting / DNS / robots writes
- No Domain or Hosting as new standalone Digital Assets
- No full Events Manager / visitor PII / form submission content
- No claiming complete Google Page Indexing report parity
- No paid DataForSEO calls on page render

---

## 3. Website Digital Asset Definition

| Attribute | Required | Notes |
| --- | --- | --- |
| Digital Asset ID | YES | MoxDOP identity |
| Brand relationship | YES | Brand → Website |
| `domain` | YES | Registrable / primary hostname context |
| `primary_url` | YES | Canonical base URL (scheme + host [+ path prefix]) |
| `cms` | Optional | WordPress or other; may be unknown |
| `languages` | Optional | Operator / observed |
| `site_type` | Optional | Corporate / lead_gen / … |
| `hosting_context` | Optional | OPERATOR_MAINTAINED summary |
| Connection state(s) | YES | Per capability — not one boolean |
| Operational status | YES | Asset status |

**Website ≠ WordPress.** A Website may exist with only public HTTP + GSC + GA4 and no Site Connector.

---

## 4. Source Classification

| Class | Meaning |
| --- | --- |
| `WEBSITE_DIRECT_OBSERVATION` | Public HTTP/HTML/robots/sitemap/TLS fetch |
| `WORDPRESS_SITE_CONNECTOR` | Authenticated WP REST / connector |
| `CMS_METADATA` | CMS object fields (post type, dates, taxonomies) |
| `GA4` | Per GA4 Data Contract V1 |
| `SEARCH_CONSOLE` | Per Search Console Data Contract V1 |
| `DATAFORSEO` | External SEO intelligence (Prompt 6 owns exact API) |
| `PAGESPEED_TECHNICAL` | PSI / Lighthouse lab (and CrUX field when used) |
| `DOMAIN_DNS_TLS` | DNS / certificate / domain lifecycle observations |
| `MOXDOP_DERIVED` | Counts, deltas, orphan heuristics, striking distance |
| `MOXDOP_MAPPING` | Page role, Business Action mapping |
| `MOXDOP_CLASSIFICATION` | Needs attention states, AI readiness conditions |
| `CROSS_ASSET` | GBP / Ads / Instagram consistency |
| `OPERATOR_MAINTAINED` | Hosting provider, renewal, Brand Context |
| `OPERATIONS_DOMAIN` | Findings / Recommendations / Tasks / Outcomes |
| `UNAVAILABLE` | Cannot be honestly obtained |
| `DEMO_ONLY` | Fixture UX only (e.g. AI mention samples, availability timeline) |

---

## 5. UI Requirement Matrix

**Req** = Required / Optional / Conditional / Demo-only.  
Scope: **W** = Website-level · **U** = URL-level.

| Requirement ID | Workspace | UI | Operator question | Semantic | Demo source | Source class | Source | Scope | Fields | Identity | Freshness | Formula | Mapping | Cross-asset | Req | Provenance | Missing | Conflict | Dataset | Coverage | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| WEB_ASSET_IDENTITY | Header | Identity | Which Website? | Asset + primary_url + domain | identity | OPERATOR_MAINTAINED + WEBSITE_DIRECT | DigitalAsset | W | id, domain, primary_url, cms, languages | asset_id | snapshot | — | — | Brand | Required | Asset | block | — | website_asset | KEEP | |
| WEB_SOURCE_FRESHNESS | Header | Freshness chips | Per-source age? | Separate freshness per source | source_freshness | OPERATIONS_DOMAIN | Runs | W | source, observed_at | — | per source | — | — | — | Required | Run | Unknown | — | — | partial | **No single global freshness** |
| WEB_OVERVIEW_FINDINGS | Overview | Glance findings | Open issues? | Ops counts | glance | OPERATIONS_DOMAIN | Findings | W | open/high counts | — | live | — | — | — | Required | Ops | 0 open OK | — | — | Demo+prod Findings | Not a Health Score |
| WEB_OVERVIEW_SEARCH_VIS | Overview | Search visibility KPI | Organic queries? | GSC observed queries | glance.search_visibility | SEARCH_CONSOLE | GSC contract | W | query count / window | — | GSC lag | — | — | GSC | Required | GSC | Unavailable | ≠ DataForSEO | gsc datasets | GSC collector | Measured |
| WEB_OVERVIEW_INVENTORY | Overview | Site inventory KPI | Known URLs? | Multi-source inventory size | glance.site_inventory | WORDPRESS + WEBSITE_DIRECT | WP + sitemap | W | url count + provenance | — | per source | count | — | — | Required | Multi | Partial coverage label | — | website_url | partial | Partial ≠ complete |
| WEB_OVERVIEW_ATTENTION | Overview | Needs attention | What needs work? | Finding-backed cards | needs_attention | OPERATIONS_DOMAIN | Findings | W | — | — | — | — | — | GBP/Ads | Conditional | Ops | hide empty | — | — | Demo | |
| WEB_HEALTH_SUMMARY | Health | Summary line | Checks/findings? | Check counts — **no score** | health.summary | MOXDOP_DERIVED + OPS | diagnosis runs | W | evaluated/open/high/unavailable | — | diagnosis | counts | — | — | Required | Diagnosis | Unavailable checks explicit | — | — | diagnosis present | Explicit “No Website Health score” |
| WEB_HEALTH_GROUPS | Health | Group filters | Issue families? | Taxonomy of Findings | groups | OPERATIONS_DOMAIN | catalog | W | group keys | — | — | — | — | — | Required | Catalog | — | — | — | Demo+catalog | observation≠Finding |
| WEB_HEALTH_CANONICAL | Health | Canonical findings | Missing canonical? | HTML rel=canonical absence | wf-canonical-* | WEBSITE_DIRECT_OBSERVATION | Diagnosis HTML | U | canonical href or null | normalized URL | diagnosis | — | — | GSC index later | Required | HTTP HTML | missing≠unhealthy alone | CMS SEO field may differ | website_metadata_snapshot | Diagnosis KEEP | |
| WEB_HEALTH_HTTP_STATUS | Health | Crawl findings | Broken links/pages? | HTTP status of targets | wf-broken-links | WEBSITE_DIRECT | HTTP fetch | U | status, final_url | URL | diagnosis | — | — | — | Required | HTTP | 404 observation | — | website_http_snapshot | Diagnosis KEEP | 200≠healthy |
| WEB_HEALTH_SCHEMA | Health | Structured data | Schema missing? | Parseable JSON-LD types | wf-schema | WEBSITE_DIRECT | HTML | U | types present | URL | diagnosis | — | — | — | Required | HTML | none≠invalid rich result | — | website_schema_snapshot | Diagnosis partial | Validity ≠ rich-result eligibility |
| WEB_HEALTH_LCP | Health | Performance finding | Lab LCP poor? | Lab LCP on URL | wf-lcp-implant | PAGESPEED_TECHNICAL | PSI/Lighthouse | U | LCP lab | URL | PSI | — | — | Ads landing | Required | Lab | Unavail if not measured | ≠ field LCP | website_performance_measurement | Probe exists; UI Demo | Lab≠Field |
| WEB_HEALTH_SECURITY_HEADER | Health | Security | CSP missing? | Response header presence | wf-security-headers | WEBSITE_DIRECT | headers | W/U | header name/value | — | diagnosis | — | — | — | Optional | HTTP | absent = observation | — | http snapshot | Diagnosis | Hygiene ≠ vuln |
| WEB_HEALTH_WP_UPDATES | Health | WordPress | Plugin updates? | Connector update flags | wf-wp-updates | WORDPRESS_SITE_CONNECTOR | WP | W | plugin update flags | — | connector | — | — | — | Conditional | WP | update≠CVE | — | website_cms_snapshot | Probe weak | Read-only |
| WEB_HEALTH_AVAILABILITY | Health | Availability | Uptime incidents? | Monitoring timeline | availability | DEMO_ONLY / UNAVAILABLE | — | W | — | — | — | — | — | — | Demo-only | Demo | not configured | — | — | Demo | Prod: not configured |
| WEB_VIS_GSC_KPIS | Visibility · organic | KPIs | Clicks/impr/CTR/pos? | GSC Search Analytics | organic.kpis | SEARCH_CONSOLE | GSC V1 | W | clicks, impressions, ctr, position | property | GSC | GSC formulas | — | GSC | Required | GSC | — | ≠ DFS | gsc_* | KEEP | Reuse GSC contract |
| WEB_VIS_GSC_GROUPS | Visibility · organic | Query groups | Growing/declining…? | Derived from GSC query facts | organic.groups | MOXDOP_DERIVED + SEARCH_CONSOLE | GSC | U/query | deltas, striking distance | query+page | period | heuristics | — | GSC | Required | GSC+MoxDOP | — | — | derived | partial striking-distance | No Visibility Score |
| WEB_VIS_DFS | Visibility · organic | DataForSEO panel | Ext. keyword intel? | Estimated ranked/opps | organic.dataforseo | DATAFORSEO | DFS Labs | W/domain | ranked_keywords, keywords_for_site, opportunities | domain | DFS TTL | — | — | Prompt 6 | Required | DFS estimated | stale OK | ≠ GSC | dfs evidence | collectors KEEP | Never on page render |
| WEB_VIS_LOCAL | Visibility · local | Local lens | Local readiness? | Website+GBP signals | local | WEBSITE_DIRECT + CROSS_ASSET + SEARCH_CONSOLE | HTML+GBP+GSC | W | NAP, schema, local queries | — | multi | — | — | GBP | Conditional | Multi | Partial | — | — | Demo rich | Not ranking guarantee |
| WEB_VIS_AI | Visibility · AI | AI readiness | Site AI conditions? | Classification conditions | ai.readiness | MOXDOP_CLASSIFICATION | Website obs | W | condition states | — | — | — | — | GA4 referrals | Conditional | MoxDOP | Unmeasured mentions = Demo | — | — | Demo | No AI Readiness Score; Demo rows DEMO_ONLY |
| WEB_CONTENT_INVENTORY | Content | CPT counts | CMS inventory? | WP object counts | inventory | WORDPRESS_SITE_CONNECTOR | WP REST | W | post_type counts, media | WP IDs | connector | — | — | sitemap count | Required | WP | partial REST | ≠ public crawl | website_cms_snapshot | **MISSING prod inventory** | |
| WEB_CONTENT_DIRECTORY | Content | Page table | What pages? | Page/URL directory | directory | WORDPRESS + WEBSITE_DIRECT + GA4 + GSC | multi | U | title, url, role, h1, word_count, schema, traffic, organic | URL + WP object id | multi | — | role map | GA4/GSC | Required | Multi | Not observed | CMS vs rendered | website_url + content snapshot | Demo | |
| WEB_CONTENT_TITLE | Content/Health | Title | HTML/CMS title? | Title string | directory.title | WEBSITE_DIRECT / CMS | head / WP | U | title | URL | obs | — | — | — | Required | Dual provenance | null | may disagree | metadata snapshot | Diagnosis head | Observation≠Finding |
| WEB_CONTENT_META | Content/Health | Meta description | Present? | meta description | head | WEBSITE_DIRECT / CMS | HTML/WP SEO | U | description | URL | obs | — | — | — | Required | Dual | null | plugin vs rendered | metadata | Diagnosis | |
| WEB_CONTENT_H1 | Content | H1 | Primary heading? | H1 text | directory.h1 | WEBSITE_DIRECT | HTML | U | h1 | URL | obs | — | — | — | Required | HTML | missing | — | heading snapshot | Diagnosis | Full H2+ optional |
| WEB_CONTENT_BODY | Content | Word count / refresh | Content body stats? | word_count, updated | directory | WORDPRESS / DIRECT | content | U | word_count, modified | WP id / URL | connector | — | — | — | Required | Prefer CMS dates | — | — | content snapshot | Demo | Raw HTML not required for freeze |
| WEB_CONTENT_ROLE | Content | Role | Page role? | MoxDOP role taxonomy | role | MOXDOP_MAPPING | operator/rules | U | role | URL | — | — | Offering | Brand | Required | MoxDOP | Unknown | — | mapping | Demo | |
| WEB_PERF_FIELD_CWV | Performance | Field vitals | Field LCP/INP/CLS? | CrUX-style field | vitals.field | PAGESPEED_TECHNICAL | CrUX via PSI | W/U | LCP,INP,CLS,TTFB | origin/URL | CrUX lag | — | — | — | Required | FIELD | Unavail | ≠ lab | performance measurement | Demo; probe partial | |
| WEB_PERF_LAB | Performance | Lab vitals | Lab LCP…? | Lighthouse lab | vitals.lab | PAGESPEED_TECHNICAL | PSI | U | LCP,INP,CLS,SI | URL | on collect | — | — | — | Required | LAB | Unavail | ≠ field | performance measurement | Probe KEEP | Priority pages only |
| WEB_PERF_ACQUISITION | Performance | Acquisition | Sessions/sources? | GA4 | acquisition | GA4 | GA4 V1 | W | sessions, users, sources | property | GA4 | — | — | GA4 | Required | GA4 | — | — | ga4_* | KEEP | Reuse GA4 |
| WEB_PERF_LANDING | Performance | Landing table | LP engagement? | GA4 + GSC + Website role | landing_pages | GA4 + SEARCH_CONSOLE + WEBSITE | multi | U | sessions, clicks, events | normalized URL | period | — | role | Ads | Required | Multi | — | join key | multi | partial | |
| WEB_PERF_CONVERSIONS | Performance | Mapping | BA mapped? | GA4 event ↔ BA | conversion_mapping | MOXDOP_MAPPING + GA4 | Settings | W | mapped flags | — | — | — | BA | GA4 | Required | Mapping | unmapped debt | — | mapping | Demo+GA4 | |
| WEB_INFRA_DOMAIN | Infrastructure | Domain | Domain lifecycle? | Domain identity + expiry | domain | DOMAIN_DNS_TLS / OPERATOR | WHOIS/DNS/manual | W | domain, expires, registrar | domain | slow | — | — | — | Required | Provenance required | Unknown | inferred≠authoritative | infrastructure snapshot | Demo; diagnosis SSL stronger | Not standalone asset |
| WEB_INFRA_DNS | Infrastructure | DNS | Nameservers/records? | NS + selected records | dns | DOMAIN_DNS_TLS | DNS lookup | W | NS, A/AAAA, MX, TXT as shown | domain | slow | — | — | — | Required | DNS | — | CDN hides origin | dns snapshot | Demo | Minimal set |
| WEB_INFRA_TLS | Infrastructure | SSL | Cert valid? | Certificate facts | ssl | DOMAIN_DNS_TLS | TLS probe | W | issuer, expiry, SAN, https | hostname | diagnosis | days_remaining derived | — | — | Required | TLS | Unavail | grade may be DEMO | tls snapshot | Diagnosis KEEP | “SSL Healthy” = classification |
| WEB_INFRA_HOSTING | Infrastructure | Hosting | Provider/renewal? | Operator or inferred | hosting | OPERATOR_MAINTAINED (+ weak infer) | manual | W | provider, plan, renewal | — | operator | — | — | — | Required | Manual preferred | CDN uncertainty | IP→provider weak | infra snapshot | Demo Manual | |
| WEB_INFRA_CDN | Infrastructure | CDN | CDN present? | Detected/unavailable | cdn | WEBSITE_DIRECT / UNAVAILABLE | headers | W | provider or Unavailable | — | obs | — | — | — | Conditional | Detection | missing≠none always | — | infra | Demo Unavailable | |
| WEB_INFRA_CMS | Infrastructure | CMS | Which CMS? | Detected/connector | cms | WORDPRESS / DIRECT | WP + headers | W | name, version | — | multi | — | — | — | Required | Dual | unknown | — | cms snapshot | probe+Demo | |
| WEB_SETUP_CONNECTOR | Setup | WordPress | Connector state? | Connection + capabilities | connections | WORDPRESS_SITE_CONNECTOR | binding | W | state, last, provides | site URL | connector ≠ data freshness | — | — | — | Required | Connection | disconnected may have history | — | — | Demo+probe | |
| WEB_SETUP_GSC_GA4 | Setup | Related assets | GSC/GA4 linked? | Related Digital Assets | connections | CROSS_ASSET | bindings | W | asset refs | — | — | — | — | GSC/GA4 | Required | Binding | — | — | — | KEEP | Not Website connections only |
| WEB_OPS_* | Operations | Pipeline | Work? | Ops domain | activity/ops | OPERATIONS_DOMAIN | — | W | — | — | — | — | — | — | Conditional | Ops | — | — | — | Demo | observation≠Finding |

**Totals audited: 42 requirement IDs** (plus Ops Conditional).  
**Required: 34 · Optional: 1 · Conditional: 5 · Demo-only: 1**

---

## 6. Canonical URL Identity Requirements

Future join key across Website / GA4 / GSC / Ads / Meta / DataForSEO:

| Concept | Definition |
| --- | --- |
| Observed URL | Exact URL as seen by a source |
| Normalized URL | Scheme/host/path policy applied (tracking params stripped; www policy; trailing slash policy; fragment dropped) |
| Declared canonical | `rel=canonical` href from HTML (or CMS SEO field — separate provenance) |
| Redirect final URL | Final URL after redirect chain |
| CMS permalink | WordPress `link` / permalink |

**NORMALIZED URL ≠ DECLARED CANONICAL URL.**  
**Redirect destination ≠ canonical.**  
Preserve all; derived “MoxDOP URL identity” is a later resolution layer with uncertainty.

Normalization policy requirements (implement later): scheme lowercase; host lowercase; default ports omitted; strip common tracking params (`utm_*`, `gclid`, `fbclid`, …); fragment ignored; trailing-slash & www policy **DECISION REQUIRED** per Brand/Website; encoding normalized.

---

## 7. URL Inventory

### URL inventory matrix

| Source | Discover URLs? | Stable CMS ID? | Public URL? | Canonical? | Indexability? | Indexing? | Traffic? | Search vis? | Completeness | Freshness | Authority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| WordPress | YES | YES (post ID) | permalink | SEO plugin maybe | no | no | no | no | REST-visible only | connector | AUTHORITATIVE for CMS objects |
| Sitemap | YES | no | listed URL | no | weak hint | no | no | no | published set | fetch | DISCOVERY |
| Direct crawl | YES | no | YES | observed | via robots/meta | no | no | no | crawl budget limited | diagnosis | DISCOVERY + OBSERVATIONAL |
| GSC pages | YES | no | observed | Inspection | Inspection | Inspection | clicks/impr | YES | sampled/incomplete | GSC | OBSERVATIONAL |
| GA4 landings | YES | no | landing path | no | no | no | sessions | no | thresholded | GA4 | OBSERVATIONAL |
| DataForSEO | YES | no | ranked URL | no | no | no | no | estimated | external | DFS | ENRICHMENT |
| Operator seed | YES | maybe | seed | no | no | no | no | no | intentional | manual | AUTHORITATIVE seed |

**Canonical inventory policy:** Union of sources with **per-source provenance**; never one boolean `exists` without semantics. “184 known URLs · partial coverage” is the honest default.

---

## 8. Direct Website Observation Requirements

Public fetch observations (diagnosis/crawl):

- requested URL, final URL, status, redirect chain, content-type, selected headers, HTML availability, observed_at, run_id  
- Not equivalent to full browser rendering (JS-heavy sites may differ)

---

## 9. HTTP / Redirect Requirements

| Need | Contract |
| --- | --- |
| Status codes | Store exact code; classify 2xx/3xx/4xx/5xx as derived labels |
| Redirects | source, status, destination, chain length, final, loop flag |
| Response time | Only if UI uses — currently optional (not Overview KPI) |

HTTP 200 is **not** “Healthy page.”

---

## 10. Title / Meta Requirements

| Field | Required | Notes |
| --- | --- | --- |
| HTML title | YES | Observation |
| Meta description | YES | Observation |
| Length | Optional | Only if Finding methodology needs it |
| Duplicate title/meta | Derived later | Deterministic compare possible |
| Viewport / OG / Twitter | Optional | Include only if Health/Content shows them — freeze focuses canonical/robots/schema more than full OG suite |

No SEO score.

---

## 11. Heading Requirements

| Need | Contract |
| --- | --- |
| H1 text | Required (Content directory) |
| H2+ full sequence | Optional — not required for freeze tables |
| Counts / multiple H1 | Observation; Finding later |

---

## 12. Content Requirements

| Need | Contract |
| --- | --- |
| Word count | Required for Content directory |
| Publish/modified dates | Prefer CMS |
| Language | Required when known |
| Taxonomy | Conditional (WP) |
| Excerpt / author | Optional |
| **Raw HTML retained?** | **NOT required for Contract V1** — store extracted fields + optional compressed artifact later if replay needed |
| Draft/private posts | **Default exclude** unless product explicitly needs drafts (Demo shows one draft opportunity — treat as Conditional demo) |

---

## 13. Internal Link Requirements

| Need | Contract |
| --- | --- |
| Base edge | source_url, destination_url, optional anchor |
| Nofollow etc. | Optional |
| Orphan / depth | **MOXDOP_DERIVED** from edges + inventory |
| External links | Not required as full inventory for freeze |

Volume risk: high — prefer aggregated counts + broken-target list over storing every DOM occurrence.

---

## 14. Image / Media Requirements

| Need | Contract |
| --- | --- |
| Page image src + alt | Required when Health image checks exist (currently Findings mention hero size in LCP evidence — Conditional) |
| Dimensions / format / broken | Conditional for performance Findings |
| WP Media Library IDs | Conditional when connector present |

---

## 15. Structured Data / Schema Requirements

| Need | Contract |
| --- | --- |
| Formats | Prefer JSON-LD (freeze LocalBusiness/FAQ) |
| Raw payload | Optional retain for debug |
| Normalized types | Required (type list per URL) |
| Parse validity | Syntactic parse ≠ rich-result eligibility ≠ semantic correctness |

---

## 16. Canonical Requirements

| Observation | Declared `rel=canonical` href (or null) |
| Judgment | Missing / cross-domain / non-self — derived |
| CMS SEO canonical field | Separate provenance from rendered HTML |

---

## 17. Robots Requirements

| robots meta | index/noindex, follow/nofollow as observed |
| robots.txt | availability, body, sitemap declarations, relevant UA rules |
| Indexability | Website directives only — **≠ Google indexed** |

---

## 18. Sitemap Requirements

| Website sitemap | Discovered sitemap URL(s), fetch status, URL entry count, lastmod if present |
| GSC sitemap | Per GSC contract — submitted/known in Search Console |
| Distinction | Published ≠ submitted ≠ indexed |

---

## 19. CMS / WordPress Requirements

| Field | Required when WP connected |
| --- | --- |
| Site URL / home | YES |
| WP version, theme | YES (Health wordpress panel) |
| Plugin count / update flags | YES |
| REST reachable | YES |
| CPT list + counts | YES (Content inventory) |
| Post/page identity | id, type, status, slug, permalink, title, modified | YES |
| Taxonomies | Conditional |
| SEO plugin fields | Conditional — only if needed; do not hardcode all plugins |

Non-WordPress sites: CMS may be detected/unknown; connector N/A.

---

## 20. Site Connector Requirements

### Role

WordPress Site Connector = **authenticated access path / capability**, not the Website Asset.

### Capability categories (conceptual)

| Capability ID | Frozen consumer | Required data | WP source | Auth | Stable IDs | Public fallback | Req | Coverage |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CONTENT_READ | Content directory | posts/pages/CPTs | REST | YES | YES | crawl titles incomplete | Required | **MISSING** beyond probe |
| URL_INVENTORY_READ | Inventory KPI | permalinks | REST | YES | YES | sitemap/crawl | Required | MISSING |
| MEDIA_READ | Media count | media library | REST | YES | YES | HTML images | Conditional | MISSING |
| TAXONOMY_READ | Coverage | terms | REST | YES | YES | — | Conditional | MISSING |
| SEO_METADATA_READ | Canonical/title CMS | plugin fields | plugin REST | YES | — | HTML | Conditional | MISSING |
| TECHNICAL_METADATA_READ | Health WP | version/theme/plugins | REST | YES | — | headers | Required | **PARTIAL** (index probe only) |

### Connection states (conceptual)

`not_configured` · `configured` · `connected` · `auth_failed` · `capability_limited` · `stale` · `disconnected`

**Connection ≠ data freshness.**

---

## 21. Health Workspace Requirements

### Health matrix

| UI concept | Base observation(s) | Derived classification | Finding? | Source(s) | Threshold | Cross-asset | Missing |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Checks evaluated / open / high / unavailable | Run results | counts | No (summary) | Diagnosis | — | — | Unavailable explicit |
| Missing canonical | canonical null | template pattern | YES (contextual) | HTTP HTML | scope count | GSC | null observation |
| Broken internal links | HTTP 404 on internal href | broken destination | YES | Crawl+HTTP | count | — | |
| Lab LCP poor | lab LCP value | poor/needs_improvement | YES w/ traffic context | PSI | e.g. >2.5s lab | Ads/GSC | Unavail if not measured |
| Schema missing | no LocalBusiness/FAQ types | opportunity | YES low | HTML | — | Local | |
| CSP absent | header missing | hygiene | YES low | Headers | — | Hosting | |
| WP updates | update flags | review needed | YES | Connector | — | — | |
| Phone mismatch | NAP strings | inconsistency | YES | Website+GBP | — | GBP | |
| Availability timeline | — | — | — | DEMO_ONLY | — | — | Not configured |

**Numeric Health Score: NONE.**

---

## 22. Visibility Workspace Requirements

### Visibility matrix

| UI concept | GSC | DataForSEO | Website | GA4 | Kind | Date | Distinctions |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Organic KPIs | YES | no | no | no | measured | Shared Range | GSC position ≠ exact rank |
| Query groups / striking distance | YES | no | no | no | derived | Shared | Heuristic |
| DFS ranked/opps | no | YES | domain | no | estimated | DFS refresh | ≠ GSC impressions/volume |
| Local matrix | local queries | no | NAP/schema | no | mixed | snapshot | Not ranking guarantee |
| AI readiness | crawl/entity | no | YES | referrals optional | classification | — | No AI score; mentions DEMO_ONLY |
| Competitors | — | optional later | Discovery | — | bounded | — | No “competitor better” without Evidence |

---

## 23. Content Workspace Requirements

Every Content column maps to: CMS and/or HTML observation + optional GSC organic + GA4 traffic + role mapping + Findings chips.  
“Thin” / “Needs refresh” = **MOXDOP_CLASSIFICATION** (methodology required before automation).  
No Content Quality Score.

---

## 24. Performance Workspace Requirements

### Performance matrix

| Metric | Source | Class | Scope | Unit | Aggregation | Freshness | Coverage | History | Limits | UI |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Field LCP/INP/CLS/TTFB | CrUX via PSI | FIELD | origin/URL | s/ms | provider | CrUX lag | origin + priority URLs | period | sampling | vitals.field |
| Lab LCP/INP/CLS/SI | Lighthouse/PSI | LAB | URL | s/ms | per test | on collect | **priority pages** | snapshots | quota/cost | vitals.lab |
| Lighthouse category score | PSI | LAB provider score | URL | 0–100 | provider | on collect | priority | optional | ≠ Health Score | Optional if shown |
| Sessions/sources | GA4 | ANALYTICS | property/page | count | sum | GA4 | GA4 thresholds | daily | GA4 contract | acquisition |
| Landing engagement/events | GA4 | ANALYTICS | URL | — | sum | GA4 | join URL | daily | — | landing |
| Organic clicks on LP | GSC | SEARCH | URL | clicks | sum | GSC | GSC | daily | — | landing |

**Lab ≠ Field.** Never substitute.

**URL coverage DECISION REQUIRED:** homepage + strategic/high-traffic/paid landings first — **not** PSI for every URL.

---

## 25. Infrastructure Workspace Requirements

### Infrastructure matrix

| UI concept | Source | Kind | Reliability | Unavailable? | Notes |
| --- | --- | --- | --- | --- | --- |
| Domain hostname/expiry/registrar | DNS/WHOIS/operator | mixed | WHOIS often masked | YES | Provenance required |
| DNS NS + A/MX/TXT | DNS lookup | direct | high for records | rare | Minimal record set |
| TLS issuer/expiry/SAN/HTTPS | TLS probe | direct | high | YES | Diagnosis present |
| SSL “grade” | may be DEMO / third-party | inferred | weak | YES | Do not invent grade authority |
| Hosting provider/plan/renewal | **OPERATOR_MAINTAINED** | manual | authoritative if entered | YES | IP→provider weak |
| CDN | header detection | inferred | uncertain | YES | missing≠none |
| CMS name/version | WP + detect | dual | connector better | YES | |

**Standalone Domain/Hosting Digital Assets: NO** (legacy deprecated context only).

---

## 26. Setup Workspace Requirements

- Website Asset configuration (primary_url, cms, languages, hosting_context)  
- Site Connector binding state + last success  
- Related GSC / GA4 Digital Assets  
- Collection capability availability  
- Per-source freshness links  

Not a duplicate Integrations screen — Website-scoped setup.

---

## 27. GA4 Cross-Asset Requirements

Reuse **GA4 Data Contract V1**.

Website consumers: Performance acquisition/landing/conversions; Visibility AI referrals; Content traffic/events columns; Overview conversion snapshot.

Join: normalized Website URL ↔ GA4 landing page path/host.

---

## 28. Search Console Cross-Asset Requirements

Reuse **Search Console Data Contract V1**.

Website consumers: Visibility organic; Performance search/landing organic clicks; Health/indexing context via URL Inspection (GSC side); Overview search visibility.

Join: normalized page URL.

Limitations: incomplete indexing inventory; position ≠ rank; Demand ≠ market volume.

---

## 29. DataForSEO Dependency Requirements

| Website requirement | External intelligence | Future DFS Contract | Req | Refresh | Cost |
| --- | --- | --- | --- | --- | --- |
| Ranked keywords count | Labs ranked keywords | DFS-RK | Required | TTL/manual confirm | Paid |
| Keywords for site | Labs keywords_for_site | DFS-KFS | Required | same | Paid |
| Opportunities | derived cross-source | DFS-OPP | Required | same | Paid |
| Competitors / SERP | Discovery/SERP | DFS-SERP | Conditional | — | Paid |

Exact endpoint/field semantics → **Prompt 6**.  
**No paid requests in this audit.**

---

## 30. PageSpeed / Technical Measurement Requirements

| Measurement | Source | Lab/Field | Scope | Refresh | Quota |
| --- | --- | --- | --- | --- | --- |
| Lab CWV + SI | PSI v5 / Lighthouse | LAB | Priority URLs | on demand / daily max | High cost if naive |
| Field CWV | CrUX via PSI | FIELD | Origin (+ URL if available) | slower | API limits |
| Direct TTFB | HTTP timing | DIRECT | Optional | diagnosis | — |

Do not collect entire Lighthouse diagnostics dump unless a frozen Finding needs a specific audit.

---

## 31. Domain / DNS / TLS / Hosting Requirements

Covered in §25. Minimal DNS types: NS, A/AAAA for apex/www, MX, SPF TXT as shown in freeze. Full DNS dump not required. WHOIS-heavy PII not required.

---

## 32. Operations-Domain Requirements

Findings/Recommendations/Tasks/Outcomes are **OPERATIONS_DOMAIN**.  
Evidence dependencies may include: canonical absence, HTTP 404, lab LCP, schema absence, WP updates, NAP mismatch, GSC declines, DFS opportunities.

**Observation ≠ Finding.**

---

## 33. Website Technical Observation Model

### Categories (Contract V1)

`HTTP` · `REDIRECT` · `HTML_METADATA` · `HEADING` · `CONTENT` · `LINK` · `IMAGE` · `SCHEMA` · `CANONICAL` · `ROBOTS` · `SITEMAP` · `CMS` · `TLS` · `DNS` · `PERFORMANCE`

### Provenance (required eventually)

source · observed_at · scope (Website/URL) · raw/normalized · contract_version · collection run · freshness

### Observation → Finding?

**Generally NO** — needs context (importance, traffic, offering).

---

## 34. Source Precedence / Conflict Matrix

| Concept | Configured | Observed | Measured | Enrichment | Can disagree? | MoxDOP behavior |
| --- | --- | --- | --- | --- | --- | --- |
| Page title | WP title / SEO plugin | HTML `<title>` | — | — | YES | Preserve both; Finding if diverge on priority pages |
| URL | WP permalink | redirect final / crawl | GSC/GA4 variants | DFS URL | YES | Multi observations; normalize for join |
| Canonical | CMS SEO field | HTML rel=canonical | Google canonical (GSC Inspection) | — | YES | Three-way provenance |
| Traffic | — | — | GA4 | — | — | GA4 owns |
| Search visibility | — | — | GSC | DFS estimated | YES semantics | Never merge provenance |
| Indexing | robots/canonical | — | GSC Inspection | — | YES | Indexability ≠ indexed |
| Hosting | operator | IP/headers infer | — | — | YES | Prefer operator; mark inferred |

---

## 35. Performance Source Matrix

See §24. Lab / Field / Direct / Analytics explicitly separated.

---

## 36. Indexability / Indexing Matrix

| Signal | Proves | Does NOT prove |
| --- | --- | --- |
| robots.txt allow | Crawler may fetch (for that UA) | Indexed in Google |
| meta robots index | Page allows indexing intent | Indexed |
| HTTP 200 | Resource returned | Quality / indexed |
| Canonical self | Declared preferred URL | Google chose it |
| In sitemap | Publisher listed URL | Indexed / submitted to GSC |
| GSC URL Inspection | Google index state snapshot | Sitewide totals |
| GSC Search Analytics row | Some search activity observed | Complete indexed set / market demand |

---

## 37. Candidate Normalized Datasets

| Dataset ID | Source | Scope | Grain | Keys | Base facts | Kind | Consumers | History | Refresh | Volume | Cross-asset | Limits |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `website_asset` | Asset | W | entity | asset_id | domain, primary_url, cms… | state | all | current | on edit | tiny | Brand | |
| `website_url` | multi | U | entity | url_id, asset_id, normalized_url | provenance sources | state | inventory/content | first/last seen | continuous | high | joins | partial |
| `website_cms_object` | WP | entity | wp_id+type | permalink, status, dates | state | Content | current+ | connector | med | URL link | REST-visible |
| `website_http_snapshot` | HTTP | U | url×observed_at | status, final, headers subset | snapshot | Health | change events | diagnosis | med | — | |
| `website_metadata_snapshot` | HTML/CMS | U | url×obs | title, meta, canonical, robots | snapshot | Health/Content | on change | diagnosis | med | dual source | |
| `website_heading_snapshot` | HTML | U | url×obs | h1 (+optional) | snapshot | Content | on change | diagnosis | med | |
| `website_content_stats` | CMS/HTML | U | url×obs | word_count, lang | snapshot | Content | on change | connector | med | no full HTML V1 |
| `website_link_edge` | HTML | edge | src×dst | optional anchor | edge/agg | Health | optional hist | crawl | **very high** | prefer agg |
| `website_schema_snapshot` | HTML | U | url×obs | types, parse_ok | snapshot | Health | on change | diagnosis | med | |
| `website_sitemap_snapshot` | Sitemap | W | sitemap×obs | urls count, status | snapshot | Inventory | periodic | fetch | low | ≠ GSC |
| `website_cms_snapshot` | WP | W | asset×obs | version, theme, plugins | snapshot | Health/Setup | periodic | connector | low | |
| `website_tls_snapshot` | TLS | W | host×obs | cert fields | snapshot | Infra/Health | periodic | diagnosis | low | |
| `website_dns_snapshot` | DNS | W | domain×obs | NS/records | snapshot | Infra | slow | lookup | low | |
| `website_infrastructure_state` | operator+obs | W | asset | hosting/cdn/domain | state | Infra | current | manual+obs | tiny | provenance |
| `website_performance_measurement` | PSI/CrUX | U/W | url×strategy×obs | lab/field metrics | snapshot | Perf/Health | retain tests | on demand | med | quota |

Do **not** create all automatically — prioritize url + http/metadata + cms + performance + infra.

---

## 38. Derived Metric / Classification Registry

| ID | UI label | Inputs | Method | Thresholds | Missing | Consumers |
| --- | --- | --- | --- | --- | --- | --- |
| D_WEB_INVENTORY_COUNT | Known URLs | url provenance union | count distinct normalized | — | Partial label | Overview |
| D_WEB_FINDING_COUNTS | Open/high findings | Findings | count | — | 0 OK | Overview/Health |
| D_WEB_STATUS_CLASS | 2xx/3xx/4xx/5xx | HTTP status | map | — | Unavail | Health |
| D_WEB_CANONICAL_MISSING | Missing canonical | canonical null | boolean | — | — | Health |
| D_WEB_BROKEN_INTERNAL | Broken internal | link→404 | count | — | — | Health |
| D_WEB_TLS_DAYS | Days to expiry | cert notAfter | date delta | attention <90d Demo | Unavail | Infra |
| D_WEB_HOSTING_RENEWAL | Renewal due | operator date | days | Demo 34d | Unavail | Infra |
| D_WEB_STRIKING_DISTANCE | Striking distance | GSC position+impr | heuristic | GSC contract | — | Visibility |
| D_WEB_VIS_DELTA | Growing/declining | GSC period compare | relative % | — | Unavail if prev 0 | Visibility |
| D_WEB_PAGE_ROLE | Content role | rules/operator | mapping | — | Unknown | Content |
| D_WEB_AI_READINESS | AI readiness conditions | crawl/entity/schema | classification | no score | Partial | Visibility AI |
| D_WEB_LAB_RATING | poor/NI/good | lab metric | CWV thresholds | provider bands | Unavail | Performance |

**Magic scores: NONE** (including no Website Health Score, no AI Readiness Score, no Visibility Score, no Content Quality Score).  
Lighthouse performance category score, if shown, is **provider lab score** — not MoxDOP Health Score.

---

## 39. Historical Storage Requirements

| Family | History |
| --- | --- |
| URL inventory | first_seen / last_seen; not full forever HTML |
| HTTP/canonical/robots/schema | change snapshots / events |
| Content stats | on change |
| Link graph | prefer current agg + broken list |
| Performance tests | retain lab/field snapshots for priority URLs |
| DNS/TLS | periodic snapshots |
| CMS inventory | periodic snapshots |
| Hosting operator fields | current + optional edit audit |

---

## 40. Refresh / Freshness Requirements

| Source | Cadence | Note |
| --- | --- | --- |
| Direct diagnosis/crawl | scheduled / on demand | Health |
| WordPress connector | ≥ daily when connected | ≠ connection state |
| GSC / GA4 | per their contracts | |
| DataForSEO | TTL + explicit confirm | never on render |
| PageSpeed | priority URLs; cost-aware | |
| DNS/TLS | weekly / on demand | |
| Operator hosting | on edit | |

**Global single `last_updated_at` is NOT sufficient.**

---

## 41. Cardinality / Scale Risks

| Scale | Implication |
| --- | --- |
| 100 URLs | Full metadata OK |
| 1k–10k | Prioritize; link edges explode |
| 100k+ | Must sample crawl; PSI only priority; aggregate links |

Risks: link-edge cardinality, media, schema objects, PSI call volume.

---

## 42. Existing Implementation Reuse Matrix

| Component | Responsibility | Source | Coverage | Disposition |
| --- | --- | --- | --- | --- |
| `DigitalAsset` Website fields | Identity | DB | Strong | KEEP |
| `WebsiteDiagnosisService` + parsers | HTTP/TLS/robots/sitemap/canonical/head | Direct | Strong partial | KEEP / ADAPT |
| `PublicSiteCrawler` / Discovery | Bounded crawl + candidates | Direct | Partial | KEEP / ADAPT |
| WordPress probe | REST index | WP | Weak vs Content UI | ADAPT LATER |
| PageSpeed probe | PSI lab Evidence | PSI | Present unused in Filament UI | ADAPT LATER |
| GSC/GA4 collectors | Measurement | GSC/GA4 | Strong | KEEP |
| DataForSEO SEO Intelligence | Ranked/KFS | DFS | Partial | KEEP; Prompt 6 |
| Demo Website fixtures / OverviewPage | Frozen IA | Demo | Spec source | KEEP Demo |
| Site Connector Demo ZIP | UX | Demo | Not production plugin | ADAPT / REPLACE LATER |
| Legacy domain/hosting asset types | Deprecated | Demo catalog | Legacy | REMOVE LATER from creation |

---

## 43. WordPress Connector Gap Analysis

| Area | Status |
| --- | --- |
| Site identity | PARTIAL (probe `/wp-json/`) |
| Content inventory pages/posts/CPTs | **MISSING** |
| Taxonomy | **MISSING** |
| Media library | **MISSING** |
| SEO metadata adapters | **MISSING** |
| Capabilities model | **MISSING** (Demo lists provides[]) |
| Pagination | **MISSING** |
| Multilingual | **MISSING** |
| Production readiness | **NOT ready** for Content tab — probe-only |

---

## 44. DataForSEO Current Capability Audit

| Capability | Present in code? | Website need |
| --- | --- | --- |
| Ranked keywords | YES | Required |
| Keywords for site | YES | Required |
| Relevant pages / opportunities | PARTIAL (cross-source) | Required |
| Competitor domains | Discovery-related / limited | Conditional |
| Paid requests executed this audit | **NO** | — |

Exact contract → Prompt 6.

---

## 45. Unsupported / Demo-Only Concepts

| Concept | Class |
| --- | --- |
| Availability monitoring timeline | DEMO_ONLY / not configured |
| AI mention platform rows | DEMO_ONLY |
| Generative search reporting | UNAVAILABLE |
| SSL “grade” authority | DEMO / weak |
| Website Health Score | FORBIDDEN |
| Domain/Hosting as new assets | FORBIDDEN |
| Uncontrolled competitor crawling | Not implemented (bounded Discovery only) |

### Semantic review required later (do not redesign now)

- Local visibility matrix could be misread as ranking guarantee — already footnoted; keep.  
- Hosting provider inference from IP — prefer operator.  
- “Healthy” language on DNS — classification over observation.  
- Field vs lab both shown — keep labels honest.

---

## 46. Decisions Required Before Website Collection

1. Trailing-slash and www normalization policy per Website.  
2. PSI URL coverage set (priority definition).  
3. Whether drafts enter Content inventory.  
4. Link storage: full edges vs aggregates + broken targets.  
5. Whether to retain raw HTML artifacts.  
6. WHOIS usage depth (likely minimal).  
7. Connector auth model for production plugin (application passwords vs custom).  
8. Page role classification methodology (rules vs operator).  

---

## 47. Privacy / Data Minimization

| Item | Required? |
| --- | --- |
| Visitor-level data | **NO** |
| Form submission content | **NO** |
| CMS credentials in Evidence | **NO** |
| Private drafts | **NO** unless explicitly justified |
| PII harvesting from pages | **NO** intentional collection |

Public page content may incidentally contain phones/emails (NAP) — treat as public business info when needed for consistency checks, not as user PII pipeline.

Minimization: no general-purpose crawler field mirror; no full Lighthouse dump; no full DNS/WHOIS dump.

---

## 48. Definition of Done

| Check | Status |
| --- | --- |
| Every frozen Website component traceable? | YES |
| Website's own data model explicit? | YES |
| URL identity explicit? | YES |
| Multi-source URL inventory explicit? | YES |
| CMS vs public observation separated? | YES |
| Health contains no arbitrary score? | YES |
| Visibility sources separated? | YES |
| GSC vs DataForSEO semantics separated? | YES |
| Performance lab vs field separated? | YES |
| Infrastructure semantics explicit? | YES |
| Domain/Hosting remain Website Infrastructure? | YES |
| Site Connector role explicit? | YES |
| Observation distinct from Finding? | YES |
| Cross-asset dependencies explicit? | YES |
| Candidate normalized datasets explicit? | YES |
| Freshness/history explicit? | YES |
| Existing implementation gap explicit? | YES |
| Future collection can proceed without inventing wants? | YES |

**CONTRACT STATUS: PASS**
