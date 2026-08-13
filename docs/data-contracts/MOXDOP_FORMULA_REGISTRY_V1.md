# MOXDOP FORMULA REGISTRY V1

| Field | Value |

| --- | --- |

| Registry ID | `MOXDOP_FORMULA_REGISTRY` |

| Version | `1` |

| Status | **FROZEN_FOR_CALCULATION_IMPLEMENTATION** |

| Created | 2026-08-13 |

| Data Contract Registry | `MOXDOP_DATA_CONTRACT_REGISTRY` v1 @ `b498c8c41b8a` |

| Canonical JSON | `docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json` |

| Schema | `docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.schema.json` |



## 1. Purpose

Define how MoxDOP calculates deterministic derived metrics from Prompt 7 base facts. Providers supply facts; formulas derive values; UI, presenters, reports, and AI must not invent formulas.

## 2. Relationship to Data Contract Registry

`MOXDOP_DATA_CONTRACT_REGISTRY_V1` defines **WHAT** facts/data are required.

`MOXDOP_FORMULA_REGISTRY_V1` defines **HOW** deterministic derived values are calculated.

Collectors implement the Data Contract Registry. Calculation engines implement the Formula Registry. Collectors do **not** implement formulas.

## 3. Formula Registry Principles

- `MISSING_NEVER_EQUALS_ZERO` = `True`

- `NO_SILENT_DIVIDE_BY_ZERO` = `True`

- `NO_INTERMEDIATE_ROUNDING` = `True`

- `RATIOS_RECOMPUTE_FROM_BASE_FACTS_WHERE_VALID` = `True`

- `PERCENT_CHANGE_DISTINCT_FROM_PERCENTAGE_POINT_CHANGE` = `True`

- `CURRENCY_MUST_BE_COMPARABLE` = `True`

- `REPORTING_TIMEZONE_MUST_BE_PRESERVED` = `True`

- `STALE_INPUT_PROPAGATES` = `True`

- `PARTIAL_INPUT_PROPAGATES` = `True`

- `ESTIMATED_INPUT_PROVENANCE_PROPAGATES` = `True`

- `TYPED_RESULT_IDENTITY_REQUIRED` = `True`

- `PROVIDER_FACT_DISTINCT_FROM_MOXDOP_DERIVED` = `True`

- `NO_MAGIC_SCORES` = `True`

- `INTERNAL_RATIO_SCALE` = `FRACTION_0_1`

- `DISPLAY_PERCENTAGE_SCALE` = `PERCENT_0_100`

## 4. Formula Taxonomy

`RATIO`, `RATE`, `PERCENTAGE`, `COST_PER`, `AVERAGE`, `SUM`, `ABSOLUTE_DELTA`, `RELATIVE_CHANGE`, `PERCENTAGE_POINT_CHANGE`, `INDEX_OR_PACING`, `OTHER_DETERMINISTIC`



| Group | Formula IDs |
| --- | --- |
| Engagement | `FORMULA_GA4_ENGAGEMENT_RATE`, `FORMULA_GA4_AVG_ENGAGEMENT_TIME`, `FORMULA_GA4_VIEWS_PER_SESSION`, `FORMULA_GA4_CHANNEL_SHARE`, `FORMULA_GA4_DEVICE_SHARE`, `FORMULA_GA4_UTM_UNAVAILABLE_PCT` |
| Advertising efficiency | `FORMULA_GSC_CTR`, `FORMULA_GADS_CTR`, `FORMULA_META_CTR_ALL`, `FORMULA_META_LINK_CTR`, `FORMULA_GADS_CPC`, `FORMULA_META_CPC`, `FORMULA_META_COST_PER_LINK_CLICK`, `FORMULA_META_CPM`, `FORMULA_GADS_SPEND`, `FORMULA_META_SPEND` |
| Results / conversion | `FORMULA_GADS_CPA`, `FORMULA_GADS_CVR`, `FORMULA_META_COST_PER_RESULT`, `FORMULA_META_COST_PRIMARY`, `FORMULA_META_FREQUENCY` |
| Business Actions | `FORMULA_GA4_BUSINESS_ACTION_COUNT`, `FORMULA_GA4_BUSINESS_ACTION_RATE` |
| Business Outcomes | — (none in V1) |
| Period comparisons | `FORMULA_PERIOD_RELATIVE_CHANGE`, `FORMULA_PERIOD_ABSOLUTE_DELTA`, `FORMULA_PERCENTAGE_POINT_DELTA` |
| Website deterministic ratios | `FORMULA_WEB_INVENTORY_COUNT`, `FORMULA_WEB_TLS_DAYS_REMAINING` |
| Budget/pacing | `FORMULA_GADS_BUDGET_PACING`, `FORMULA_GADS_CAMPAIGN_PACING`, `FORMULA_META_BUDGET_PACING` |
| Other | `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` |

Business Outcomes: no V1 formulas (Qualified Lead Rate / Cost per Qualified Lead rejected until Outcome freeze).

## 5. Numeric / Unit Semantics

Internal ratios use **fraction 0–1**. Display percentages use **0–100**. Percentage points are a distinct unit (`PERCENTAGE_POINT`). Money uses account currency with metadata-driven minor units (not universal 2 decimals). Counts are provider facts, not formulas, unless a genuine derived aggregation semantic is required.

## 6. Missing / Zero / Unavailable Semantics

Zero is a measured value. Missing / not collected / not configured / unavailable / stale / partial are distinct states. **Missing never equals zero.**

## 7. Divide-by-Zero Contract

| Case | Output state |
| --- | --- |
| denominator > 0 | VALUE |
| numerator = 0, denominator > 0 | VALUE_ZERO |
| numerator > 0, denominator = 0 | UNDEFINED_ZERO_DENOMINATOR |
| 0 / 0 | UNDEFINED |
| missing numerator or denominator | NOT_COLLECTED (or propagated state) |
| mapping absent (BA / typed result) | NOT_CONFIGURED / NOT_ELIGIBLE |

Never return Infinity%, NaN, or fake 0% for undefined division.

## 8. Percentage Semantics

| Kind | Internal | Display | Example |
| --- | --- | --- | --- |
| Ratio as % | FRACTION_0_1 | PERCENT_0_100 | 0.0625 → 6.25% |
| Relative % change | FRACTION_0_1 | PERCENT_0_100 | 100→120 = +0.20 → +20% |
| Percentage points | PERCENTAGE_POINT | pp | 0.04→0.06 = +0.02 (= +2 pp), not +50% and not labeled +2% |
| Provider-provided % | provider scale documented | presentation | Impression share — provider fact, not MoxDOP formula |

## 9. Rounding Contract

**No intermediate rounding.** Calculate with full available precision. Round only at presentation (`PERCENT_DISPLAY`, `CURRENCY_DISPLAY`, `COUNT_DISPLAY`). Rounding mode for display is presentation-formatter-owned; arithmetic precision remains exact.

| Policy ID | Calculation | Display |
| --- | --- | --- |
| RP_NO_INTERMEDIATE | NO_INTERMEDIATE_ROUNDING | DISPLAY_ONLY |
| RP_PERCENT_DISPLAY | NO_INTERMEDIATE_ROUNDING | PERCENT_DISPLAY |
| RP_MONEY_DISPLAY | NO_INTERMEDIATE_ROUNDING | CURRENCY_DISPLAY |
| RP_COUNT_DISPLAY | NO_INTERMEDIATE_ROUNDING | COUNT_DISPLAY |

## 10. Currency Contract

FX is **not** implemented in V1. Mixed-currency arithmetic or comparison → `NOT_COMPARABLE_CURRENCY`. Output currency derives from monetary numerator source currency. Do not hardcode TRY/USD/EUR.

| Policy ID | FX allowed | Notes |
| --- | --- | --- |
| CP_ACCOUNT_CURRENCY | False | provider_account_currency |
| CP_NA | False | not_applicable |

## 11. Timezone / Date Contract

Formulas must use canonical source reporting timezone. Previous period = immediately preceding equal-length period in that timezone. **Never rebucket daily facts using accidental server UTC.** Cross-source day alignment is not guaranteed when timezones differ.

| Policy ID | Notes |
| --- | --- |
| TZ_GA4 | {"id": "TZ_GA4", "reporting_timezone": "ga4_property_timezone"} |
| TZ_GSC | {"id": "TZ_GSC", "reporting_timezone": "gsc_reporting_date_semantics"} |
| TZ_GADS | {"id": "TZ_GADS", "reporting_timezone": "google_ads_customer_time_zone"} |
| TZ_META | {"id": "TZ_META", "reporting_timezone": "meta_ad_account_timezone"} |
| TZ_WEB | {"id": "TZ_WEB", "reporting_timezone": "observation_timestamp"} |
| TZ_DFS | {"id": "TZ_DFS", "reporting_timezone": "snapshot_retrieved_at_plus_market_context"} |

## 12. Aggregation / Additivity Contract

Ratios recompute from summed base facts where valid. **Never AVG(CTR/CPC/Frequency).** **Never SUM(daily Reach).** Meta Frequency/Reach may require period provider query. GSC Average Position prefers provider period aggregate; impression-weighted only when combining compatible rows.

## 13. Previous-Period Comparison Contract

Reuses Prompt 7 equal-length previous period. Relative change with previous=0 and current>0 → `UNDEFINED_RELATIVE_CHANGE` (absolute delta may still be available). Both zero → `UNDEFINED`. Missing previous → propagate missing. Partial periods may be numerically calculable but comparison eligibility may be `PARTIAL` / `NOT_COMPARABLE`.

| Policy ID | Type | Previous=0 |
| --- | --- | --- |
| COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RELATIVE_PERCENT_CHANGE | UNDEFINED_RELATIVE_CHANGE |
| COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | PERCENTAGE_POINT_CHANGE | n/a |
| COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | ABSOLUTE_DELTA | n/a |

## 14. Formula Registry

Total formulas: **32** (all `FROZEN`). Dispositions: **106**. Rejected magic/out-of-scope: **7**.



### 14.1 Formula table (mandatory)

| Formula ID | Name | Type | Expression | Inputs | Out type | Unit | Agg | Zero | Missing | Currency | TZ | Compare | Rounding | Provenance | Consumers | Eligibility | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | GSC Click-Through Rate | RATE | `sum(clicks) / sum(impressions)` | GSC_OVERVIEW_CLICKS, GSC_OVERVIEW_IMPRESSIONS | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | gsc_reporting_date_semantics | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GSC_OVERVIEW_CTR, GSC_PERF_CTR, WEB_VIS_GSC_KPIS | always | FROZEN |
| `FORMULA_GADS_CTR` | Google Ads CTR | RATE | `sum(clicks) / sum(impressions)` | GADS_OVERVIEW_SPEND | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | google_ads_customer_time_zone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GADS_CAMPAIGN_DAILY, GADS_OVERVIEW_CAMPAIGN_PORTFOLIO | always | FROZEN |
| `FORMULA_META_CTR_ALL` | Meta All-Click CTR | RATE | `sum(clicks) / sum(impressions)` | META_CAMPAIGN_DAILY, META_CAMPAIGN_DAILY | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | meta_ad_account_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_DAILY, META_OVERVIEW_CAMPAIGN_PORTFOLIO, META_AD_DAILY | always | FROZEN |
| `FORMULA_META_LINK_CTR` | Meta Link CTR | RATE | `sum(inline_link_clicks) / sum(impressions)` | META_CAMPAIGN_LINK_CTR, META_CAMPAIGN_DAILY | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | meta_ad_account_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_LINK_CTR | always | FROZEN |
| `FORMULA_GADS_CPC` | Google Ads CPC | COST_PER | `sum(cost) / sum(clicks)` | GADS_OVERVIEW_SPEND, GADS_CAMPAIGN_DAILY | MONEY | ACCOUNT_CURRENCY_PER_CLICK | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | GADS_CAMPAIGN_DAILY, GADS_OVERVIEW_CAMPAIGN_PORTFOLIO, GADS_KEYWORD_DAILY | always | FROZEN |
| `FORMULA_META_CPC` | Meta CPC (all clicks) | COST_PER | `sum(spend) / sum(clicks)` | META_OVERVIEW_SPEND, META_CAMPAIGN_DAILY | MONEY | ACCOUNT_CURRENCY_PER_CLICK | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_DAILY, META_OVERVIEW_CAMPAIGN_PORTFOLIO, META_AD_DAILY | always | FROZEN |
| `FORMULA_META_COST_PER_LINK_CLICK` | Meta Cost per Link Click | COST_PER | `sum(spend) / sum(inline_link_clicks)` | META_OVERVIEW_SPEND, META_LINK_CLICK | MONEY | ACCOUNT_CURRENCY_PER_LINK_CLICK | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_DAILY, META_LINK_CLICK, META_OVERVIEW_CAMPAIGN_PORTFOLIO | always | FROZEN |
| `FORMULA_META_CPM` | Meta CPM | COST_PER | `sum(spend) / sum(impressions) * 1000` | META_OVERVIEW_SPEND, META_CAMPAIGN_DAILY | MONEY | ACCOUNT_CURRENCY_PER_1000_IMPRESSIONS | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_DAILY, META_OVERVIEW_CAMPAIGN_PORTFOLIO, META_AD_DAILY | always | FROZEN |
| `FORMULA_GADS_SPEND` | Google Ads Spend | SUM | `sum(cost_micros) / 1e6` | GADS_OVERVIEW_SPEND | MONEY | ACCOUNT_CURRENCY | SUM_THEN_SCALE | not_applicable | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | GADS_OVERVIEW_SPEND | always | FROZEN |
| `FORMULA_META_SPEND` | Meta Spend | SUM | `sum(spend)` | META_OVERVIEW_SPEND | MONEY | ACCOUNT_CURRENCY | SUM | not_applicable | MP_RATIO_STANDARD | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_OVERVIEW_SPEND | always | FROZEN |
| `FORMULA_GADS_CPA` | Google Ads Cost per Primary Conversion | COST_PER | `sum(cost) / sum(primary_conversions)` | GADS_OVERVIEW_CPA, GADS_OVERVIEW_PRIMARY_CONVERSIONS | MONEY | ACCOUNT_CURRENCY_PER_CONVERSION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | GADS_OVERVIEW_CPA, GADS_CAMPAIGN_CPA | ['primary_conversion_mapping_configured'] | FROZEN |
| `FORMULA_GADS_CVR` | Google Ads Conversion Rate | RATE | `sum(primary_conversions) / sum(clicks)` | GADS_OVERVIEW_PRIMARY_CONVERSIONS, GADS_CAMPAIGN_DAILY | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | not_applicable | google_ads_customer_time_zone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GADS_CAMPAIGN_DAILY, GADS_OVERVIEW_CAMPAIGN_PORTFOLIO, GADS_OVERVIEW_PRIMARY_CONVERSIONS | Requires primary conversion mapping; abs | FROZEN |
| `FORMULA_META_COST_PER_RESULT` | Meta Cost per Typed Result | COST_PER | `sum(spend) / sum(typed_result_count[result_type])` | META_ACTION_TYPE_DAILY, META_CAMPAIGN_COST_RESULT | MONEY | ACCOUNT_CURRENCY_PER_RESULT | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_CAMPAIGN_COST_RESULT, META_OVERVIEW_CAMPAIGN_PORTFOLIO | ['result_type_identity_present', 'typed_action_mapping_if_curated'] | FROZEN |
| `FORMULA_META_COST_PRIMARY` | Meta Cost per Primary Lead | COST_PER | `sum(spend_on_lead_campaigns) / sum(lead_typed_count)` | META_OVERVIEW_COST_PRIMARY | MONEY | ACCOUNT_CURRENCY_PER_LEAD | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_OVERVIEW_COST_PRIMARY | ['lead_result_type_mapped'] | FROZEN |
| `FORMULA_META_FREQUENCY` | Meta Frequency | RATE | `period_impressions / period_reach  OR  provider_frequency(period)` | META_CAMPAIGN_FREQUENCY | RATIO | IMPRESSIONS_PER_REACHED_PERSON | PERIOD_PROVIDER_OR_PERIOD_FACTS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | not_applicable | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | META_CAMPAIGN_FREQUENCY | always | FROZEN |
| `FORMULA_GA4_ENGAGEMENT_RATE` | GA4 Engagement Rate | RATE | `sum(engagedSessions) / sum(sessions)` | GA4_BEH_ENGAGEMENT_RATE, GA4_OVERVIEW_SESSIONS | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_BEH_ENGAGEMENT_RATE, GA4_OVERVIEW_LANDING_PULSE | always | FROZEN |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | GA4 Average Engagement Time | AVERAGE | `sum(userEngagementDuration) / sum(activeUsers)` | GA4_BEH_AVG_ENGAGEMENT_TIME | DURATION | SECONDS | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | GA4_BEH_AVG_ENGAGEMENT_TIME | always | FROZEN |
| `FORMULA_GA4_VIEWS_PER_SESSION` | GA4 Views per Session | RATE | `sum(screenPageViews) / sum(sessions)` | GA4_BEH_VIEWS_PER_SESSION | RATIO | VIEWS_PER_SESSION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | GA4_BEH_VIEWS_PER_SESSION | always | FROZEN |
| `FORMULA_GA4_CHANNEL_SHARE` | GA4 Channel Share | PERCENTAGE | `sum(channel_sessions) / sum(property_sessions)` | GA4_OVERVIEW_ACQUISITION_MIX | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_OVERVIEW_ACQUISITION_MIX, GA4_ACQ_CHANNELS | always | FROZEN |
| `FORMULA_GA4_DEVICE_SHARE` | GA4 Device Share | PERCENTAGE | `sum(device_sessions) / sum(property_sessions)` | GA4_BEH_DEVICES | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_BEH_DEVICES | always | FROZEN |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | GA4 Mapped Business Action Count | SUM | `sum(eventCount where eventName in mapped_set)` | GA4_OVERVIEW_BUSINESS_ACTIONS, GA4_MEAS_EVENTS | COUNT | ACTIONS | SUM_FILTERED | not_applicable | MP_MAPPING_REQUIRED | not_applicable | ga4_property_timezone | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_COUNT_DISPLAY | MOXDOP_DERIVED | GA4_OVERVIEW_BUSINESS_ACTIONS | ['business_action_mapping_configured'] | FROZEN |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | GA4 Business Action Rate | RATE | `FORMULA_GA4_BUSINESS_ACTION_COUNT / sum(sessions)` | GA4_OVERVIEW_LANDING_PULSE | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | not_applicable | ga4_property_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_OVERVIEW_LANDING_PULSE | ['business_action_mapping_configured'] | FROZEN |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | GA4 UTM Unavailable Share | PERCENTAGE | `sum(sessions where campaign in {(not set), empty}) / sum(sessions)` | GA4_MEAS_UTM_HYGIENE | RATIO | FRACTION | RECOMPUTE_FROM_SUMS | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | ga4_property_timezone | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_MEAS_UTM_HYGIENE | always | FROZEN |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | Period Relative Change | RELATIVE_CHANGE | `(current - previous) / previous` | — | RATIO | FRACTION | COMPARE_AFTER_PERIOD_AGGREGATE | UNDEFINED_RELATIVE_CHANGE | MP_RELATIVE_CHANGE | not_applicable | not_applicable | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_SHELL_COMPARE_TOGGLE, GADS_PREVIOUS_PERIOD, GSC_SHELL_COMPARE | always | FROZEN |
| `FORMULA_PERIOD_ABSOLUTE_DELTA` | Period Absolute Delta | ABSOLUTE_DELTA | `current - previous` | — | DECIMAL | INPUT_UNIT | COMPARE_AFTER_PERIOD_AGGREGATE | not_applicable | MP_RELATIVE_CHANGE | not_applicable | not_applicable | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | GA4_SHELL_COMPARE_TOGGLE, GADS_PREVIOUS_PERIOD, GSC_PERF_AVG_POSITION | always | FROZEN |
| `FORMULA_PERCENTAGE_POINT_DELTA` | Percentage-Point Change | PERCENTAGE_POINT_CHANGE | `current_rate - previous_rate` | — | PERCENTAGE_POINT | PERCENTAGE_POINT | COMPARE_RATES | not_applicable | MP_RELATIVE_CHANGE | not_applicable | not_applicable | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | RP_PERCENT_DISPLAY | MOXDOP_DERIVED | GA4_BEH_ENGAGEMENT_RATE, GA4_SHELL_COMPARE_TOGGLE, GADS_PREVIOUS_PERIOD | always | FROZEN |
| `FORMULA_GADS_BUDGET_PACING` | Google Ads Account Budget Pacing | INDEX_OR_PACING | `actual_spend vs planned_monthly * (elapsed_days/days_in_month)` | GADS_OVERVIEW_BUDGET_PACING | DECIMAL | PACING_STATE_AND_MONEY | HYBRID_OPERATOR_PLAN | UNDEFINED_ZERO_DENOMINATOR | MP_MAPPING_REQUIRED | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone | not_applicable | RP_MONEY_DISPLAY | MOXDOP_DERIVED | GADS_OVERVIEW_BUDGET_PACING | ['agency_planned_budget_configured'] | FROZEN |
| `FORMULA_GADS_CAMPAIGN_PACING` | Google Ads Campaign Pacing Label | INDEX_OR_PACING | `classify(spend, budget_or_plan, optional_lost_is)` | GADS_CAMPAIGN_PACING | OTHER | ENUM_LABEL | HYBRID | not_applicable | MP_MAPPING_REQUIRED | not_applicable | not_applicable | not_applicable | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | GADS_CAMPAIGN_PACING | always | FROZEN |
| `FORMULA_META_BUDGET_PACING` | Meta Budget Pacing | INDEX_OR_PACING | `actual_spend vs planned * elapsed_fraction` | META_OVERVIEW_BUDGET_PACING | OTHER | PACING_STATE | HYBRID_OPERATOR_PLAN | not_applicable | MP_MAPPING_REQUIRED | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone | not_applicable | RP_MONEY_DISPLAY | MOXDOP_DERIVED | META_OVERVIEW_BUDGET_PACING | ['agency_planned_budget_configured'] | FROZEN |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | GSC Impression-Weighted Position | AVERAGE | `sum(position * impressions) / sum(impressions)` | GSC_PERF_AVG_POSITION | POSITION | AVERAGE_POSITION | WEIGHTED_AVERAGE | UNDEFINED_ZERO_DENOMINATOR | MP_RATIO_STANDARD | not_applicable | not_applicable | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | RP_NO_INTERMEDIATE | MOXDOP_DERIVED | GSC_PERF_AVG_POSITION | always | FROZEN |
| `FORMULA_WEB_INVENTORY_COUNT` | Website Inventory Count | SUM | `count(website_url rows)` | WEB_OVERVIEW_INVENTORY | COUNT | URLS | COUNT | not_applicable | MP_RATIO_STANDARD | not_applicable | not_applicable | not_applicable | RP_COUNT_DISPLAY | MOXDOP_DERIVED | WEB_OVERVIEW_INVENTORY | always | FROZEN |
| `FORMULA_WEB_TLS_DAYS_REMAINING` | TLS Days Remaining | ABSOLUTE_DELTA | `tls_not_after_date - observation_date` | WEB_INFRA_TLS | DURATION | DAYS | DATE_DIFF | not_applicable | MP_RATIO_STANDARD | not_applicable | observation_timestamp | not_applicable | RP_COUNT_DISPLAY | MOXDOP_DERIVED | WEB_INFRA_TLS | always | FROZEN |

## 15. Advertising Efficiency Formulas

GSC / Google Ads / Meta all-click / Meta link CTR are **separate** identities. CPC variants distinguish Google Ads clicks, Meta all clicks, and Meta link clicks. Meta CPM = spend/impr×1000. Spend normalizers: micros÷1e6 (GAds), account currency spend (Meta).

## 16. Engagement Formulas

GA4 Engagement Rate = engagedSessions / sessions (recompute from sums). Views/Session. Avg Engagement Time uses provisional divisor `activeUsers` (DEC non-blocking). Channel/device shares. UTM unavailable %.

## 17. Result / Conversion Formulas

Google Ads CPA = cost / primary_conversions (mapped). CVR = primary_conversions / clicks. Meta Cost per Result **requires result_type**. Mixed Meta result types → `NOT_COMPARABLE_RESULT_TYPE`. Platform Result ≠ Business Outcome.

## 18. Business Action Formulas

`FORMULA_GA4_BUSINESS_ACTION_COUNT` sums mapped events. `FORMULA_GA4_BUSINESS_ACTION_RATE` = BA count / sessions. **No BA mapping ≠ 0%** → `NOT_CONFIGURED` / `NOT_ELIGIBLE`. Mapping version is a provenance dependency.

## 19. Business Outcome Formula Dependencies

Qualified Lead Rate / Cost per Qualified Lead **not registered** for V1 (rejected). Business Outcome persistence is not production. Platform Result ≠ Business Outcome remains mandatory.

## 20. Website Deterministic Formulas

Inventory count; TLS days remaining. **No Website Health Score.** Lighthouse scores remain provider-native.

## 21. Budget / Pacing Formulas

GAds account pacing; Meta pacing; GAds campaign pacing index. Pacing ≠ Budget Health Score. Label thresholds (Ahead/Behind) are presentation/classification, not formula mathematics.

## 22. Formula Eligibility

Mapping-dependent and plan-dependent formulas return `NOT_CONFIGURED` / `NOT_ELIGIBLE` when prerequisites absent — never numeric zero.

## 23. Comparability Rules

Same semantic identity, formula version (where relevant), result_type, currency, and valid equal-length periods required before comparison.

## 24. Provider-Native vs MoxDOP-Derived Metrics

| Metric | Provider-native? | Stored base facts? | MoxDOP-derived? | Canonical aggregation | Reconciliation |
| --- | --- | --- | --- | --- | --- |
| GSC CTR | optional | clicks, impressions | YES `FORMULA_GSC_CTR` | sum/sum | optional QA |
| GAds CTR | optional | clicks, impressions | YES `FORMULA_GADS_CTR` | sum/sum | optional QA |
| Meta CTR (all) | optional | clicks, impressions | YES `FORMULA_META_CTR_ALL` | sum/sum | optional |
| Meta Link CTR | optional | link_clicks, impressions | YES `FORMULA_META_LINK_CTR` | sum/sum | optional |
| GAds/Meta CPC | optional | spend, clicks | YES | sum/sum | optional |
| Meta CPM | optional | spend, impressions | YES | sum/sum ×1000 | optional |
| GA4 Engagement Rate | possible | engagedSessions, sessions | YES canonical | sum/sum | optional |
| GSC Avg Position | YES preferred | position (+impr for combine) | weighted only for row combine | provider period | n/a |
| Meta Reach | YES period | period reach | NO from daily sum | period provider query | n/a |
| Meta Frequency | YES period preferred | impr + reach OR provider freq | derived if period reach | period | optional |
| Conversions / Results | YES typed/mapped | counts | mapping sum | sum by type/map | n/a |
| Conversion Value | YES provider | value | NO ROAS V1 | provider | n/a |
| Cost per Conversion | optional | cost, primary conv | YES `FORMULA_GADS_CPA` | sum/sum | optional |
| Cost per Result | optional | spend, typed results | YES typed | sum/sum by type | optional |
| Business Actions | NO | events + mapping | YES | sum mapped | n/a |
| Business Action Rate | NO | BA + sessions | YES | sum/sum | n/a |
| Qualified Lead Rate | n/a | Outcome not production | REJECTED V1 | n/a | n/a |

## 25. Existing Runtime Formula Audit

| File / class | Metric | Current formula | Consumer | Matches? | Missing-safe? | Zero-safe? | Agg-safe? | Currency-safe? | TZ-safe? | Future action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| app/Support/Demo/GscWorkspaceFixtures.php::~1050 | CTR | clicks/impressions; returns 0.0 when impr=0 | GSC workspace Demo | PARTIAL | False | False | True | True | UNKNOWN | REPLACE_LATER — UNDEFINED_ZERO_DENOMINATOR / UNDEFINED for 0/0; never return 0.0 |
| app/Support/Demo/MetaAdsWorkspaceFixtures.php::~1714 | Link CTR | (linkClicks/impr)*100; may fall back to base ctr | Meta Ads Demo | PARTIAL | False | PARTIAL | UNKNOWN | True | UNKNOWN | REPLACE_LATER — do not fall back all-click CTR; use UNDEFINED when impr=0 |
| app/Support/Demo/GoogleAdsWorkspaceFixtures.php::pacing | Budget pacing | actual vs planned * elapsed; uses max(0.01,...) for projected | Google Ads Demo | PARTIAL | False | False | True | False | UNKNOWN | KEEP_LATER adapt — remove silent max(0.01); currency from account |
| app/Support/Demo/MetaAdsWorkspaceFixtures.php::pacing | Meta budget pacing | actual vs planned * elapsed_fraction | Meta Ads Demo | PARTIAL | UNKNOWN | UNKNOWN | True | UNKNOWN | UNKNOWN | KEEP_LATER — align zero/missing policies |
| app/Support/Demo/DemoCatalog.php::compareDelta | Relative % delta (synthetic) | Deterministic fake deltas from efficiency_factor — not real previous period | Multi-asset Demo KPI chips | False | False | False | False | False | False | REPLACE_LATER — Demo-only synthetic; production must use equal-length previous period |
| resources/views/.../google-ads overview (hardcoded ₺) | pacing/money display | presentation | Google Ads overview Blade | False | n/a | n/a | n/a | False | n/a | REPLACE_LATER — use account currency metadata; never hardcode TRY |
| app/Support/Demo/DemoCatalog.php (CTR/CPC/CPM/CPA/CVR precomputed fixtures) | Multiple efficiency KPIs | Hardcoded/scaled fixture values; not reaggregated from base facts | Demo panels | False | False | False | False | False | False | REPLACE_LATER when panels migrate off Demo — compute via Formula Registry |

## 26. Formula Conflict Matrix

CTR semantics intentionally separate (GSC / GAds / Meta all-click / Meta link). No conflicting duplicate IDs. Avg engagement time divisor provisional (NON_BLOCKING). Demo synthetic deltas conflict with equal-length previous-period rule — REPLACE LATER.

## 27. Data Contract Gaps

Count: **0**. Blocking: **0**. Prompt 7 amendment required: **NO** for registered formulas.

## 28. Decisions

| Decision ID | Blocking | Formulas | Question | Status |
| --- | --- | --- | --- | --- |
| DEC_GA4_ENGAGEMENT_TIME_DIVISOR | NON_BLOCKING | FORMULA_GA4_AVG_ENGAGEMENT_TIME | Avg engagement time divisor activeUsers vs sessions | PROVISIONAL_FROZEN |
| DEC_GADS_CAMPAIGN_PACING_THRESHOLDS | NON_BLOCKING | FORMULA_GADS_CAMPAIGN_PACING | Exact Ahead/Behind/Constrained thresholds | PROVISIONAL_FROZEN |

## 29. Traceability

Traceability rows: **32**. Every formula links consumers → inputs → source contracts → formula version. Orphan formulas: **0**. MOXDOP_DERIVED without disposition: **0**.

## 30. Validation Results

- formulas: 32

- dispositions: 106

- rejected: 7

- frozen: 32

- validation passed: True

- errors: []

- semantic tests: {"divide_by_zero": "PASS_CONTRACT", "period_comparison": "PASS_CONTRACT", "percentage_point": "PASS_CONTRACT", "currency_mixed": "PASS_CONTRACT_NOT_COMPARABLE", "meta_typed_result": "PASS_CONTRACT_NOT_COMPARABLE_MIXED", "ba_mapping_absent": "PASS_CONTRACT_NOT_CONFIGURED"}

## 31. Versioning Rules

Formula ID semantics are immutable inside V1. Denominator, currency behavior, missing/zero behavior, or typed-result identity changes are breaking (new formula identity or Registry V2). Display decimal changes alone may be presentation-policy amendments.

## 32. Definition of Done

- Is every deterministic frozen derived metric represented or explicitly disposed? **YES**

- Are provider-native and MoxDOP-derived metrics separated? **YES**

- Are formula inputs canonical Requirement IDs? **YES**

- Are ratio aggregation rules explicit? **YES**

- Is divide-by-zero behavior explicit? **YES**

- Is missing distinct from zero? **YES**

- Are percentage semantics explicit? **YES**

- Is percentage-point change distinct from relative change? **YES**

- Is rounding explicit? **YES**

- Is currency explicit? **YES**

- Is timezone explicit? **YES**

- Are comparison periods explicit? **YES**

- Are partial/stale states explicit? **YES**

- Is Meta Result Type required for Result formulas? **YES**

- Are Business Actions dependent on mappings? **YES**

- Are Business Outcomes kept separate from provider Results? **YES**

- Are formula versions explicit? **YES**

- Are there zero orphan formulas? **YES**

- Are there zero unexplained magic scores? **YES**

- Could a future calculation engine implement these formulas without inventing mathematical or semantic behavior? **YES**

## Appendix A — Input Semantics Matrix

| Formula | Input | Requirement ID | Source | Role | Unit | Required state | Mapping | Currency | Timezone |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | clicks | `GSC_OVERVIEW_CLICKS` | SEARCH_CONSOLE_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | not_applicable | gsc_reporting_date_semantics |
| `FORMULA_GSC_CTR` | impressions | `GSC_OVERVIEW_IMPRESSIONS` | SEARCH_CONSOLE_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | not_applicable | gsc_reporting_date_semantics |
| `FORMULA_GADS_CTR` | — | `GADS_OVERVIEW_SPEND` | GOOGLE_ADS_DATA_CONTRACT | facts | see DCR | required for VALUE | — | not_applicable | google_ads_customer_time_zone |
| `FORMULA_META_CTR_ALL` | clicks | `META_CAMPAIGN_DAILY` | META_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | not_applicable | meta_ad_account_timezone |
| `FORMULA_META_CTR_ALL` | impressions | `META_CAMPAIGN_DAILY` | META_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | not_applicable | meta_ad_account_timezone |
| `FORMULA_META_LINK_CTR` | — | `META_CAMPAIGN_LINK_CTR` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | meta_ad_account_timezone |
| `FORMULA_META_LINK_CTR` | — | `META_CAMPAIGN_DAILY` | META_ADS_DATA_CONTRACT | facts | see DCR | required for VALUE | — | not_applicable | meta_ad_account_timezone |
| `FORMULA_GADS_CPC` | cost | `GADS_OVERVIEW_SPEND` | GOOGLE_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_GADS_CPC` | clicks | `GADS_CAMPAIGN_DAILY` | GOOGLE_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_META_CPC` | spend | `META_OVERVIEW_SPEND` | META_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_CPC` | clicks | `META_CAMPAIGN_DAILY` | META_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_COST_PER_LINK_CLICK` | spend | `META_OVERVIEW_SPEND` | META_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_COST_PER_LINK_CLICK` | inline_link_clicks | `META_LINK_CLICK` | META_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_CPM` | spend | `META_OVERVIEW_SPEND` | META_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_CPM` | impressions | `META_CAMPAIGN_DAILY` | META_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_GADS_SPEND` | — | `GADS_OVERVIEW_SPEND` | GOOGLE_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_META_SPEND` | — | `META_OVERVIEW_SPEND` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_GADS_CPA` | — | `GADS_OVERVIEW_CPA` | GOOGLE_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_GADS_CPA` | — | `GADS_OVERVIEW_PRIMARY_CONVERSIONS` | GOOGLE_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_GADS_CVR` | primary_conversions | `GADS_OVERVIEW_PRIMARY_CONVERSIONS` | GOOGLE_ADS_DATA_CONTRACT | numerator | see DCR | required for VALUE | mapping/plan | not_applicable | google_ads_customer_time_zone |
| `FORMULA_GADS_CVR` | clicks | `GADS_CAMPAIGN_DAILY` | GOOGLE_ADS_DATA_CONTRACT | denominator | see DCR | required for VALUE | mapping/plan | not_applicable | google_ads_customer_time_zone |
| `FORMULA_META_COST_PER_RESULT` | — | `META_ACTION_TYPE_DAILY` | META_ADS_DATA_CONTRACT | typed_actions | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_COST_PER_RESULT` | — | `META_CAMPAIGN_COST_RESULT` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_COST_PRIMARY` | — | `META_OVERVIEW_COST_PRIMARY` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_META_FREQUENCY` | — | `META_CAMPAIGN_FREQUENCY` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | not_applicable |
| `FORMULA_GA4_ENGAGEMENT_RATE` | — | `GA4_BEH_ENGAGEMENT_RATE` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_ENGAGEMENT_RATE` | — | `GA4_OVERVIEW_SESSIONS` | GA4_DATA_CONTRACT | sessions | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | — | `GA4_BEH_AVG_ENGAGEMENT_TIME` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_VIEWS_PER_SESSION` | — | `GA4_BEH_VIEWS_PER_SESSION` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_CHANNEL_SHARE` | — | `GA4_OVERVIEW_ACQUISITION_MIX` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_DEVICE_SHARE` | — | `GA4_BEH_DEVICES` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | — | `GA4_OVERVIEW_BUSINESS_ACTIONS` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | — | `GA4_MEAS_EVENTS` | GA4_DATA_CONTRACT | events | see DCR | required for VALUE | mapping/plan | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | — | `GA4_OVERVIEW_LANDING_PULSE` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | not_applicable | ga4_property_timezone |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | — | `GA4_MEAS_UTM_HYGIENE` | GA4_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | ga4_property_timezone |
| `FORMULA_GADS_BUDGET_PACING` | — | `GADS_OVERVIEW_BUDGET_PACING` | GOOGLE_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | google_ads_customer_time_zone |
| `FORMULA_GADS_CAMPAIGN_PACING` | — | `GADS_CAMPAIGN_PACING` | GOOGLE_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | not_applicable |
| `FORMULA_META_BUDGET_PACING` | — | `META_OVERVIEW_BUDGET_PACING` | META_ADS_DATA_CONTRACT | consumer | see DCR | required for VALUE | mapping/plan | CP_ACCOUNT_CURRENCY | meta_ad_account_timezone |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | — | `GSC_PERF_AVG_POSITION` | SEARCH_CONSOLE_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | not_applicable |
| `FORMULA_WEB_INVENTORY_COUNT` | — | `WEB_OVERVIEW_INVENTORY` | WEBSITE_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | not_applicable |
| `FORMULA_WEB_TLS_DAYS_REMAINING` | — | `WEB_INFRA_TLS` | WEBSITE_DATA_CONTRACT | consumer | see DCR | required for VALUE | — | not_applicable | observation_timestamp |

## Appendix B — Output Semantics Matrix

| Formula ID | Output type | Unit | Internal scale | Presentation | Round display only? | Semantic direction | Provenance |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | RATIO | FRACTION | INTERNAL_FRACTION_DISPLAY_PERCENT | RP_PERCENT_DISPLAY | YES | HIGHER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_GADS_CTR` | RATIO | FRACTION | INTERNAL_FRACTION_DISPLAY_PERCENT | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_CTR_ALL` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_LINK_CTR` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | HIGHER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_GADS_CPC` | MONEY | ACCOUNT_CURRENCY_PER_CLICK | ACCOUNT_CURRENCY_PER_CLICK | RP_MONEY_DISPLAY | YES | LOWER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_META_CPC` | MONEY | ACCOUNT_CURRENCY_PER_CLICK | ACCOUNT_CURRENCY_PER_CLICK | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_COST_PER_LINK_CLICK` | MONEY | ACCOUNT_CURRENCY_PER_LINK_CLICK | ACCOUNT_CURRENCY_PER_LINK_CLICK | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_CPM` | MONEY | ACCOUNT_CURRENCY_PER_1000_IMPRESSIONS | ACCOUNT_CURRENCY_PER_1000_IMPRESSIONS | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GADS_SPEND` | MONEY | ACCOUNT_CURRENCY | ACCOUNT_CURRENCY | RP_MONEY_DISPLAY | YES | NEUTRAL | MOXDOP_DERIVED |
| `FORMULA_META_SPEND` | MONEY | ACCOUNT_CURRENCY | ACCOUNT_CURRENCY | RP_MONEY_DISPLAY | YES | NEUTRAL | MOXDOP_DERIVED |
| `FORMULA_GADS_CPA` | MONEY | ACCOUNT_CURRENCY_PER_CONVERSION | ACCOUNT_CURRENCY_PER_CONVERSION | RP_MONEY_DISPLAY | YES | LOWER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_GADS_CVR` | RATIO | FRACTION | INTERNAL_FRACTION_DISPLAY_PERCENT | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_COST_PER_RESULT` | MONEY | ACCOUNT_CURRENCY_PER_RESULT | ACCOUNT_CURRENCY_PER_RESULT | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_COST_PRIMARY` | MONEY | ACCOUNT_CURRENCY_PER_LEAD | ACCOUNT_CURRENCY_PER_LEAD | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_FREQUENCY` | RATIO | IMPRESSIONS_PER_REACHED_PERSON | IMPRESSIONS_PER_REACHED_PERSON | RP_NO_INTERMEDIATE | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_ENGAGEMENT_RATE` | RATIO | FRACTION | INTERNAL_FRACTION_DISPLAY_PERCENT | RP_PERCENT_DISPLAY | YES | HIGHER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | DURATION | SECONDS | SECONDS | RP_NO_INTERMEDIATE | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_VIEWS_PER_SESSION` | RATIO | VIEWS_PER_SESSION | VIEWS_PER_SESSION | RP_NO_INTERMEDIATE | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_CHANNEL_SHARE` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_DEVICE_SHARE` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | COUNT | ACTIONS | ACTIONS | RP_COUNT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | RATIO | FRACTION | FRACTION | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | RATIO | FRACTION | RELATIVE_PERCENT_CHANGE | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_PERIOD_ABSOLUTE_DELTA` | DECIMAL | INPUT_UNIT | INPUT_UNIT | RP_NO_INTERMEDIATE | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_PERCENTAGE_POINT_DELTA` | PERCENTAGE_POINT | PERCENTAGE_POINT | PERCENTAGE_POINT | RP_PERCENT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GADS_BUDGET_PACING` | DECIMAL | PACING_STATE_AND_MONEY | PACING_STATE_AND_MONEY | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GADS_CAMPAIGN_PACING` | OTHER | ENUM_LABEL | ENUM_LABEL | RP_NO_INTERMEDIATE | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_META_BUDGET_PACING` | OTHER | PACING_STATE | PACING_STATE | RP_MONEY_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | POSITION | AVERAGE_POSITION | AVERAGE_POSITION | RP_NO_INTERMEDIATE | YES | LOWER_IS_GENERALLY_FAVORABLE | MOXDOP_DERIVED |
| `FORMULA_WEB_INVENTORY_COUNT` | COUNT | URLS | URLS | RP_COUNT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |
| `FORMULA_WEB_TLS_DAYS_REMAINING` | DURATION | DAYS | DAYS | RP_COUNT_DISPLAY | YES | CONTEXT_DEPENDENT | MOXDOP_DERIVED |

## Appendix C — Zero / Missing Matrix

| Formula | Normal | Num zero | Den zero | 0/0 | Num missing | Den missing | Not configured | Unavailable | Partial | Stale |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GADS_CTR` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_CTR_ALL` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_LINK_CTR` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GADS_CPC` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_CPC` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_COST_PER_LINK_CLICK` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_CPM` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GADS_CPA` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GADS_CVR` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_COST_PER_RESULT` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_COST_PRIMARY` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_META_FREQUENCY` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_ENGAGEMENT_RATE` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_VIEWS_PER_SESSION` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_CHANNEL_SHARE` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_DEVICE_SHARE` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | VALUE | UNDEFINED | UNDEFINED_RELATIVE_CHANGE | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | n/a | UNAVAILABLE | PARTIAL | STALE_DERIVED |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | VALUE | VALUE_ZERO | UNDEFINED_ZERO_DENOMINATOR | UNDEFINED | NOT_COLLECTED | NOT_COLLECTED | NOT_CONFIGURED | UNAVAILABLE | PARTIAL | STALE_DERIVED |

## Appendix D — Currency Matrix

| Formula | Currency source | Same currency required? | FX allowed? | Output currency | Precision | Display | Mismatch |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GADS_CPC` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_CPC` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_COST_PER_LINK_CLICK` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_CPM` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_GADS_SPEND` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_SPEND` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_GADS_CPA` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_COST_PER_RESULT` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_COST_PRIMARY` | CP_ACCOUNT_CURRENCY | YES if money | NO | numerator source currency | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | not_applicable | YES if money | NO | n/a | full internal | RP_COUNT_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_GADS_BUDGET_PACING` | CP_ACCOUNT_CURRENCY | YES if money | NO | n/a | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_GADS_CAMPAIGN_PACING` | not_applicable | YES if money | NO | n/a | full internal | RP_NO_INTERMEDIATE | NOT_COMPARABLE_CURRENCY |
| `FORMULA_META_BUDGET_PACING` | CP_ACCOUNT_CURRENCY | YES if money | NO | n/a | full internal | RP_MONEY_DISPLAY | NOT_COMPARABLE_CURRENCY |
| `FORMULA_WEB_INVENTORY_COUNT` | not_applicable | YES if money | NO | n/a | full internal | RP_COUNT_DISPLAY | NOT_COMPARABLE_CURRENCY |

## Appendix E — Timezone Matrix

| Formula | Provider/source | Reporting TZ policy | Period-based? | Previous-period dependency | Cross-source? | Limitation |
| --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | SEARCH_CONSOLE_DATA_CONTRACT,WEBSITE_DATA_CONTRACT | gsc_reporting_date_semantics | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_CTR` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_CTR_ALL` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_LINK_CTR` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_CPC` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_CPC` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_COST_PER_LINK_CLICK` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_CPM` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_SPEND` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_SPEND` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_CPA` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_CVR` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_COST_PER_RESULT` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_COST_PRIMARY` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_FREQUENCY` | META_ADS_DATA_CONTRACT | not_applicable | YES | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_ENGAGEMENT_RATE` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_VIEWS_PER_SESSION` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_CHANNEL_SHARE` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_DEVICE_SHARE` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | GA4_DATA_CONTRACT | ga4_property_timezone | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | GA4_DATA_CONTRACT,GOOGLE_ADS_DATA_CONTRACT,META_ADS_DATA_CONTRACT,SEARCH_CONSOLE_DATA_CONTRACT | not_applicable | YES | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_PERIOD_ABSOLUTE_DELTA` | GA4_DATA_CONTRACT,SEARCH_CONSOLE_DATA_CONTRACT,GOOGLE_ADS_DATA_CONTRACT,META_ADS_DATA_CONTRACT | not_applicable | YES | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_PERCENTAGE_POINT_DELTA` | GA4_DATA_CONTRACT,SEARCH_CONSOLE_DATA_CONTRACT,META_ADS_DATA_CONTRACT | not_applicable | YES | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_BUDGET_PACING` | GOOGLE_ADS_DATA_CONTRACT | google_ads_customer_time_zone | YES | not_applicable | NO by default | Do not rebucket via server UTC |
| `FORMULA_GADS_CAMPAIGN_PACING` | GOOGLE_ADS_DATA_CONTRACT | not_applicable | YES | not_applicable | NO by default | Do not rebucket via server UTC |
| `FORMULA_META_BUDGET_PACING` | META_ADS_DATA_CONTRACT | meta_ad_account_timezone | YES | not_applicable | NO by default | Do not rebucket via server UTC |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | SEARCH_CONSOLE_DATA_CONTRACT | not_applicable | YES | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | NO by default | Do not rebucket via server UTC |
| `FORMULA_WEB_INVENTORY_COUNT` | WEBSITE_DATA_CONTRACT | not_applicable | grain-dependent | not_applicable | NO by default | Do not rebucket via server UTC |
| `FORMULA_WEB_TLS_DAYS_REMAINING` | WEBSITE_DATA_CONTRACT | observation_timestamp | grain-dependent | not_applicable | NO by default | Do not rebucket via server UTC |

## Appendix F — Comparison Matrix

| Metric / Formula | Comparison type | Equal-period? | Previous=0 | Same semantic? | Same result type? | Same currency? | Partial allowed? | Output unit | Semantic direction |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `FORMULA_GSC_CTR` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | HIGHER_IS_GENERALLY_FAVORABLE |
| `FORMULA_GADS_CTR` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_META_CTR_ALL` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_META_LINK_CTR` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | HIGHER_IS_GENERALLY_FAVORABLE |
| `FORMULA_GADS_CPC` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_CLICK | LOWER_IS_GENERALLY_FAVORABLE |
| `FORMULA_META_CPC` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_CLICK | CONTEXT_DEPENDENT |
| `FORMULA_META_COST_PER_LINK_CLICK` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_LINK_CLICK | CONTEXT_DEPENDENT |
| `FORMULA_META_CPM` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_1000_IMPRESSIONS | CONTEXT_DEPENDENT |
| `FORMULA_GADS_SPEND` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY | NEUTRAL |
| `FORMULA_META_SPEND` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY | NEUTRAL |
| `FORMULA_GADS_CPA` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_CONVERSION | LOWER_IS_GENERALLY_FAVORABLE |
| `FORMULA_GADS_CVR` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_META_COST_PER_RESULT` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_RESULT | CONTEXT_DEPENDENT |
| `FORMULA_META_COST_PRIMARY` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACCOUNT_CURRENCY_PER_LEAD | CONTEXT_DEPENDENT |
| `FORMULA_META_FREQUENCY` | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | IMPRESSIONS_PER_REACHED_PERSON | CONTEXT_DEPENDENT |
| `FORMULA_GA4_ENGAGEMENT_RATE` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | HIGHER_IS_GENERALLY_FAVORABLE |
| `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | SECONDS | CONTEXT_DEPENDENT |
| `FORMULA_GA4_VIEWS_PER_SESSION` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | VIEWS_PER_SESSION | CONTEXT_DEPENDENT |
| `FORMULA_GA4_CHANNEL_SHARE` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_GA4_DEVICE_SHARE` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_GA4_BUSINESS_ACTION_COUNT` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | ACTIONS | CONTEXT_DEPENDENT |
| `FORMULA_GA4_BUSINESS_ACTION_RATE` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_GA4_UTM_UNAVAILABLE_PCT` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | COMPARE_RELATIVE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | FRACTION | CONTEXT_DEPENDENT |
| `FORMULA_PERIOD_ABSOLUTE_DELTA` | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | INPUT_UNIT | CONTEXT_DEPENDENT |
| `FORMULA_PERCENTAGE_POINT_DELTA` | COMPARE_PERCENTAGE_POINT_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | PERCENTAGE_POINT | CONTEXT_DEPENDENT |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | COMPARE_ABSOLUTE_EQUAL_PREVIOUS_PERIOD | YES | UNDEFINED_RELATIVE_CHANGE for relative | YES | YES if Meta result | YES if money | PARTIAL eligibility | AVERAGE_POSITION | LOWER_IS_GENERALLY_FAVORABLE |

## Appendix G — Aggregation Matrix

| Metric / Formula | Type | Sum safe? | Average safe? | Recompute? | Provider period? | Across days? | Across entities? | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Meta Reach | NON_ADDITIVE | NO | NO | NO from daily | YES period | NO sum days | NO across entities without provider | period provider query |
| Meta Frequency | RATIO/NON_ADDITIVE | NO | NO AVG | period impr/reach or provider | preferred | NO | NO | never AVG daily freq |
| GSC Avg Position | PROVIDER AVG | NO | NO simple AVG | weighted only if combining rows | preferred | careful | careful | prefer provider period |
| CTR family | RATIO | NO | NO | YES sum/sum | NO | YES days | YES entities | recompute |
| CPC/CPM/Cost-Result | COST_PER | NO | NO | YES sum/sum | NO | YES | YES if same currency/type | recompute |
| GA4 Engagement Rate | RATE | NO | NO | YES sum/sum | NO | YES | YES | recompute |
| Additive counts | COUNT | YES | NO | n/a | NO | YES | YES | provider facts |

## Appendix H — Percentage Semantics Matrix

| Metric | Internal | Display | Ratio? | %? | PP? | Relative change? | Example | Comparison formula |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CTR / Engagement Rate / CVR | FRACTION_0_1 | PERCENT_0_100 | YES | display | NO | separate | 0.06 → 6% | FORMULA_PERCENTAGE_POINT_DELTA and/or RELATIVE |
| Relative period change | FRACTION_0_1 | PERCENT_0_100 | NO | relative % | NO | YES | 100→120 = +20% | FORMULA_PERIOD_RELATIVE_CHANGE |
| Rate period change | PERCENTAGE_POINT | pp | NO | NO | YES | NO | 4%→6% = +2 pp | FORMULA_PERCENTAGE_POINT_DELTA |
| Provider impression share | provider | % display | NO | provider % | NO | via period formulas | 68% | PERIOD formulas on provider fact |

## Appendix I — Rejected / Magic Scores

| ID | Reason |
| --- | --- |
| REJECTED_WEBSITE_HEALTH_SCORE | Frozen product forbids Website Health Score |
| REJECTED_SEO_SCORE | No transparent frozen methodology |
| REJECTED_OPPORTUNITY_SCORE | Opportunities are domain concepts |
| REJECTED_CAMPAIGN_HEALTH_SCORE | Pacing ≠ health score |
| REJECTED_GADS_ROAS | NOT REQUIRED V1 by Google Ads contract |
| REJECTED_META_ROAS | No ROAS UI in Meta freeze |
| REJECTED_QUALIFIED_LEAD_RATE | Not a frozen V1 KPI formula; Business Outcome dependency not production — exclude until Value/Outcome freeze requires it |

Expected magic scores in product: **NONE**.

---

MOXDOP_DATA_CONTRACT_REGISTRY_V1 defines which source facts MoxDOP requires.



MOXDOP_FORMULA_REGISTRY_V1 defines how deterministic derived metrics are calculated from those facts.



Provider facts must never be silently replaced by MoxDOP-derived values.

Derived metrics must use canonical inputs, aggregation rules, missing-value semantics, timezone semantics, currency semantics and formula versions.



Zero is a real value. Missing is not zero. Undefined division is not zero. Percentage change is not percentage-point change. Different currencies are not directly comparable. Different typed Results are not directly comparable. Intermediate values are not rounded for presentation convenience.

