# Google Ads Real Data Migration (Prompt 30)

## 1. Purpose

Prompt 30 migrates the frozen Google Ads Paid Acquisition specialist workspace
(`OverviewPage`, path `/app/.../google-ads` / demo route `demo.google-ads.overview`)
from deterministic Demo fixtures to **read-only presentation** of the normalized
Google Ads data pool populated by Prompt 19 and gated by Prompt 26 (integrity) +
Prompt 27 (freshness/materialization).

Hard rules enforced:

- **No live Google Ads Search / SearchStream / OAuth / discovery on page render**
- **No Demo fallback on query exceptions**
- **Account KPIs from `google_ads_account_daily` ONLY** — never campaign/keyword/term sums
- **`cost_amount` is already normalized** — never divide `cost_micros` again; no FX
- **Customer timezone + currency are source truth**
- **Keyword ≠ Search Term**; PMax terms use `campaign_search_term_view` grain — no fake AdGroup/Keyword
- **Search term impressions ≠ market search volume**
- **AssetGroup ≠ AdGroup**; Ads ≠ Assets; no fabricated PMax AssetGroups as ads
- **Landing pages from Google Ads pool only** — not GA4/GSC/Website
- **`conversions` ≠ `all_conversions`**; conversion ≠ Qualified Lead / Business Outcome / revenue
- **CPA / agency pacing UNAVAILABLE** without canonical mapping / plan store
- **No Evidence / Findings / Opportunities / Recommendations created**
- Demo catalog asset `gads-atlas` remains 100% Demo

## 2. Architecture

```
OverviewPage (Livewire)
    └── GoogleAdsSpecialistReadService::workspace(assetId, preset, start, end)
            ├── GoogleAdsSpecialistBindingResolver → BindingContext
            ├── GoogleAdsUiDatasetGate → DatasetReadiness (integrity + coverage + freshness)
            ├── GoogleAdsPoolReadRepository → bounded SQL over google_ads_*
            └── GoogleAdsFormulaCalculator → FORMULA_GADS_*
```

Binding capability: `google_ads` → `GOOGLE_ADS_CUSTOMER` ExternalResource (`external_id` = customer id).
Managers are rejected as analytical roots. No first-accessible / domain heuristic.

Repository API version: **v25** (`config('moxdop.google.ads_api_version')`).

## 3. Provider verification (2026-08-13 / reconfirmed for Prompt 30)

| Topic | Official / contract | Compatibility |
|---|---|---|
| Search / SearchStream | Google Ads API REST search + streaming docs | Contract uses both; UI calls: **0** |
| SearchTermView | Classic search-term grain | Collected into `google_ads_search_term_daily` |
| CampaignSearchTermView | PMax campaign-level terms (v21+) | Collected as separate phase; `metadata.source_view=campaign_search_term_view` |
| KeywordView | Criterion identity + match type | Distinct from search terms |
| ConversionAction | Typed identity; conversions vs all_conversions | Daily typed table keeps both |
| Currency / timezone | Customer currencyCode / timeZone | Preserved on facts + binding |
| Data Contract mismatch | — | **NONE blocking** |

## 4. Field migration matrix (real-bound)

### Overview

| Field | State | Dataset / Formula | Reason |
|---|---|---|---|
| identity.* | REAL | Binding + DigitalAsset | Customer id / TZ / currency |
| freshness.google_ads | REAL | account_daily gate | |
| glance.spend | REAL / PARTIAL | account_daily + FORMULA_GADS_SPEND | cost_amount only |
| glance.conversions | REAL / PARTIAL | account_daily conversions | Provider conversions — not leads |
| glance.cpa | UNAVAILABLE | — | Mapping required |
| glance.pacing / pacing block | UNAVAILABLE | — | Agency plan not in pool |
| performance_trend | REAL / PARTIAL | account_daily series | `leads` key = provider conversions for frozen chart |
| campaigns portfolio | REAL / PARTIAL | campaign_daily + snapshot | |
| spend_by_offering | UNAVAILABLE | — | No offering taxonomy |
| needs_attention | DEMO | — | Residual |
| opportunities / recent_outcomes | DEMO | — | Residual |
| business_goal mapping | UNAVAILABLE / DEMO | — | No BA mapping |

### Campaigns

| Field | State | Notes |
|---|---|---|
| id / name / status / channel | REAL | Provider IDs; status ≠ health |
| budget | REAL (current config) | Budget ≠ spend; not historical unless snapshots claim history |
| spend / clicks / conversions | REAL / PARTIAL | campaign_daily |
| CPA | UNAVAILABLE | |
| pacing labels | UNAVAILABLE | |
| offering / market / language / funnel | UNAVAILABLE | |
| PMax AdGroups | never fabricated | |
| IS / lost IS | REAL when present | Non-additive share — display only |

### Search & Demand

| Field | State | Notes |
|---|---|---|
| terms | PROVIDER_LIMITED | Top-N; may omit terms |
| PMax terms | PROVIDER_LIMITED | campaign_search_term_view; no fake ad_group |
| keywords | REAL / PARTIAL | criterion_id + match_type |
| intent / fit / decision / inbox / drift | DEMO | Residual heuristics |
| market volume | never | |

### Ads & Assets

| Field | State | Notes |
|---|---|---|
| ads.rows | PARTIAL | Snapshot metadata only; no ad_daily |
| asset_groups fixture groups | UNAVAILABLE | No AssetGroup hierarchy UI fabrication |
| asset coverage inventory | REAL (thin) | Flat assets; ≠ Ad |
| ad_strength | REAL when present | ≠ performance score |

### Landing Pages

| Field | State | Notes |
|---|---|---|
| URL + spend/clicks/conversions | REAL / PARTIAL | landing_page_daily |
| technical / mobile / message / language | UNAVAILABLE | Not Ads pool |
| GA4/GSC substitution | NO | |

### Measurement

| Field | State | Notes |
|---|---|---|
| conversion action matrix | REAL | Typed IDs; roles from provider flags |
| conversions vs all_conversions | REAL distinct | Never merged |
| conversion value as revenue | NO | |
| health / debt / interruption narrative | DEMO | Residual |
| generic Results | UNAVAILABLE | |

### Operations

| Field | State | Notes |
|---|---|---|
| collection_state | REAL | Gates + materializations |
| findings / recs / tasks / outcomes | DEMO | Residual; **0 created** |

## 5. Tab reality (real-bound)

| Tab | Final |
|---|---|
| Overview | PARTIAL |
| Campaigns | PARTIAL |
| Search & Demand | PARTIAL / PROVIDER_LIMITED |
| Ads & Assets | PARTIAL / PROVIDER_LIMITED |
| Landing Pages | PARTIAL |
| Measurement | PARTIAL |
| Operations | PARTIAL (control-plane REAL) |

## 6. Dataset → UI consumers

| Dataset | Consumers |
|---|---|
| google_ads_account_daily | glance spend/conversions, trend |
| google_ads_campaign_daily + snapshot | campaigns table |
| google_ads_campaign_budget_snapshot | current budget display |
| google_ads_keyword_daily + snapshot | keywords |
| google_ads_search_term_daily | search terms (classic + PMax) |
| google_ads_landing_page_daily | landing pages |
| google_ads_conversion_action_snapshot + daily | measurement |
| google_ads_ad_snapshot | ads thin rows |
| google_ads_asset_coverage_snapshot | asset inventory |

## 7. Grain / join safety

- Account totals: account_daily only
- Campaign + typed conversions: aggregate independently — never join before sum (no cost fanout)
- Search terms never joined to invent keyword relations for PMax
- Ads never get campaign spend copied as asset performance

## 8. Formula matrix

| Formula | Expression | Forbidden |
|---|---|---|
| FORMULA_GADS_CTR | sum(clicks)/sum(impressions) | AVG(CTR) |
| FORMULA_GADS_CPC | sum(cost_amount)/sum(clicks) | AVG(CPC); micros÷1e6 again |
| FORMULA_GADS_SPEND | identity over cost_amount | second micros division |
| FORMULA_GADS_CPA | cost / typed conversions | generic Results; used only when mapping exists → currently UNAVAILABLE |
| FORMULA_GADS_CVR | typed conversions / clicks | |

## 9. Demo retirement

| Domain | Action |
|---|---|
| Account glance spend/conversions + trend | Retired when gates pass |
| Campaigns performance | Retired when gates pass |
| Keywords / search terms / landing pages | Retired when gates pass |
| Conversion action matrix | Retired when gates pass |
| Intent/inbox/drift/attention/ops narrative/opportunities | Retained DEMO |
| CPA / agency pacing / offering mix / site-wide PMax AssetGroups | UNAVAILABLE |

## 10. Tests

`tests/Feature/GoogleAds/GoogleAdsRealDataMigrationTest.php`

Regression: Google Ads collector, integrity, freshness, GA4/GSC real migration, ModuleBoundary.
