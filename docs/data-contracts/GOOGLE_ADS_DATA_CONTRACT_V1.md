# GOOGLE ADS DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR COLLECTION IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Cumulative docs base | `cursor/data-contract-gsc-ea01` @ `d2be8c4a1de99c8e175c082d0c06903dc4193502` (includes GA4 + Search Console Data Contract V1; not yet on `main`) |
| Audit branch | `cursor/data-contract-google-ads-ea01` |
| Runtime product code changed | **NONE** |

Future semantic changes require **v2** or an explicit amendment.

Official Google references used for provider field names (not blogs):

- [Google Ads API field reference — metrics](https://developers.google.com/google-ads/api/fields/v21/metrics)
- [Google Ads API — customer](https://developers.google.com/google-ads/api/fields/v21/customer)
- [Google Ads API — campaign](https://developers.google.com/google-ads/api/fields/v21/campaign)
- [Google Ads API — search_term_view](https://developers.google.com/google-ads/api/fields/v21/search_term_view)
- [Google Ads API — campaign_search_term_view](https://developers.google.com/google-ads/api/fields/v21/campaign_search_term_view)
- [Google Ads API — keyword_view](https://developers.google.com/google-ads/api/fields/v21/keyword_view)
- [Google Ads API — landing_page_view](https://developers.google.com/google-ads/api/fields/v21/landing_page_view)
- [Google Ads API — conversion_action](https://developers.google.com/google-ads/api/fields/v21/conversion_action)
- [GoogleAdsService Search / SearchStream](https://developers.google.com/google-ads/api/docs/reporting/streaming)
- [Conversion management overview](https://developers.google.com/google-ads/api/docs/conversions/overview)
- Installed collector targets **API v25** (`GoogleAdsBoundCollector`); field names must be re-checked against the installed version’s field reference at implementation time when marked **REQUIRES PROVIDER VERIFICATION**.

Hard semantic boundaries for this contract:

1. **GOOGLE ADS DATA ≠ BUSINESS OUTCOME**
2. **PLATFORM CONVERSION ≠ QUALIFIED LEAD / SALE / PATIENT**
3. **SEARCH TERM ≠ KEYWORD**
4. **CAMPAIGN ENTITY ≠ GA4 CAMPAIGN DIMENSION**
5. **PROVIDER METRIC ≠ MOXDOP INTERPRETATION**

---

## 1. Purpose

Define **exactly** what the frozen Google Ads operator workspace requires from the Google Ads API and from MoxDOP domains **before** any production GAQL collector expansion, SearchStream ingestion, warehouse table, migration, queue job, Evidence pipeline, or UI migration.

```text
Frozen Google Ads UI
  → Explicit provider requirements
  → Explicit formulas
  → Explicit semantic boundaries
  → Future normalized storage
  → Future Evidence
```

The future Google Ads collector **must not invent** data requirements. This document already decided.

**Hard boundary of this milestone:** audit + documentation only. No collectors, migrations, live Customer API pulls, OAuth/developer-token changes, UI redesign, Evidence/Findings implementation, or provider writes.

---

## 2. Frozen UI Scope

### Verified primary IA

Source: `App\Livewire\Demo\GoogleAds\OverviewPage::$allowedTabs`, views under `resources/views/livewire/demo/google-ads/`, `App\Support\Demo\GoogleAdsWorkspaceFixtures`, `docs/product/google-ads/GOOGLE_ADS.md`.

| Tab key | Operator label | Present in freeze |
| --- | --- | --- |
| `overview` | Overview | YES |
| `campaigns` | Campaigns | YES |
| `search_demand` | Search & Demand | YES (subs: `terms` · `keywords` · `inbox` · `drift`) |
| `ads_assets` | Ads & Assets | YES |
| `landing_pages` | Landing Pages | YES |
| `measurement` | Measurement | YES |
| `operations` | Operations | YES (subs: Findings · Recommendations · Tasks · Outcomes) |

Legacy remaps (not primary IA): `adgroups`→campaigns; `keywords`/`search_terms`→search_demand; `ads`→ads_assets; `conversions`→measurement; `insights`→overview.

### Supporting surfaces audited

- Route / Livewire: `demo.google-ads` → `OverviewPage`
- Fixtures: `GoogleAdsWorkspaceFixtures`
- Shared period: `InteractsWithDemoPeriod` + `DemoPeriod` + `period-bar`
- Drawers: campaign, cluster, ad, landing, attention, finding
- Existing runtime (not Demo UI): `MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector`, `GoogleAdsDiscoverer`, Google OAuth / developer-token path, Evidence types listed in product blueprint

### Explicit non-goals of the frozen product (for collection)

- No Google Ads **write** actions (pause/enable, bids, budgets, ads, negatives application)
- No user-level / GCLID / Customer Match / PII streams
- No Measurement Score / Budget Health Score inventions
- No equating platform conversions with Business Outcomes
- No silent merge of Google Ads `campaign` with GA4 `sessionCampaignName`
- No treating Top-N UI presentation as a collection limit

---

## 3. Provider Capability Boundary

| Capability | Google Ads surface | Can support |
| --- | --- | --- |
| Account / customer metadata | `customer`, discovery via `customer_client` / `listAccessibleCustomers` | id, name, currency, timezone, manager/test flags |
| Campaign entity + metrics | `campaign` + metrics + `segments.date` | status, channel type, daily cost/clicks/impressions/conversions |
| Budgets | `campaign_budget` (linked from campaign) | amount_micros, delivery method, shared flag |
| Search terms (Search) | `search_term_view` | term, ad group, campaign, metrics; **privacy omissions** |
| Search terms (PMax) | `campaign_search_term_view` | campaign-level terms (no ad group) |
| Keywords | `keyword_view` + `ad_group_criterion.keyword` | text, match type, status, metrics |
| Ads | `ad_group_ad` | ad id, type, status, RSA assets, final URLs, Ad Strength |
| Landing pages | `landing_page_view` | `unexpanded_final_url` + metrics (+ optional campaign segment) |
| Conversion actions | `conversion_action` | metadata + `include_in_conversions_metric` / primary flags |
| Impression share (Search) | campaign-level competitive metrics | Search IS / lost IS budget / lost IS rank |
| Reporting transport | `GoogleAdsService.Search` / `SearchStream` | read-only GAQL |
| **Not in this contract** | mutate services | any write |

MoxDOP will **not** scrape the Google Ads UI, invent auction insights beyond documented metrics, or apply negatives/bids via API.

---

## 4. Source Classification

| Class | Meaning |
| --- | --- |
| `GOOGLE_ADS_RESOURCE` | Provider entity / attribute (campaign, keyword, conversion_action, …) |
| `GOOGLE_ADS_METRIC` | Provider-measured metric (`metrics.*`) |
| `GOOGLE_ADS_CONFIGURATION` | Provider config (budget amount, bidding type, include_in_conversions_metric, …) |
| `MOXDOP_DERIVED` | Computed in MoxDOP from base facts (CTR, CPC, CPA, pacing math) |
| `MOXDOP_MAPPING` | Operator/system mapping (Conversion Action → Business Action) |
| `MOXDOP_CLASSIFICATION` | Intent / fit / decision / measurement health labels |
| `CROSS_ASSET` | Website / GA4 / GSC / Brand joins |
| `OPERATOR_MAINTAINED` | Campaign Context, planned agency budget, strategy notes |
| `OPERATIONS_DOMAIN` | Findings, Recommendations, Tasks, Outcomes |
| `UNAVAILABLE` | Cannot be obtained from Google Ads as frozen UI implies |
| `DEMO_ONLY` | Fixture/stub only; not a collection requirement |

A requirement may list multiple classes as dependencies.

---

## 5. UI Requirement Matrix

Column abbreviations: **Req** = Required / Optional / Conditional / Unavailable / Demo-only.

| Requirement ID | Workspace | UI component | Operator question | Semantic definition | Demo source | Source class | Resource / view | Exact provider fields | Segments | Metrics | Filters | Grain | Date | Comparison | TZ | Currency | Formula | Mapping | Cross-asset | Req | Provenance | Missing | Completeness | Dataset | Coverage | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GADS_ACCOUNT_ID | Header | Identity | Which Ads customer? | Google Ads customer id | Fixture identity | GOOGLE_ADS_RESOURCE | `customer` | `customer.id` | — | — | — | entity | snapshot | — | account | — | — | — | — | Required | Provider | block collection | full for accessible | account snapshot | Discoverer KEEP | digits only |
| GADS_ACCOUNT_NAME | Header | Title | What is the account called? | Descriptive name | Fixture title | GOOGLE_ADS_RESOURCE | `customer` | `customer.descriptive_name` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | show external id | — | account snapshot | Discoverer KEEP | |
| GADS_ACCOUNT_TIMEZONE | Cross-cutting | Date interpretation | Which TZ defines a day? | Account reporting timezone | DemoPeriod Europe/Berlin | GOOGLE_ADS_RESOURCE | `customer` | `customer.time_zone` | — | — | — | entity | snapshot | — | **account TZ** | — | — | — | Brand TZ policy | Required | Provider | do not default UTC | — | account snapshot | Discoverer has; collector ComparisonPeriod UTC = **gap** | Hard rule |
| GADS_ACCOUNT_CURRENCY | Cross-cutting | Money format | Which currency? | Account currency code | Fixture ₺ display | GOOGLE_ADS_RESOURCE | `customer` | `customer.currency_code` | — | — | — | entity | snapshot | — | — | account | micros÷1e6 | — | — | Required | Provider | Unavailable | — | account snapshot | Discoverer KEEP | No FX |
| GADS_ACCOUNT_MANAGER | Binding | MCC context | Manager login customer? | login-customer-id / manager flag | metadata | GOOGLE_ADS_RESOURCE | `customer` / discovery | `customer.manager`, metadata `login_customer_id` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider + binding | setup-required | — | binding metadata | Discoverer KEEP | for API headers |
| GADS_ACCOUNT_FRESHNESS | Header | Freshness chips | How old is Ads data? | Last successful Run age | Fixture chips | OPERATIONS_DOMAIN + Run | — | Run timestamps | — | — | — | run | — | — | app | — | — | — | — | Required | MoxDOP Run | Unknown | — | — | partial | not a GAQL field |
| GADS_OVERVIEW_SPEND | Overview | KPI Spend | How much was spent? | Sum cost in account currency | `glance.spend` | GOOGLE_ADS_METRIC | `customer` or sum of campaign daily | — | `segments.date` | `metrics.cost_micros` | non-REMOVED campaigns optional | daily→range | Shared Date Range | relative % vs previous | account | account | micros÷1e6; sum | — | — | Required | Provider | Unavailable ≠ 0 | — | `google_ads_account_daily` / campaign daily | collector range summary | Prefer reaggregatable daily |
| GADS_OVERVIEW_PRIMARY_CONVERSIONS | Overview | KPI Primary conversions | How many primary mapped platform conversions? | **Not** all conversions; mapped primary action set (Demo: Lead form) | `glance.conversions` labeled leads | GOOGLE_ADS_METRIC + MOXDOP_MAPPING | campaign/customer + conversion_action | mapping to action ids | `segments.date` | Prefer mapped action performance; else `metrics.conversions` **only if** mapping ≡ include_in_conversions set | — | daily→range | Shared | relative % | account | — | sum mapped | **Business Action map** | ≠ Outcome | Required | Provider + mapping | if mapping missing → Unavailable | late conversions | conversion_action_daily / campaign daily | collector uses raw `conversions` — **semantic gap** | See §8 |
| GADS_OVERVIEW_CPA | Overview | Cost / primary conversion | Cost per primary conversion? | spend / primary conversions | `glance.cpa` | MOXDOP_DERIVED | — | — | — | cost + primary conv | — | range | Shared | relative % (Demo +6%) | account | account | F_GADS_CPA | mapping | — | Required | Derived | Unavailable if denom 0 or mapping missing | — | derived | none | Never average CPA rows |
| GADS_OVERVIEW_BUDGET_PACING | Overview | Budget pacing KPI + panel | Ahead/behind planned spend? | Agency **planned monthly budget** vs actual spend vs elapsed | `pacing.*` source “Agency planned budget” | OPERATOR_MAINTAINED + MOXDOP_DERIVED + GOOGLE_ADS_METRIC | spend from Ads; budget plan from operator | spend: `cost_micros` | date | cost | — | MTD/range | calendar month in account TZ | — | account | account | F_GADS_BUDGET_PACING | — | — | Required | Hybrid | if no planned budget → Unavailable | — | derived | **not** Ads campaign_budget alone | Do not invent Budget Health Score |
| GADS_OVERVIEW_TREND | Overview | Performance chart | Spend & primary conv over time? | Daily series | `performance` 14d series | GOOGLE_ADS_METRIC + MOXDOP_MAPPING | account/campaign daily | — | `segments.date` | cost_micros + primary conv | — | **daily** | Shared | vs previous window narrative | account | account | — | mapping | — | Required | Provider | gaps ≠ 0 | late conv | daily datasets | missing daily grain today | |
| GADS_OVERVIEW_NEEDS_ATTENTION | Overview | Attention list | What needs operator action? | Finding-backed attention cards | `needs_attention` | OPERATIONS_DOMAIN | — | Evidence deps | — | — | — | — | — | — | — | — | — | — | Website Finding possible | Conditional | Ops | hide empty | — | — | Demo | no GAQL solely for card |
| GADS_OVERVIEW_CAMPAIGN_PORTFOLIO | Overview | Campaign table snippet | Portfolio snapshot? | Same metrics as Campaigns (subset) | `campaigns` | see campaign rows | `campaign` | see below | date | cost, conv | — | range from daily | Shared | — | account | account | CPA derived | context | — | Required | Provider | — | — | campaign daily | top-N UI ≠ limit | |
| GADS_OVERVIEW_SEARCH_SUMMARY | Overview | Search demand glance | Review spend / inbox? | Derived from search-term classifications | `search` summary | MOXDOP_CLASSIFICATION + GOOGLE_ADS_METRIC | search_term daily | — | — | cost of classified terms | — | range | Shared | — | account | account | sum review spend | — | GSC optional later | Required | Hybrid | — | search terms incomplete | search_term_daily | Demo | |
| GADS_OVERVIEW_LANDING_SUMMARY | Overview | Landing glance | Pages needing review / exposure? | Ads spend on URLs + Website signals | `landing_pages` | GOOGLE_ADS_METRIC + CROSS_ASSET | `landing_page_view` | URL + cost | date | cost_micros, clicks, conv | — | daily→range | Shared | — | account | account | — | — | Website | Required | Hybrid | — | URL normalization | landing_page_daily | collector final_urls only | |
| GADS_OVERVIEW_MEASUREMENT_GLANCE | Overview | Measurement glance | Measurement healthy? | MoxDOP classification over actions | `measurement.glance` | MOXDOP_CLASSIFICATION + GOOGLE_ADS_CONFIGURATION | conversion_action | status, include flags | — | recent signal | — | snapshot + recent metrics | — | — | — | — | — | Business Action | GA4 | Required | Hybrid | Incomplete ≠ Healthy | — | conversion_action snapshot | partial | No Measurement Score |
| GADS_OVERVIEW_SPEND_BY_OFFERING | Overview | Spend by offering | Spend by Brand offering? | Group campaign spend by operator Offering | `spend_by_offering` | OPERATOR_MAINTAINED + GOOGLE_ADS_METRIC | campaign daily + context | — | — | cost | — | range | Shared | — | account | account | sum | Campaign Context | Brand Offering | Required | Hybrid | unmapped → Other | — | campaign daily | Demo | |
| GADS_OVERVIEW_OPPORTUNITIES | Overview | Opportunity cards | Where to act next? | Ops / derived summaries | `opportunities` | OPERATIONS_DOMAIN / DEMO_ONLY | — | — | — | — | — | — | — | — | — | — | — | — | — | Demo-only | Demo | — | — | — | Demo | not a collector family |
| GADS_OVERVIEW_OUTCOMES | Overview | Recent outcomes | Did work help? | Operations outcomes | `recentOutcomes` | OPERATIONS_DOMAIN | — | — | — | — | — | — | — | — | — | — | — | — | — | Conditional | Ops | Insufficient evidence | — | — | Demo | |
| GADS_CAMPAIGN_ENTITY | Campaigns | Row identity | Which campaign? | Provider campaign id + name | `campaigns[].id/name` | GOOGLE_ADS_RESOURCE | `campaign` | `campaign.id`, `campaign.name` | — | — | status≠REMOVED | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | campaign snapshot | KEEP | ≠ GA4 campaign dim |
| GADS_CAMPAIGN_STATUS | Campaigns | Badge / drawer | Enabled? | `campaign.status` (ENABLED/PAUSED/REMOVED) | ENABLED | GOOGLE_ADS_RESOURCE | `campaign` | `campaign.status` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | serving≠status | campaign snapshot | KEEP | do not flatten with primary_status if unused |
| GADS_CAMPAIGN_TYPE | Campaigns | Subtitle type | Search vs other? | `advertising_channel_type` | Search | GOOGLE_ADS_RESOURCE | `campaign` | `campaign.advertising_channel_type` (+ subtype if UI needs) | — | — | — | entity | snapshot | — | — | — | map enum→label | — | — | Required | Provider | Unknown | — | campaign snapshot | KEEP | verify enum at API version |
| GADS_CAMPAIGN_BUDGET | Campaigns | Budget column | Configured budget amount? | campaign_budget amount in account currency | `budget` | GOOGLE_ADS_CONFIGURATION | `campaign_budget` | `campaign_budget.amount_micros`, `explicitly_shared`, status | — | — | — | entity | snapshot | — | — | account | micros÷1e6 | — | — | Required | Provider | Unavailable | shared budget cardinality | budget snapshot | **MISSING** in collector | ≠ planned agency budget |
| GADS_CAMPAIGN_DAILY | Campaigns | Spend column + trend | Daily campaign cost/clicks/impr/conv? | Base facts for arbitrary ranges | spend | GOOGLE_ADS_METRIC | `campaign` | — | `segments.date` | `cost_micros`, `impressions`, `clicks`, mapped/primary conversions, optional `conversions_value` | status≠REMOVED | **date×campaign** | Shared | previous equal length | account | account | — | mapping | — | Required | Provider | missing day ≠ 0 | — | `google_ads_campaign_daily` | range aggregates only today | |
| GADS_CAMPAIGN_PRIMARY_CONVERSIONS | Campaigns | Primary result / leads | Primary conversions for campaign? | Mapped primary platform conversions | `leads` | GOOGLE_ADS_METRIC + MOXDOP_MAPPING | campaign | — | date | see §8 | — | date×campaign | Shared | relative | account | — | sum | map | ≠ Outcome | Required | Hybrid | Unavailable if unmapped | — | campaign daily | semantic gap | UI says “leads” colloquially |
| GADS_CAMPAIGN_CPA | Campaigns | CPA column | Cost per primary conv? | spend/primary | `cpa` | MOXDOP_DERIVED | — | — | — | — | — | range | Shared | relative; down may be “better” | account | account | F_GADS_CPA | map | — | Required | Derived | Unavailable | — | derived | | |
| GADS_CAMPAIGN_PACING | Campaigns | Pacing badge | Ahead/Behind/On pace/Constrained? | MoxDOP pacing vs planned or budget+IS | `pacing` | MOXDOP_DERIVED + OPERATOR_MAINTAINED (+ IS) | — | budget + spend + optional lost IS | — | — | — | range/MTD | — | — | account | account | F_GADS_CAMPAIGN_PACING | context | — | Required | Derived | Unavailable without plan/budget | Demo methodology | derived | | |
| GADS_CAMPAIGN_IMPRESSION_SHARE | Campaigns | IS / Lost | Search coverage / lost causes? | Search IS + lost budget + lost rank | `impr_share`, `lost_is_*` | GOOGLE_ADS_METRIC | `campaign` | — | date (if supported) | `metrics.search_impression_share`, `metrics.search_budget_lost_impression_share`, `metrics.search_rank_lost_impression_share` | Search channel | campaign×date or range | Shared | — | account | — | display as % | — | — | Required | Provider | null when N/A | competitive metrics thresholds; **REQUIRES PROVIDER VERIFICATION** for date-segment support on installed API version | campaign daily or IS snapshot | **MISSING** | Do not use auction_insight competitor fields |
| GADS_CAMPAIGN_CONTEXT | Campaigns | Drawer Campaign Context | Offering/market/goal/strategy? | Operator-maintained strategy | offering, market, language, goal, funnel, search_strategy | OPERATOR_MAINTAINED | — | — | — | — | — | entity | — | — | — | — | — | Brand goals | — | Required | Operator | empty context allowed | — | moxdop context store (future) | Demo | **not** from Google Ads |
| GADS_CAMPAIGN_DEVICE_STUB | Campaigns | Drawer breakdowns | Device/location/hour? | Specialist stub in Demo | hardcoded 52/48 | DEMO_ONLY | would be campaign + `segments.device` | — | device | cost | — | — | — | — | — | — | — | — | — | Demo-only | Demo | — | — | — | not required V1 | do not collect for freeze |
| GADS_AD_GROUP_IDENTITY | Search/Ads | Parent of terms/keywords/ads | Which ad group? | id + name + campaign id | `ad_group` in terms | GOOGLE_ADS_RESOURCE | `ad_group` | `ad_group.id`, `ad_group.name`, `ad_group.status` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | ad_group snapshot | partial via search terms | **No ad-group metrics tab**; metrics optional |
| GADS_KEYWORD_ENTITY | Search · keywords | Keyword coverage table | Which configured keyword? | Criterion keyword text | `keywords[].keyword` | GOOGLE_ADS_RESOURCE | `keyword_view` / `ad_group_criterion` | `ad_group_criterion.keyword.text`, criterion id | — | — | ≠REMOVED | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | keyword snapshot | **MISSING** | ≠ search term |
| GADS_KEYWORD_MATCH_TYPE | Search · keywords | Match column | Exact/Phrase/Broad? | Provider match type | Phrase/Exact | GOOGLE_ADS_CONFIGURATION | `ad_group_criterion` | `ad_group_criterion.keyword.match_type` | — | — | — | entity | snapshot | — | — | — | enum→label | — | — | Required | Provider | — | — | keyword snapshot | MISSING | |
| GADS_KEYWORD_STATUS | Search · keywords | (implicit) | Enabled? | criterion status | Demo assumes active | GOOGLE_ADS_RESOURCE | `ad_group_criterion` | `ad_group_criterion.status` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | keyword snapshot | MISSING | |
| GADS_KEYWORD_DAILY | Search · keywords | Spend / leads | Keyword performance? | Daily cost + primary conv | spend, leads | GOOGLE_ADS_METRIC + MOXDOP_MAPPING | `keyword_view` | — | `segments.date` | cost_micros, clicks, impressions, primary conv | — | date×keyword | Shared | — | account | account | — | map | — | Required | Provider | — | — | `google_ads_keyword_daily` | MISSING | Quality Score **not** in UI → not required |
| GADS_KEYWORD_OBSERVED_ALIGNMENT | Search · keywords | aligned/review/misaligned counts | How observed terms fit keyword? | Counts of classified search terms under keyword | aligned/review/misaligned/observed | MOXDOP_CLASSIFICATION | — | join search terms→keyword when provider link exists | — | — | — | range | Shared | — | — | — | count by class | — | — | Required | Derived | if no term link → Unavailable | privacy omissions | derived | Demo | |
| GADS_SEARCH_TERM_DAILY | Search · terms | Search terms table | What queries were observed? | Observed paid search term × campaign × ad group × date | `terms[]` | GOOGLE_ADS_METRIC + RESOURCE | `search_term_view` (+ PMax `campaign_search_term_view`) | `search_term_view.search_term`, campaign/ad_group ids | `segments.date` | cost_micros, clicks, impressions, primary conv | — | date×term×ad_group (Search) / date×term×campaign (PMax) | Shared | — | account | account | — | map | ≠ GSC query | Required | Provider | **no row ≠ zero activity** | privacy thresholds | `google_ads_search_term_daily` | top-N LIMIT today | |
| GADS_SEARCH_TERM_STATUS | Search · terms | (optional) | Added/excluded? | `search_term_view.status` | not shown | GOOGLE_ADS_RESOURCE | `search_term_view` | `search_term_view.status` | — | — | — | entity/range | — | — | — | — | — | — | — | Optional | Provider | — | — | search_term_daily | collector has | useful for negatives |
| GADS_SEARCH_TERM_CLASSIFICATION | Search · terms | Intent / Fit / Decision | Relevant? Negative candidate? | MoxDOP labels | intent, fit, decision | MOXDOP_CLASSIFICATION | — | — | — | — | filters | term | — | — | — | — | methodology TBD | Campaign Context | Brand | Required | MoxDOP | Unclassified ≠ Irrelevant | — | classification store | Demo | not provider |
| GADS_SEARCH_DECISION_INBOX | Search · inbox | Clusters | What decisions await? | Clustered classified terms | `clusters` | MOXDOP_CLASSIFICATION + MOXDOP_DERIVED | — | — | — | sum spend | — | — | Shared | — | — | account | cluster spend | — | Website/GSC for content opp | Required | Derived | — | — | derived | Demo | no auto-apply negatives |
| GADS_SEARCH_INTENT_DISTRIBUTION | Search · drift | Distribution | Intent mix? | Share of classified spend/impr | `intent_distribution` | MOXDOP_DERIVED | — | — | — | — | — | range | Shared | — | — | — | % of classified base | — | — | Required | Derived | Unavailable if none classified | incomplete terms | derived | Demo | No Demand Score |
| GADS_SEARCH_INTENT_DRIFT | Search · drift | Drift | Intent mix changed? | Period-over-period class shares | `intent_drift` | MOXDOP_DERIVED | — | — | — | — | — | two ranges | previous equal length | pp or relative on shares | — | — | delta of shares | — | — | Required | Derived | — | — | derived | Demo | |
| GADS_SEARCH_REVIEWABLE_SPEND | Search | Review spend | Spend needing review? | Sum cost where fit≠Aligned / decision≠None | `review_spend` | MOXDOP_DERIVED | — | — | — | cost | class filter | range | Shared | — | account | account | sum | — | — | Required | Derived | — | — | derived | Demo | |
| GADS_AD_ENTITY | Ads & Assets | Ad rows | Which ad? | ad_group_ad identity | `ads.rows` | GOOGLE_ADS_RESOURCE | `ad_group_ad` | `ad_group_ad.ad.id`, type, campaign/ad_group | — | — | ≠REMOVED | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | ad snapshot | MISSING (only final_urls) | RSA focus in Demo |
| GADS_AD_COPY | Ads & Assets | Headlines | What message? | RSA headlines/descriptions | `headlines` | GOOGLE_ADS_RESOURCE | `ad_group_ad` | RSA `headlines`, `descriptions` (format-specific) | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | format variance | ad snapshot | MISSING | do not over-normalize formats |
| GADS_AD_FINAL_URL | Ads & Assets | final_url | Where does ad land? | final URLs | `final_url` | GOOGLE_ADS_RESOURCE | `ad_group_ad` | `ad_group_ad.ad.final_urls` | — | — | — | entity | snapshot | — | — | — | — | — | Website join | Required | Provider | — | — | ad snapshot | KEEP partial | ≠ landing_page_view alone |
| GADS_AD_STATUS | Ads & Assets | state | Enabled? | ad_group_ad.status | ENABLED | GOOGLE_ADS_RESOURCE | `ad_group_ad` | `ad_group_ad.status` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | ad snapshot | MISSING | |
| GADS_AD_STRENGTH | Ads & Assets | google_strength | Google Ad Strength? | Provider ad strength | Good/Average/Excellent | GOOGLE_ADS_RESOURCE | `ad_group_ad` | `ad_group_ad.ad_strength` | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | Unknown | not a performance metric | ad snapshot | MISSING | ≠ MoxDOP quality |
| GADS_AD_POLICY | Ads & Assets | policy | Approved/Limited? | Policy / approval summary | Approved/Limited | GOOGLE_ADS_RESOURCE / DEMO_ONLY | policy summary fields | **REQUIRES PROVIDER VERIFICATION** exact field set for freeze labels | — | — | — | entity | snapshot | — | — | — | — | — | — | Conditional | Provider | Unknown | — | ad snapshot | unknown | |
| GADS_AD_MESSAGE_MATCH | Ads & Assets | landing_match / intent_match | Message aligned? | MoxDOP judgment | Partial/Weak/Strong | MOXDOP_CLASSIFICATION + CROSS_ASSET | — | — | — | — | — | — | — | — | — | — | — | — | Website + search intent | Required | MoxDOP | Unreviewed | — | — | Demo | |
| GADS_ASSET_EXTENSION_COVERAGE | Ads & Assets | Asset groups Present/Partial/Missing | Extension coverage? | Association presence by type | sitelinks/callouts/… | GOOGLE_ADS_RESOURCE + MOXDOP_DERIVED | asset + asset_link resources | type-specific; **REQUIRES PROVIDER VERIFICATION** | — | — | — | snapshot | — | — | — | — | Present/Partial/Missing rules | — | — | Conditional | Hybrid | Missing is valid state | PMax asset groups ≠ extensions | asset snapshot | MISSING | Demo is extension-style; not full PMax asset-group studio |
| GADS_LANDING_PAGE_DAILY | Landing Pages | Table spend/clicks/leads | LP paid performance? | Metrics by unexpanded final URL | `landing_pages.rows` | GOOGLE_ADS_METRIC | `landing_page_view` | `landing_page_view.unexpanded_final_url` | `segments.date` (+ campaign if needed) | cost_micros, clicks, impressions, primary conv | — | date×url | Shared | — | account | account | — | map | Website/GA4 | Required | Provider | no row ≠ zero | URL explosion | `google_ads_landing_page_daily` | **MISSING** (final_urls only) | |
| GADS_LANDING_CROSS_ASSET | Landing Pages | technical/mobile/measurement | Page healthy? | Website + GA4 signals | technical, mobile, measurement | CROSS_ASSET | Website / GA4 contracts | — | — | — | — | — | — | — | — | — | — | — | Website Finding, GA4 | Required | Cross-asset | Unknown | join key required | — | Demo | no join impl now |
| GADS_LANDING_MESSAGE_MATCH | Landing Pages | message | Message match? | MoxDOP vs ads/queries | message Strong/Partial/Weak | MOXDOP_CLASSIFICATION | — | — | — | — | — | — | — | — | — | — | — | — | Ads + Website | Required | MoxDOP | — | — | — | Demo | |
| GADS_CONVERSION_ACTION_META | Measurement | Matrix sources | Which Ads conversion actions exist? | Action inventory | measurement matrix | GOOGLE_ADS_CONFIGURATION | `conversion_action` | id, name, status, type, category, origin, primary_for_goal, include_in_conversions_metric | — | — | ≠REMOVED | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | conversion_action snapshot | KEEP | never store tag snippets |
| GADS_CONVERSION_ACTION_PRIMARY_FLAG | Measurement | Primary/Secondary | Primary for Google goal? | `primary_for_goal` + include_in_conversions_metric | Primary/Secondary | GOOGLE_ADS_CONFIGURATION | `conversion_action` | those fields | — | — | — | entity | snapshot | — | — | — | — | — | — | Required | Provider | — | — | snapshot | KEEP | ≠ MoxDOP Business Action primary |
| GADS_MEASUREMENT_MATRIX | Measurement | Business action matrix | Connected to outcomes? | Rows = Business Actions × sources | matrix | MOXDOP_MAPPING + CROSS_ASSET + GOOGLE_ADS_CONFIGURATION | — | — | — | — | — | — | — | — | — | — | — | BA mapping | GA4 | Required | Hybrid | Not configured | — | mapping store | Demo | |
| GADS_BUSINESS_ACTION_MAPPING | Measurement | Mapping | Ads action → Business Action? | Explicit map; never by name alone | Lead form ↔ generate_lead-like | MOXDOP_MAPPING | — | — | — | — | — | — | — | — | — | — | — | yes | GA4 maps separate | Required | Operator/system | unmapped | — | mapping store | Demo concept | |
| GADS_MEASUREMENT_HEALTH | Measurement | Healthy / Needs mapping / No recent signal | Trust state? | MoxDOP classification + Evidence | Healthy etc. | MOXDOP_CLASSIFICATION | — | recent metrics + config | — | — | — | — | — | — | — | — | rules TBD | map | — | Required | MoxDOP | Incomplete | — | — | Demo | No score |
| GADS_GA4_LINKAGE | Measurement | GA4 labels | Ads↔GA4 relationship? | Binding / Admin / operator — not one API | ga4_label | CROSS_ASSET / OPERATOR_MAINTAINED | GA4 Admin + MoxDOP Binding | — | — | — | — | — | — | — | — | — | — | — | GA4 contract | Conditional | Multi-source | Unknown | — | — | Demo | |
| GADS_OPS_* | Operations | Findings/Recs/Tasks/Outcomes | Work pipeline? | Operations domain | `operations` | OPERATIONS_DOMAIN | — | Evidence from Ads datasets | — | — | — | — | — | — | — | — | — | — | Website | Conditional | Ops | — | — | — | Demo | no GAQL for cards alone |
| GADS_SHARED_DATE_RANGE | Cross-cutting | Period bar | Which window? | Canonical presets | DemoPeriod | OPERATOR_MAINTAINED UX | — | — | — | — | — | — | presets | previous equal length | **account TZ** for Ads facts | — | — | — | — | Required | Product | — | — | — | Demo uses Europe/Berlin | |
| GADS_PREVIOUS_PERIOD | Cross-cutting | Deltas | vs previous? | Immediately preceding equal-length range | secondary labels | MOXDOP_DERIVED | — | — | — | — | — | — | previousBounds | see §26 | account | — | F_GADS_DELTA_* | — | — | Required | Derived | see §27 | — | — | | |

**Totals (audited requirement IDs): 66**  
**Required: 52 · Optional: 1 · Conditional: 7 · Demo-only: 3 · Unavailable: 0** (Unavailable used for edge semantics, not whole IDs)

---

## 6. Account Metadata

| Need | Field | Source | Required |
| --- | --- | --- | --- |
| Customer ID | `customer.id` | Google Ads | YES |
| Descriptive name | `customer.descriptive_name` | Google Ads | YES |
| Currency | `customer.currency_code` | Google Ads | YES |
| Timezone | `customer.time_zone` | Google Ads | YES |
| Test account | `customer.test_account` | Google Ads | YES (discovery/filter) |
| Manager flag | `customer.manager` | Google Ads | YES |
| Login customer (MCC) | binding metadata `login_customer_id` | Discovery + Binding | YES for API calls |
| Auto-tagging | — | — | **NOT REQUIRED** by frozen UI |
| Account-level conversion tracking “state” string | — | — | Use conversion_action inventory + MoxDOP health instead |

Discovery already selects these via `GoogleAdsDiscoverer` (`customer` / `customer_client` queries).

---

## 7. Core Metric Semantics

| UI / base concept | Provider metric | Unit | Notes |
| --- | --- | --- | --- |
| Spend / Cost | `metrics.cost_micros` | micros of account currency | Display = micros / 1_000_000 |
| Impressions | `metrics.impressions` | count | Base fact; not an Overview KPI card |
| Clicks | `metrics.clicks` | count | **Clicks ≠ Interactions/Engagements/Link clicks** — frozen UI means Ads **clicks** |
| CTR | Prefer derive `clicks/impressions`; provider `metrics.ctr` optional for reconciliation | ratio | **Never average row CTRs** |
| CPC | Prefer derive `cost/clicks`; provider `metrics.average_cpc` is micros | money | **Never average row CPCs** when aggregating |
| CPM | — | — | **NOT REQUIRED BY CONTRACT V1** (not in frozen UI) |
| Impression share family | `metrics.search_impression_share`, `metrics.search_budget_lost_impression_share`, `metrics.search_rank_lost_impression_share` | fraction 0–1 (UI %) | Campaign Search IS columns; verify date-segment support at collect time |

**Canonical storage preference:** store **base facts** (`cost_micros`, `impressions`, `clicks`, conversion counts/values). Derive ratios at read time.

---

## 8. Conversion Semantics

### Hard boundary

Google Ads conversions in this contract are **provider-measured platform conversions**.

They are **not**:

- Qualified Leads
- Consultations / Sales / Patients
- Revenue Business Outcomes

Those belong to **MoxDOP Business Outcomes** (Operations / Brand domain), optionally *informed* by mapped platform conversions + GA4 Business Actions.

### Conversion semantics matrix

| UI label | Provider metric / source | Conversion scope | Included action set | Value semantics | Formula if derived | Date behavior | Attribution caveat | Business Outcome relation | Missing tracking | Provenance |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Primary conversions / “leads” (Overview, Campaigns, Search, Landing) | Prefer performance for **mapped** conversion action(s); fallback `metrics.conversions` only when mapping equals the include_in_conversions set | Actions with `include_in_conversions_metric=true` **or** explicit MoxDOP-mapped subset | Operator-mapped primary Business Action ↔ Ads conversion action id(s) | count | sum | `segments.date` = interaction/reporting date per Google Ads (not “by conversion date” unless explicitly chosen later) | Model/attribution settings affect counts; late conversions may revise history | **Informational only** — not Outcome | If no mapping or no actions → **Unavailable** (not 0) | GOOGLE_ADS_METRIC + MOXDOP_MAPPING |
| All conversions | `metrics.all_conversions` | Broader than Conversions | Includes actions not in Conversions column | — | — | — | — | — | — | **NOT REQUIRED BY CONTRACT V1** |
| Conversion value | `metrics.conversions_value` | Same scope as Conversions | Mapped/include set | account currency value | — | with date segment | — | ≠ revenue Outcome | Unavailable if unused | **Optional** — frozen UI does not show ROAS/value KPIs |
| All conversion value | `metrics.all_conversions_value` | All conversions scope | — | — | — | — | — | — | — | **NOT REQUIRED V1** |
| Cost / primary conversion (CPA) | derived | primary scope | mapped | money | `cost / primary_conversions` | range sums then divide | — | efficiency of platform primary signal only | Unavailable if denom 0 or unmapped | MOXDOP_DERIVED |
| Conversion rate | derived if needed | primary / clicks | — | ratio | `primary_conversions / clicks` | — | — | — | Unavailable if clicks 0 | **Not a frozen Overview KPI**; optional for Evidence |
| ROAS | value/cost | — | — | — | — | — | — | — | — | **NOT REQUIRED V1** |
| Results / Result type | — | — | — | — | — | — | — | — | — | **DEMO wording only** where “Primary result” = primary conversions |

**Do not silently substitute All Conversions for Conversions / Primary conversions.**

---

## 9. Conversion Actions

### Metadata (required)

From `conversion_action` (collector already aligned):

- `conversion_action.id`
- `conversion_action.name`
- `conversion_action.status`
- `conversion_action.type`
- `conversion_action.category`
- `conversion_action.origin`
- `conversion_action.primary_for_goal`
- `conversion_action.include_in_conversions_metric`

**Never** collect `tag_snippets` / secrets.

### Performance

Frozen Measurement needs **recent signal** and primary totals. Campaign/search/landing primary conversion columns need either:

1. **Mapped action performance** segmented by conversion action (preferred for Measurement honesty), or  
2. Account/campaign `metrics.conversions` when mapping ≡ include_in_conversions set.

**Segmentation recommendation:**  
- Account/campaign/search-term/landing daily: store **primary-mapped** conversion counts (and optionally raw `metrics.conversions` for reconciliation).  
- Conversion-action daily: Conditional — required when Measurement must show per-action volume or “no recent signal” per action.

### Business Action mapping

```text
Google Ads Conversion Action
  → MOXDOP_MAPPING (explicit, not by name)
  → MoxDOP Business Action
  → (later) Business Outcome evaluation in Operations
```

Demo already shows Lead form / WhatsApp / Phone / Appointment with mixed Google Ads vs GA4 sources — classify as **MOXDOP_MAPPING** + **CROSS_ASSET**. Do not implement persistence in this prompt.

---

## 10. Campaign Requirements

| Concern | Contract |
| --- | --- |
| Identity | `campaign.id`, `campaign.name` |
| Status | `campaign.status` — do not flatten with limited/serving unless UI adds it |
| Type | `campaign.advertising_channel_type` (Demo: Search). Subtype only if UI needs — currently label “Search” suffices |
| Objective / goal | **OPERATOR_MAINTAINED** Campaign Context (`goal`, funnel, search_strategy) — **not** invented generic provider “Objective” |
| Budget | `campaign_budget.amount_micros` (+ shared flag) |
| Bidding | **NOT REQUIRED** as frozen table column; optional later for intelligence |
| Daily facts | cost_micros, impressions, clicks, primary conversions (optional conversions_value) |
| IS metrics | search impression share family (§7) |
| GA4 | **CAMPAIGN ENTITY ≠ GA4 CAMPAIGN DIMENSION** — join only via explicit future cross-asset rules |

---

## 11. Ad Group Requirements

| Question | Answer |
| --- | --- |
| Required? | **YES** for identity/relationship (search terms, keywords, ads) |
| Metrics tab? | **NO** — do not collect ad-group daily metrics solely for a non-existent tab |
| Metrics needed? | Optional; only if needed to reconcile child rows |
| Fields | `ad_group.id`, `ad_group.name`, `ad_group.status`, parent `campaign.id` |

---

## 12. Keyword Requirements

| Item | Contract |
| --- | --- |
| Required | YES |
| Text | `ad_group_criterion.keyword.text` |
| Match type | `ad_group_criterion.keyword.match_type` |
| Status | `ad_group_criterion.status` |
| Daily facts | cost, clicks, impressions, primary conversions |
| Quality Score / diagnostics | **NOT REQUIRED** (not in frozen UI) |
| Limitation | Keyword ≠ Search Term; observed alignment is MOXDOP_CLASSIFICATION |

---

## 13. Search Term Requirements

| Item | Contract |
| --- | --- |
| Required | YES — high-value |
| Grain | Search: date × search_term × campaign × ad_group; PMax: date × search_term × campaign via `campaign_search_term_view` |
| Metrics | cost_micros, clicks, impressions, primary conversions |
| Status | Optional `search_term_view.status` |
| Completeness | **RETURNED SEARCH TERMS ≠ COMPLETE USER QUERY UNIVERSE** (privacy thresholds omit low-activity terms; campaign totals may exceed term-sum) |
| Classification | Intent / Fit / Decision = **MOXDOP_CLASSIFICATION** |
| Paid demand | Observed paid query interaction/exposure — **not** full market demand; complementary to GSC + DataForSEO |
| Negatives | Decision Inbox “Negative candidate” = recommendation domain — **no write** |

### Search term / keyword matrix

| Concept | Source | Meaning | Completeness | Negative-candidate? | Market demand? | Organic demand? | Joinable? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Configured Keyword | Google Ads criterion | Targeting configuration | Complete for account criteria | Indirect (if wasted) | No | No | To terms when provider associates |
| Observed Search Term | `search_term_view` / PMax view | Actual query reporting row | **Incomplete** (privacy) | Yes (MoxDOP class) | Partial paid signal only | No | Soft join to keyword/GSC by normalized text |
| MoxDOP Search Intent Classification | MoxDOP | Intent/fit/decision labels | As classified | Decision output | Interpretive | Interpretive | N/A |
| External DataForSEO Keyword | DataForSEO | Market keyword metrics | External crawl/API | No (different system) | Yes (market) | Indirect | Future; not Ads |
| GSC Query | Search Console | Organic observed query | GSC sampling/limits | No | Organic observed | Yes | Future text/URL joins |

---

## 14. Ads Requirements

| Item | Contract |
| --- | --- |
| Types in freeze | RSA-style Search ads (Demo) |
| Copy | Headlines (+ descriptions when shown) — format-specific fields |
| Status | `ad_group_ad.status` |
| Final URLs | `ad.final_urls` |
| Ad Strength | `ad_group_ad.ad_strength` |
| Policy | Conditional — verify fields |
| Performance daily | **NOT required** for frozen Ads table (no spend/clicks columns). Optional for future Evidence |
| Message match | MOXDOP_CLASSIFICATION + CROSS_ASSET |

---

## 15. Asset Requirements

| Item | Contract |
| --- | --- |
| Types in freeze | Extension-style coverage: sitelinks, callouts, structured snippets, images, call, location |
| Need | Asset identity/type + association presence → Present/Partial/Missing |
| Performance metrics | **Do not fabricate** universal impressions/clicks/conversions for all asset types |
| Unsupported | Treating every asset type as having campaign-like metrics |
| PMax | Frozen UI is Search-centric; PMax **asset-group studio depth NOT required**. PMax search terms via `campaign_search_term_view` **are** required when PMax campaigns exist |
| Asset vs association | Distinguish Asset resource vs link to campaign/ad group |

---

## 16. Landing Page Requirements

### Landing page matrix

| Concept | Google Ads source | Website | GA4 | Normalized URL key | Provider metrics | Derived | Cross-source limits |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Paid LP performance | `landing_page_view.unexpanded_final_url` | page identity/title | engagement / Business Actions | future normalized URL (scheme/host/path; strip tracking params; policy on trailing slash/query) | cost, clicks, impressions, primary conv | CPA | Ads URL ≠ Website CMS path without normalization |
| Ad final URL list | `ad_group_ad.ad.final_urls` | soft | — | same | none (config) | coverage | Insufficient alone for spend-by-URL |
| Message match | — | content | — | — | — | MOXDOP | subjective |
| Technical/mobile | — | Website Findings | — | — | — | CROSS_ASSET | |
| Measurement on LP | conversion config | — | events | — | — | MOXDOP health | |

**Ad final URLs alone are not sufficient** for Landing Pages workspace metrics.

---

## 17. Budget Requirements

| Item | Contract |
| --- | --- |
| Campaign budget amount | YES — `campaign_budget.amount_micros` |
| Shared flag | YES if shared budgets used |
| Account “Agency planned budget” | **OPERATOR_MAINTAINED** — Overview pacing |
| Infer spend health from budget alone | **NO** |
| Budget Health Score | **FORBIDDEN** |

---

## 18. Bidding Requirements

Frozen Campaigns table does **not** display bidding strategy type / tCPA / tROAS.

| Item | Contract V1 |
| --- | --- |
| Bidding strategy display | **NOT REQUIRED** |
| Bidding ≠ performance judgment | Always true — Maximize Conversions is configuration, not good/bad |

---

## 19. Status / Serving Requirements

Preserve provider enums where used:

| Entity | Field | Notes |
| --- | --- | --- |
| Campaign | `campaign.status` | ENABLED/PAUSED/REMOVED |
| Ad group | `ad_group.status` | relationship |
| Keyword | `ad_group_criterion.status` | |
| Ad | `ad_group_ad.status` | |
| Conversion action | `conversion_action.status` | |
| Search term targeting | `search_term_view.status` | ADDED/EXCLUDED/NONE/… |
| Serving / limited / disapproved | only if UI needs — Ad Strength/policy Conditional | Do not flatten into one generic state |

---

## 20. Measurement Workspace

| Layer | Source |
| --- | --- |
| Conversion action configuration | GOOGLE_ADS_CONFIGURATION |
| Conversion metrics / recent signal | GOOGLE_ADS_METRIC |
| Business Action matrix | MOXDOP_MAPPING + CROSS_ASSET |
| Healthy / Needs mapping / No recent signal / Not configured | MOXDOP_CLASSIFICATION |
| Duplicate Ads+GA4 risk | MOXDOP_CLASSIFICATION + Evidence |
| GA4 relationship | Binding / GA4 Admin / operator — **multi-source** |
| Operations findings | OPERATIONS_DOMAIN |

**No Measurement Score.**

---

## 21. Search & Demand Semantics

| Concept | Meaning | Source |
| --- | --- | --- |
| Configured Keyword | Targeting criterion | Google Ads |
| Observed Search Term | Reported paid query | Google Ads views |
| Paid Demand Signal | Cost/clicks/impr/conv on observed terms (+ classifications) | Ads + MOXDOP |
| External Market Demand | Keyword market datasets | DataForSEO (out of scope collect) |
| Organic Observed Demand | GSC queries | Search Console contract |

**Paid Demand (V1):** observed paid search-term activity (impressions/clicks/cost/primary conversions) interpreted with Campaign Context and MoxDOP intent classes. **No Demand Score.**

Negative keyword candidates = **MoxDOP Recommendation** inputs — never auto-applied.

---

## 22. Cross-Asset Requirements

| Pair | Future use | Join key (contract only) |
| --- | --- | --- |
| Google Ads ↔ Website | LP technical/mobile/message | Normalized URL / Website page id |
| Google Ads ↔ GA4 | Platform conv vs GA4 Business Actions; landing engagement | Mapping tables; URL; **not** campaign name equality |
| Google Ads ↔ GSC | Organic overlap opportunities | Normalized query text |
| Google Ads ↔ DataForSEO | Market demand context | Keyword text |
| Google Ads ↔ Brand Goals / Offering | Campaign Context, spend-by-offering | Operator Campaign Context ↔ Brand Offering ids |

Do **not** implement joins in this milestone.

---

## 23. Operations-Domain Requirements

Operations cards do **not** create GAQL requirements by themselves.

Future Evidence dependencies (examples that exist in Demo):

| Concept | Evidence inputs (future) |
| --- | --- |
| Search intent drift | search_term_daily + classifications + Campaign Context |
| Measurement gap | conversion metrics + mapping + traffic continued (clicks) |
| Landing mobile | landing_page_daily spend + Website Finding |
| Budget pacing | planned budget + cost_micros + elapsed |
| Language mismatch | Campaign Context language + Website page language |

**Do not implement rules now.**

---

## 24. Date / Timezone Contract

| Rule | Definition |
| --- | --- |
| Account timezone | `customer.time_zone` — **GOOGLE ADS DAILY FACTS MUST RESPECT THE ACCOUNT REPORTING TIMEZONE** |
| Do not silently use UTC | Current `ComparisonPeriod::lastTwentyEightCompleteDays` uses UTC — **contract gap to fix later** |
| Brand timezone differs | Store both; **Ads facts keyed in account TZ**; UI Shared Date Range for Ads interpreted in account TZ; document Brand TZ separately for cross-asset charts |
| Shared Date Range presets | `last_7`, `last_14`, `last_28`, `last_30`, `this_month`, `last_month`, `custom`; Demo also has `last_90` |
| Grain | Metric facts: **daily**; entity/config: **snapshot** (current) unless UI needs history |
| Partial period | this_month → available days only; do not extrapolate fake conversions |

---

## 25. Currency Contract

| Rule | Definition |
| --- | --- |
| Provider currency | `customer.currency_code` |
| Monetary metrics | `cost_micros` / value micros → divide by 1e6 for display |
| FX conversion | **NOT IN CONTRACT V1** |
| Cross-currency aggregation | **CROSS-CURRENCY AGGREGATION NOT SUPPORTED BY CONTRACT V1** |

---

## 26. Previous-Period Comparison

Canonical previous period: **immediately preceding equal-length range** (`DemoPeriod::previousBounds`).

| Metric | Comparison presentation (frozen Demo) | Direction note |
| --- | --- | --- |
| Spend | Relative % | Neutral without context |
| Primary conversions | Relative % | Higher often desirable for acquisition — still not Outcome |
| CPA | Relative % (Demo shows “+6%”) | **Higher CPA is typically worse**; coloring needs methodology — do not hardcode universal green/red without product rules |
| CTR (if shown) | Prefer **percentage points** for rate deltas | — |
| CPC (if shown) | Relative % | — |
| Conversion rate (if shown) | Prefer **percentage points** | — |
| Budget pacing | State label, not a simple “good delta” | Context-dependent |

---

## 27. Missing / Zero / Unavailable Semantics

| Situation | Behavior |
| --- | --- |
| No conversion tracking / no mapping | **Unavailable** — never fake 0 conversions |
| No search-term row | **≠ zero query activity** (privacy omission) |
| No clicks | CPC/CTR/CVR **Unavailable** (no Infinity/NaN) |
| Conversions = 0 with tracking present | CPA **Unavailable**; conversions may display 0 |
| Previous = 0, current > 0 | Relative delta **Unavailable** or “new” — never Infinity |
| Both 0 | Show 0 / em dash per UI — not Infinity |
| Currency mismatch across accounts | Do not aggregate |
| Conversion action disabled | Exclude from primary; Measurement state Not configured / Needs review |
| Partial dataset / failed Run | Unknown / stale — not zeros |
| Competitive IS null | Display Unavailable / — |

---

## 28. Provider Request Families

| ID | Resource / view | Consumers | Fields (core) | Segments | Metrics | Filters | Date slicing | Search vs Stream | Grain | Keys | Volume risk | Priority | Why |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| RF_GADS_CUSTOMER_META | `customer` | Account, TZ, currency | id, descriptive_name, currency_code, time_zone, manager, test_account | — | — | — | snapshot | Search | entity | customer_id | Low | Required | Identity |
| RF_GADS_CAMPAIGN_SNAPSHOT | `campaign` (+ budget join) | Campaigns, Overview | id, name, status, advertising_channel_type, campaign_budget fields | — | — | status≠REMOVED | snapshot | Search | entity | campaign_id | Low–Med | Required | Entity/config |
| RF_GADS_CAMPAIGN_DAILY | `campaign` | Overview, Campaigns, Ops Evidence | campaign id | `segments.date` | cost_micros, impressions, clicks, conversions (+ value optional), search IS family | status≠REMOVED | daily slices | SearchStream if large | date×campaign | campaign_id, date | Med | Required | Reaggregatable facts |
| RF_GADS_AD_GROUP_SNAPSHOT | `ad_group` | Relationship | id, name, status, campaign | — | — | ≠REMOVED | snapshot | Search | entity | ad_group_id | Med | Required | Parent keys |
| RF_GADS_KEYWORD_SNAPSHOT_DAILY | `keyword_view` | Search · keywords | criterion keyword text/match/status, campaign, ad_group | `segments.date` | cost, clicks, impr, conversions | ≠REMOVED | daily | SearchStream | date×criterion | criterion_id, date | Med–High | Required | Keyword ≠ term |
| RF_GADS_SEARCH_TERM_DAILY | `search_term_view` | Search & Demand | search_term, status, campaign, ad_group | `segments.date` | cost, clicks, impr, conversions | — | daily | **SearchStream** | date×term×ad_group | composite | **High** | Required | Core demand |
| RF_GADS_PMAX_SEARCH_TERM_DAILY | `campaign_search_term_view` | Search (PMax) | search_term, campaign | `segments.date` | same | channel=PERFORMANCE_MAX | daily | SearchStream | date×term×campaign | composite | High | Conditional | PMax terms |
| RF_GADS_AD_SNAPSHOT | `ad_group_ad` | Ads & Assets | ad id, type, status, strength, headlines/descriptions, final_urls | — | — | ≠REMOVED | snapshot | Search | entity | ad_id | Med | Required | Creative |
| RF_GADS_LANDING_PAGE_DAILY | `landing_page_view` | Landing Pages | unexpanded_final_url | `segments.date` (+ campaign optional) | cost, clicks, impr, conversions | — | daily | SearchStream | date×url | url, date | High | Required | LP metrics |
| RF_GADS_CONVERSION_ACTION_META | `conversion_action` | Measurement | id, name, status, type, category, origin, primary_for_goal, include_in_conversions_metric | — | — | ≠REMOVED | snapshot | Search | entity | action_id | Low | Required | Config |
| RF_GADS_CONVERSION_ACTION_DAILY | segments by conversion action (verify resource) | Measurement signal | action id | date + conversion action segment | conversions / value | — | daily | Search | date×action | action_id, date | Med | Conditional | Per-action freshness |
| RF_GADS_ACCOUNT_DAILY | `customer` | Overview totals / reconcile | — | `segments.date` | cost, clicks, impr, conversions | — | daily | Search | date | customer_id, date | Low | Recommended | Reconcile vs campaign sum |

Do **not** create one GAQL query per UI card.

---

## 29. Search / SearchStream Strategy

Per [Report streaming](https://developers.google.com/google-ads/api/docs/reporting/streaming):

| Family | Recommendation |
| --- | --- |
| Metadata / conversion actions / account daily | `Search` OK |
| Campaign daily (typical portfolio sizes) | `Search` usually OK; Stream if huge MCC children |
| Search terms, keywords, landing pages | Prefer **`SearchStream`** (cardinality / multi-page) |
| Ads snapshot | `Search` or Stream by volume |

One query counts as one operation whether paged or streamed — still minimize redundant families.

**Do not implement either in this prompt.**

---

## 30. Candidate Normalized Datasets

| Dataset ID | Grain | Keys | Base facts | Configuration | Consumers | History | Refresh | Volume | Partition | Completeness |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `google_ads_account_snapshot` | entity | customer_id | — | name, tz, currency, manager, test | header, collectors | current | on discover / daily | tiny | customer_id | full if accessible |
| `google_ads_account_daily` | date | customer_id, date | cost, clicks, impr, conversions | — | Overview | min 90d | daily + late recheck | low | date | provider complete |
| `google_ads_campaign_snapshot` | entity | campaign_id | — | name, status, type, budget_id | Campaigns | current | daily | low–med | customer_id | |
| `google_ads_campaign_budget_snapshot` | entity | budget_id | — | amount_micros, shared | Campaigns | current | daily | low | customer_id | |
| `google_ads_campaign_daily` | date×campaign | campaign_id, date | cost, clicks, impr, primary/raw conversions, IS fields | — | Overview, Campaigns | min 90d | daily + late recheck | med | date | IS may be null |
| `google_ads_ad_group_snapshot` | entity | ad_group_id | — | name, status, campaign_id | relationship | current | daily | med | customer_id | |
| `google_ads_keyword_snapshot` | entity | ad_group_id × criterion_id | — | text, match, status, campaign, ad_group | Keywords | current | daily | med | customer_id | |
| `google_ads_keyword_daily` | date×ad_group×criterion | ad_group_id, criterion_id, date | cost, clicks, impr, conversions | — | Keywords | min 90d | daily | med–high | date | |
| `google_ads_search_term_daily` | date×term×ad_group (or campaign for PMax) | composite | cost, clicks, impr, conversions | status optional | Search & Demand | min 90d (cardinality!) | daily | **high** | date | **privacy incomplete** |
| `google_ads_ad_snapshot` | entity | ad_id | — | copy, urls, status, strength | Ads & Assets | current | daily | med | customer_id | |
| `google_ads_asset_coverage_snapshot` | entity/assoc | asset/link keys | — | type, association state | Ads & Assets | current | daily | med | customer_id | Conditional |
| `google_ads_landing_page_daily` | date×url | normalized_url, date | cost, clicks, impr, conversions | unexpanded_final_url raw | Landing Pages | min 90d | daily | high | date | URL cardinality |
| `google_ads_conversion_action_snapshot` | entity | action_id | — | meta flags | Measurement | current | daily | low | customer_id | |
| `google_ads_conversion_action_daily` | date×action | action_id, date | conversions, value | — | Measurement | min 90d | daily + late recheck | med | date | Conditional |

**Not created automatically** — candidates only. Prefer campaign daily as reconciliation base; keep account daily for provider totals when campaign filters exclude channels.

---

## 31. Derived Formula Registry

| ID | Name | Formula | Inputs | Aggregation | Null | Zero denominator | Currency | Formatting | Comparison | Direction | Provenance | Consumers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| F_GADS_SPEND | Spend | `sum(cost_micros)/1e6` | cost_micros | sum micros then convert | Unavailable if no data | n/a | account | currency | relative % | neutral | Provider base | Overview, Campaigns, … |
| F_GADS_CTR | CTR | `sum(clicks)/sum(impressions)` | clicks, impr | **recompute from sums** | if either missing | if impr=0 → Unavailable | — | % | prefer pp | higher often better | Derived (provider ctr optional reconcile) | Optional UI / Evidence |
| F_GADS_CPC | CPC | `sum(cost)/sum(clicks)` | cost, clicks | recompute | missing → Unavail | clicks=0 → Unavail | account | currency | relative % | context | Derived | Optional |
| F_GADS_CPA | Cost / primary conversion | `sum(cost)/sum(primary_conversions)` | cost, primary conv | recompute | unmapped → Unavail | conv=0 → Unavail | account | currency | relative % | lower often better | Derived | Overview, Campaigns |
| F_GADS_CVR | Conversion rate | `sum(primary_conv)/sum(clicks)` | — | recompute | — | clicks=0 → Unavail | — | % | pp | higher often better | Derived | Optional |
| F_GADS_ROAS | ROAS | — | — | — | — | — | — | — | — | — | — | **NOT IN V1** |
| F_GADS_BUDGET_PACING | Account budget pacing | Compare actual spend to `planned_monthly * elapsed_fraction` | planned (operator), spend, elapsed % | — | no plan → Unavail | — | account | state + money | — | Ahead≠bad always | Derived + operator | Overview |
| F_GADS_CAMPAIGN_PACING | Campaign pacing label | Demo: Ahead/Behind/On pace/Constrained from plan/budget/IS | budget, spend, lost IS | — | Unavail without inputs | — | account | enum | — | context | Derived | Campaigns |
| F_GADS_DELTA_REL | Relative delta | `(curr-prev)/prev` | metric | — | prev=0 → Unavail | — | — | % | — | per metric | Derived | KPI secondaries |
| F_GADS_REVIEW_SPEND | Reviewable spend | sum cost where classification requires review | search_term cost + class | sum | none classified → 0 or Unavail per product | — | account | currency | — | lower often better | Derived | Search overview |

**Incorrect ratio averaging prevented:** always aggregate base facts first.

**Provider vs derived:** prefer derived from base facts for reaggregation; optionally store provider `metrics.ctr` / `average_cpc` for reconciliation only — **canonical display = derived**.

---

## 32. Historical Backfill

| Dataset family | Minimum required history | Recommended initial backfill | Notes |
| --- | --- | --- | --- |
| Daily metric facts (account/campaign/keyword/term/landing) | Cover Shared Date Range max used in product (**90 days** Demo custom max) **plus** equal-length previous period ⇒ **~180 days** practical minimum for last_90+compare | **180–365 days** if quota allows | DECISION REQUIRED for >365d |
| Search terms | Same window | Start **90–180 days** — cardinality/cost risk | Privacy incomplete forever |
| Snapshots (entities/config) | Current only | Current | No CDC unless UI needs history |
| Conversion actions | Current + recent daily signal window | 90d conditional daily | Late conversion recheck |

**Do not choose “all history” automatically.**

---

## 33. Refresh / Freshness

| Dataset | Cadence | Freshness expectation | Late conversion recheck | Staleness |
| --- | --- | --- | --- | --- |
| Snapshots (campaign, ads, keywords, actions, budgets) | ≥ daily | same day | n/a | >24–48h stale warning |
| Daily facts | ≥ daily | T+1 typical | **Yes** — re-pull recent N days (window **REQUIRES PROVIDER VERIFICATION** / product decision; Google Ads conversions can change after click date) | mark incomplete recent days |
| Search terms | ≥ daily | T+1 | same | incomplete by privacy |
| Metadata TZ/currency | on discover + periodic | rare change | n/a | |

Do not implement schedulers here.

---

## 34. Cardinality / Volume Risks

| Risk | Mitigation |
| --- | --- |
| Search term explosion | SearchStream; partition by date; do not Top-N truncate storage |
| Landing URL explosion | normalize URLs; still store full facts for freeze ranges |
| Shared budgets | model budget entity separately |
| IS metrics nulls | allow null |
| Top-N UI | presentation only — **collection is full window** |
| MCC many customers | per-binding collection; quota planning |

---

## 35. Existing Implementation Reuse Matrix

| Component | Responsibility | Fields/data | Contract coverage | Disposition | Notes |
| --- | --- | --- | --- | --- | --- |
| Google OAuth + scopes | Auth | tokens | Account access | KEEP | no change this prompt |
| Developer token config | Ads API auth | token | Required | KEEP | |
| `GoogleAdsDiscoverer` | Resource discovery | customer_client / customer meta | Account metadata | KEEP | |
| Manager hierarchy / login-customer-id | MCC header | metadata | Required | KEEP | |
| `GoogleApiClient` Ads search | HTTP GAQL | v25 | Transport | ADAPT LATER | add Stream |
| `GoogleAdsBoundCollector` | Bound collection | summary, campaigns LIMIT 50, final_urls, search terms LIMIT 200, conversion actions | Partial | ADAPT LATER | daily grain, remove Top-N as storage limit |
| ComparisonPeriod UTC | Date window | 28d UTC | **Wrong TZ vs contract** | ADAPT LATER | use account TZ |
| Evidence types | Persist payloads | landing urls, search terms, conversion actions, account/campaign | Partial | ADAPT LATER | |
| Demo fixtures / OverviewPage | Frozen UI | full IA | Spec source | KEEP (Demo) | not production warehouse |
| Findings evaluator / AI | Intelligence | Evidence-based | Out of scope collect | KEEP | no writes |
| Mutate paths | — | — | Forbidden | KEEP absent | |

---

## 36. Current Collector Gap Analysis

| Item | Classification |
| --- | --- |
| cost_micros, impressions, clicks, conversions, conversions_value on account/campaign (range) | REQUIRED BY CONTRACT (but need **daily** grain) |
| search_term_view + PMax campaign_search_term_view metrics | REQUIRED — currently Top-N truncated (**unsafe** as sole storage) |
| conversion_action metadata fields | REQUIRED — covered |
| final_urls from ad_group_ad | USEFUL BUT NOT SUFFICIENT for Landing Pages |
| customer timezone on collect path | MISSING (discovery has it) |
| campaign_budget amount | MISSING |
| search impression share metrics | MISSING |
| keyword_view daily | MISSING |
| landing_page_view daily | MISSING |
| ad_group_ad copy / strength / status snapshot | MISSING |
| asset coverage | MISSING |
| mapped primary conversions vs raw `metrics.conversions` | SEMANTICALLY WRONG if treated as Business Leads / Outcome |
| LIMIT 50/100/200 as permanent store | UNSAFE / REDUNDANT vs full-window requirement |
| Provider ctr/average_cpc stored without base-fact discipline | USEFUL for reconcile; not canonical |
| Device/hour stubs | DEMO_ONLY — do not collect for V1 freeze |
| all_conversions | NOT REQUIRED |
| Write/mutate | UNSAFE — must remain absent |

---

## 37. Unsupported / Demo-Only Concepts

| Concept | Class |
| --- | --- |
| Device/location/hour breakdown cards in campaign drawer | DEMO_ONLY |
| Opportunity cards copy | DEMO_ONLY / Ops narrative |
| Colloquial “leads” label for platform primary conversions | DEMO wording — map to Primary conversions |
| Auction Insights competitor domains | NOT REQUIRED (and auction insight IS field is restricted) |
| Quality Score history | NOT REQUIRED |
| ROAS / All conversions KPIs | NOT REQUIRED |
| Full PMax asset-group studio | NOT REQUIRED |
| Auto-apply negatives / pause campaigns | FORBIDDEN |
| Measurement Score / Budget Health Score | FORBIDDEN |
| User-level / GCLID warehouse | NOT REQUIRED / forbidden by privacy default |

---

## 38. Decisions Required Before Collection

1. **Primary conversion definition:** Always MoxDOP-mapped action set vs trust `include_in_conversions_metric` when mapping absent?  
2. **Late-conversion reprocessing window** length (official attribution/lag guidance + product tolerance).  
3. **Initial backfill depth** beyond 180 days (quota vs product).  
4. **Account daily vs campaign-sum reconciliation** when channels excluded.  
5. **Search-term storage retention** given cardinality (90 vs 180 vs 365).  
6. **URL normalization policy** shared with Website/GA4 contracts.  
7. **Impression share date-segment support** on installed API version (verify before daily IS facts).  
8. **Ad policy field set** for Approved/Limited labels.  
9. **Brand TZ vs Ads account TZ** display when they differ.  
10. Whether conversion-action daily segmentation is Required or Conditional for Measurement “no recent signal”.

---

## 39. Definition of Done

| Check | Status |
| --- | --- |
| Every frozen Google Ads component traceable? | YES |
| Provider entities separated from metrics? | YES |
| Search Term ≠ Keyword? | YES |
| Platform Conversion ≠ Business Outcome? | YES |
| Conversion Action semantics explicit? | YES |
| Formulas explicit? | YES |
| Campaign/Ad/Asset semantics explicit? | YES |
| Landing-page dependencies explicit? | YES |
| Timezone explicit? | YES |
| Currency explicit? | YES |
| Missing distinct from zero? | YES |
| Request families explicit? | YES |
| Dataset candidates explicit? | YES |
| Current collector gap explicit? | YES |
| Future collector can implement without inventing wants? | YES |

**CONTRACT STATUS: PASS** (documentation complete; implementation intentionally not started).

---

## Appendix — Privacy

| Question | Answer |
| --- | --- |
| USER-LEVEL DATA REQUIRED | **NO** |
| PII REQUIRED | **NO** |
| GCLID-level persistence | **NO** unless a future frozen requirement explicitly proves otherwise (none today) |

---

## Appendix — Completeness checklist (Prompt §100)

Account metadata · Timezone · Currency · Spend · Impressions · Clicks · CTR · CPC · Conversions · All Conversions (not required) · Conversion Value (optional) · Conversion Actions · Business Outcome boundary · Campaign · Ad Group · Keyword · Search Term · Keyword≠Search Term · Search completeness · Ads · Assets · Landing Pages · Budgets · Bidding (not required) · Statuses · Measurement · Search & Demand · Shared Date Range · Previous period · Metric direction · Historical backfill · Freshness · Late conversions · Request families · Search/SearchStream · Normalized datasets · Collector gap · Missing≠zero — all addressed above.
