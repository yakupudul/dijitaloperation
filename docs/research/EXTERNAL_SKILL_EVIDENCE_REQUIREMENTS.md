# External Skill Evidence Requirements (Prompt 48)

> **Status:** RESEARCH ARTIFACT — no production code, no runtime, no dependency change
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47)
> **Parent artifact:** [`MOXDOP_SKILL_RESEARCH_MATRIX.md`](./MOXDOP_SKILL_RESEARCH_MATRIX.md)
> **Owns:** evidence levels A–H, per-capability evidence requirements, data availability, abstention rules, eval expectations

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| Production MoxDOP Skill implementation | **NOT YET** |

This artifact answers: **what would a MoxDOP Skill need to be able to say something honest**, for each methodology extracted from the external corpus.

---

## 1. Evidence level ladder (A–H)

| Level | Name | Definition | Reproducible? | Deterministic Finding allowed? |
| --- | --- | --- | --- | --- |
| **A** | `DIRECT_STRUCTURAL_FACT` | Directly observable in a fetched artifact and re-derivable from that same artifact | Yes, from stored artifact | **Yes** |
| **B** | `DIRECT_MEASURED_METRIC` | First-party measured metric from an authorized provider account | Yes, within provider windows and lag | **Yes**, source labelled |
| **C** | `PROVIDER_REPORTED_ESTIMATE` | Third-party/vendor reported, modelled, or sampled value | Partially — vendor-dependent | No — advisory context only |
| **D** | `DOCUMENTED_PLATFORM_RULE` | Rule stated in primary provider or standards documentation | Yes, by citation | **Yes**, as the rule behind an A/B observation |
| **E** | `DERIVED_COMPUTATION` | Computed by MoxDOP from A/B/D inputs via a registered formula | Yes, if inputs and formula recorded | **Yes** |
| **F** | `EXPERT_HEURISTIC` | Practitioner convention without primary-source confirmation | Not verifiable as truth | No — advisory with uncertainty label |
| **G** | `CORRELATIONAL_STUDY_CLAIM` | Third-party study/correlation not verified for this asset | No | No |
| **H** | `UNSUPPORTED_CAUSAL_CLAIM` | Causal assertion with no supporting evidence | No | **Never — must not appear in MoxDOP output** |

### 1.1 Composition rules

| # | Rule |
| --- | --- |
| E1 | A Skill's output may not claim a higher evidence level than the **lowest** level among the inputs it depends on |
| E2 | A Skill that requires level G or H to reach its conclusion must **abstain** |
| E3 | Level A/B/D/E assertions and level C/F assertions must be visually and structurally separated in output; equal presentation is itself a defect |
| E4 | A level E derivation must name its inputs and its registered formula; an unnamed derivation is level F |
| E5 | Absence of an input never becomes a zero value; it becomes an availability statement (see §4) |
| E6 | A vendor estimate (C) may never satisfy an evidence requirement that names a first-party source (B) |
| E7 | Sampled observations must record sampling method, surface, locale, and timestamp, or they are unusable |
| E8 | Composite scoring across levels is prohibited (parent matrix §24) |

### 1.2 Level assignment for the corpus's recurring claims

| Claim type found in the corpus | Level | Rationale |
| --- | --- | --- |
| `robots.txt` directive present / user-agent disallowed | A | Deterministic parse of a fetched file |
| Canonical tag present / target URL | A | HTML head observation |
| HTTP status / redirect chain | A | Response observation |
| JSON-LD block present, type, property completeness | A (+ D for the rule) | Parse + documented type requirements |
| Sitemap present, URL count, referenced URLs | A | Fetched file |
| hreflang set and reciprocity | A (+ D) | Head/HTTP observation + documented rule |
| TLS validity / expiry, DNS records | A | Direct observation |
| GSC clicks / impressions / position | B | First-party provider measurement |
| GA4 sessions / conversions | B | First-party provider measurement |
| CrUX field CWV | B | First-party aggregate of real users |
| Lighthouse / PSI lab score | E | Synthetic derivation |
| DataForSEO volume / rank / ETV | C | Vendor estimate (ETV compounded) |
| SE Ranking volume / authority / AI share-of-voice | C | Vendor estimate/index |
| Geogrid local rank | C | Sampled per grid point |
| Domain authority / domain rating | F | Proprietary construct, not a platform signal |
| Title/meta length bands | F | No primary source specifies a limit |
| 134–167-word citability blocks | F/G | Heuristic from a third-party passage analysis |
| E-E-A-T sub-factor scoring | F | Rater guidance is not a computable site score |
| "GEO optimization yields 30–115% more AI visibility" | G | Correlational study; **H if restated causally** |
| "Brand mentions correlate 3x stronger than backlinks for AI" | G | No primary source |
| "Implementing X will increase citations by N%" | H | Prohibited |

## 2. Per-capability evidence requirements

Format per capability: required evidence (must exist for the Skill to run), optional evidence (raises fidelity), evidence support classification, abstention rules, and forbidden claims.

### 2.1 Website Technical Audit

| Field | Value |
| --- | --- |
| Required | `WEBSITE_DIRECT_OBSERVATION` — successful fetch of the primary URL: HTTP status, response headers, HTML document |
| Required | `DOMAIN_DNS_TLS` — TLS validity and expiry; DNS resolution |
| Optional | `PAGESPEED_TECHNICAL` field CWV; `WORDPRESS_SITE_CONNECTOR` URL inventory and update state |
| Evidence support | `SUPPORTED` |
| Max output level | A + D (E for derived counts/deltas) |
| Abstain when | Primary URL fetch failed or returned a non-content response; TLS/DNS lookup failed |
| Never claim | That absence of an optional signal is a defect; that a lab score is a field measurement; a single composite health number |
| MoxDOP subjects already modelled | `WEB_HEALTH_HTTP_STATUS`, `WEB_HEALTH_AVAILABILITY`, `WEB_HEALTH_SECURITY_HEADER`, `WEB_INFRA_TLS`, `WEB_INFRA_DNS`, `WEB_HEALTH_LCP` |

### 2.2 Indexability Analysis

| Field | Value |
| --- | --- |
| Required | `robots.txt` fetch outcome (found / not found / error — three distinct states); canonical observation; HTTP status; sitemap fetch outcome |
| Optional | GSC index coverage signals; `noindex` meta/header observation |
| Evidence support | `PARTIAL` — MoxDOP can observe declarations but not Google's actual index decision |
| Max output level | A + D |
| Abstain when | `robots.txt` or sitemap fetch **errored** — an error is not "no restrictions" and not "no sitemap" |
| Never claim | That a page is indexed or not indexed without GSC evidence; that a missing sitemap is a rule violation (sitemaps are recommended, not required); that canonical is a directive rather than a signal |
| Notes | The three-state fetch outcome (found / absent / error) is the single most important guard here and is missing from most external repos |

### 2.3 Metadata Consistency

| Field | Value |
| --- | --- |
| Required | Head-region observation: `title`, meta description, `charset`, `viewport`, heading structure |
| Optional | `CMS_METADATA` equivalents (WP SEO fields) for conflict detection |
| Evidence support | `SUPPORTED` |
| Max output level | A (presence/consistency) + D (documented handling) |
| Abstain when | The retrieval method demonstrably strips head fields. The SE Ranking repository documents this exact trap: a post-processed HTML format silently removes canonical, hreflang, and JSON-LD, so zero-valued head fields on an obviously-tagged site indicate a retrieval defect, not a site defect |
| Never claim | That a length band is a platform requirement (level F); that CMS-vs-HTML disagreement means the CMS value is served (report the conflict, per `WEB_CONTENT_TITLE` dual-provenance note) |
| MoxDOP subjects already modelled | `WEB_CONTENT_TITLE`, `WEB_CONTENT_META`, `WEB_CONTENT_H`, `WEB_HEALTH_CANONICAL` |

### 2.4 Structured Data Audit

| Field | Value |
| --- | --- |
| Required | JSON-LD / microdata blocks extracted from the fetched document; Schema.org vocabulary and Google structured-data documentation for the types in scope |
| Optional | CMS-emitted schema configuration |
| Evidence support | `SUPPORTED` for presence and property completeness; `PARTIAL` for eligibility claims |
| Max output level | A + D |
| Abstain when | Blocks exist but are unparseable — report "unparseable", never "absent"; type is not covered by MoxDOP's verified type catalog |
| Never claim | Rich-result eligibility without current Google documentation; that a deprecated/retired type is beneficial; that a library's supported types define Google's eligibility |
| Deprecation requirement | The catalog needs an explicit deprecation axis (from HEAD's `DEPRECATED.md` pattern and claude-seo's dated retirement reference). Retirement dates must be re-read from Google's documentation at implementation time — they age faster than any other fact in this corpus |
| MoxDOP subject already modelled | `WEB_HEALTH_SCHEMA` |

### 2.5 Internal Linking Analysis

| Field | Value |
| --- | --- |
| Required | A URL inventory with **known coverage**, plus internal anchor extraction per document |
| Optional | CMS taxonomy; GSC page-level data for value weighting |
| Evidence support | `FUTURE_DATA_REQUIRED` — MoxDOP has no bounded internal-link collector today, and a general crawler is out of scope |
| Max output level | A (observed links) + E (depth, orphan candidates) |
| Abstain when | Inventory coverage is unknown or partial — **no orphan claim is honest without known-complete inventory** |
| Never claim | That a page is orphaned when only a subset of the site was observed; that a link count is a quality measure |
| Design note | "Orphan" is a claim about absence, so it is the clearest case in this corpus where missing ≠ zero decides whether the Skill can exist at all |

### 2.6 Content Quality Review

| Field | Value |
| --- | --- |
| Required | Content body observation; Brand Context (audience, offering, positioning) |
| Optional | GSC engagement/query alignment; GA4 behaviour |
| Evidence support | `PARTIAL` — level F ceiling |
| Max output level | F (advisory) |
| Abstain when | No Brand Context exists — generic quality advice without positioning is noise |
| Never claim | A numeric quality/E-E-A-T score as a MoxDOP metric; that quality judgment is a deterministic Finding; that AI-generated content is inherently penalized |
| Routing | **Playbook-leaning (Prompt 45).** A rubric a human works through with guidance fits `playbooks` / `playbook_revisions` better than an AI Skill that emits assertions |

### 2.7 Search Demand Analysis

| Field | Value |
| --- | --- |
| Required | `SEARCH_CONSOLE` query data for a defined window (queries, impressions, clicks, position) |
| Optional | `DATAFORSEO` volume — level C, behind Prompt 34 cost/freshness control |
| Evidence support | `SUPPORTED` |
| Max output level | B + E |
| Abstain when | No authorized GSC connection; window shorter than the analysis requires; provider lag makes the window incomplete |
| Never claim | That vendor volume is demand; that GSC impressions and vendor volume are comparable quantities; a demand trend across a window that mixes provider lag states |
| Notes | Vendor volume answers "how big might this market be"; GSC answers "what did this site actually surface for". Different questions, never one column |

### 2.8 Query Opportunity Analysis

| Field | Value |
| --- | --- |
| Required | GSC query + position + page mapping over a defined window |
| Optional | Vendor rank for cross-checking (labelled separately) |
| Evidence support | `SUPPORTED` |
| Max output level | B + E (striking-distance style derivation) |
| Abstain when | Data volume below the threshold where position averages are meaningful; page mapping ambiguous |
| Never claim | Predicted traffic gain from a position change (that is level G at best, level H as stated); that a vendor rank contradicting GSC position means GSC is wrong |
| Notes | MoxDOP already models `D_WEB_STRIKING_DISTANCE` as a derived subject; external "quick wins" skills are the same idea with less provenance discipline |

### 2.9 Local Profile Completeness

| Field | Value |
| --- | --- |
| Required | Authorized Google Business Profile field data (categories, hours, attributes, services, products, description, media presence) |
| Optional | Website NAP observation for cross-surface consistency |
| Evidence support | `FUTURE_DATA_REQUIRED` — `REQUIRES_NEW_COLLECTOR` |
| Max output level | A (field presence) + D (documented field semantics) |
| Abstain when | No authorized GBP data — absence of data is not an incomplete profile |
| Never claim | A completeness percentage as a metric; that an unfilled optional field is a defect; that a category choice causes ranking change |
| Notes | Field *existence* is documented by Google; completeness *thresholds* in the corpus are practitioner heuristics (level F) |

### 2.10 Local Review Intelligence

| Field | Value |
| --- | --- |
| Required | Reviews via an **official API only**: volume, rating distribution, timestamps, response presence and latency |
| Optional | Operator-supplied context on campaigns or incidents |
| Evidence support | `FUTURE_DATA_REQUIRED` |
| Max output level | B (counts/timestamps from the official source) + E (velocity, response coverage) + F (sentiment themes) |
| Abstain when | No official source is available. **Scraping is rejected** — the secondary-repo decision on the review scraper stands |
| Never claim | Sentiment as measurement; that review velocity causes ranking change; a reputation score |

### 2.11 Measurement Audit

| Field | Value |
| --- | --- |
| Required | GA4 configuration/data availability; GSC property linkage; Website direct observation of tag presence |
| Optional | Ads linkage; conversion definitions |
| Evidence support | `PARTIAL` — depends on how many legs are connected |
| Max output level | A + B |
| Abstain when | Any leg is missing — and the output must **name the missing leg** rather than degrade silently |
| Never claim | That GSC clicks and GA4 sessions should reconcile; that a discrepancy is necessarily a defect (they measure different things); a data-quality score |
| Notes | This is the capability where R7 does the most work. The external corpus routinely blends these sources |

### 2.12 GEO Observation Analysis

| Field | Value |
| --- | --- |
| Required | `robots.txt` AI/LLM user-agent directives; structured data presence; llms.txt fetch outcome (found / absent / error) |
| Optional | Entity presence on named third-party pages (level A per fetched page only) |
| Evidence support | `PARTIAL` for observables; **`UNSUPPORTED`** for mention, citation, and AI Overview appearance |
| Max output level | A for observables; F for citability structure heuristics |
| Abstain when | Any question about AI mentions, citations, or AI Overview appearance — MoxDOP has no data source |
| Never claim | A GEO score; that llms.txt presence improves citation (contested; no primary source); any percentage uplift from GEO work; that an AI surface was measured when it was sampled |
| Mandatory labelling | `EXPERIMENTAL`, plus the six-way concept disambiguation from parent matrix §25 |

## 3. Evidence support summary

| Capability | Evidence support | Blocking gap |
| --- | --- | --- |
| Website Technical Audit | `SUPPORTED` | — |
| Indexability Analysis | `PARTIAL` | Index-state truth requires GSC |
| Metadata Consistency | `SUPPORTED` | — |
| Structured Data Audit | `SUPPORTED` (presence) / `PARTIAL` (eligibility) | Verified type + deprecation catalog |
| Internal Linking Analysis | `FUTURE_DATA_REQUIRED` | Bounded inventory + link extraction |
| Content Quality Review | `PARTIAL` | Level F ceiling; Playbook routing |
| Search Demand Analysis | `SUPPORTED` | — |
| Query Opportunity Analysis | `SUPPORTED` | — |
| Local Profile Completeness | `FUTURE_DATA_REQUIRED` | Authorized GBP data |
| Local Review Intelligence | `FUTURE_DATA_REQUIRED` | Official review source |
| Measurement Audit | `PARTIAL` | Connection completeness |
| GEO Observation Analysis | `PARTIAL` / `UNSUPPORTED` | AI-surface observability |

## 4. Data availability matrix and the missing ≠ zero rule

Availability enums: `AVAILABLE` · `PARTIAL` · `NOT_AVAILABLE` · `PROVIDER_LIMITED` · `REQUIRES_NEW_COLLECTOR` · `REQUIRES_OPERATOR_INPUT` · `HEURISTIC_ONLY`

| Data need | MoxDOP source class | Availability | Correct behaviour when absent |
| --- | --- | --- | --- |
| robots.txt | `WEBSITE_DIRECT_OBSERVATION` | `AVAILABLE` | Distinguish absent (200 with no file → no restrictions declared) from error (unknown) |
| Canonical | `WEBSITE_DIRECT_OBSERVATION` | `AVAILABLE` | Absent canonical is an observation, not automatically unhealthy |
| HTTP status / redirects | `WEBSITE_DIRECT_OBSERVATION` | `AVAILABLE` | Unreachable ≠ 404 |
| Title / meta / headings | `WEBSITE_DIRECT_OBSERVATION` + `CMS_METADATA` | `AVAILABLE` | Null title is an observation; retrieval-stripped fields are a defect in the run |
| Structured data blocks | `WEBSITE_DIRECT_OBSERVATION` | `AVAILABLE` | Unparseable ≠ absent |
| Sitemap | `WEBSITE_DIRECT_OBSERVATION` | `AVAILABLE` | Absent = not declared, not a violation |
| hreflang | `WEBSITE_DIRECT_OBSERVATION` | `PARTIAL` | Single-language site legitimately has none |
| TLS / DNS / domain | `DOMAIN_DNS_TLS` | `AVAILABLE` | Lookup failure = unknown |
| CWV field | `PAGESPEED_TECHNICAL` | `PARTIAL` | Insufficient field data = unknown, never "good" |
| URL inventory | `WORDPRESS_SITE_CONNECTOR` / direct | `PARTIAL` | Unknown coverage blocks absence-based claims |
| Internal link graph | derived | `REQUIRES_NEW_COLLECTOR` | No orphan claims |
| GSC queries | `SEARCH_CONSOLE` | `AVAILABLE` | No connection = capability unavailable |
| GA4 metrics | `GA4` | `AVAILABLE` | No property = capability unavailable |
| Keyword volume / rank / ETV | `DATAFORSEO` | `PROVIDER_LIMITED` | Prompt 34 cost/freshness governs; no fallback provider |
| GBP profile fields | `CROSS_ASSET` | `REQUIRES_NEW_COLLECTOR` | No completeness claim |
| Reviews | `CROSS_ASSET` | `REQUIRES_NEW_COLLECTOR` | Official API only |
| Citations / directories | `OPERATOR_MAINTAINED` | `REQUIRES_OPERATOR_INPUT` | Absent citation ≠ negative signal |
| Geogrid rank | — | `NOT_AVAILABLE` | Do not synthesize |
| AI mention / citation / AI Overview | — | `NOT_AVAILABLE` (`DEMO_ONLY` fixtures exist for UX) | Abstain; never present fixture as observation |
| Content quality judgment | AI advisory | `HEURISTIC_ONLY` | Label uncertainty |

### 4.1 Missing ≠ zero — the four failure shapes

| Shape | Example from the corpus | MoxDOP guard |
| --- | --- | --- |
| Absent input scored as 0 in a composite | Category scores computed when a category has no data | Composites rejected outright; availability reported per category |
| Retrieval failure read as absence | Post-processed HTML stripping canonical/hreflang/JSON-LD, yielding "zero" head fields | Three-state fetch outcome + fidelity assertion before analysis |
| Partial coverage read as complete | Orphan-page claims from partial crawls | Coverage must be known before any absence claim |
| Unavailable capability rendered as a passing check | "No issues found" when the check never ran | Skill eligibility → **not applicable**, distinct from "clean" |

## 5. Abstention rules (cross-cutting)

| # | Rule |
| --- | --- |
| AB1 | If a required evidence input is absent or errored, the Skill reports **not applicable** with the named missing input — it does not produce a degraded conclusion |
| AB2 | If coverage of an inventory is unknown, no claim about absence may be made |
| AB3 | If the only available support is level F/G, the Skill emits an advisory observation with an explicit uncertainty label, never a Finding |
| AB4 | If two sources conflict, the Skill reports the conflict with both provenances; it does not pick a winner |
| AB5 | If a check depends on a platform rule MoxDOP has not verified against primary documentation, the check is withheld from the catalog |
| AB6 | If a claim requires level H reasoning to be useful, the claim is dropped |
| AB7 | A Skill never creates a Task, Finding, Recommendation, or Notification as a side effect of abstention or of success |

## 6. Verification and eval expectations (Prompt 49 input)

Adapted from the `addyosmani/agent-skills` verification discipline and the dated-example corpora in `seranking/seo-skills` and `aaron-marketing-skills`. **No eval runner exists or is proposed in Prompt 48.**

| Eval case class | Purpose | Expected outcome |
| --- | --- | --- |
| Happy path | All required evidence present and clean | Correct assertions, correct evidence levels attached |
| Partial evidence | One optional input missing | Output produced, missing input named, no silent degradation |
| Missing required evidence | A required input absent | **Not applicable** with named gap; no assertions |
| Retrieval-fidelity defect | Document fetched but head fields stripped | Detected as a run defect, not reported as site defects |
| Error vs absence | robots.txt / sitemap fetch errors | Reported as unknown, not as "no restrictions" / "no sitemap" |
| Conflicting sources | CMS title ≠ HTML title | Conflict reported with both provenances |
| Vendor/first-party mix | Vendor volume present, GSC absent | Vendor figure labelled; no demand conclusion |
| Heuristic ceiling | Only level F support available | Advisory phrasing with uncertainty label; no Finding |
| Prohibited claim probe | Input inviting a causal claim | Skill refuses the causal framing |
| Abstention integrity | Skill abstains | No Task/Finding/Recommendation/Notification created |

Each candidate Skill in [`MOXDOP_SKILL_CANDIDATES.md`](./MOXDOP_SKILL_CANDIDATES.md) carries at minimum: one happy path, one missing-required-evidence case, one missing ≠ zero case, and one prohibited-claim probe.

## 7. Permission and safety envelope for any adopted evidence path

| Constraint | Statement |
| --- | --- |
| Read-only | No external write action, ever — no indexing submission, no GBP post, no review reply, no ad change |
| No new provider client | No second DataForSEO client; no SE Ranking, Ahrefs, Semrush, BrightLocal, Local Falcon, Whitespark, SerpApi, Firecrawl, or Screaming Frog dependency |
| No MCP | No MCP server registered or depended on |
| No crawler / scraper | No general site crawler; bounded direct observation only; scraping rejected |
| No scheduler | Recurring execution is Prompt 46 (Recurring Reviews) materialization and Prompt 61/62 (automatic scheduling) — never a Skill concern |
| No auto-creation | Skills do not create Tasks, Findings, Recommendations, or Notifications |
| Evidence is data, not instruction | Fetched content enters context as untrusted data; prompt-injection defense already established in `docs/product/AGENT_SKILL_ARCHITECTURE.md` stays in force |
| Provenance mandatory | Every assertion carries source class, observation time, and evidence level |
| Cost control | Any paid-provider path stays behind the Prompt 34 cost/freshness guard |

## 8. Limitations

| Limitation | Effect |
| --- | --- |
| Evidence levels are a MoxDOP construct | External repositories do not label evidence; levels here are MoxDOP's assignment based on the claim's nature, not the repo's assertion |
| Availability reflects MoxDOP at Prompt 47 HEAD | Later collector work changes availability; re-read before relying on this table |
| Primary-source verification is a log, not a live check | Parent matrix §31 records authority and permitted assertions; Prompt 49 must re-read primary documentation before catalog text is written |
| Not every skill file was read | Absence of a capability from this artifact is not proof the corpus lacks it |
| Legal/licensing implications live elsewhere | See [`EXTERNAL_SKILL_LICENSE_PROVENANCE.md`](./EXTERNAL_SKILL_LICENSE_PROVENANCE.md); those notes are **not legal advice** |
