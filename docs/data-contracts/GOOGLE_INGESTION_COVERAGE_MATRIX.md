# GOOGLE INGESTION COVERAGE MATRIX

Status vocabulary:

- `PROVEN_STAGING`
- `IMPLEMENTED_UNPROVEN`
- `MISSING`
- `BLOCKED_EXTERNAL`
- `UNAVAILABLE_PROVIDER_API`
- `DEFERRED_WITH_REASON`

This matrix is a runtime-first audit of the current Google ingestion foundation on branch `cursor/google-ingestion-foundation-ea01`.
It records what is discovered, collected, raw-stored, normalized, and proven on staging today.

## GA4

| entity / dataset | provider API availability | discovered? | collected? | raw stored? | normalized? | physical table | grain | historical range | pagination | incremental strategy | current staging proof | limitation | missing permission/scope | implementation status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| property inventory | Analytics Admin `accountSummaries.list` | yes | n/a | no | `core_external_resources` | n/a | property | current | page token | refresh discovery | 89 properties discovered | inventory only, not analytical data | none on staging | `PROVEN_STAGING` |
| property metadata | Admin `properties.get` + streams | via discovery/binding | yes | yes | yes | `ga4_property_metadata` | property snapshot | current | none | re-collect snapshot / overwrite | 1 row on staging | only bound properties collect | none on staging | `PROVEN_STAGING` |
| property daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_property_daily` | property × day | 180d currently planned | offset/page size | incremental via freshness planner | 172 rows on staging | current plan uses 180d, not max-practical history yet | none on staging | `PROVEN_STAGING` |
| acquisition channel daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_acquisition_channel_daily` | channel × day | 180d currently planned | offset/page size | incremental via freshness planner | 401 rows on staging | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| source / medium daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_source_medium_daily` | source/medium × day | 180d currently planned | offset/page size | incremental via freshness planner | resumed successfully after schema fix; 44 rows written so far | run still in progress at audit cutoff | none on staging | `PROVEN_STAGING` |
| campaign daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_campaign_daily` | campaign × day | 180d currently planned | offset/page size | incremental via freshness planner | resumed successfully after schema fix; 38 rows written so far | run still in progress at audit cutoff | none on staging | `PROVEN_STAGING` |
| landing page daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_landing_page_daily` | landing page × day | 180d currently planned | offset/page size | incremental via freshness planner | resumed successfully after schema fix; 37 rows written so far | run still in progress at audit cutoff | none on staging | `PROVEN_STAGING` |
| event daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_event_daily` | event × day | 180d currently planned | offset/page size | incremental via freshness planner | resumed successfully after schema fix; 92 rows written so far | run still in progress at audit cutoff | none on staging | `PROVEN_STAGING` |
| event × channel daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_channel_daily` | event × channel × day | 180d currently planned | offset/page size | incremental via freshness planner | event breakdown family resumed on staging | family still running at audit cutoff | none on staging | `PROVEN_STAGING` |
| event × campaign daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_campaign_daily` | event × campaign × day | 180d currently planned | offset/page size | incremental via freshness planner | family resumed on staging | family still running at audit cutoff | none on staging | `PROVEN_STAGING` |
| event × landing daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_landing_daily` | event × landing × day | 180d currently planned | offset/page size | incremental via freshness planner | family resumed on staging | family still running at audit cutoff | none on staging | `PROVEN_STAGING` |
| device daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_device_daily` | device × day | 180d currently planned | offset/page size | incremental via freshness planner | 281 rows on staging | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| event × source/medium daily | Data API can support | no separate discovery | no | no | no | none | event × source/medium × day | n/a | n/a | none yet | storage contract gap explicitly recorded | contract/storage gap | none on staging | `MISSING` |
| data streams metadata | Admin `dataStreams.list` | no separate discovery row | yes | yes | yes in metadata JSON | `ga4_property_metadata` | stream snapshot | current | none | overwrite snapshot | covered inside property metadata | not isolated into separate table | none on staging | `PROVEN_STAGING` |
| custom dimensions / custom metrics metadata | Admin/Data API compatibility surfaces available | no | no | no | no | none | metadata snapshot | current | provider list | none yet | not implemented in collector | not yet modeled in V1 storage | none on staging | `MISSING` |
| geography | Data API available | no | no | no | no | none | geography × day | n/a | n/a | none yet | not in current request families | contract breadth incomplete | none on staging | `MISSING` |
| technology / browser / OS | Data API available | no | no | no | no | none | technology × day | n/a | n/a | none yet | not in current request families | contract breadth incomplete | none on staging | `MISSING` |
| ecommerce / revenue | Data API available where property configured | no | no | no | no | none | commerce × day | n/a | n/a | none yet | current V1 GA4 contract does not model monetary surfaces | product/contract incomplete for commerce | none on staging | `MISSING` |
| journeys / funnels | partially available only with explicit funnel config | no | no | no | no | none | funnel/path | n/a | n/a | none yet | current contract defers funnels and rejects fake path reconstruction | explicit product defer | none on staging | `DEFERRED_WITH_REASON` |

## Search Console

| entity / dataset | provider API availability | discovered? | collected? | raw stored? | normalized? | physical table | grain | historical range | pagination | incremental strategy | current staging proof | limitation | missing permission/scope | implementation status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| property inventory | `sites.list` | yes | n/a | no | `core_external_resources` | n/a | property | current | none | refresh discovery | 79 properties discovered | inventory only | none on staging | `PROVEN_STAGING` |
| property daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_property_daily` | property × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 178 rows on staging | provider top-rows limitations still apply | none on staging | `PROVEN_STAGING` |
| query daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_query_daily` | query × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 9,946 rows written | provider top rows, non-exhaustive universe | none on staging | `PROVEN_STAGING` |
| page daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_page_daily` | page × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 10,507 rows written | provider top rows, non-exhaustive universe | none on staging | `PROVEN_STAGING` |
| query × page daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_query_page_daily` | query × page × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 13,181 rows written | highest-cardinality path, provider top rows | none on staging | `PROVEN_STAGING` |
| country daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_country_daily` | country × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 2,299 rows written | provider top rows | none on staging | `PROVEN_STAGING` |
| device daily | `searchanalytics.query` | via binding | yes | yes | yes | `gsc_device_daily` | device × day | planner currently 180d minimum policy | `startRow` paging | incremental via freshness planner | 414 rows written | provider top rows | none on staging | `PROVEN_STAGING` |
| sitemaps | `sitemaps.list` | via binding | yes | yes | yes | `gsc_sitemap_snapshot` | sitemap snapshot | current | none | overwrite snapshot | 94 rows written | submitted ≠ indexed | none on staging | `PROVEN_STAGING` |
| URL inspection | `urlInspection.index.inspect` | via binding | conditional | yes when targets supplied | yes | `gsc_url_inspection_snapshot` | inspection snapshot | explicit targets only | per-target | conditional replay | current run marked not eligible with no targets | intentionally controlled, not automatic full-site crawl | none on staging | `IMPLEMENTED_UNPROVEN` |
| search appearance daily | API available | no | no | no | table exists but planner excludes | `gsc_search_appearance_daily` | appearance × day | n/a | `startRow` paging | none yet | explicit source-contract exclusion | excluded by current source contract | none on staging | `DEFERRED_WITH_REASON` |

## Google Ads

| entity / dataset | provider API availability | discovered? | collected? | raw stored? | normalized? | physical table | grain | historical range | pagination | incremental strategy | current staging proof | limitation | missing permission/scope | implementation status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| account discovery | `customers:listAccessibleCustomers` + hierarchy expansion | yes | n/a | no | `core_external_resources` | n/a | account | current | page token / hierarchy traversal | refresh discovery | 56 accounts discovered | discovery only; not proof of ingestion | none on staging | `PROVEN_STAGING` |
| account snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_account_snapshot` | account snapshot | current | paged/search | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| account daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_account_daily` | account × day | current contract plans 180d | paged or search stream | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| campaign snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_campaign_snapshot` | campaign snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| campaign daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_campaign_daily` | campaign × day | current contract plans 180d | search stream | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| ad group snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_ad_group_snapshot` | ad group snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| ad snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_ad_snapshot` | ad snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| keyword snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_keyword_snapshot` | keyword snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| keyword daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_keyword_daily` | keyword × day | current contract plans 180d | search stream | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| search term daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_search_term_daily` | search term × day | current contract plans 180d | search stream | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| landing page daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_landing_page_daily` | landing page × day | current contract plans 180d | search stream | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| conversion action snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_conversion_action_snapshot` | conversion action snapshot | current | paged/search | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| conversion action daily | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_conversion_action_daily` | conversion action × day | current contract plans 180d | paged/search | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| campaign budget snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_campaign_budget_snapshot` | budget snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |
| asset coverage snapshot | Google Ads GAQL | discovered but not bound on staging | implemented | yes | yes | `google_ads_asset_coverage_snapshot` | asset snapshot | current | multi-snapshot | incremental via freshness planner | no bound/staged ingest yet | staging lacks bound Ads resource proof | none on staging | `IMPLEMENTED_UNPROVEN` |

## Google Business Profile

| entity / dataset | provider API availability | discovered? | collected? | raw stored? | normalized? | physical table | grain | historical range | pagination | incremental strategy | current staging proof | limitation | missing permission/scope | implementation status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| account / location inventory | Account Management + Business Information APIs | blocked on staging | n/a | no | `core_external_resources` when enabled | n/a | location inventory | current | page token | refresh discovery | current staging discovery returns `scope_required` | `include_gbp_scope=false`, `gbp_discovery_enabled=false` | `business.manage` not requested; API access disabled | `BLOCKED_EXTERNAL` |
| location profile snapshot | provider API available | no staging proof | no production collector | no | no | none | location snapshot | current | paged/list | none | no analytical collector exists in collection engine | no request families or storage contract | `business.manage` / API access | `MISSING` |
| reviews | provider API available when authorized | no | no | no | no | none | review snapshot/history | provider dependent | paged | none | not implemented in production collector path | no request families or tables | `business.manage` / API access | `MISSING` |
| performance metrics | provider API availability varies | no | no | no | no | none | metric × day/snapshot | provider dependent | provider dependent | none | not implemented in production collector path | no request families or tables | `business.manage` / API access | `MISSING` |
| search keyword / discovery performance | partially provider-limited | no | no | no | no | none | keyword/performance | provider limited | provider dependent | none | product asks for parity, but no collector exists yet | some desired data may be unavailable through first-party API | `business.manage` / API access | `MISSING` |
| calls / website actions / direction requests | provider dependent | no | no | no | no | none | action metric | provider dependent | provider dependent | none | no production collector exists yet | some actions may require separate GBP access tiers | `business.manage` / API access | `MISSING` |
| media metadata | provider dependent | no | no | no | no | none | media snapshot | current | provider dependent | none | not implemented in production collector path | no request families or tables | `business.manage` / API access | `MISSING` |

## Current staging proof summary

- Google integration connected: `PROVEN_STAGING`
- Search Console discovery: `PROVEN_STAGING`
- GA4 discovery: `PROVEN_STAGING`
- Google Ads discovery: `PROVEN_STAGING`
- GBP discovery: `BLOCKED_EXTERNAL`
- Search Console collection: `PROVEN_STAGING`
- GA4 non-partitioned collection: `PROVEN_STAGING`
- GA4 partitioned PostgreSQL schema repair: `PROVEN_STAGING`
- Google Ads collection: `IMPLEMENTED_UNPROVEN`
- GBP analytical collection: `MISSING`
