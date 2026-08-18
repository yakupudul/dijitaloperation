# META ADS DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR COLLECTION IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Cumulative docs base | `cursor/data-contract-google-ads-ea01` @ `1527845d8e10b613db5b5f8e3cdab1666f853a03` (includes GA4 + GSC + Google Ads contracts; not yet on `main`) |
| Audit branch | `cursor/data-contract-meta-ads-ea01` |
| Runtime product code changed | **NONE** |

Future semantic changes require **v2** or an explicit amendment.

Official Meta references used (not blogs):

- [Ads Insights API](https://developers.facebook.com/docs/marketing-api/insights/)
- [Insights best practices / limits](https://developers.facebook.com/documentation/ads-commerce/marketing-api/insights/best-practices)
- [Ad Report Run Insights fields (Graph v26)](https://developers.facebook.com/docs/marketing-api/reference/ad-report-run/insights/)
- [Marketing API versioning](https://developers.facebook.com/docs/marketing-api/overview/versioning/)
- Installed config: `MetaApiConfig::DEFAULT_API_VERSION = v26.0`
- Existing normalizers (spec sources, not runtime changes this prompt): `MetaActionNormalizer`, `MetaResultResolver`

Hard semantic boundaries:

1. **META ACTION ≠ BUSINESS OUTCOME**
2. **META "RESULT" IS NOT ONE UNIVERSAL METRIC**
3. **CAMPAIGN ≠ AD SET ≠ AD ≠ CREATIVE**
4. **CLICK ≠ LINK CLICK ≠ LANDING PAGE VIEW**
5. **REACH ≠ IMPRESSIONS**
6. **FREQUENCY IS NOT AN INDEPENDENT COUNT**
7. **PROVIDER ACTION TYPE MUST NEVER BE THROWN AWAY**
8. **PLATFORM LEAD ≠ QUALIFIED LEAD**
9. **META CREATIVE METADATA ≠ WEBSITE CONTENT**

---

## 1. Purpose

Define **exactly** what the frozen Meta Ads Paid Social Operating Workspace requires from the Meta Marketing / Insights APIs and from MoxDOP domains **before** any production collector expansion, async Insights jobs, warehouse tables, migrations, queue orchestration, Evidence pipeline, or UI migration.

```text
Frozen Meta Ads UI
  → Provider entities
  → Provider Insights
  → Typed Actions
  → MoxDOP Result Mapping
  → Normalized future storage
  → Future Evidence
```

The future Meta collector **must not invent** data requirements.

**Hard boundary of this milestone:** audit + documentation only. No collectors, migrations, live Customer Graph pulls, OAuth changes, UI redesign, Evidence/Findings implementation, or provider writes.

---

## 2. Frozen UI Scope

### Verified primary IA

Source: `App\Livewire\Demo\Meta\OverviewPage::$allowedTabs`, views under `resources/views/livewire/demo/meta/`, `App\Support\Demo\MetaAdsWorkspaceFixtures`, `docs/product/meta-ads/META_ADS.md`, `docs/product/META_ADS_EXPERT_WORKSPACE.md`.

| Tab key | Operator label | Present |
| --- | --- | --- |
| `overview` | Overview | YES |
| `campaigns` | Campaigns | YES |
| `creatives` | Creatives | YES |
| `audience` | Audience & Delivery | YES |
| `funnel` | Funnel & Destinations | YES |
| `measurement` | Measurement | YES |
| `operations` | Operations | YES (Findings · Recommendations · Tasks · Outcomes) |

Legacy remaps: `adsets`→campaigns; `ads`→creatives; `breakdowns`/`delivery`→audience; `destinations`→funnel; `insights`→operations.

Supporting detail surfaces (not primary tabs): Campaign detail (with Ad Sets), Ad detail, Creative drawers.

### Supporting artifacts audited

- Livewire: `OverviewPage`, `CampaignsPage`, `CampaignDetailPage`, `CreativesPage`, `AdSetsPage`, `AdsPage`, …
- Fixtures: `MetaAdsWorkspaceFixtures` (90-day daily demo series)
- Shared period: `InteractsWithDemoPeriod` + `DemoPeriod`
- Runtime: `MetaAdsBoundCollector`, `MetaActionNormalizer`, `MetaResultResolver`, `MetaApiClient`, `MetaResourceDiscoveryService` / `MetaProviderResourceDiscovery`, `MetaAdsConnectionProbeService`

### Explicit non-goals

- No Meta **write** (pause, budget, creative publish, targeting mutate)
- No person-level / message-content / Instant Form PII ingestion
- No Measurement Score / Budget Health Score / Audience Quality Score
- No summing heterogeneous Result types into one account “Results” KPI
- No equating Meta Lead with CRM Qualified Lead / Business Outcome

---

## 3. Provider Capability Boundary

| Capability | Graph surface | Supports |
| --- | --- | --- |
| Business | Business node / discovery | Authorization scope; owned/accessible ad accounts |
| Ad Account | `act_{id}` | id, name, status, currency, timezone_name, business |
| Campaign / Ad Set / Ad | Marketing API objects + `/insights` | Entity config + Insights metrics |
| Creative | AdCreative node | Copy/media metadata (no media download required for freeze) |
| Insights | `/{object-id}/insights` | spend, impressions, reach, frequency, clicks, inline_link_clicks, actions, action_values, cost_per_action_type, breakdowns |
| Async Insights | Ad Report Run | Large history / heavy breakdowns |
| **Not in contract** | mutate edges | Any write |

Digital Asset for MoxDOP = **Ad Account** (`meta_ads`), not Business.

---

## 4. Source Classification

| Class | Meaning |
| --- | --- |
| `META_GRAPH_RESOURCE` | Business / Ad Account / Campaign / Ad Set / Ad / Creative entity fields |
| `META_INSIGHTS_METRIC` | Insights scalar metrics (spend, impressions, reach, …) |
| `META_CONFIGURATION` | Objective, optimization_goal, billing_event, budgets, status, destination_type |
| `META_TYPED_ACTION` | `actions` / `action_values` / `cost_per_action_type` rows keyed by `action_type` |
| `MOXDOP_DERIVED` | CTR, CPC, CPM, Frequency (when derived), Cost/Result, pacing |
| `MOXDOP_MAPPING` | Meta action → Platform Result → Business Action |
| `MOXDOP_CLASSIFICATION` | Measurement health, creative fatigue signal, attention |
| `CROSS_ASSET` | Website / GA4 / Instagram / CRM demo |
| `OPERATOR_MAINTAINED` | Campaign Context, planned agency budget, Brand strategy |
| `OPERATIONS_DOMAIN` | Findings, Recommendations, Tasks, Outcomes |
| `UNAVAILABLE` | Cannot be honestly claimed from Meta |
| `DEMO_ONLY` | Fixture-only narrative |

---

## 5. UI Requirement Matrix

**Req** = Required / Optional / Conditional / Demo-only.

| Requirement ID | Workspace | UI component | Operator question | Semantic definition | Demo source | Source class | Graph / Insights | Exact fields | Metrics | Breakdowns | Action BD | Grain | Date | Attribution | Comparison | TZ | Currency | Formula | Mapping | Cross-asset | Req | Additivity | Missing | Dataset | Coverage | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| META_ACCOUNT_ID | Header | Identity | Which ad account? | `act_*` id | identity.ad_account | META_GRAPH_RESOURCE | AdAccount | `account_id` / `id` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | block | account snapshot | Discoverer KEEP | Canonical key |
| META_ACCOUNT_NAME | Header | Title | Account name? | display name | title | META_GRAPH_RESOURCE | AdAccount | `name` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | account snapshot | KEEP | |
| META_ACCOUNT_TZ | Cross-cutting | Date boundary | Reporting TZ? | Ad Account timezone | DemoPeriod Europe/Berlin | META_GRAPH_RESOURCE | AdAccount | `timezone_name` | — | — | — | entity | snapshot | — | — | **account TZ** | — | — | — | Brand TZ policy | Required | — | never UTC default | account snapshot | Discoverer has; ComparisonPeriod UTC = **gap** | Hard rule |
| META_ACCOUNT_CURRENCY | Cross-cutting | Money | Currency? | Account currency | TRY | META_GRAPH_RESOURCE | AdAccount | `currency` / Insights `account_currency` | — | — | — | entity | snapshot | — | — | — | account | — | — | — | Required | — | Unavailable | account snapshot | KEEP | No FX |
| META_ACCOUNT_STATUS | Header | Connected | Account usable? | account_status | Connected | META_GRAPH_RESOURCE | AdAccount | `account_status` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | account snapshot | KEEP | |
| META_BUSINESS_CONTEXT | Integration | Discovery | Which Business? | Business id/name for auth scope | metadata | META_GRAPH_RESOURCE | Business | `business{id,name}` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Conditional | — | setup | binding metadata | KEEP | **Not** the Digital Asset |
| META_ACCOUNT_FRESHNESS | Header | Chips | How fresh? | Last Run age | freshness[] | OPERATIONS_DOMAIN | Run | timestamps | — | — | — | run | — | — | — | app | — | — | — | — | Required | — | Unknown | — | partial | |
| META_OVERVIEW_SPEND | Overview | KPI Spend | How much spent? | Sum spend | glance.spend | META_INSIGHTS_METRIC | account insights | — | `spend` | — | — | daily→range | Shared | unified setting | relative % | account | account | sum | — | — | Required | ADDITIVE | Unavailable≠0 | account/campaign daily | range only today | Prefer daily |
| META_OVERVIEW_RESULT_MIX | Overview | KPI Result Mix + panel | What result types? | Typed grouped platform results — **never one total** | glance.result_mix + result_mix | META_TYPED_ACTION + MOXDOP_MAPPING | account + campaign actions | `actions[].action_type` | typed counts | — | no default | range by type | Shared | preserve attribution_setting | compare **per type only** | account | — | group by type | Platform Result map | — | Required | ADDITIVE **within same action_type** | no mapping → Mixed/Unavailable | typed_action_daily | ResultResolver KEEP | **DECISION LOCKED: Result Mix** |
| META_OVERVIEW_COST_PRIMARY | Overview | Cost / primary | Cost per primary Lead? | spend_on_Lead_campaigns / Lead counts | cost_primary | MOXDOP_DERIVED + MOXDOP_MAPPING | — | Lead-type actions only | spend + lead counts | — | — | range | Shared | — | only vs Lead↔Lead | account | account | F_META_COST_PRIMARY | Lead mapping | CRM later | Required | RATIO | Unavailable if no Lead type | derived | Demo uses Instant Form leads | Not cost of all actions |
| META_OVERVIEW_BUDGET_PACING | Overview | Budget pacing | Ahead/behind plan? | Agency planned budget vs spend vs elapsed | pacing | OPERATOR_MAINTAINED + MOXDOP_DERIVED | spend from Insights | — | spend | — | — | MTD/range | calendar | — | — | account | account | F_META_BUDGET_PACING | — | — | Required | — | no plan → Unavail | derived | Demo | Not Meta budget alone |
| META_OVERVIEW_TREND | Overview | Performance chart | Spend vs primary over time? | Daily spend + primary typed results | performance_trend | META_INSIGHTS_METRIC + META_TYPED_ACTION | account/campaign daily | — | spend + typed | — | — | **daily** | Shared | — | — | account | account | — | primary type | — | Required | see matrix | gaps≠0 | daily datasets | **MISSING daily grain** | Types stay separate |
| META_OVERVIEW_NEEDS_ATTENTION | Overview | Attention list | What needs action? | Ops/Finding cards | needs_attention | OPERATIONS_DOMAIN | — | Evidence deps | — | — | — | — | — | — | — | — | — | — | — | Website/CRM | Conditional | — | hide empty | — | Demo | no Graph solely for card |
| META_OVERVIEW_CAMPAIGN_PORTFOLIO | Overview | Campaign table | Portfolio? | Campaign rows subset | campaigns | see campaign reqs | campaign insights | — | spend, typed result | — | — | range | Shared | — | — | account | account | cost/result | map | — | Required | — | — | campaign daily | Top-N UI ≠ limit | |
| META_OVERVIEW_CREATIVE_PULSE | Overview | Creative cards | Creative signals? | Ad-level metrics + creative meta | creative_pulse | META_GRAPH_RESOURCE + META_INSIGHTS_METRIC + MOXDOP_CLASSIFICATION | ad + creative | — | spend, result, freq, ctr | — | — | range | Shared | — | — | account | account | — | type | — | Required | — | — | ad daily + creative snapshot | partial | Fatigue = classification |
| META_OVERVIEW_OPPORTUNITIES | Overview | Opportunity cards | Where next? | Narrative/ops | opportunities | DEMO_ONLY / OPERATIONS_DOMAIN | — | — | — | — | — | — | — | — | — | — | — | — | — | — | Demo-only | — | — | — | Demo | |
| META_CAMPAIGN_ENTITY | Campaigns | Row | Which campaign? | campaign_id + name | campaigns[].id | META_GRAPH_RESOURCE | Campaign | `id`,`name` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | campaign snapshot | KEEP | ID not name |
| META_CAMPAIGN_STATUS | Campaigns | Status | Active/Paused? | configured status (+ effective if shown) | ACTIVE/PAUSED | META_CONFIGURATION | Campaign | `status`, `effective_status` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | campaign snapshot | KEEP | Do not flatten |
| META_CAMPAIGN_OBJECTIVE | Campaigns | Subtitle objective | What objective family? | Provider objective (enums evolve) | objective_family | META_CONFIGURATION | Campaign | `objective` | — | — | — | entity | snapshot | — | — | — | — | map→family label | ≠ Brand Goal | — | Required | — | Unknown | campaign snapshot | KEEP | Verify enums at API version |
| META_CAMPAIGN_DAILY | Campaigns | Spend + trend | Daily base facts? | spend, impressions, reach*, link_clicks, actions | spend… | META_INSIGHTS_METRIC + META_TYPED_ACTION | campaign insights | — | spend, impressions, reach, frequency, clicks, inline_link_clicks, actions… | — | — | **date×campaign** | Shared | use_unified_attribution_setting | previous equal length | account | account | — | — | — | Required | Reach NON_ADDITIVE | missing≠0 | `meta_campaign_daily` | range aggregates only | *see Reach policy |
| META_CAMPAIGN_RESULT | Campaigns | Result column | Typed results? | count **+** result_label/type | results + result_label | META_TYPED_ACTION + MOXDOP_MAPPING | actions + objective/opt | action_type | selected type count | — | — | date×campaign | Shared | — | **same type only** | account | — | — | Result Type | — | Required | ADDITIVE within type | unmapped → Unresolved | typed_action + campaign | KEEP resolver | Never bare “Results” |
| META_CAMPAIGN_COST_RESULT | Campaigns | Cost / result | Efficiency? | spend / typed result count | cost_result | MOXDOP_DERIVED | — | — | — | — | — | range | Shared | — | same type only | account | account | F_META_COST_RESULT | type | — | Required | RATIO | denom 0 → Unavail | derived | | |
| META_CAMPAIGN_PACING | Campaigns | Pacing badge | Ahead/Behind? | MoxDOP vs plan/budget | pacing | MOXDOP_DERIVED + OPERATOR_MAINTAINED | — | — | spend | — | — | range | — | — | — | account | account | F_META_CAMPAIGN_PACING | context | — | Required | — | Unavail without plan | derived | Demo | No Budget Health Score |
| META_CAMPAIGN_CONTEXT | Campaigns | Drawer Strategy | Offering/market/goal? | Operator-maintained | offering, market, language, goal | OPERATOR_MAINTAINED | — | — | — | — | — | entity | — | — | — | — | — | — | Brand | — | Required | — | empty OK | context store | Demo | Not from Meta objective |
| META_CAMPAIGN_DESTINATION | Campaigns | Destination col | Where does traffic go? | Destination type config | Instant Form/Website/Messaging/IG | META_CONFIGURATION + MOXDOP_MAPPING | AdSet `destination_type` / creative link | destination_type, link_url | — | — | — | entity | snapshot | — | — | — | — | — | Website/IG | — | Required | — | Unknown | adset/creative | partial | ≠ measured outcome |
| META_CAMPAIGN_LINK_CTR | Campaign detail | Link CTR KPI | Link click rate? | inline_link_clicks / impressions | ctr | MOXDOP_DERIVED / META_INSIGHTS_METRIC | insights | `inline_link_clicks`, `impressions` or `inline_link_click_ctr` | — | — | — | range | Shared | — | prefer pp | account | — | F_META_LINK_CTR | — | — | Required | RATIO | impr=0 → Unavail | derived | Demo labels Link CTR | ≠ all-click CTR |
| META_CAMPAIGN_FREQUENCY | Campaign detail | Frequency KPI | Avg times seen? | impressions/reach or provider frequency | frequency | META_INSIGHTS_METRIC / MOXDOP_DERIVED | insights | `frequency` or derive | — | — | — | **period query** preferred | Shared | — | relative | account | — | F_META_FREQUENCY | — | — | Required | RATIO / NON_ADDITIVE inputs | reach=0 → Unavail | period insights | KEEP note | Never avg row freq |
| META_CAMPAIGN_REACH | Campaign detail / Audience | Reach | Unique people? | Provider reach | reach | META_INSIGHTS_METRIC | insights | `reach` | — | — | — | period or daily* | Shared | — | period-safe compare | account | — | — | — | — | Required | **NON_ADDITIVE** | no row≠0 people | period insights | KEEP limitation | Do not sum daily reach |
| META_ADSET_ENTITY | Campaign detail | Ad set list | Which ad sets? | adset id+name+campaign | adsets[] | META_GRAPH_RESOURCE | AdSet | id, name, campaign_id | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | adset snapshot | KEEP | No primary Ad Sets tab |
| META_ADSET_OPTIMIZATION | Campaign detail | Optimization | What is optimized? | optimization_goal | optimization | META_CONFIGURATION | AdSet | `optimization_goal` | — | — | — | entity | snapshot | — | — | — | — | — | ≠ objective | — | Required | — | — | adset snapshot | KEEP | Hard rule |
| META_ADSET_BILLING | Config | Billing event | How billed? | billing_event | not primary UI | META_CONFIGURATION | AdSet | `billing_event` | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Optional | — | — | adset snapshot | KEEP | Useful for interpretation |
| META_ADSET_BUDGET | Campaigns/detail | Budget level | CBO vs ad set budget? | daily/lifetime budget | planned_budget Demo | META_CONFIGURATION | AdSet/Campaign | `daily_budget`,`lifetime_budget` (+ campaign budget) | — | — | — | entity | snapshot | — | — | — | account (minor units**) | micros/100 | — | — | Required | — | — | budget snapshot | KEEP | **REQUIRES PROVIDER VERIFICATION** unit (often cents) |
| META_ADSET_STATUS | Campaign detail | Status | Delivering? | status / effective_status | ACTIVE | META_CONFIGURATION | AdSet | status, effective_status | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | adset snapshot | KEEP | Preserve CAMPAIGN_PAUSED etc. |
| META_ADSET_DAILY | Campaign detail | Ad set metrics | Ad set performance? | spend + typed result + link CTR | adsets spend/results | META_INSIGHTS_METRIC + META_TYPED_ACTION | adset insights | — | spend, impressions, inline_link_clicks, actions, reach* | — | — | date×adset | Shared | — | — | account | account | — | type | — | Required | Reach NON_ADDITIVE | — | `meta_adset_daily` | range only | |
| META_ADSET_DESTINATION_TYPE | Funnel | Destination | Destination config | destination_type | destination | META_CONFIGURATION | AdSet | `destination_type` | — | — | — | entity | snapshot | — | — | — | — | — | Website/Msg/IG | — | Required | — | — | adset snapshot | KEEP | |
| META_AD_ENTITY | Creatives / Ads | Ad row | Which ad? | ad_id | gallery via ads | META_GRAPH_RESOURCE | Ad | id, name, adset_id, campaign_id | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | ad snapshot | KEEP | |
| META_AD_STATUS | Creatives | Status | Active? | status / effective_status | ACTIVE | META_CONFIGURATION | Ad | status, effective_status | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | ad snapshot | KEEP | |
| META_AD_CREATIVE_REL | Creatives | Creative link | Which creative? | creative_id | creative{id,name} | META_GRAPH_RESOURCE | Ad | creative{id,name} | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | ad snapshot | KEEP | Ad ≠ Creative |
| META_AD_DAILY | Creatives | Performance | Ad metrics? | spend, result, freq, link CTR | creative spend/result | META_INSIGHTS_METRIC + META_TYPED_ACTION | ad insights | — | spend, impressions, reach*, frequency, inline_link_clicks, actions | — | — | date×ad | Shared | — | same type | account | account | — | type | — | Required | — | — | `meta_ad_daily` | range Top-N | Creative performance via **Ad** Insights |
| META_CREATIVE_METADATA | Creatives | Gallery | Creative identity? | creative id/name/object_type/status | creatives | META_GRAPH_RESOURCE | AdCreative | id, name, object_type, status | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Required | — | — | creative snapshot | KEEP | |
| META_CREATIVE_COPY | Creatives | Copy | Headline/body/CTA? | title, body, CTA | note/headlines Demo | META_GRAPH_RESOURCE | AdCreative | `title`,`body`,`call_to_action_type`,`link_url` | — | — | — | entity | snapshot | — | — | — | — | — | Website URL | — | Required | — | Dynamic Creative caveat | creative snapshot | KEEP | Advantage+ may combine assets |
| META_CREATIVE_MEDIA | Creatives | Thumb | Preview? | thumbnail reference | thumb_gradient Demo | META_GRAPH_RESOURCE | AdCreative | `thumbnail_url` (ephemeral) | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Conditional | — | URL expiry | creative snapshot | KEEP no download | Do not assume permanent URLs |
| META_CREATIVE_PERFORMANCE | Creatives | Pulse metrics | Creative efficiency? | **Ad-level Insights** rolled to creative_id | spend/result/freq/ctr | MOXDOP_DERIVED from META_AD_DAILY | ad insights | — | — | — | — | date×ad→creative | Shared | — | same type | account | account | sum spend/actions by creative_id | type | — | Required | Reach: re-query or mark unsafe | — | derived from ad daily | ADAPT | No fabricated creative Insights edge |
| META_CREATIVE_CLASSIFICATION | Creatives | Signal badges | Fatigue/quality? | MoxDOP labels | fatigue_candidate etc. | MOXDOP_CLASSIFICATION | — | freq trend, CTR, CRM | — | — | — | — | — | — | — | — | — | rules later | CRM | — | Required | — | Unreviewed | — | Demo | No hardcoded Finding thresholds |
| META_DELIVERY_PLACEMENT | Audience | Placement bars | Where delivered? | publisher_platform / platform_position | placements | META_INSIGHTS_METRIC | insights + breakdown | — | spend (+optional impr) | placement / publisher_platform | no | date×account (or campaign) × placement | Shared | — | — | account | account | — | — | — | Required | ADDITIVE for spend | privacy thresholds | delivery_breakdown_daily | **MISSING** | Separate request family |
| META_DELIVERY_AGE | Audience | Age bars | Age delivery? | age breakdown | age | META_INSIGHTS_METRIC | insights | — | spend | `age` | no | date×…×age | Shared | — | — | account | account | — | — | — | Required | spend ADDITIVE | thresholds | delivery_breakdown_daily | MISSING | Provider categories only |
| META_DELIVERY_GENDER | Audience | Gender bars | Gender delivery? | gender breakdown | gender | META_INSIGHTS_METRIC | insights | — | spend | `gender` | no | date×…×gender | Shared | — | — | account | account | — | — | — | Required | spend ADDITIVE | thresholds | delivery_breakdown_daily | MISSING | |
| META_DELIVERY_COUNTRY | Audience | Country bars | Geo delivery? | country breakdown | country | META_INSIGHTS_METRIC | insights | — | spend | `country` | no | date×…×country | Shared | — | — | account | account | — | — | — | Required | spend ADDITIVE | — | delivery_breakdown_daily | MISSING | ≠ targeting config |
| META_DELIVERY_PLATFORM | Audience | Platform bars | FB/IG/AN? | publisher_platform | platform | META_INSIGHTS_METRIC | insights | — | spend | `publisher_platform` | no | date×…×platform | Shared | — | — | account | account | — | — | — | Required | spend ADDITIVE | — | delivery_breakdown_daily | MISSING | Verify enum labels |
| META_TARGETING_CONFIG | Audience | Configured panel | What was targeted? | Targeting / Advantage+ config | configured[] | META_CONFIGURATION + OPERATOR_MAINTAINED | AdSet targeting fields | **REQUIRES PROVIDER VERIFICATION** minimal fields | — | — | — | entity | snapshot | — | — | — | — | — | — | — | Conditional | — | — | targeting snapshot | unknown depth | Delivery ≠ targeting |
| META_FUNNEL_DESTINATION | Funnel | Destination rows | Spend by destination? | Group campaigns by destination type | destinations | META_CONFIGURATION + META_INSIGHTS_METRIC | adset destination + spend | — | spend + typed results | — | — | range | Shared | — | per type | account | account | group | — | — | Required | — | — | derived | Demo | |
| META_FUNNEL_INSTANT_FORM | Funnel | Instant Form panel | Form leads? | Meta lead actions + form destination | instant_form | META_TYPED_ACTION + META_CONFIGURATION | lead actions | `lead` / grouped | count | — | — | range | Shared | — | — | account | account | cost/lead | BA map | CRM | Required | — | Missing CRM ≠ 0 qualified | typed_action | Demo CRM | No PII |
| META_FUNNEL_WEBSITE | Funnel | Website panel | Website path? | Website destination + LP/Website leads | website | META_CONFIGURATION + CROSS_ASSET + META_TYPED_ACTION | link_url + actions | landing_page_view / offsite | — | — | — | range | Shared | — | — | account | — | — | map | Website/GA4 | Required | — | — | — | Demo | Language mismatch story |
| META_FUNNEL_MESSAGING | Funnel | Messaging panel | Conversations? | Messaging conversation actions | messaging | META_TYPED_ACTION | messaging action types | messaging_conversation_started_7d etc. | count | — | — | range | Shared | — | — | account | account | cost/conversation | map | Inbox/CRM | Required | — | click≠conversation | typed_action | KEEP map | |
| META_FUNNEL_IG_PROFILE | Funnel | IG profile panel | Profile visits? | profile_visit action / awareness | instagram_profile | META_TYPED_ACTION | profile_visit | profile_visit | count | — | — | range | Shared | — | — | account | account | cost/visit | awareness | Instagram asset | Required | — | ≠ follow/DM/sale | typed_action | KEEP | Platform-only |
| META_LANDING_PAGE_VIEW | Funnel/Web | LPV | Landing loaded? | Meta `landing_page_view` action | Demo stages | META_TYPED_ACTION | actions | action_type=landing_page_view | count | — | — | date×entity | Shared | — | — | account | — | — | — | GA4 sessions differ | Conditional | ADDITIVE within type | ≠ link_click | typed_action | in INSIGHT_FIELDS via actions | Distinct from link click |
| META_LINK_CLICK | Core | Link clicks | Link clicks? | `inline_link_clicks` | link_clicks | META_INSIGHTS_METRIC | insights | `inline_link_clicks` | — | — | — | daily | Shared | — | relative % | account | — | — | — | — | Required | ADDITIVE | ≠ clicks | daily datasets | KEEP | |
| META_ACTION_TYPE_DAILY | Measurement/All | Typed actions | What happened? | Normalized typed facts | actions everywhere | META_TYPED_ACTION | insights actions | action_type, value | count | optional later | optional | date×scope×entity×action_type | Shared | store attribution_setting | per type | account | value currency | — | mapping version | — | Required | ADDITIVE within type | no row≠0 outcome | `meta_typed_action_daily` | Normalizer KEEP; need daily | Mandatory |
| META_ACTION_VALUE | Commerce | Values | Value of action? | action_values by type | not Overview KPI | META_TYPED_ACTION | action_values | action_type, value | value | — | — | same | Shared | — | — | account | account | — | — | — | Optional | ADDITIVE within type | — | typed_action | KEEP in fields | No ROAS UI in freeze |
| META_MEASUREMENT_MAPPING | Measurement | Matrix | Meta→Business Action? | Explicit mapping | matrix | MOXDOP_MAPPING | — | — | — | — | — | — | — | — | — | — | — | — | yes | GA4/CRM | Required | — | Needs mapping | mapping store | Demo | Never by name alone |
| META_MEASUREMENT_HEALTH | Measurement | States | Healthy/Partial? | MoxDOP classification | Healthy/Partial/Needs mapping | MOXDOP_CLASSIFICATION | — | — | — | — | — | — | — | — | — | — | — | — | map | — | Required | — | Incomplete | — | Demo | No score |
| META_PIXEL_CONTEXT | Measurement | Pixel/dataset | Event source? | Connection state identity only | CRM demo / needs mapping | META_GRAPH_RESOURCE / Conditional | Pixel/Dataset | **REQUIRES PROVIDER VERIFICATION** if UI needs id | — | — | — | entity | snapshot | — | — | — | — | — | GA4 | — | Conditional | — | no pixel≠0 events | — | unknown | No Events Manager dump |
| META_OPS_* | Operations | Pipeline | Work? | Ops domain | operations | OPERATIONS_DOMAIN | — | Evidence | — | — | — | — | — | — | — | — | — | — | — | — | Conditional | — | — | — | Demo | |
| META_SHARED_DATE_RANGE | Cross-cutting | Period bar | Window? | Canonical presets | DemoPeriod | OPERATOR_MAINTAINED UX | — | — | — | — | — | — | presets | — | — | account TZ for Meta | — | — | — | — | Required | — | — | — | | |
| META_PREVIOUS_PERIOD | Cross-cutting | Deltas | vs previous? | Equal-length prior range | compare_label | MOXDOP_DERIVED | — | — | — | — | — | — | previousBounds | — | §28 | account | — | F_META_DELTA_* | — | — | Required | — | §29 | — | | |

**Totals: 68 requirement IDs**  
**Required: 54 · Optional: 2 · Conditional: 8 · Demo-only: 1** (plus Ops Conditional)

---

## 6. Business / Ad Account Identity

| Entity | Role | Required fields |
| --- | --- | --- |
| Business | Discovery / authorization scope | `id`, `name` (metadata) — **not** Digital Asset |
| Ad Account | MoxDOP Digital Asset | `account_id`/`id`, `name`, `account_status`, `currency`, `timezone_name`, `business{id,name}` |

Canonical IDs: Business `id`; Ad Account `act_{account_id}` / numeric `account_id` (normalize consistently).

---

## 7. Entity Hierarchy

```text
Business (auth scope)
  └── Ad Account  ← Digital Asset
        └── Campaign
              └── Ad Set
                    └── Ad
                          └── Creative (referenced)
```

| Level | Canonical key | Notes |
| --- | --- | --- |
| Business | `business_id` | Discovery only |
| Ad Account | `account_id` | Asset external_id |
| Campaign | `campaign_id` | Never name |
| Ad Set | `adset_id` | |
| Ad | `ad_id` | |
| Creative | `creative_id` | Separate from ad_id |

---

## 8. Core Insight Metrics

| Concept | Provider field | Frozen usage | Notes |
| --- | --- | --- | --- |
| Spend | `spend` | Overview, Campaigns, Creatives, Audience, Funnel | Account currency |
| Impressions | `impressions` | Base for CTR/CPM/Frequency; Audience volume | ≠ Reach |
| Reach | `reach` | Campaign detail, Audience context | **NON_ADDITIVE** |
| Frequency | `frequency` or derive | Campaign detail, Creative fatigue | Never average rows |
| Clicks (all) | `clicks` | Optional/reconcile | **Not** primary frozen “Link Clicks” |
| Link clicks | `inline_link_clicks` | Campaigns/Creatives | Distinct |
| Link CTR | derive or `inline_link_click_ctr` | Campaign detail “Link CTR” | |
| CPC (all) | `cpc` / derive | Optional | ≠ cost per link click |
| Cost per link click | `cost_per_inline_link_click` / derive | Optional | |
| CPM | `cpm` / derive | Optional (not Overview KPI) | |
| Outbound clicks | `outbound_clicks` | Optional | Distinct from inline link clicks |

---

## 9. Metric Additivity Matrix

| Metric | Provider field | Type | Across days? | Across campaigns? | Across ads? | Canonical aggregate | Period re-query? | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Spend | `spend` | ADDITIVE | YES | YES | YES | sum | No | |
| Impressions | `impressions` | ADDITIVE | YES | YES | YES | sum | No | |
| Reach | `reach` | **NON_ADDITIVE** | **NO** | **NO** | **NO** | Provider period query at desired grain | **YES** for period totals | Do not sum daily reach |
| Frequency | `frequency` | RATIO | NO | NO | NO | `impr/reach` at same grain **or** provider period frequency | Prefer period query | Never average frequencies |
| Clicks | `clicks` | ADDITIVE | YES | YES | YES | sum | No | |
| Link clicks | `inline_link_clicks` | ADDITIVE | YES | YES | YES | sum | No | |
| Typed action count | `actions[].value` per `action_type` | ADDITIVE **within type** | YES* | YES* | YES* | sum same `action_type` | Late data recheck | *attribution lag |
| Action value | `action_values` | ADDITIVE within type | YES* | YES* | YES* | sum | Late recheck | Preserve type |
| CTR / Link CTR | ratio | RATIO | recompute | recompute | recompute | sum num / sum den | No | Never avg % |
| CPC / CPLINK / CPM / Cost/Result | ratio | RATIO | recompute | recompute | recompute | sum cost / sum count | No | Never avg ratios |

**Reach policy:** Store daily reach only as diagnostic/non-roll-up; **account/campaign/ad period Reach must come from a provider Insights query for that exact time_range**. UI period comparisons use period queries, not summed daily reach.

**Frequency policy:** Prefer provider period `frequency`, else `sum(impressions)/period_reach` where period_reach from provider; never average daily frequency rows.

---

## 10. Typed Action / Result Semantics

### Typed action matrix (frozen-needed + structural)

| Raw field | Raw action type | Provider meaning | Scope | Value type | Currency? | Additive? | Platform Result | Business Action | Outcome? | Attribution | Consumers | Mapping status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| actions | `lead` | Meta-attributed lead | camp/adset/ad/account | count | no | within type | Meta Lead | New enquiry | **NO** | yes | Overview Lead bucket, Funnel Instant Form, Measurement | Mapped in Demo |
| actions | `onsite_conversion.lead_grouped` | Grouped lead | same | count | no | within type | Meta Lead (grouped) | New enquiry | NO | yes | Result Mix (alias handling) | Alias; never sum with lead |
| actions | `onsite_conversion.messaging_conversation_started_7d` | Messaging conversation started | same | count | no | within type | Messaging conversation | Conversation started | NO | yes | Messaging funnel | Mapped |
| actions | `onsite_conversion.messaging_first_reply` | First reply | same | count | no | within type | Messaging first reply | — | NO | yes | diagnostics | Optional |
| actions | `onsite_conversion.total_messaging_connection` | Messaging connection | same | count | no | within type | Messaging connection | — | NO | yes | mix | Distinct from conversation |
| actions | `profile_visit` | Profile visit | same | count | no | within type | Instagram profile visit | Profile engagement | NO | yes | Awareness funnel | Platform-only |
| actions | `landing_page_view` | Landing page view | same | count | no | within type | Landing page view | — | NO | yes | Website funnel | ≠ link_click |
| actions | `link_click` | Link click (action) | same | count | no | within type | Link click (action) | — | NO | yes | diagnostics | Prefer `inline_link_clicks` metric for UI “Link Clicks” |
| action_values | purchase family | Purchase value | same | money | account | within type | Purchase | — | NO | yes | Optional | NOT Overview KPI |
| cost_per_action_type | per type | Provider CPA | same | money | account | RATIO | — | — | — | — | reconcile | Prefer recompute |

**Never** flatten to `results=123` without type.

### Result semantics matrix

| UI label | Result count source | Result type source | Objective | Optimization | Provider action | Formula | Cost/result | Aggregate across campaigns? | Comparison | Outcome | Missing mapping |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Result Mix (account) | counts per type | each row’s type | mixed | mixed | preserved raw types | group by type | per-type spend/count for Lead primary | **NO universal total** — typed groups only | per matching type | NO | show Mixed / Unresolved |
| Leads | lead action count | “Leads” | OUTCOME_LEADS / LEAD_GENERATION | Leads | `lead` / grouped | count | Lead spend / leads | YES among Lead campaigns only | Lead↔Lead | Platform only | Unavailable |
| Messaging conversations | messaging_conversation_started_7d | Messaging conversations | MESSAGES / ENGAGEMENT | Messaging | that action | count | spend / conv | YES among Messaging | Messaging↔Messaging | NO | Unavailable |
| Instagram profile visits | profile_visit | Profile visits | AWARENESS | Profile visits | profile_visit | count | spend / visits | YES among Awareness | type-matched | NO | Unavailable |
| Website leads | mapped website conversion/lead actions | Website leads | TRAFFIC/LEADS web | Website leads/conversions | offsite/lead variants **REQUIRES PROVIDER VERIFICATION** per account | count | spend / count | YES among Website | type-matched | NO | Needs mapping |
| Cost / primary | Lead-only | Leads | — | — | lead | Lead spend / leads | — | Lead only | Lead only | NO | Mixed result types |

### Mixed Result Types — **LOCKED DECISION**

Frozen product already implements:

- Account Overview → **Result Mix** (do not sum types)
- Cost / primary → Instant Form **Leads** only when Lead campaigns exist
- Campaign rows → always show `results` **with** `result_label`

**DECISION REQUIRED before collection only if** product later wants a single “Results” number — **Contract V1 forbids** a universal Results total.

---

## 11. Meta Action Normalization Contract

```text
Meta Raw Action (actions / action_values row)
  → Preserve raw action_type + source_field + attribution_setting
  → Normalized Meta Action Type (optional category; null if unknown)
  → MoxDOP Platform Result Mapping (versioned)
  → MoxDOP Business Action Mapping (versioned)
  → Business Outcome remains SEPARATE (CRM/ops)
```

Conceptual typed fact:

| Field | Required |
| --- | --- |
| provider = `meta_ads` | YES |
| entity_scope | account/campaign/adset/ad |
| entity_id | YES |
| date | YES (account TZ day) |
| action_type (raw) | YES — **primary semantic identity** |
| action_destination / reaction / video_type | if provider returns & UI needs |
| attribution_context | YES when available |
| value (count) | YES |
| action_value + currency | if present |
| source_field | `actions` / `action_values` / both |
| mapping_version | YES when mapped |
| contract_version | YES |

**Do not** create hundreds of hardcoded columns per action type.

Existing `MetaActionNormalizer` / `MetaResultResolver` align with this contract — **KEEP**; expand mapping tables later without destructive overwrite of raw types.

---

## 12. Campaign Requirements

| Concern | Contract |
| --- | --- |
| Identity | `campaign_id`, `name` |
| Status | `status` + `effective_status` when delivery differs |
| Objective | `objective` (current API enums — Outcome* / legacy) |
| Daily facts | spend, impressions, reach*, frequency*, clicks, inline_link_clicks, actions |
| Result | typed via resolver + raw actions |
| Budget | may be campaign-level or ad-set-level — inspect both |
| Context | OPERATOR_MAINTAINED |

---

## 13. Ad Set Requirements

| Item | Contract |
| --- | --- |
| Required | YES (detail + optimization/destination/budget) |
| Optimization goal | Required — ≠ campaign objective |
| Billing event | Optional |
| Budget | daily_budget / lifetime_budget |
| Status / effective_status | Required |
| destination_type | Required for Funnel |
| attribution_spec | Conditional (preserve when present) |
| Daily facts | Required for detail metrics |

---

## 14. Ad Requirements

| Item | Contract |
| --- | --- |
| Required | YES |
| Creative relation | creative_id required |
| Status | status + effective_status |
| Daily metrics | spend, impr, reach*, link clicks, typed actions, frequency* |
| Destination | via creative link_url / ad set destination_type |

---

## 15. Creative Requirements

### Creative matrix

| Concept | Provider source | Ad dependency | Dynamic caveat | Media | Performance | Aggregation | Destination | Consumers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Identity | AdCreative id/name | Ad.creative_id | — | — | — | — | — | Creatives |
| Headline | `title` | via Ad | asset feed may vary | — | — | — | — | Creatives |
| Body | `body` | via Ad | same | — | — | — | — | Creatives |
| CTA | `call_to_action_type` | via Ad | — | — | — | — | — | Creatives |
| Link | `link_url` | via Ad | — | — | — | — | Website join | Funnel |
| Thumb | `thumbnail_url` | via Ad | ephemeral | reference only | — | — | — | UI preview |
| Performance | **Ad Insights** | must join Ad→Creative | Dynamic Creative metrics stay at Ad | — | Ad daily | sum spend/actions by creative_id; Reach unsafe | — | Creative pulse |
| Classification | MoxDOP | — | — | — | uses freq/CTR trends | — | CRM qualified rate Demo | Fatigue signals |

**Creative ≠ Ad.** Do not collapse identities.

---

## 16. Audience & Delivery Requirements

| Breakdown | Required? | Provider breakdown | Metrics | Notes |
| --- | --- | --- | --- | --- |
| Placement / platform position | YES | verify `publisher_platform` / `platform_position` | spend (primary) | Separate request family |
| Age | YES | `age` | spend | Privacy thresholds |
| Gender | YES | `gender` | spend | |
| Country | YES | `country` | spend | ≠ targeting config |
| Platform | YES | `publisher_platform` | spend | |
| Device | NO for freeze | — | — | Not in frozen bars |
| Reach in breakdowns | CONDITIONAL | limited (>13mo rules) | — | Prefer spend-first; Reach with breakdowns has API limits |

**Delivery ≠ Targeting configuration.**

Configured targeting panel may use AdSet targeting fields + operator notes — Conditional depth.

---

## 17. Funnel & Destination Requirements

### Destination matrix

| Destination | Config source | Measured action | Website | GA4 | Messaging | Prove arrival? | Prove outcome? | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Website | destination_type + link_url | landing_page_view / website lead actions | YES | YES | — | LPV ≠ click | Website lead / BA | Language mismatch Demo |
| Instant Form | Instant form destination | `lead` actions | — | — | — | form open may be unavailable | Meta lead ≠ qualified | No PII |
| Messaging | Messaging destination | conversation started actions | — | — | Inbox/CRM | click≠conversation | conversation ≠ qualified | |
| Instagram Profile | Profile destination | profile_visit | — | — | Instagram asset | visit only | ≠ follow/DM/sale | Platform-only |

### Funnel stages

| Stage | Source |
| --- | --- |
| Ad delivery | Insights |
| Destination type | Configuration |
| Link click | `inline_link_clicks` |
| Landing page view | typed action |
| Meta lead / conversation / profile visit | typed action |
| CRM accepted / qualified | CROSS_ASSET / OPERATOR — **not Meta** |

---

## 18. Budget Requirements

| Level | Fields | Notes |
| --- | --- | --- |
| Campaign budget | campaign budget fields when CBO | Not always present |
| Ad Set budget | `daily_budget`, `lifetime_budget` | Common |
| Agency planned budget | OPERATOR_MAINTAINED | Overview pacing |
| Pacing | MOXDOP_DERIVED | No Budget Health Score |

Unit: Meta often returns budgets in account currency **minor units** (e.g. cents) — **REQUIRES PROVIDER VERIFICATION** at implementation; Insights `spend` is standard currency decimal string.

---

## 19. Objective / Optimization Requirements

### Objective / Optimization / Result matrix

| Campaign Objective (examples) | Ad Set Optimization Goal | Observed Action Type | MoxDOP Result Type | Relationship | Can differ? |
| --- | --- | --- | --- | --- | --- |
| OUTCOME_LEADS / LEAD_GENERATION | LEAD_GENERATION / quality lead goals | `lead`, lead_grouped | Meta Lead | Prefer optimization list first | YES vs Brand Goal |
| MESSAGES / OUTCOME_ENGAGEMENT | CONVERSATIONS / REPLIES | messaging_conversation_started_7d | Messaging conversation | Prefer messaging actions | YES |
| OUTCOME_TRAFFIC / LINK_CLICKS | LINK_CLICKS / LANDING_PAGE_VIEWS | link_click / landing_page_view | Traffic actions | Prefer opt goal | YES |
| OUTCOME_AWARENESS / REACH | REACH / IMPRESSIONS / THRUPLAY | reach metric or profile_visit | Awareness / Profile visit | Reach may be metric not action | YES |
| OUTCOME_SALES | OFFSITE_CONVERSIONS | purchase family | Purchase | Optional in freeze | YES |

**CAMPAIGN OBJECTIVE ≠ AD SET OPTIMIZATION GOAL.**  
**Neither equals Brand Goal / Offering.**  
If optimization and objective prefer different nonzero families → **Unresolved / Mixed** (existing resolver behavior — KEEP).

---

## 20. Status Requirements

Preserve distinct provider states where UI needs them:

| Entity | Fields |
| --- | --- |
| Campaign / Ad Set / Ad | `status`, `effective_status` |
| Creative | `status` |

Do not flatten ACTIVE / PAUSED / ARCHIVED / DELETED / CAMPAIGN_PAUSED / ADSET_PAUSED / DISAPPROVED into one generic state without product need.

Frozen Demo primarily shows ACTIVE/PAUSED — still store effective_status for delivery honesty.

---

## 21. Measurement Workspace

| Layer | Class |
| --- | --- |
| Typed Meta results | META_TYPED_ACTION |
| Pixel/dataset identity | Conditional META_GRAPH_RESOURCE |
| Meta → Business Action map | MOXDOP_MAPPING |
| Healthy / Partial / Needs mapping / Platform only | MOXDOP_CLASSIFICATION |
| CRM stages | CROSS_ASSET / OPERATOR (Demo) |
| Website / GA4 | CROSS_ASSET |
| Ops findings | OPERATIONS_DOMAIN |

---

## 22. Platform Result / Business Action Boundary

```text
Meta Lead (platform)
  → Platform Result
  → (optional) Business Action “New enquiry”
  → CRM Accepted / Qualified Lead / Patient / Sale = Business Outcome (NOT Meta)
```

Hard rules:

- Platform Lead ≠ Qualified Lead  
- Messaging conversation ≠ Qualified enquiry  
- Profile visit ≠ follow/DM/sale  
- Missing CRM evidence ≠ Meta result of zero  

---

## 23. Cross-Asset Requirements

| Pair | Join / relation | Notes |
| --- | --- | --- |
| Meta ↔ Website | normalized destination URL | Creative `link_url` / LP |
| Meta ↔ GA4 | Business Actions / sessions | Expect legitimate mismatch vs Meta LPV/clicks |
| Meta ↔ Instagram | IG identity / profile destination | Separate Digital Asset; no IG ingestion here |
| Meta ↔ Brand Goals / Offerings | Campaign Context | Not from objective alone |
| Meta ↔ CRM | qualification funnel | Demo connected; production mapping later |

---

## 24. Operations-Domain Requirements

Ops cards do not invent Graph fields.

Future Evidence deps (from frozen Findings stories):

| Concept | Inputs |
| --- | --- |
| Creative fatigue | ad/creative frequency trend + link CTR + spend |
| Lead quality gap | Meta lead counts + CRM accept (cross-asset) |
| Destination language mismatch | Campaign Context language + Website page language + spend |
| Result-type ambiguity | Result Mix + unresolved resolver |
| Delivery concentration | placement spend share |

No detectors in this prompt.

---

## 25. Date / Timezone Contract

| Rule | Definition |
| --- | --- |
| Account TZ | `timezone_name` — **DO NOT USE UTC FOR META DAILY REPORTING** |
| Shared Date Range | last_7/14/28/30, this_month, last_month, custom (+ Demo last_90) |
| Daily grain | Insights `time_increment=1` for temporal facts |
| Brand TZ differs | Store both; Meta facts in account TZ |
| Snapshots | Entity/config current only unless CDC needed (not required) |

---

## 26. Attribution / Late Data Contract

| Topic | Contract |
| --- | --- |
| Unified attribution | Prefer Ads Manager–aligned behavior (`use_unified_attribution_setting` historically; note Meta 2025+ default convergence — verify at implement time) |
| Preserve | `attribution_setting` / ad set `attribution_spec` when returned |
| action_report_time | Document provider mixed behavior; do not invent custom windows |
| Late conversions | Actions can change after first pull → **reprocessing window** for recent N days (**DECISION REQUIRED** for N) |
| Comparability | Result comparisons require matching action_type / Result Type |

---

## 27. Currency Contract

| Rule | Definition |
| --- | --- |
| Currency | Ad Account `currency` / `account_currency` |
| FX | NOT IN V1 |
| Cross-currency aggregation | **NOT SUPPORTED BY CONTRACT V1** |
| Spend | Insights decimal in account currency |
| Budgets | Verify minor-unit scaling |

---

## 28. Previous-Period Comparison

Previous = immediately preceding equal-length range.

| Metric | Comparison | Constraint |
| --- | --- | --- |
| Spend | relative % | neutral without context |
| Impressions | relative % | |
| Reach | period-vs-period provider values | not summed daily |
| Frequency | relative or absolute Δ as UI shows | not avg of avgs |
| Link CTR | prefer percentage points | |
| CPC / Cost per link click | relative % | |
| Results | relative **only same Result Type** | hard rule |
| Cost/Result | relative only same type | lower often “better” — no universal coloring |

---

## 29. Missing / Zero / Unavailable Semantics

| Situation | Behavior |
| --- | --- |
| No action row | ≠ zero Business Outcome |
| No Result mapping | ≠ zero Results — Unresolved/Unavailable |
| No Link Click field | ≠ zero all clicks |
| No Reach row | ≠ zero people |
| No creative preview | ≠ no creative entity |
| No Pixel context | ≠ zero events |
| No destination mapping | ≠ no destination config |
| Permission failure | ≠ zero data — error/stale |
| Denominator 0 | Unavailable (CTR/CPC/CPM/Frequency/Cost-Result) — no NaN/Infinity/fake 0% |

---

## 30. Provider Request Families

| ID | Resource / Insights | Level | Fields / metrics | Breakdowns | Actions | time_increment | Attribution | Pagination | Sync/Async | Grain | Consumers | Req | Volume | Why |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| RF_META_BUSINESS_DISCOVERY | Business / user adaccounts | — | business + accounts | — | — | — | — | yes | Sync | snapshot | Integration | Conditional | low | Auth scope |
| RF_META_AD_ACCOUNT_META | AdAccount | account | id,name,status,currency,timezone_name,business | — | — | — | — | — | Sync | entity | Header | Required | low | Identity |
| RF_META_CAMPAIGN_SNAPSHOT | Campaign | — | id,name,status,effective_status,objective,budget fields | — | — | — | — | yes | Sync | entity | Campaigns | Required | med | Config |
| RF_META_ADSET_SNAPSHOT | AdSet | — | id,name,campaign_id,status,effective_status,optimization_goal,billing_event,destination_type,budgets,attribution_spec | — | — | — | — | yes | Sync | entity | Detail/Funnel | Required | med | Opt≠Obj |
| RF_META_AD_SNAPSHOT | Ad | — | id,name,adset_id,campaign_id,status,effective_status,creative{id,name} | — | — | — | — | yes | Sync | entity | Creatives | Required | med–high | Ad≠Creative |
| RF_META_CREATIVE_META | AdCreative | — | id,name,title,body,cta,link_url,thumbnail_url,object_type,status | — | — | — | — | by id | Sync | entity | Creatives | Required | med | Copy |
| RF_META_ACCOUNT_DAILY | act_id/insights | account | spend,impr,reach,freq,clicks,inline_link_clicks,actions,action_values,cost_per_action_type,attribution_setting | — | preserve | **1** | unified/default | — | Sync; Async if long history | date | Overview | Required | low | Totals + mix |
| RF_META_CAMPAIGN_DAILY | insights | campaign | same + ids | — | preserve | 1 | same | yes | Sync/Async | date×campaign | Campaigns | Required | med | |
| RF_META_ADSET_DAILY | insights | adset | same | — | preserve | 1 | same | yes | Sync/Async | date×adset | Detail | Required | med–high | |
| RF_META_AD_DAILY | insights | ad | same | — | preserve | 1 | same | yes | **Async likely** | date×ad | Creatives | Required | high | |
| RF_META_TYPED_ACTION_EXTRACT | derived from Insights rows | all levels | normalize actions | — | all returned types | inherits | preserve | — | — | date×entity×type | Measurement | Required | high | Mandatory |
| RF_META_DELIVERY_BREAKDOWNS | insights | account (and/or campaign) | spend (+impr optional) | age; gender; country; publisher_platform; placement **as separate calls** | avoid by default | 1 or period | same | yes | **Async recommended** | date×dim | Audience | Required | high | Do not cartesian-explode |
| RF_META_PERIOD_REACH | insights | account/campaign/ad | reach, frequency, impressions | — | — | period (no daily rollup) | same | — | Sync | period | KPI Reach/Freq | Required | low | Non-additive |

Do **not** one request per card. Do **not** one giant incompatible query with all breakdowns.

---

## 31. Async Insights Strategy

| Family | Recommendation |
| --- | --- |
| Account/campaign daily without breakdowns (≤90–180d) | Sync often OK |
| Ad daily + long history | **Async** |
| Multiple breakdown families | **Async** + separate jobs |
| Reach with breakdowns & older windows | Async + throttle awareness ([best practices](https://developers.facebook.com/documentation/ads-commerce/marketing-api/insights/best-practices)) |

Do not implement async in this prompt.

Pagination: always follow `paging.next` — first page ≠ complete. Current collector `MAX_PAGES=3` + `ENTITY_LIMIT=50` is **unsafe** as permanent storage policy.

---

## 32. Candidate Normalized Datasets

| Dataset ID | Grain | Keys | Base facts | Typed actions | Config | Additivity | Consumers | History | Refresh | Volume | Partition | Completeness |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `meta_ad_account_snapshot` | entity | account_id | — | — | tz, currency, status, business | — | header | current | discover/daily | tiny | account | |
| `meta_campaign_snapshot` | entity | campaign_id | — | — | objective, status, budgets | — | Campaigns | current | daily | low–med | account | |
| `meta_adset_snapshot` | entity | adset_id | — | — | opt goal, billing, destination, budgets | — | Detail/Funnel | current | daily | med | account | |
| `meta_ad_snapshot` | entity | ad_id | — | — | status, creative_id | — | Creatives | current | daily | med | account | |
| `meta_creative_snapshot` | entity | creative_id | — | — | copy, cta, link, thumb ref | — | Creatives | current | daily | med | account | |
| `meta_account_daily` | date | account_id, date | spend, impr, clicks, link_clicks | via child table | — | Reach not rolled from this alone | Overview | 180d+ | daily+late | low | date | |
| `meta_campaign_daily` | date×campaign | campaign_id, date | spend, impr, clicks, link_clicks | yes | — | Reach diagnostic only | Campaigns | 180d+ | daily+late | med | date | |
| `meta_adset_daily` | date×adset | adset_id, date | same | yes | — | same | Detail | 180d+ | daily+late | med–high | date | |
| `meta_ad_daily` | date×ad | ad_id, date | same | yes | creative_id | same | Creatives | 180d+ | daily+late | high | date | |
| `meta_typed_action_daily` | date×scope×entity×action_type | composite | count, value | **core** | attribution | within type | Measurement/Mix | 180d+ | daily+late | **high** | date | raw type preserved |
| `meta_delivery_breakdown_daily` | date×dim_type×dim_value | composite | spend (+impr) | optional later | — | spend additive | Audience | 90–180d | daily | high | date | separate dims |
| `meta_period_reach_cache` | period×entity | entity, since, until | reach, frequency, impr | — | — | NON_ADDITIVE | KPI | on demand | with period | low | — | provider period |

---

## 33. Derived Formula Registry

| ID | Name | Formula | Inputs | Type | Aggregation | Null | Zero den | Currency | Comparison | Direction | Provenance | Consumers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| F_META_SPEND | Spend | sum(spend) | spend | ADDITIVE | sum | Unavail | n/a | account | relative % | neutral | Provider | Overview… |
| F_META_LINK_CTR | Link CTR | sum(inline_link_clicks)/sum(impressions) | link clicks, impr | RATIO | recompute | missing→Unavail | impr=0 | — | prefer pp | higher often better | Derived (provider ctr optional reconcile) | Campaign detail |
| F_META_CTR_ALL | All-click CTR | sum(clicks)/sum(impr) | clicks, impr | RATIO | recompute | — | impr=0 | — | pp | — | Derived | Optional — **not** Link CTR |
| F_META_CPC | CPC | sum(spend)/sum(clicks) | spend, clicks | RATIO | recompute | — | clicks=0 | account | relative | context | Derived | Optional |
| F_META_COST_LINK | Cost per link click | sum(spend)/sum(inline_link_clicks) | — | RATIO | recompute | — | 0→Unavail | account | relative | context | Derived | Optional |
| F_META_CPM | CPM | sum(spend)/sum(impr)*1000 | — | RATIO | recompute | — | impr=0 | account | relative | context | Derived | Optional |
| F_META_FREQUENCY | Frequency | period_impr/period_reach OR provider frequency | impr, reach | RATIO | period | — | reach=0 | — | relative/abs | high may flag fatigue later | Provider/Derived | Campaign/Creative |
| F_META_COST_RESULT | Cost / result | sum(spend)/sum(typed_result_count) | spend, **same** action type | RATIO | recompute | unmapped→Unavail | count=0 | account | same type only | lower often better | Derived | Campaigns |
| F_META_COST_PRIMARY | Cost / primary Lead | Lead_campaign_spend / Lead_count | Lead only | RATIO | recompute | no leads→Mixed/Unavail | 0 | account | Lead only | — | Derived | Overview |
| F_META_BUDGET_PACING | Budget pacing | compare spend vs planned×elapsed | operator plan, spend | — | — | no plan→Unavail | — | account | — | Ahead≠bad always | Hybrid | Overview |
| F_META_DELTA_REL | Relative delta | (c-p)/p | metric | — | — | p=0→Unavail | — | — | — | per metric | Derived | KPI |

**Ratio averaging prevented:** always recompute from sums / period provider values.

---

## 34. Historical Backfill

| Family | Minimum | Recommended initial | Notes |
| --- | --- | --- | --- |
| Daily insights (account/campaign) | ~180d (90+compare) | 180–365d | Quota-aware |
| Ad daily | 90–180d | 180d | Cardinality |
| Typed actions | same as parent insights | same | Late recheck |
| Delivery breakdowns | 90d | 90–180d | Explosion risk |
| Snapshots | current | current | No CDC required |
| Period reach cache | on demand | — | |

**DECISION REQUIRED** for >365d.

---

## 35. Refresh / Freshness

| Dataset | Cadence | Late recheck | Staleness |
| --- | --- | --- | --- |
| Snapshots | ≥ daily | n/a | >24–48h |
| Daily insights + typed actions | ≥ daily | recent N days | mark incomplete recent |
| Breakdowns | ≥ daily | optional | |
| Creative thumbs | daily | URLs expire | refresh references |
| Period reach | per UI period request / cache TTL | — | |

---

## 36. Cardinality / Breakdown Risks

Forbidden default: `date × campaign × adset × ad × age × gender × country × placement × action_type`.

Use **separate** breakdown request families (one dimension family per call), primarily at **account** (and campaign when needed).

Top-N UI ≠ collection limit.

---

## 37. Existing Implementation Reuse Matrix

| Component | Responsibility | Data | Coverage | Disposition | Notes |
| --- | --- | --- | --- | --- | --- |
| Meta OAuth + tokens | Auth | encrypted | access | KEEP | |
| `MetaApiConfig` v26.0 | Version/host | — | versioning | KEEP | bind tested version later |
| `MetaApiClient` | Graph GET | — | transport | KEEP | |
| Business/Ad Account discovery | Resources | account meta | identity | KEEP | |
| `MetaAdsBoundCollector` | Sync Insights | account/campaign/adset/ad + creatives | Partial | ADAPT LATER | daily grain, remove hard Top-N, breakdowns, period reach |
| `MetaActionNormalizer` | Typed actions | raw types preserved | Strong | KEEP | |
| `MetaResultResolver` | Primary + Result Mix | no blind sum | Strong | KEEP | |
| ComparisonPeriod UTC | Windows | 28d UTC | Wrong TZ | ADAPT LATER | account TZ |
| Evidence types | Persist | summaries | Partial | ADAPT LATER | |
| Demo fixtures / OverviewPage | Frozen UI | full IA | Spec | KEEP Demo | |
| Async Insights | — | — | Missing | ADD LATER | |
| Mutate | — | — | Forbidden | KEEP absent | |

---

## 38. Current Collector Gap Analysis

| Item | Class |
| --- | --- |
| spend, impressions, reach, frequency, clicks, inline_link_clicks, ctr/cpc/cpm, actions, action_values, cost_per_action_type | REQUIRED — present on range Insights |
| Action normalization + Result Mix / no blind sum | REQUIRED — **covered well** |
| Campaign/adset/ad metadata join by ID | REQUIRED — covered |
| Creative title/body/cta/link/thumb | REQUIRED — covered (limits) |
| `time_increment=1` daily facts | **MISSING** |
| Delivery breakdowns (age/gender/country/placement/platform) | **MISSING** |
| Period reach strategy separate from daily sum | Documented in Evidence notes; storage still range-only — **ADAPT** |
| Account timezone applied to ComparisonPeriod | **MISSING** (UTC) |
| ENTITY_LIMIT 50 / CREATIVE_LIMIT 40 / MAX_PAGES 3 as permanent store | **UNSAFE** |
| Flattening actions / discarding action_type | **Not present** (good) |
| Generic Results without type | Account uses Result Mix — good; ensure UI never regresses |
| Treating Meta lead as qualified lead | Guarded in limitations text — keep enforcing |
| Link clicks vs clicks conflation | Fields distinct — keep UI labels honest |
| Messaging click vs conversation | Resolver prefers conversation actions — keep |
| Purchase/ROAS expansion | NOT REQUIRED by freeze |
| Async reporting | MISSING (needed later) |

### Action normalization audit (current collector)

| Check | Status |
| --- | --- |
| actions flattened into fake total? | **NO** (blind_action_sum=false) |
| action_type discarded? | **NO** |
| generic Result without type at account? | **NO** — Result Mix |
| mixed result types handled? | **YES** |
| Reach summed unsafely in code comments? | Warned; still need period-reach dataset |
| Frequency averaged unsafely? | Provider frequency stored per row — aggregation discipline needed for daily |
| CTR/CPC averaged unsafely? | Risk if range rows re-averaged later — contract forbids |
| Link Clicks conflated with Clicks? | Fields separate |
| messaging click conflated with conversation? | Preference list uses conversation actions |
| Meta lead conflated with qualified lead? | Explicitly separated in product copy |

---

## 39. Unsupported / Demo-Only Concepts

| Concept | Class |
| --- | --- |
| Opportunity card narratives | DEMO_ONLY / Ops |
| CRM demo accept rates | DEMO / CROSS_ASSET |
| Creative thumb gradients | DEMO (replace with thumbnail_url) |
| Audience Quality Score | FORBIDDEN |
| Universal Results KPI | FORBIDDEN |
| Message content / lead PII | FORBIDDEN |
| Device breakdown bars | NOT in freeze |
| Full Events Manager event stream | NOT REQUIRED |

---

## 40. Decisions Required Before Collection

1. Late-action reprocessing window length (N days).  
2. Backfill depth beyond 180 days.  
3. Budget field unit scaling confirmation.  
4. Exact breakdown parameter names for placement vs platform_position on v26.  
5. Whether Pixel/Dataset id is required for Measurement V1 or mapping-only.  
6. Brand TZ vs Ad Account TZ display policy.  
7. Website lead action_type set per account (pixel events vary) — mapping config.  
8. Async job threshold (entity count / day span / breakdowns).  
9. Confirm default attribution behavior on current Graph version vs explicit params.

---

## 41. Definition of Done

| Check | Status |
| --- | --- |
| Every frozen Meta component traceable? | YES |
| Business/Account/Campaign/AdSet/Ad/Creative hierarchy explicit? | YES |
| Actions typed and normalized? | YES |
| Raw action type preserved? | YES |
| Result Count paired with Result Type? | YES |
| Mixed Result Types handled / locked (Result Mix)? | YES |
| Platform Results ≠ Business Outcomes? | YES |
| Reach aggregation safe? | YES |
| Frequency aggregation safe? | YES |
| Clicks ≠ Link Clicks? | YES |
| Destination ≠ measured outcome? | YES |
| Objective ≠ Optimization Goal? | YES |
| Creative ≠ Ad? | YES |
| Formulas explicit? | YES |
| Timezone explicit? | YES |
| Currency explicit? | YES |
| Attribution explicit? | YES |
| Missing ≠ zero? | YES |
| Request families explicit? | YES |
| Async candidates explicit? | YES |
| Dataset candidates explicit? | YES |
| Collector gap explicit? | YES |
| Future collector can implement without inventing wants? | YES |

**CONTRACT STATUS: PASS**

---

## Appendix — Privacy

| Question | Answer |
| --- | --- |
| USER-LEVEL DATA REQUIRED | **NO** |
| PII REQUIRED | **NO** |
| MESSAGE CONTENTS REQUIRED | **NO** |
| LEAD FORM CONTACT DATA REQUIRED | **NO** |

---

## Appendix — Minimization

If the future collector implemented exactly this contract, it would **not** need: full Events Manager streams, device breakdowns, ROAS/purchase KPIs, auction/competitive fields, person-level data, or one mega cartesian breakdown cube. Those are excluded.
