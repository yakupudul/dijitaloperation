# Google Ads Intelligence + Analyst V1

> Status: **IMPLEMENTED V1** (read-only operational intelligence)  
> Module: `app-modules/google-ads`  
> Related: [`GOOGLE_ADS.md`](./GOOGLE_ADS.md) · [`../AGENT_SKILL_ARCHITECTURE.md`](../AGENT_SKILL_ARCHITECTURE.md) · [`../AI_CONTROL_PLANE.md`](../AI_CONTROL_PLANE.md)

## Purpose

Turn the Google Ads Digital Asset into a reusable Agent + Skill intelligence domain (second operational Agent after Website SEO Analyst), without mutating Google Ads.

## IMPLEMENTED V1

| Piece | Detail |
| --- | --- |
| **Google Ads Analyst** | `google_ads.analyst` @ `1.0.0` → route `google_ads.ai_guidance` |
| **Skills (5)** | `account-performance-audit`, `campaign-performance-analysis`, `search-query-analysis`, `measurement-quality-review`, `landing-page-alignment` under `app-modules/google-ads/resources/skills/` |
| **AI Route** | `google_ads.ai_guidance` — default OpenAI / `gpt-5-mini` via AI Control Plane (failover preserved) |
| **Evidence reused** | `google_ads_account_summary`, `google_ads_campaign_performance`, `google_ads_landing_final_urls` |
| **New Evidence** | `google_ads_search_term_performance`, `google_ads_conversion_actions` |
| **Deterministic Findings** | Existing performance rules + search-term waste/opportunity **candidates** + measurement config risk + landing coverage risk |
| **AI Guidance** | Grounded structured output + human Recommendation gate |
| **Workspace UX** | Overview · Performance · Search terms · Intelligence · Connections · Activity |

## Evidence inventory (V1)

| Type | Purpose | Aggregation | Notes |
| --- | --- | --- | --- |
| `google_ads_account_summary` | Account spend/clicks/conversions + prior period deltas | Account / 28-day windows | Money from micros |
| `google_ads_campaign_performance` | Campaign delivery metrics | Campaign | Channel/status when available |
| `google_ads_landing_final_urls` | Final URL coverage | Ad final URLs | Compat with Website↔Ads check |
| `google_ads_search_term_performance` | Search + PMax search terms | Term × campaign (± ad group) | `search_term_view` + `campaign_search_term_view` (PMax); untrusted text; bounded |
| `google_ads_conversion_actions` | Conversion **configuration** | Conversion action | No tag snippets; not browser validation |

API: Google Ads REST **v25** (config `moxdop.google.ads_api_version`), GAQL via `googleAds:search`, MCC `login-customer-id`.

## Search-term safety

- Search terms / campaign names / URLs are **UNTRUSTED Evidence**.
- AI context ranks/bounds rows (spend/clicks) — never dumps full account history.
- Failed/partial search-term collection stores `response_ok=false` and does **not** evaluate search-term Finding rules (prior Findings stay open).
- PMax uses `campaign_search_term_view`; ad_group and targeting_status are not fabricated.

## Measurement limitations

Configuration Evidence ≠ real event validation. MoxDOP does **not** prove tags, consent mode, GTM, or CRM accuracy in this milestone.

## Skills → Agency Agents methodology (reference only)

| Skill | Methodology reference |
| --- | --- |
| account-performance-audit | Paid Media Auditor |
| campaign-performance-analysis | PPC Campaign Strategist |
| search-query-analysis | Search Query Analyst |
| measurement-quality-review | Tracking & Measurement Specialist |
| landing-page-alignment | Ad Creative / landing alignment patterns |

**Agency Agents runtime is NOT imported.**

## Absolute non-goals (still forbidden)

- Google Ads writes (bids, budgets, keywords, negatives, ads, assets, conversions)
- Autopilot optimization / auto-apply provider recommendations
- Playbooks, Capability Router, Discovery runtime, RAG
- Recommendation Reviewer AI, Meta Ads, GBP Reputation, GEO expansion
- Claiming Ads conversions = business profit/ROI without CRM linkage

## Capability metadata

Skills declare `required_capabilities` / `optional_capabilities` (e.g. `google-ads.read`, optional `website.content.read`).  
**Capability Router remains NOT IMPLEMENTED** — metadata only; no automatic external access.
