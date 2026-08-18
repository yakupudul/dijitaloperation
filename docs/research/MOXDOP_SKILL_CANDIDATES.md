# MoxDOP Skill Candidates (Prompt 48)

> **Status:** RESEARCH ARTIFACT — **candidates only. Production Skill implementation: NOT YET.**
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47)
> **Parent artifact:** [`MOXDOP_SKILL_RESEARCH_MATRIX.md`](./MOXDOP_SKILL_RESEARCH_MATRIX.md)
> **Owns:** candidate MoxDOP Skills synthesized by capability, with data, evidence, permissions, abstention, evals, license posture, status

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| Skills implemented from this artifact | **0** |
| Registry / loader / migration changes proposed here | **none** |

Candidates are synthesized **by capability, not one per repository**. Twelve external repositories collapse into twelve capability candidates because the corpus overwhelmingly repeats the same questions with different packaging.

---

## 1. Relationship to shipped MoxDOP Skills

Currently shipped (per `docs/product/AGENT_SKILL_ARCHITECTURE.md` and `app-modules/website/resources/skills/`):

| Shipped Skill | Agent |
| --- | --- |
| `technical-seo-analysis` | Website SEO Analyst |
| `search-console-analysis` | Website SEO Analyst |
| `keyword-opportunity-analysis` | Website SEO Analyst |
| `gsc-search-demand-review` | Website SEO Analyst |
| `ga4-measurement-quality` | Website SEO Analyst |
| `recommendation-framing` | cross-cutting |
| `brand-context-discovery` | Website Brand Discovery Analyst |

Google Ads Analyst ships `account-performance-audit`, `campaign-performance-analysis`, `search-query-analysis`, `measurement-quality-review`, `landing-page-alignment`.

Candidates below either **extend**, **split**, or **add** relative to that baseline. None replaces a shipped Skill.

## 2. Candidate register

| # | Candidate | Capability question | Status | Relationship |
| --- | --- | --- | --- | --- |
| C1 | Website Technical Audit | Is the site structurally sound and observable? | `READY_FOR_NORMALIZATION` | Extends `technical-seo-analysis` |
| C2 | Indexability Analysis | Can search and AI systems reach, read, and index this? | `READY_FOR_NORMALIZATION` | Split out of `technical-seo-analysis` |
| C3 | Metadata Consistency | Do title/meta/head signals agree across sources? | `READY_FOR_NORMALIZATION` | New |
| C4 | Structured Data Audit | Is structured data present, valid, and current? | `NEEDS_PRIMARY_SOURCE_WORK` | New |
| C5 | Internal Linking Analysis | How does link structure distribute access and emphasis? | `NEEDS_DATA` | New |
| C6 | Content Quality Review | Is the content actually useful for the intended audience? | `PLAYBOOK_NOT_SKILL` | Prompt 45 surface |
| C7 | Search Demand Analysis | What demand exists, and where does this site appear? | `READY_FOR_NORMALIZATION` | Aligns with `gsc-search-demand-review` |
| C8 | Query Opportunity Analysis | Which measured positions are worth acting on? | `READY_FOR_NORMALIZATION` | Aligns with `keyword-opportunity-analysis` |
| C9 | Local Profile Completeness | Is the local profile complete and internally consistent? | `NEEDS_DATA` | New |
| C10 | Local Review Intelligence | What is happening in reviews, and how are we responding? | `NEEDS_DATA` | New |
| C11 | Measurement Audit | Can we trust the numbers end to end? | `READY_FOR_NORMALIZATION` | Extends `ga4-measurement-quality` |
| C12 | GEO Observation Analysis | What can we honestly observe about AI-system access? | `EXPERIMENTAL` | New |

## 3. Cross-cutting requirements (apply to every candidate)

| # | Requirement |
| --- | --- |
| X1 | Bounded inputs only — named Evidence/observation types, no free-form external fetching |
| X2 | Explicit `data_availability` per required input (`AVAILABLE` … `HEURISTIC_ONLY`) |
| X3 | Explicit `abstention_rules` — when the Skill reports **not applicable** instead of concluding |
| X4 | Evidence level (A–H) attached to every assertion; output level capped by the lowest input level |
| X5 | Explicit `forbidden_claims` list |
| X6 | **No composite score field.** `score` is not a permitted output |
| X7 | No Task, Finding, Recommendation, or Notification creation |
| X8 | No external write action; no MCP; no crawler; no scheduler; no new provider client |
| X9 | Evidence enters context as untrusted data (prompt-injection defense already in force) |
| X10 | Any paid-provider input stays behind the Prompt 34 cost/freshness guard |
| X11 | Minimum eval set: happy path · missing required evidence · missing ≠ zero · prohibited-claim probe |
| X12 | Method text authored from primary sources; no external prose copied |

Permission model for all candidates: **read-only**, operator-initiated, scoped to a Brand / Customer / DigitalAsset the operator already has access to, under the single `app` panel and `web` guard with `spatie/laravel-permission`. No new permission concept is proposed.

---

## 4. C1 — Website Technical Audit

| Field | Value |
| --- | --- |
| Capability question | Is the site structurally sound, reachable, and correctly configured at the infrastructure and document level? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | claude-seo `seo-technical`; seo-skills `seo-technical-audit`; platinum `tech-audit` (concept only, copy-blocked); aaron `technical-seo-checker`; HEAD subject inventory |
| Required data | Successful fetch of primary URL (HTTP status, headers, HTML); TLS validity/expiry; DNS resolution |
| Optional data | CrUX field CWV; WordPress connector state |
| Source classes | `WEBSITE_DIRECT_OBSERVATION`, `DOMAIN_DNS_TLS`, `PAGESPEED_TECHNICAL` (optional), `WORDPRESS_SITE_CONNECTOR` (optional) |
| Data availability | `AVAILABLE` (required) · `PARTIAL` (CWV field) |
| Evidence levels | A (observations) + D (platform rules) + E (derived counts/deltas) |
| Evidence support | `SUPPORTED` |
| Abstention | Primary URL fetch failed or returned non-content; TLS/DNS lookup failed |
| Forbidden claims | A composite health score; lab metric presented as field measurement; "no issues" when checks did not run; security-header absence framed as a vulnerability |
| Eval cases | Clean site · unreachable host · TLS expiring soon · insufficient CrUX field data (must read as unknown, not good) · prohibited score probe |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES` — method text from Google Search Central and web.dev |
| MoxDOP subjects touched | `WEB_HEALTH_HTTP_STATUS`, `WEB_HEALTH_AVAILABILITY`, `WEB_HEALTH_SECURITY_HEADER`, `WEB_INFRA_TLS`, `WEB_INFRA_DNS`, `WEB_HEALTH_LCP` |

## 5. C2 — Indexability Analysis

| Field | Value |
| --- | --- |
| Capability question | Can search engines and AI systems reach, read, and index this content — and what does the site declare about that? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | claude-seo `seo-technical` / `seo-sitemap`; platinum `robots-policy-audit` (concept only); geo-seo-claude `geo-crawlers` (AI user-agent directives); HEAD (robots/canonical subjects) |
| Required data | `robots.txt` fetch outcome as **three states** (found / absent / error); canonical observation; HTTP status and redirect chain; sitemap fetch outcome; `noindex` meta/header observation |
| Optional data | GSC index coverage signals |
| Source classes | `WEBSITE_DIRECT_OBSERVATION`, `SEARCH_CONSOLE` (optional) |
| Data availability | `AVAILABLE` (declarations) · `PARTIAL` (actual index state) |
| Evidence levels | A + D |
| Evidence support | `PARTIAL` — MoxDOP observes declarations, not Google's index decision |
| Abstention | robots.txt or sitemap fetch **errored** — an error is not "no restrictions" and not "no sitemap" |
| Forbidden claims | That a page is or is not indexed without GSC evidence; that a missing sitemap violates a rule; that canonical is a directive rather than a signal; that AI user-agent permission implies AI usage |
| Eval cases | Site with clean robots + sitemap · robots.txt 500 error (must read unknown) · conflicting canonical chain · `noindex` present with sitemap inclusion · prohibited "not indexed" probe |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| Note | The AI user-agent directive check lives here, **not** in C12, because it is a robots-file fact. C12 consumes it |

## 6. C3 — Metadata Consistency

| Field | Value |
| --- | --- |
| Capability question | Are title, description, and head-level signals present, coherent, and consistent between what the CMS holds and what the page serves? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | **HEAD** (subject inventory + deprecation list); claude-seo `seo-page`; seo-skills `seo-page` (including its documented retrieval-fidelity trap); aaron `on-page-seo-checker`; next-seo (metadata property expectations) |
| Required data | Head-region observation: `title`, meta description, `charset`, `viewport`, heading structure, Open Graph / social tags, canonical, hreflang presence |
| Optional data | CMS/WP SEO field values for conflict detection |
| Source classes | `WEBSITE_DIRECT_OBSERVATION`, `CMS_METADATA` (optional) |
| Data availability | `AVAILABLE` |
| Evidence levels | A (presence/consistency) + D (documented handling) + F (length conventions, labelled) |
| Evidence support | `SUPPORTED` |
| Abstention | The retrieval method demonstrably strips head fields — a fidelity assertion must pass before analysis |
| Forbidden claims | That a character-length band is a platform requirement; that CMS-vs-served disagreement means the CMS value is what users see; that a deprecated vendor tag is required |
| Eval cases | Complete head · missing title · CMS/HTML title conflict (report both provenances) · retrieval-stripped head (run defect, not site defect) · title outside a convention band (advisory, not Finding) |
| License posture | `RESEARCH_ONLY` for HEAD (no license file); element names taken as factual vocabulary and described from WHATWG / Google documentation |
| MoxDOP subjects touched | `WEB_CONTENT_TITLE`, `WEB_CONTENT_META`, `WEB_CONTENT_H`, `WEB_HEALTH_CANONICAL` |

## 7. C4 — Structured Data Audit

| Field | Value |
| --- | --- |
| Capability question | Is structured data present, well-formed, complete for its declared type, and still current? |
| Status | `NEEDS_PRIMARY_SOURCE_WORK` |
| External methodology sources | claude-seo `seo-schema` (including a dated deprecated-types reference); **next-seo** (type/property coverage and test corpus); seo-skills `seo-schema`; aaron `serp-markup-builder`; HEAD (`DEPRECATED.md` pattern) |
| Required data | JSON-LD / microdata blocks extracted from the fetched document; a MoxDOP-verified type catalog with required/recommended properties; a deprecation axis |
| Optional data | CMS schema configuration |
| Source classes | `WEBSITE_DIRECT_OBSERVATION` |
| Data availability | `AVAILABLE` (blocks) · type catalog `REQUIRES_OPERATOR_INPUT`/authoring work before use |
| Evidence levels | A (presence, parse result, property completeness) + D (Schema.org and Google type rules) |
| Evidence support | `SUPPORTED` for presence and completeness; `PARTIAL` for eligibility |
| Abstention | Blocks present but unparseable → report **unparseable**, never absent; type not in the verified catalog → out of scope, not "invalid" |
| Forbidden claims | Rich-result eligibility without current Google documentation; that a retired type is beneficial; that a library's component surface defines platform eligibility |
| Why `NEEDS_PRIMARY_SOURCE_WORK` | The type catalog and, critically, the **deprecation dates** must be read from Google's current documentation. Dated retirement claims are the fastest-ageing facts in the entire corpus, and two external repositories disagree on which types remain useful |
| Eval cases | Valid Organization block · malformed JSON-LD (unparseable ≠ absent) · required property missing · retired type present (flag as retired, do not recommend) · unknown type (out of scope) |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; Schema.org vocabulary and Google documentation are the authority |
| MoxDOP subject touched | `WEB_HEALTH_SCHEMA` |

## 8. C5 — Internal Linking Analysis

| Field | Value |
| --- | --- |
| Capability question | How does internal link structure distribute access and emphasis across the site's pages? |
| Status | `NEEDS_DATA` |
| External methodology sources | platinum `planning/internal-links` (concept only, copy-blocked); marketingskills `site-architecture`; aaron `site-structure-optimizer`; seo-geo-claude-skills `internal-linking-optimizer` |
| Required data | A URL inventory with **known coverage**, plus internal anchor extraction per document |
| Optional data | CMS taxonomy; GSC page-level data for emphasis weighting |
| Source classes | `WORDPRESS_SITE_CONNECTOR` / `WEBSITE_DIRECT_OBSERVATION` + derived |
| Data availability | `REQUIRES_NEW_COLLECTOR` |
| Evidence levels | A (observed links) + E (depth, orphan candidacy) |
| Evidence support | `FUTURE_DATA_REQUIRED` |
| Abstention | Inventory coverage unknown or partial — **no orphan claim is honest without known-complete inventory** |
| Forbidden claims | "Orphan page" from a partial observation; link count as a quality measure; that internal links cause ranking change by a given amount |
| Blocking question for the Architect | Does bounded link extraction belong to the WordPress connector path or to a bounded direct-observation collector, given the no-general-crawler rule? |
| Eval cases | Known-complete small inventory · partial inventory (must refuse orphan claims) · deep-nesting detection · nofollow/internal-redirect handling |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; note that the strongest external treatment is in the AGPL repository and is copy-blocked |

## 9. C6 — Content Quality Review (Playbook-leaning)

| Field | Value |
| --- | --- |
| Capability question | Is this content genuinely useful for the intended audience, and what would make it better? |
| Status | **`PLAYBOOK_NOT_SKILL`** |
| External methodology sources | claude-seo `seo-content` (E-E-A-T method); aaron `content-quality-auditor` (CORE-EEAT gate); seo-skills `seo-content-audit` (60-item + 30-item checklists); marketingskills `copywriting`; localseoskills `local-content-strategy` |
| Required data | Content body observation; Brand Context (audience, offering, positioning) |
| Optional data | GSC query alignment; GA4 behaviour |
| Data availability | `HEURISTIC_ONLY` for the judgment itself |
| Evidence levels | F ceiling |
| Evidence support | `PARTIAL` |
| Abstention | No Brand Context — generic quality advice without positioning is noise |
| Forbidden claims | A numeric quality or E-E-A-T score as a MoxDOP metric; quality judgment as a deterministic Finding; that AI-generated content is inherently penalized |
| **Why Playbook, not Skill** | The external treatments are all *checklists a human works through with guidance*. That shape maps to Prompt 45 `playbooks` / `playbook_revisions` (knowledge + instructions + references, Service Definition applicability, Service Scope resolver) far better than to an AI Skill that emits graded assertions. Routing it as a Skill would create exactly the magic-score pressure that §24 of the parent matrix rejects |
| What may still be a Skill | A narrow **advisory framing** step that helps an operator articulate observations — but the rubric itself belongs to the Playbook |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; quality-rater guidance describes human rater assessment, not a computable score |

## 10. C7 — Search Demand Analysis

| Field | Value |
| --- | --- |
| Capability question | What search demand exists in this space, and where does this site actually appear? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | open-seo keyword research workflow (cost model); seo-skills `seo-keyword-niche` / `seo-keyword-cluster` (credit preflight); aaron `keyword-research`; claude-seo `seo-cluster`; localseoskills `local-keyword-research` |
| Required data | GSC query data for a defined window: queries, impressions, clicks, average position |
| Optional data | DataForSEO volume — level C, **behind the Prompt 34 cost/freshness guard** |
| Source classes | `SEARCH_CONSOLE`, `DATAFORSEO` (optional, governed) |
| Data availability | `AVAILABLE` (GSC) · `PROVIDER_LIMITED` (DataForSEO) |
| Evidence levels | B + E; C for vendor volume, labelled separately |
| Evidence support | `SUPPORTED` |
| Abstention | No authorized GSC connection; window shorter than the analysis requires; provider lag leaves the window incomplete |
| Forbidden claims | Vendor volume presented as demand; GSC impressions compared directly with vendor volume; a demand trend across a window mixing lag states |
| Adopted pattern | **Credit preflight and cost ceiling** from seo-skills — expressed by *reusing* the existing Prompt 34 guard, not by building a second one |
| Eval cases | Healthy GSC window · no GSC connection (not applicable) · vendor volume present with GSC absent (no demand conclusion) · lag-incomplete window · prohibited "true volume" probe |
| License posture | `RESEARCH_ONLY` for the vendor repositories; method authored from GSC documentation |

## 11. C8 — Query Opportunity Analysis

| Field | Value |
| --- | --- |
| Capability question | Which measured query/page positions are close enough to matter, and what is the specific action? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | open-seo striking-distance concept; platinum `quick-wins` (concept only, copy-blocked); claude-seo `seo-plan`; seo-skills `seo-competitor-gap-analysis` |
| Required data | GSC query + position + page mapping over a defined window |
| Optional data | Vendor rank as a separately labelled cross-check |
| Source classes | `SEARCH_CONSOLE`, `DATAFORSEO` (optional, governed) |
| Data availability | `AVAILABLE` |
| Evidence levels | B + E (registered derivation) |
| Evidence support | `SUPPORTED` |
| Abstention | Data volume below the level where position averages are meaningful; page mapping ambiguous |
| Forbidden claims | Predicted traffic gain from a position change (level G at best, H as usually stated); that a vendor rank contradicting GSC means GSC is wrong; a priority score |
| Eval cases | Clear striking-distance set · sparse data (abstain) · ambiguous page mapping · vendor/GSC disagreement (report both) · prohibited traffic-forecast probe |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| MoxDOP subject touched | `D_WEB_STRIKING_DISTANCE` (already a modelled derived subject) |

## 12. C9 — Local Profile Completeness

| Field | Value |
| --- | --- |
| Capability question | Is the local business profile complete, internally consistent, and consistent with the website? |
| Status | `NEEDS_DATA` |
| External methodology sources | **localseoskills** `gbp-optimization` / `local-seo-audit` / `local-citations` / `multi-location-seo` / `service-area-seo`; claude-seo `seo-local` / `seo-maps`; platinum `gbp-audit` (concept only); seo-skills `local-gmb-visibility` |
| Required data | Authorized Google Business Profile field data: categories, hours, attributes, services, products, description, media presence |
| Optional data | Website NAP observation for cross-surface consistency |
| Source classes | `CROSS_ASSET` |
| Data availability | `REQUIRES_NEW_COLLECTOR` |
| Evidence levels | A (field presence) + D (documented field semantics) + F (completeness conventions) |
| Evidence support | `FUTURE_DATA_REQUIRED` |
| Abstention | No authorized GBP data — absence of data is not an incomplete profile |
| Forbidden claims | A completeness percentage as a metric; that an unfilled optional field is a defect; that a category choice causes ranking change; that a missing directory citation is a negative signal |
| Adopted guard | The **doorway-page volume guard** concept from claude-seo — a multi-location recommendation must not become a mass-page tactic |
| Rejected outright | Any GBP write path (posts, replies, field updates); scheduled monitoring; approval tiers |
| Eval cases | Complete profile · no authorized data (not applicable) · optional field empty (observation, not defect) · website/profile NAP mismatch (report conflict) · multi-location volume guard trip |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; Google Business Profile documentation is the authority for field semantics |

## 13. C10 — Local Review Intelligence

| Field | Value |
| --- | --- |
| Capability question | What is happening in this business's reviews, and how well are we responding? |
| Status | `NEEDS_DATA` |
| External methodology sources | **localseoskills** `review-management`; claude-seo review-signal layer; secondary review-scraper repo (**product concept only; scraper rejected**) |
| Required data | Reviews via an **official API only**: volume, rating distribution, timestamps, response presence, response latency |
| Optional data | Operator-supplied context on campaigns or incidents |
| Source classes | `CROSS_ASSET`, `OPERATOR_MAINTAINED` (optional) |
| Data availability | `REQUIRES_NEW_COLLECTOR` |
| Evidence levels | B (counts/timestamps from the official source) + E (velocity, response coverage) + F (sentiment themes) |
| Evidence support | `FUTURE_DATA_REQUIRED` |
| Abstention | No official source available — **scraping is rejected**, so the capability simply does not run |
| Forbidden claims | Sentiment presented as measurement; that review velocity causes ranking change; a reputation score; competitor review comparisons from unofficial sources |
| Rejected outright | Review scraping; automated review-response publishing; drafted-then-executed reply queues |
| Eval cases | Official data present · no official source (not applicable) · zero reviews vs no data (must differ) · response latency computation · prohibited reputation-score probe |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES` |

## 14. C11 — Measurement Audit

| Field | Value |
| --- | --- |
| Capability question | Can we trust the numbers — is measurement configured, connected, and internally coherent? |
| Status | `READY_FOR_NORMALIZATION` |
| External methodology sources | marketingskills `attribution`; localseoskills `google-analytics-tool` / `google-search-console-tool`; aaron `conversion-signal-qa` (paid-media analogue); Google Ads Analyst `measurement-quality-review` (already shipped, same discipline) |
| Required data | GA4 configuration and data availability; GSC property linkage; website observation of tag presence |
| Optional data | Ads linkage; conversion definitions |
| Source classes | `GA4`, `SEARCH_CONSOLE`, `WEBSITE_DIRECT_OBSERVATION` |
| Data availability | `AVAILABLE` per leg; overall `PARTIAL` depending on connections |
| Evidence levels | A + B |
| Evidence support | `PARTIAL` |
| Abstention | Any leg missing — and the output must **name the missing leg** rather than degrade silently |
| Forbidden claims | That GSC clicks and GA4 sessions should reconcile; that a discrepancy between them is necessarily a defect; a data-quality score |
| Why this matters most | This is the capability where "vendor estimate ≠ first-party measurement" does the most work. The external corpus routinely blends GSC, GA4, and vendor figures into one view, which is the single most common integrity failure observed |
| Eval cases | All legs connected · GA4 missing (name the gap) · tag present but property unlinked · GSC/GA4 divergence (explain, do not flag) · prohibited reconciliation probe |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; GA4 and GSC documentation are the authority |

## 15. C12 — GEO Observation Analysis (`EXPERIMENTAL`)

| Field | Value |
| --- | --- |
| Capability question | What can MoxDOP honestly observe today about AI-system access to this site — and what can it not? |
| Status | **`EXPERIMENTAL`** |
| External methodology sources | geo-seo-claude `geo-crawlers` / `geo-llmstxt` / `geo-citability` / `geo-schema`; claude-seo `seo-geo` (myth reframes and primary-source posture); aaron `geo-content-optimizer`; seo-skills `seo-geo` / AI share-of-voice; platinum `geo-analysis` / `aio-competitor-map` (concept only) |
| Required data | AI/LLM user-agent directives from `robots.txt` (consumed from C2); structured data presence (from C4); llms.txt fetch outcome as three states |
| Optional data | Entity presence on named third-party pages — level A **per fetched page only**, never as "entity strength" |
| Source classes | `WEBSITE_DIRECT_OBSERVATION` |
| Data availability | `AVAILABLE` for the three observables · `NOT_AVAILABLE` for mention, citation, and AI Overview appearance · `HEURISTIC_ONLY` for citability structure |
| Evidence levels | A for observables; F for citability structure heuristics |
| Evidence support | `PARTIAL` for observables; **`UNSUPPORTED`** for mention/citation/AI Overview |
| Mandatory vocabulary | The six-way disambiguation in parent matrix §25: AI bot accessibility · AI mention · AI citation · AI Overview appearance · entity presence · citability heuristic. These are **never** summed or blended |
| Abstention | Any question about AI mentions, citations, or AI Overview appearance — MoxDOP has no data source and must say so |
| Forbidden claims | A GEO score of any kind; that llms.txt presence improves citation (contested, no primary source); any percentage uplift attributed to GEO work; that an AI surface was measured when it was at best sampled; that a citability word band is a requirement |
| Sampling requirement (if a collector ever exists) | Record surface/product, model or version identifier if exposed, locale, language, query text, timestamp, and sampling method — otherwise the observation is uninterpretable and therefore not Evidence |
| Eval cases | AI user-agents allowed vs disallowed · llms.txt absent vs fetch error · prohibited GEO-score probe · prohibited "how many AI citations do we have" probe (must abstain) · citability hint phrased as advisory |
| License posture | `REEXPRESS_FROM_PRIMARY_SOURCES`; where no primary source exists, the check is either level A observation or withheld |
| Open Architect question | Ship as `EXPERIMENTAL` with level A observables only, or defer entirely until the AI-surface data question is answered? |

## 16. Candidate summary matrix

| # | Candidate | Data availability (worst required) | Evidence support | Max evidence level | Status | License posture |
| --- | --- | --- | --- | --- | --- | --- |
| C1 | Website Technical Audit | `AVAILABLE` | `SUPPORTED` | A + D + E | `READY_FOR_NORMALIZATION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C2 | Indexability Analysis | `AVAILABLE` | `PARTIAL` | A + D | `READY_FOR_NORMALIZATION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C3 | Metadata Consistency | `AVAILABLE` | `SUPPORTED` | A + D (+F labelled) | `READY_FOR_NORMALIZATION` | `RESEARCH_ONLY` (HEAD) + primary sources |
| C4 | Structured Data Audit | `AVAILABLE` (catalog work pending) | `SUPPORTED` / `PARTIAL` | A + D | `NEEDS_PRIMARY_SOURCE_WORK` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C5 | Internal Linking Analysis | `REQUIRES_NEW_COLLECTOR` | `FUTURE_DATA_REQUIRED` | A + E | `NEEDS_DATA` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C6 | Content Quality Review | `HEURISTIC_ONLY` | `PARTIAL` | F | `PLAYBOOK_NOT_SKILL` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C7 | Search Demand Analysis | `AVAILABLE` | `SUPPORTED` | B + E | `READY_FOR_NORMALIZATION` | `RESEARCH_ONLY` + primary sources |
| C8 | Query Opportunity Analysis | `AVAILABLE` | `SUPPORTED` | B + E | `READY_FOR_NORMALIZATION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C9 | Local Profile Completeness | `REQUIRES_NEW_COLLECTOR` | `FUTURE_DATA_REQUIRED` | A + D | `NEEDS_DATA` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C10 | Local Review Intelligence | `REQUIRES_NEW_COLLECTOR` | `FUTURE_DATA_REQUIRED` | B + E + F | `NEEDS_DATA` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C11 | Measurement Audit | `AVAILABLE` | `PARTIAL` | A + B | `READY_FOR_NORMALIZATION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| C12 | GEO Observation Analysis | `AVAILABLE` (observables) / `NOT_AVAILABLE` (AI surfaces) | `PARTIAL` / `UNSUPPORTED` | A (+F labelled) | `EXPERIMENTAL` | `REEXPRESS_FROM_PRIMARY_SOURCES` |

**Rejected as candidates entirely:** magic-score skills of any kind; installer/MCP/crawler skills; scheduler and task-automation skills; external write skills (indexing ping, GBP posting, review replies); vendor-transport skills; agency-sales skills (proposal, prospecting, white-label reporting); second-provider-stack skills; frozen-line skills.

## 17. Proposed Skill schema fields (Prompt 49 input)

Derived by intersecting the `addyosmani/agent-skills` anatomy with the MoxDOP ontology in `docs/product/AGENT_SKILL_ARCHITECTURE.md`. **Proposal only — no schema change in Prompt 48.**

| Field | Source of the idea | Purpose |
| --- | --- | --- |
| `slug`, `version` | MoxDOP (already present) | Identity and versioning in Run provenance |
| `purpose` | MoxDOP + agent-skills `description` | One-sentence capability statement |
| `triggers[]` | agent-skills "Use when…" | When the Skill is appropriate |
| `not_for[]` | Agent Reach (prior audit) + agent-skills | Negative scope, prevents over-firing |
| `lifecycle_phase` | aaron (survey/implement/tune/evaluate) | Catalog metadata |
| `required_evidence[]` / `optional_evidence[]` | MoxDOP (already present) | Eligibility mechanics |
| `data_availability{}` | aaron tiering + MoxDOP contracts | Honest capability statement per input |
| `method_steps[]` | agent-skills Process | The workflow |
| `output_shape` | claude-seo falsifiable frame | Observation → why → action → dependencies → success signal → failure signal → watch metrics |
| `evidence_requirements[]` | agent-skills Verification | Per-assertion evidence level A–H |
| `verification[]` | agent-skills | Exit criteria |
| `abstention_rules[]` | MoxDOP (missing ≠ zero) | When to report not applicable |
| `forbidden_claims[]` | MoxDOP | Claims the Skill may never make |
| `anti_rationalizations[]` | agent-skills | Excuse/rebuttal pairs |
| `red_flags[]` | agent-skills | Signs the run is going wrong |
| `eval_cases[]` | agent-skills evals + seo-skills examples | Measurable quality |
| `references[]` | agent-skills progressive disclosure | Lazily loaded primary-source references |

Explicitly **not** a field: `score`, `grade`, `health`, `readiness_percent`, or any composite.

## 18. Sequencing guidance (documentation only)

| Order | Work | Why |
| --- | --- | --- |
| 1 | Normalize the Skill schema (§17) | Everything else depends on the shape |
| 2 | C1, C2, C3 | Data already available; existing subjects already modelled; highest confidence |
| 3 | C7, C8, C11 | Data available; align with already-shipped Skills |
| 4 | C4 | Requires a verified type + deprecation catalog first |
| 5 | C6 routing decision | Architect decides Playbook vs Skill before any authoring |
| 6 | C5, C9, C10 | Documentation only until the data question is answered |
| 7 | C12 | Only if `EXPERIMENTAL` labelling is accepted; abstention-first |

## 19. Limitations

| Limitation | Effect |
| --- | --- |
| Candidates are not specifications | No prompts, no schema files, no code; Prompt 49 owns normalization |
| Status reflects MoxDOP at Prompt 47 HEAD | Availability changes as collectors land; re-read before relying on statuses |
| Capability boundaries are proposals | C1/C2 split and C6 routing are open Architect questions |
| Evidence levels are MoxDOP's assignment | External sources do not label evidence; the mapping is interpretive by design |
| Primary-source verification must be repeated | Parent matrix §31 records authority; Prompt 49 must re-read before catalog text, especially anything dated |
| Not every external skill file was read | Additional capability ideas may exist in the corpus |
| Legal notes are **not legal advice** | See [`EXTERNAL_SKILL_LICENSE_PROVENANCE.md`](./EXTERNAL_SKILL_LICENSE_PROVENANCE.md) |
