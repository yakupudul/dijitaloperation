# Meta Ads Real Data Migration (Prompt 31)

## 1. Purpose

Prompt 31 migrates the frozen Meta Ads specialist workspace
(`App\Livewire\Demo\Meta\OverviewPage`, demo route `demo.meta.overview`,
default asset `meta-atlas`) from deterministic Demo fixtures to **read-only
presentation** of the normalized Meta pool (`meta_*` tables) populated by
Prompt 24 and gated by Prompt 26 + Prompt 27.

Hard rules:

- **No Marketing API / Insights / async report / OAuth / discovery on render**
- **No Demo fallback on exceptions**
- **META_AD_ACCOUNT is the analytical root** — META_BUSINESS never is
- **No first-accessible Ad Account fallback**
- **Spend is major currency units** — never Google Ads micros; no FX
- **Ad Account timezone + currency are source truth**
- **Campaign ≠ Ad Set ≠ Ad ≠ Creative**
- **Objective ≠ Optimization ≠ Destination**
- **Budget ≠ Spend**; **Clicks ≠ Link Clicks ≠ Outbound Clicks**
- **Reach / Frequency are non-additive** — never sum daily Reach; never blind-average Frequency
- **Typed Actions retain `action_type`** — never generic Results
- **Action ≠ Qualified Lead / Business Outcome**; Action Value ≠ Revenue
- **Only contracted breakdowns** (age / gender / publisher_platform) — country UNAVAILABLE
- **No targeting scrape, Custom Audience members, lead PII, message content**
- **No Evidence / Findings / Opportunities / Recommendations created**
- Demo catalog `meta-atlas` remains 100% Demo

## 2. Architecture

```
OverviewPage (Livewire)
    └── MetaAdsSpecialistReadService::workspace(...)
            ├── MetaAdsSpecialistBindingResolver (capability=meta_ads)
            ├── MetaAdsUiDatasetGate (provider META_ADS)
            ├── MetaAdsPoolReadRepository (meta_* SQL)
            └── MetaAdsFormulaCalculator (FORMULA_META_*)
```

Repository Graph API version: **v26.0** (`moxdop-meta-ads-collector.api_version`).

There is **no `meta_account_daily`**. Overview additive KPIs (spend / impressions / clicks)
aggregate from `meta_campaign_daily` only. Period Reach/Frequency remain UNAVAILABLE.

## 3. Provider verification (2026-08-13 / Prompt 31)

| Topic | Status |
|---|---|
| Graph/Marketing API | v26.0 (repo + official current major) |
| Ad Account / Campaign / Ad Set / Ad / Creative | Contract-aligned |
| Insights levels | campaign / adset / ad daily |
| Actions / action_values | `meta_typed_action_daily` (+ metadata amounts) |
| Breakdowns collected | age, gender, publisher_platform |
| Attribution | `use_unified_attribution_setting` stored in metadata |
| Reach / Frequency | NON_ADDITIVE — period reconstruction forbidden |
| Currency / timezone | Ad Account source truth |
| Data Contract mismatch | **NONE blocking** |

## 4. Field migration matrix (real-bound)

### Overview
| Field | State | Source / reason |
|---|---|---|
| identity | REAL | Binding + DigitalAsset + account snapshot |
| glance.spend | REAL / PARTIAL | sum(meta_campaign_daily.spend) |
| glance.result_mix / generic Results | UNAVAILABLE | generic_results_forbidden |
| glance.cost_primary / CPA | UNAVAILABLE | typed BA mapping required |
| glance.pacing / pacing block | UNAVAILABLE | agency plan not in pool |
| performance_trend.spend | REAL / PARTIAL | campaign daily series |
| period reach / frequency | UNAVAILABLE | non-additive; no period Dataset |
| needs_attention / opportunities | DEMO | residual |

### Campaigns
| Field | State | Notes |
|---|---|---|
| id/name/status/objective | REAL | campaign snapshot + daily |
| spend/impressions/clicks | REAL / PARTIAL | additive |
| link_clicks | REAL / PARTIAL | metadata inline_link_clicks |
| reach/frequency (period) | UNAVAILABLE | |
| optimization / destination | PARTIAL | adset snapshot when present |
| results / cost_result | UNAVAILABLE | no generic Results |
| offering/market/funnel story | UNAVAILABLE / DEMO | residual Demo taxonomy |

### Creatives
| Field | State | Notes |
|---|---|---|
| creative identity + content meta | REAL | meta_creative_snapshot |
| Ad→Creative performance | PARTIAL | aggregate ads first, then creative |
| fatigue / scores | UNAVAILABLE | |
| Page/IG actors | REAL metadata | no Organic DigitalAssets |
| angles / personas / tests | DEMO | residual |

### Audience & Delivery
| Field | State | Notes |
|---|---|---|
| age / gender / publisher_platform | REAL / PARTIAL | delivery_breakdown_daily |
| country | UNAVAILABLE | not collected |
| configured targeting copy | UNAVAILABLE / DEMO | no targeting scrape |
| period reach/frequency | UNAVAILABLE | |
| Custom Audience members | never | |

### Funnel & Destinations
| Field | State | Notes |
|---|---|---|
| destination_type (adset) | PARTIAL | snapshot metadata when present |
| typed action stages | PARTIAL | typed_action_daily |
| Lead Form submissions / messages | never | |
| Website→GA4 / WhatsApp→QL inference | NO | |

### Measurement
| Field | State | Notes |
|---|---|---|
| typed actions matrix | REAL / PARTIAL | action_type retained |
| action values | PARTIAL | metadata amounts; ≠ revenue |
| attribution | REAL note | unified setting provenance |
| generic Results / CRM / lead quality | UNAVAILABLE / DEMO | |

### Operations
| Field | State | Notes |
|---|---|---|
| collection_state | REAL | gates + materializations |
| findings/recs/tasks/outcomes | DEMO | residual; **0 created** |

## 5. Tab reality (real-bound)

| Tab | Final |
|---|---|
| Overview | PARTIAL |
| Campaigns | PARTIAL |
| Creatives | PARTIAL / PROVIDER_LIMITED |
| Audience & Delivery | PARTIAL / PROVIDER_LIMITED / UNAVAILABLE |
| Funnel & Destinations | PARTIAL / PROVIDER_LIMITED |
| Measurement | PARTIAL / PROVIDER_LIMITED |
| Operations | PARTIAL (control-plane REAL) |

## 6. Dataset → UI consumers

| Dataset | Consumers |
|---|---|
| meta_campaign_daily | overview spend/trend, campaigns |
| meta_campaign_snapshot | campaign metadata/objective/status |
| meta_adset_daily / snapshot | optimization/destination context |
| meta_ad_daily | creative performance via Ad→Creative |
| meta_creative_snapshot | creatives tab |
| meta_typed_action_daily | measurement + funnel actions |
| meta_delivery_breakdown_daily | audience age/gender/platform |
| meta_ad_account_snapshot | currency/timezone identity |

## 7. Grain / reach / formula safety

- Additive: spend, impressions, clicks (and link_clicks from metadata) may sum across compatible days/campaigns
- Non-additive: reach, frequency — period UI fields UNAVAILABLE
- Typed actions aggregated independently before combine (no spend fanout)
- Creative rollup: aggregate Ad performance by `ad_id` first, then group by `creative_id`
- Formulas: FORMULA_META_CTR_ALL, LINK_CTR, CPC, COST_PER_LINK_CLICK, CPM, SPEND — never AVG(row ratios)
- Cost-per-result / Cost-primary: UNAVAILABLE without typed mapping

## 8. Demo retirement

| Domain | Action |
|---|---|
| Spend / clicks / impressions / campaign portfolio | Retired when gates pass |
| Typed actions / age-gender-platform breakdowns | Retired when gates pass |
| Creative snapshot metadata | Retired when gates pass |
| Generic Results / period Reach-Freq / country / pacing / CPA | UNAVAILABLE |
| Intent fatigue / CRM / ops narrative / opportunities | Retained DEMO |

## 9. Tests

`tests/Feature/MetaAds/MetaAdsRealDataMigrationTest.php`

Regression: Meta collector, integrity, freshness, Google Ads/GSC/GA4 real migrations, ModuleBoundary.
