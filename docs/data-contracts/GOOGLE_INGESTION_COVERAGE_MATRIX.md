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
| property daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_property_daily` | property × day | 180d currently planned | offset/page size | incremental via freshness planner | 172 rows, min=2026-02-20 max=2026-08-18. CollectionRun #3 replay dataset 47 rewrote 172 rows; natural-key count stayed 172 | current plan uses 180d, not max-practical history yet | none on staging | `PROVEN_STAGING` |
| acquisition channel daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_acquisition_channel_daily` | channel × day | 180d currently planned | offset/page size | incremental via freshness planner | 401 rows on staging | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| source / medium daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_source_medium_daily` | source/medium × day | 180d currently planned | offset/page size | incremental via freshness planner | 454 rows on staging, min=2026-02-20 max=2026-08-18; CollectionRun #2 dataset 23 completed. CollectionRun #1 dataset 4 remains historically failed (`PROVIDER_5XX`) | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| campaign daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_campaign_daily` | campaign × day | 180d currently planned | offset/page size | incremental via freshness planner | 389 rows on staging, min=2026-02-20 max=2026-08-18 | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| landing page daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_landing_page_daily` | landing page × day | 180d currently planned | offset/page size | incremental via freshness planner | 395 rows on staging, min=2026-02-20 max=2026-08-18 | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| event daily | Data API `runReport` | via binding | yes | yes | yes | `ga4_event_daily` | event × day | 180d currently planned | offset/page size | incremental via freshness planner | 866 rows on staging, min=2026-02-20 max=2026-08-18 | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| event × channel daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_channel_daily` | event × channel × day | 180d currently planned | offset/page size | incremental via freshness planner | 1729 rows on staging, min=2026-02-20 max=2026-08-18; CollectionRun #2 dataset 27 completed with 5027 rows written across the three event-breakdown tables. CollectionRun #1 dataset 8 remains historically failed (`PROVIDER_5XX`) | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| event × campaign daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_campaign_daily` | event × campaign × day | 180d currently planned | offset/page size | incremental via freshness planner | 1683 rows on staging, min=2026-02-20 max=2026-08-18; proven by completed dataset 27, not by resume-alone | current plan uses 180d | none on staging | `PROVEN_STAGING` |
| event × landing daily | Data API `runReport` via event breakdowns | via binding | yes | yes | yes | `ga4_event_landing_daily` | event × landing × day | 180d currently planned | offset/page size | incremental via freshness planner | 1615 rows on staging, min=2026-02-20 max=2026-08-18; proven by completed dataset 27, not by resume-alone | current plan uses 180d | none on staging | `PROVEN_STAGING` |
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

Bound staging resource: `core_asset_binding_id=3`, `digital_asset_id=2`, `external_resource_id=173`, one Ads customer. CollectionRun #2 (`d24a53b2-caf5-49ad-978f-a4f9629fa91d`) executed through the collection engine (website-anchored, `allow_multi_asset_bindings=true`).

| entity / dataset | provider API availability | discovered? | collected? | raw stored? | normalized? | physical table | grain | historical range | pagination | incremental strategy | current staging proof | limitation | missing permission/scope | implementation status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| account discovery | `customers:listAccessibleCustomers` + hierarchy expansion | yes | n/a | no | `core_external_resources` | n/a | account | current | page token / hierarchy traversal | refresh discovery | 56 accounts discovered | discovery only; not proof of ingestion | none on staging | `PROVEN_STAGING` |
| account snapshot | Google Ads GAQL | yes, **bound** on staging | yes | yes | yes | `google_ads_account_snapshot` | account snapshot | current | paged/search | incremental via freshness planner | dataset 39 completed; 1 row; binding 3 / asset 2 / resource 173 | only the bound customer collects | none on staging | `PROVEN_STAGING` |
| account daily | Google Ads GAQL | yes, bound | yes | yes (empty results) | n/a (zero rows) | `google_ads_account_daily` | account × day | 180d planned and executed 2026-02-20→2026-08-18 | paged search | incremental via freshness planner | dataset 40 completed, 8 attempts, 0 rows. Raw object `account_daily:2026-02-20` has HTTP-success payload with `results: []` and `requestId`. Materialization AVAILABLE, `row_count_approx=0` | bound account has no metric delivery in range | none on staging | `PROVEN_STAGING` |
| campaign snapshot | Google Ads GAQL v25 `start_date_time`/`end_date_time` | yes, bound | yes | yes | yes | `google_ads_campaign_snapshot` | campaign snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 10 rows (9 PAUSED, 1 ENABLED PERFORMANCE_MAX) | v25 field rename was a collector defect; historical UNRECOGNIZED_FIELD preserved | none on staging | `PROVEN_STAGING` |
| campaign daily | Google Ads GAQL | yes, bound | yes | empty SearchStream slices skip raw objects | n/a (zero rows) | `google_ads_campaign_daily` | campaign × day | 180d executed 2026-02-20→2026-08-18 | search stream | date-slice replay | dataset 41 completed, 27 attempts, 0 rows. Live follow-up Search + SearchStream for 2026-08-12→2026-08-18 returned HTTP 200 with 0 results. Materialization AVAILABLE zero-row | no campaign metrics in range despite entity snapshots | none on staging | `PROVEN_STAGING` |
| ad group snapshot | Google Ads GAQL | yes, bound | yes | yes | yes | `google_ads_ad_group_snapshot` | ad group snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 18 rows | bound customer only | none on staging | `PROVEN_STAGING` |
| ad snapshot | Google Ads GAQL | yes, bound | yes | yes | yes | `google_ads_ad_snapshot` | ad snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 33 rows | bound customer only | none on staging | `PROVEN_STAGING` |
| keyword snapshot | Google Ads GAQL | yes, bound | yes | yes | yes | `google_ads_keyword_snapshot` | keyword snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 735 rows after collapsing ad-group-scoped duplicate `criterion_id` values onto the contract natural key | contract grain is customer×criterion_id; duplicates last-write-win | none on staging | `PROVEN_STAGING` |
| keyword daily | Google Ads GAQL | yes, bound | yes | empty slices skip raw objects | n/a (zero rows) | `google_ads_keyword_daily` | keyword × day | 180d executed | search stream, 1-day slices | date-slice replay | dataset 42 completed, 181 attempts, 0 rows. Materialization AVAILABLE zero-row | consistent with account/campaign daily zeros | none on staging | `PROVEN_STAGING` |
| search term daily | Google Ads GAQL | yes, bound | yes | empty slices skip raw objects | n/a (zero rows) | `google_ads_search_term_daily` | search term × day | 180d executed | search stream, 1-day slices + PMax view | date-slice replay | dataset 43 completed, 364 attempts, 0 rows. Materialization AVAILABLE zero-row | includes `search_term_view` and `campaign_search_term_view` phases | none on staging | `PROVEN_STAGING` |
| landing page daily | Google Ads GAQL | yes, bound | yes | empty slices skip raw objects | n/a (zero rows) | `google_ads_landing_page_daily` | landing page × day | 180d executed | search stream, 1-day slices | date-slice replay | dataset 44 completed, 181 attempts, 0 rows. Materialization AVAILABLE zero-row | consistent with other daily zeros | none on staging | `PROVEN_STAGING` |
| conversion action snapshot | Google Ads GAQL | yes, bound | yes | yes | yes | `google_ads_conversion_action_snapshot` | conversion action snapshot | current | paged/search | incremental via freshness planner | datasets 45 and 39; 45 rows | snapshot ≠ daily metrics | none on staging | `PROVEN_STAGING` |
| conversion action daily | Google Ads GAQL | yes, bound | yes | n/a (zero rows) | n/a (zero rows) | `google_ads_conversion_action_daily` | conversion action × day | 180d executed | paged/search | date-slice replay | dataset 45 completed, 29 attempts, 45 rows written (snapshot only). Daily table 0 rows. Materialization AVAILABLE zero-row for daily coverage 2026-02-20→2026-08-18 | no conversion metrics in range | none on staging | `PROVEN_STAGING` |
| campaign budget snapshot | Google Ads GAQL | yes, bound | yes | yes (with campaign snapshot) | yes | `google_ads_campaign_budget_snapshot` | budget snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 10 rows | budget ≠ spend | none on staging | `PROVEN_STAGING` |
| asset coverage snapshot | Google Ads GAQL | yes, bound | yes | yes | yes | `google_ads_asset_coverage_snapshot` | asset snapshot | current | multi-snapshot | incremental via freshness planner | dataset 39; 503 rows | asset ≠ ad; no binary download | none on staging | `PROVEN_STAGING` |

## Historical failed attempts (preserved)

Do not treat these as current status. They remain part of the staging audit trail.

1. **CollectionRun #2 first poll `queued` / `attempt_count=0`:** stale short poll. Horizon `supervisor-collection` (`queue=collection`, `maxProcesses=1`) was busy with CollectionRun #1 GA4 continuation. Redis collection llen/delayed/reserved were 0 after the run advanced; `failed_jobs=0`. The engine did dispatch.
2. **Ads CROSS_TENANT:** all 8 Ads families failed immediately with `authorization` / `CROSS_TENANT` / `Cross-tenant protection: Google Ads scope mismatch.` Root cause: eligibility required `CollectionRun.digital_asset_id === Ads DigitalAsset.id`. Run is website asset 1; Ads lives on sibling asset 2. Fixed in `CollectionBindingScope` / Ads-GA4-GSC guards (`917b7e7`).
3. **Ads GAQL v25:** dataset 39 failed at `campaign_snapshot` with `CONTRACT_MISMATCH` / `Request contains an invalid argument.` Isolated query: `UNRECOGNIZED_FIELD` for `campaign.start_date` / `campaign.end_date`. Fixed to `campaign.start_date_time` / `campaign.end_date_time`; error mapper now surfaces GoogleAdsFailure details (`e98a7ba`).
4. **Keyword snapshot upsert:** dataset 39 then failed `PERSISTENCE` / PostgreSQL `ON CONFLICT DO UPDATE command cannot affect row a second time` because Ads `criterion_id` is ad-group-scoped while storage grain is customer×criterion_id. Collapse last-write-wins (`02a764d`).
5. **Stale write-batch checksum:** resume of the collapsed keyword payload hit `Write batch checksum conflict`. Non-committed batches may replace checksum (`45f4435`).
6. **GA4 CollectionRun #1 leftovers:** dataset 4 `GA4_RF_SOURCE_MEDIUM_DAILY` and dataset 8 `GA4_RF_EVENT_BREAKDOWNS` remain `failed` / `PROVIDER_5XX`. Later CollectionRun #2 completed those families with real rows. Event-breakdown `PROVEN_STAGING` is from dataset 27 completion + warehouse counts, not from the failed resume-alone poll.

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
- Google Ads **binding**: `PROVEN_STAGING` (binding 3 on asset 2 / resource 173)
- GBP discovery: `BLOCKED_EXTERNAL`
- Search Console collection: `PROVEN_STAGING`
- GA4 non-partitioned collection: `PROVEN_STAGING`
- GA4 partitioned PostgreSQL schema repair: `PROVEN_STAGING`
- GA4 event breakdowns with real rows: `PROVEN_STAGING`
- GA4 property-daily idempotency replay (CollectionRun #3 dataset 47): 172 rows received/written; warehouse still 172 natural keys (no multiply)
- Google Ads snapshot collection: `PROVEN_STAGING`
- Google Ads daily facts: `PROVEN_STAGING` as **successful zero-row** datasets (not “metrics present”)
- GBP analytical collection: `MISSING`

