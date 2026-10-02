# Normalized Skill Catalog (Prompt 49)

> **Status:** Authoritative inventory of shipped MoxDOP Skills after Prompt 49 normalization  
> **Count:** **21**  
> **Storage:** Markdown `SKILL.md` under module `resources/skills/` — **no** Skills DB table, **no** SkillV2  
> **Contract:** [`SKILL_DEFINITION_SPEC.md`](./SKILL_DEFINITION_SPEC.md) · [`MOXDOP_SKILL_NORMALIZATION.md`](../implementation/MOXDOP_SKILL_NORMALIZATION.md)  
> **No fake runtime stats** — this catalog records definitions only (no run counts, scores, or success rates)

Stable key = `module.slug`. Signature = `module.slug@version`.

---

## Summary

| Module | Skills | Versions |
| --- | --- | --- |
| website | 9 | 1.0.0 (new C2/C3) or 1.1.0 |
| google-ads | 5 | 1.1.0 (contract upgrade) |
| meta-ads | 5 | 1.1.0 (contract upgrade) |
| google-business-profile | 2 | 1.1.0 (contract upgrade) |

**Prompt 48 READY normalized:** C1→`technical-seo-analysis@1.1.0`, C2→`indexability-analysis@1.0.0`, C3→`metadata-consistency@1.0.0`, C7→`gsc-search-demand-review@1.1.0`, C8→`keyword-opportunity-analysis@1.1.0`, C11→`ga4-measurement-quality@1.1.0`.

**Deferred (not listed as Skills):** C4 NEEDS_PRIMARY_SOURCE_WORK; C5/C9/C10 NEEDS_DATA; C6 PLAYBOOK_NOT_SKILL; C12 EXPERIMENTAL; license-blocked platinum copy.

---

## Website

### website.technical-seo-analysis

| Field | Value |
| --- | --- |
| Name | Technical SEO Analysis |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Interpret bounded HTTP, document, and infrastructure Evidence for reachability and structural configuration — without composite health scores. |
| Required Evidence | `page_html` (PRIMARY_FACT, ABSTAIN if missing) |
| Optional Evidence | `http_fetch`, `technical_any` |
| Allowed conclusions | Reachability/HTTP outcomes; document structure issues; TLS/DNS only when present; field vs lab CWV distinction; explicit uncertainty when optional infra absent |
| Research source | Prompt 48 **C1** Website Technical Audit |
| Primary references | Google Search Central crawling/indexing; web.dev CWV/CrUX; MDN/WHATWG HTTP/document semantics |

### website.indexability-analysis

| Field | Value |
| --- | --- |
| Name | Indexability Analysis |
| Version | 1.0.0 |
| Domain / module | website |
| Status | active |
| Purpose | Interpret robots, sitemap, canonical, and noindex Evidence as declared crawl/index access signals — without asserting live indexation state from declarations alone. |
| Required Evidence | `page_html` |
| Optional Evidence | `robots`, `sitemap`, `http_fetch`, `search_console_performance` |
| Allowed conclusions | robots.txt directives as file facts (incl. AI UA lines); three-state robots/sitemap outcomes; canonical/noindex as signals not hard guarantees |
| Research source | Prompt 48 **C2** Indexability Analysis |
| Primary references | Google Search Central robots.txt; Google Search Central sitemaps |

### website.metadata-consistency

| Field | Value |
| --- | --- |
| Name | Metadata Consistency |
| Version | 1.0.0 |
| Domain / module | website |
| Status | active |
| Purpose | Assess title, description, and head-level signal presence/coherence across observed HTML (and optional CMS fields) without treating length bands as platform requirements. |
| Required Evidence | `page_html` |
| Optional Evidence | `technical_any` |
| Allowed conclusions | Presence/absence of title/meta/charset/viewport/headings/OG/canonical/hreflang; CMS vs HTML conflicts when both provenances exist; heuristic length notes labelled non-Finding-grade |
| Research source | Prompt 48 **C3** Metadata Consistency |
| Primary references | Google Search Central title links/snippets; WHATWG HTML document metadata |

### website.gsc-search-demand-review

| Field | Value |
| --- | --- |
| Name | GSC Search Demand Review |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Interpret Search Console query/page Evidence as first-party organic demand and appearance intelligence for a defined window — without treating impressions as market volume or average position as exact rank. |
| Required Evidence | `search_console_performance` |
| Optional Evidence | `dataforseo_any` |
| Allowed conclusions | Demand/appearance observations from GSC rows; momentum/ownership/discoverability **candidates**; vendor volume only as labelled market context |
| Research source | Prompt 48 **C7** Search Demand Analysis |
| Primary references | Google Search Console Help performance metrics; Search Console API searchanalytics docs |

### website.keyword-opportunity-analysis

| Field | Value |
| --- | --- |
| Name | Keyword Opportunity Analysis |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Identify bounded query/page opportunities from measured GSC positions (optional labelled vendor rank) without forecasting traffic or ranking gains. |
| Required Evidence | `gsc_any` |
| Optional Evidence | `search_console_performance`, `dataforseo_any` |
| Allowed conclusions | Striking-distance/opportunity candidates from measured positions; bounded non-scored prioritization; Brand offering alignment when Brand Context present |
| Research source | Prompt 48 **C8** Query Opportunity Analysis |
| Primary references | GSC Help performance / average position; Google Search Central helpful content guidance |

### website.ga4-measurement-quality

| Field | Value |
| --- | --- |
| Name | GA4 Measurement Quality |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Assess whether GA4 (and related) measurement legs are connected and coherent enough to trust — without reconciling GSC↔GA4 into one truth or equating key events to business outcomes. |
| Required Evidence | `ga4_events` |
| Optional Evidence | `search_console_performance`, `page_html` |
| Allowed conclusions | Measurement availability/coherence observations; named gaps; Business Action mapping **candidates** distinct from raw event names |
| Research source | Prompt 48 **C11** Measurement Audit |
| Primary references | Google Analytics Help GA4 events/key events; Analytics Data API event reporting semantics |

### website.search-console-analysis

| Field | Value |
| --- | --- |
| Name | Search Console Analysis |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Interpret normalized GSC Evidence for query/page performance Findings without inventing metrics or exact ranks. |
| Required Evidence | `gsc_any` |
| Optional Evidence | `search_console_performance` |
| Allowed conclusions | Interpretation of supplied query/page Findings; non-scored prioritization among open GSC Findings; watch signals tied to present GSC metrics |
| Research source | `existing-canonical-pre-prompt-48` (contract upgrade) |
| Primary references | Official Google Search Console documentation; GSC Help performance metrics |

### website.recommendation-framing

| Field | Value |
| --- | --- |
| Name | Recommendation Framing |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Turn supported observations into actionable, measurable Recommendation Guidance drafts without auto-creating Tasks, Findings, or Recommendations. |
| Required Evidence | (none — operates on selected Finding/Evidence IDs already in Agent context; abstains when unsupported) |
| Optional Evidence | (none) |
| Allowed conclusions | Actionable drafts tied to Finding/Evidence IDs; qualitative non-scored priority/effort estimates |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | MoxDOP MASTER_SPEC / AI Insights rules; AGENT_SKILL_ARCHITECTURE advisory Recommendation workflow |

### website.brand-context-discovery

| Field | Value |
| --- | --- |
| Name | Brand Context Discovery |
| Version | 1.1.0 |
| Domain / module | website |
| Status | active |
| Purpose | Guide bounded Brand Context inference proposals from public Website Discovery Evidence for human review — without mutating Brand Context automatically. |
| Required Evidence | `website_public_site_summary` |
| Optional Evidence | (none listed) |
| Allowed conclusions | Proposed business summary/positioning/differentiator/audience/market inferences; optional consolidation naming for duplicated service labels |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | MoxDOP DISCOVERY_INTELLIGENCE.md; BRAND_INTELLIGENCE.md |

---

## Google Ads

### google-ads.account-performance-audit

| Field | Value |
| --- | --- |
| Name | Account Performance Audit |
| Version | 1.1.0 |
| Domain / module | google-ads |
| Status | active |
| Purpose | Understand overall Google Ads account performance/context and identify evidence-supported risk/opportunity areas. |
| Required Evidence | `google_ads_account_summary` |
| Allowed conclusions | Evidence-supported account risk/opportunity areas; prioritization of open performance Findings; advisory next steps for a human operator outside MoxDOP |
| Research source | `existing-canonical-pre-prompt-48` (1.1.0 contract upgrade) |
| Primary references | Agency Agents Paid Media Auditor (methodology reference only); Official Google Ads API reporting fields |

### google-ads.campaign-performance-analysis

| Field | Value |
| --- | --- |
| Name | Campaign Performance Analysis |
| Version | 1.1.0 |
| Domain / module | google-ads |
| Status | active |
| Purpose | Analyze campaign-level delivery/performance using normalized campaign Evidence. |
| Required Evidence | `google_ads_campaign_performance` |
| Allowed conclusions | Grounded campaign delivery/performance interpretation; human-actionable investigation steps (no mutate automation) |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents PPC Campaign Strategist (reference only); Google Ads API campaign resource fields |

### google-ads.search-query-analysis

| Field | Value |
| --- | --- |
| Name | Search Query Analysis |
| Version | 1.1.0 |
| Domain / module | google-ads |
| Status | active |
| Purpose | Analyze actual user search terms for waste and opportunity candidates when search-term Evidence exists. |
| Required Evidence | `google_ads_search_term_performance` |
| Allowed conclusions | Bounded waste/opportunity candidates with Evidence IDs and period; human review guidance for negatives/keywords outside MoxDOP |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Search Query Analyst (reference only); Google Ads API search_term_view fields |

### google-ads.measurement-quality-review

| Field | Value |
| --- | --- |
| Name | Measurement Quality Review |
| Version | 1.1.0 |
| Domain / module | google-ads |
| Status | active |
| Purpose | Interpret available conversion/measurement configuration Evidence without pretending MoxDOP has browser/GTM access. |
| Required Evidence | `google_ads_conversion_actions` |
| Allowed conclusions | Configuration gaps/risks grounded in Evidence; explicit uncertainty about event validation |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Tracking & Measurement Specialist (reference only); Google Ads API conversion_action resource |
| Forbidden semantic note | Must not equate conversions with qualified leads without explicit mapping |

### google-ads.landing-page-alignment

| Field | Value |
| --- | --- |
| Name | Landing Page Alignment |
| Version | 1.1.0 |
| Domain / module | google-ads |
| Status | active |
| Purpose | Evaluate Google Ads landing/final-URL Evidence in context of campaign/search intent and available Brand Context. |
| Required Evidence | `google_ads_landing_final_urls` |
| Allowed conclusions | Coverage/alignment risks grounded in Evidence; human review of landing relevance as Recommendation candidates |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Ad Creative Strategist / landing alignment methodology (reference only) |

---

## Meta Ads

### meta-ads.account-performance-audit

| Field | Value |
| --- | --- |
| Name | Account Performance Audit |
| Version | 1.1.0 |
| Domain / module | meta-ads |
| Status | active |
| Purpose | Understand overall Meta Ads account performance/context and identify evidence-supported risk/opportunity areas. |
| Required Evidence | `meta_ads_account_summary` |
| Allowed conclusions | Evidence-supported account risk/opportunity areas; prioritization of open performance Findings; advisory next steps outside MoxDOP |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Paid Media Auditor (reference only); Official Meta Marketing API insights fields |

### meta-ads.campaign-performance-analysis

| Field | Value |
| --- | --- |
| Name | Campaign Performance Analysis |
| Version | 1.1.0 |
| Domain / module | meta-ads |
| Status | active |
| Purpose | Analyze campaign-level delivery/performance using normalized campaign Evidence. |
| Required Evidence | `meta_ads_campaign_performance` |
| Allowed conclusions | Grounded campaign delivery/performance interpretation; human-actionable investigation steps (no mutate automation) |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents PPC Campaign Strategist (reference only); Meta Marketing API campaign insights fields |

### meta-ads.adset-delivery-analysis

| Field | Value |
| --- | --- |
| Name | Ad Set Delivery Analysis |
| Version | 1.1.0 |
| Domain / module | meta-ads |
| Status | active |
| Purpose | Analyze ad set delivery, audience/placement signals, and spend efficiency using normalized ad set Evidence. |
| Required Evidence | `meta_ads_adset_performance` |
| Allowed conclusions | Grounded ad set delivery/efficiency interpretation; human review guidance for targeting/placements/budget outside MoxDOP |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Paid Social Delivery Analyst (reference only); Meta Marketing API ad set insights fields |

### meta-ads.ad-creative-performance-analysis

| Field | Value |
| --- | --- |
| Name | Ad Creative Performance Analysis |
| Version | 1.1.0 |
| Domain / module | meta-ads |
| Status | active |
| Purpose | Analyze ad-level creative and delivery performance using normalized ad Evidence, with optional bounded creative metadata. |
| Required Evidence | `meta_ads_ad_performance` |
| Allowed conclusions | Grounded creative performance interpretation with bounded copy references; human review guidance for creative iteration outside MoxDOP |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Ad Creative Strategist (reference only); Meta Marketing API ad insights fields |

### meta-ads.measurement-result-review

| Field | Value |
| --- | --- |
| Name | Measurement Result Review |
| Version | 1.1.0 |
| Domain / module | meta-ads |
| Status | active |
| Purpose | Interpret available Meta actions/results and measurement context without pretending MoxDOP has pixel/CAPI validation or CRM access. |
| Required Evidence | `meta_ads_campaign_performance` |
| Allowed conclusions | Measurement/result interpretation gaps grounded in Evidence; explicit uncertainty about event validation and business outcome linkage |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Agency Agents Tracking & Measurement Specialist (reference only); Meta Marketing API action/result breakdown fields |
| Forbidden semantic note | Must not collapse typed `action_type` values into a generic Result |

---

## Google Business Profile

### google-business-profile.local-presence-audit

| Field | Value |
| --- | --- |
| Name | Local Presence Audit |
| Version | 1.1.0 |
| Domain / module | google-business-profile |
| Status | active |
| Purpose | Review GBP profile consistency against Brand Context and Website Evidence without inventing a composite local visibility metric. |
| Required Evidence | `gbp_location_profile` |
| Allowed conclusions | Consistency conflicts grounded in Evidence; attention candidates with clear required human input |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Official Google Business Profile APIs (read) |

### google-business-profile.review-pulse-analysis

| Field | Value |
| --- | --- |
| Name | Review Pulse Analysis |
| Version | 1.1.0 |
| Domain / module | google-business-profile |
| Status | active |
| Purpose | Summarize GBP review topics and response queue candidates without auto-sending replies. |
| Required Evidence | `gbp_reviews` |
| Allowed conclusions | Response queue candidates with provenance; topic pulse grounded in observed reviews |
| Research source | `existing-canonical-pre-prompt-48` |
| Primary references | Official Google Business Profile review resources (read) |

---

## Not in catalog (explicit)

| Item | Reason |
| --- | --- |
| C4 Structured Data Audit | NEEDS_PRIMARY_SOURCE_WORK |
| C5 Internal Linking Analysis | NEEDS_DATA |
| C6 Content Quality Review | PLAYBOOK_NOT_SKILL |
| C9 / C10 Local profile/review Skills | NEEDS_DATA |
| C12 GEO Observation Analysis | EXPERIMENTAL — not shipped |
| Platinum / third-party prompt copies | License-blocked / do not copy |
| Runtime success rates / scores | Not tracked in Prompt 49 |

AI Skill **execution** remains Prompt 50.
