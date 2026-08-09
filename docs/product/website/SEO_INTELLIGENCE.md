# Website SEO Intelligence (DataForSEO Light V1)

## Purpose

Small, high-value external keyword intelligence for Website operators — not a full SEO suite.

Answers:

1. Which commercially/relevantly important keywords does this Website currently rank for?
2. Where does it rank?
3. What is the estimated search demand?
4. Which relevant keyword opportunities exist outside current GSC visibility?
5. Which external SEO opportunities deserve investigation?

## Architecture

```
Website + DataForSEO Integration + SEO market config
  → Website DataForSEO collectors (app-modules/website/SeoIntelligence)
  → Core Run / normalized Evidence
  → Website Performance presenters + cross-source opportunities
```

- Shared DataForSEO client remains provider infrastructure (`app/Services/Integrations/DataForSeo`)
- SEO semantics belong in the Website module
- No DataForSEO-specific analytics tables
- No Core ranked-keyword domain models

## SEO market

Website → Settings → **Search market**

- Country + Language (searchable selects)
- Resolved via free `GET /v3/dataforseo_labs/locations_and_languages`
- Operators never enter raw `location_code` / `language_code`
- Stored: human names + stable provider identifiers on `digital_assets`

Paid queries never silently default to an arbitrary market.

## Collectors & Evidence

### Ranked Keywords

- Endpoint: `POST /v3/dataforseo_labs/google/ranked_keywords/live`
- Organic only (`item_types: ["organic"]`)
- No clickstream enrichment in V1
- Bounded limit (default 100; config `moxdop.seo_intelligence.ranked_keywords.limit`)
- Evidence:
  - `dataforseo_ranked_keywords_summary`
  - `dataforseo_ranked_keywords`

### Keywords For Site

- Endpoint: `POST /v3/dataforseo_labs/google/keywords_for_site/live`
- `include_serp_info = false`, no clickstream
- Bounded limit (default 100)
- Evidence: `dataforseo_keyword_opportunities`

## Measured GSC vs estimated DataForSEO

| Source | Meaning |
| --- | --- |
| GA4 / GSC | Measured performance Evidence |
| DataForSEO `etv` / traffic value | **Estimated** provider metrics |

UI labels use “Estimated organic traffic” — never “Organic users”.

## Cost guard

Both paid collectors use:

1. Canonical request parameters
2. `PaidRequestFingerprint`
3. `EvidenceFreshnessGuard` (`HIT_FRESH` → skip provider)
4. `PaidRequestExecutor` fingerprint lock (duplicate-click protection)
5. Provider-reported `cost` stored on Run metadata
6. Capability-specific `fresh_until`

## TTL policy (MoxDOP, not DataForSEO)

| Capability | Default TTL | Reasoning |
| --- | --- | --- |
| Ranked keywords | 5 days | Source data updates weekly |
| Keywords for site | 7 days | Keyword DB metrics do not need minute-level refresh |

Centralized in `config/moxdop.php` → `seo_intelligence.*`.

## Paid refresh UX

- **Not** part of generic **Refresh data** (GA4/GSC)
- Secondary action: **Refresh SEO intelligence**
- Confirmation before paid MISS
- If both datasets fresh: reuse Evidence, 0 provider calls, $0 cost
- Cache HIT creates provenance Runs without duplicating Evidence

## Opportunity heuristics

Cross-source view-model (exact/case-normalized keyword match only):

| Category | Meaning |
| --- | --- |
| NEW OPPORTUNITY | Relevant in DataForSEO; not in current GSC window / ranked keywords |
| VISIBLE BUT WEAK | Meaningful demand; rank band 11–30 |
| EXISTING VISIBILITY | Already in GSC or ranked keywords |

Priority labels: **High opportunity** / **Medium opportunity** (transparent rules — not a fake 0–100 SEO score).

Phrase GSC absence as: “Not observed in the current GSC Evidence window.”

## Findings

V1 creates **zero** new Finding types. Keyword intelligence stays on Performance as analytical opportunities. No Finding-per-keyword.

## Explicit non-goals

Rank tracking over time, scheduler, backlinks, competitors, SERP Live, OnPage, AI recommendations, keyword seed research UI, bulk import, content gap crawler, fake SEO health score, force-paid prominent UI, paid calls on page render.
