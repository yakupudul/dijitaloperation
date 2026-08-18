# GOOGLE ADS PRODUCTION COLLECTOR

## 1. Purpose

Prompt 19 implements the production Google Ads collector: human-confirmed bound Google Ads Customers populate the canonical MoxDOP data pool with real provider facts through the shared Collection Engine.

## 2. Contract Boundary

Implements only:

- `docs/data-contracts/GOOGLE_ADS_DATA_CONTRACT_V1.md` (RF_GADS_* semantics)
- Registry IDs `GADS_RF_*` in `MOXDOP_DATA_CONTRACT_REGISTRY_V1.json`
- Physical storage `MOXDOP_DATA_POOL_STORAGE_V1.json`
- Formula ownership remains in `MOXDOP_FORMULA_REGISTRY_V1.json`

No Keyword Planner, recommendations, mutate services, BigQuery transfer, click-level/GCLID data, or specialist UI migration (Prompt 30).

## 3. Binding Eligibility

Requires active Google Integration, Ads OAuth scope, application developer-token readiness, `google_ads` ExternalResource, human-confirmed active Binding, tenant consistency.

- Discovered-only: NOT eligible
- Manager accounts (`metadata.is_manager`): NOT performance roots (`MANAGER_NOT_PERFORMANCE_ROOT`)

## 4. Google Ads Client / Credential Path

`GoogleAdsClientFactory` → `GoogleApiClient::searchAds` / `searchStreamAds` → `GoogleCredentialBroker`.

No collector token decrypt/refresh. No tokens in DatasetRun/queue/checkpoint/raw.

## 5. Developer Token Boundary

Application-level (`config('moxdop.google.developer_token')` / provider credential app config). Not Customer/ExternalResource/Binding/queue/DatasetRun field. Missing → `DEVELOPER_TOKEN_REQUIRED`.

## 6. Manager / Login Customer Context

`login_customer_id` / `manager_customer_id` from ExternalResource discovery metadata (Prompt 15). Request header only — not fact identity. Hierarchy is not rediscovered per DatasetRun.

## 7. Account Metadata

`GADS_RF_ENTITY_SNAPSHOT` step `customer_meta`: id, descriptive_name, currency_code, time_zone, manager, test_account, auto_tagging. Entire Customer resource is not mirrored.

## 8. GAQL Architecture

`GoogleAdsGaqlRequestBuilder` — contract-only SELECT/FROM. UI cannot supply GAQL. Required incompatibility → `CONTRACT_MISMATCH` (no silent field drop). No mega-query.

## 9. Search vs SearchStream

| Family | Mode | Replay boundary |
| --- | --- | --- |
| ENTITY_SNAPSHOT / CONVERSION meta / ACCOUNT_DAILY | SEARCH_PAGED | page token within tick; natural keys |
| SEARCH_STREAM (overview) / CAMPAIGN_DAILY / KEYWORD / SEARCH_TERM / LANDING | SEARCH_STREAM | **date slice** + natural-key idempotency |
| CONVERSION daily | SEARCH_PAGED | date slice |

SearchStream: official REST returns full stream response; MoxDOP processes application write batches (`write_batch_size`). No full-report buffering of normalized state beyond the current provider response chunk processing path. Failed stream → replay bounded date slice.

## 10. Historical Date Slicing

Range from CollectionPlan / `StartCollectionRequest.dateRange`. Config `moxdop-google-ads-collector.date_slice_days`. Inclusive, contiguous. Prompt 20 owns orchestration.

## 11. Customer Timezone

`customer.time_zone` → `source_timezone` / reporting `segments.date`. No Brand / GA4 / server UTC rebucketing. Manager TZ not substituted for child facts.

## 12. Currency / Micros

Account `currency_code` on monetary facts. `cost_micros` preserved; `cost_amount` via exact micros÷1e6 string arithmetic (no float). No FX. Budget ≠ spend (separate snapshot).

## 13. Campaigns

Snapshot + daily (incl. Search IS family when returned). Channel type preserved. PMax campaigns included in campaign daily; lower structure remains type-specific.

## 14. Ad Groups

Standard `ad_group` snapshot only. Not merged with AssetGroup.

## 15. Performance Max

- Campaign daily: yes
- Search terms: `campaign_search_term_view` (separate phase) — absence from standard view ≠ PMax zero
- No synthetic AdGroup/Ad from AssetGroup
- Asset coverage via `asset` inventory (not PMax studio depth)

## 16. Search Terms

`search_term_view` + PMax `campaign_search_term_view`. ≠ Keyword; ≠ GSC Query. Privacy omissions ≠ zero. Storage NK is term×date (Storage V1); ad-group/campaign contexts retained in metadata; same-term rows aggregated within batch.

## 17. Keywords

`keyword_view` daily + snapshot by `criterion_id`. Text/match/status preserved. Negatives not collected.

## 18. Ads

`ad_group_ad` snapshot: id, type, status, strength, final_urls (configuration). Not PMax ads. Final URL ≠ landing-page performance.

## 19. Assets

`asset` inventory → `google_ads_asset_coverage_snapshot`. No binary download. No MoxDOP creative score.

## 20. Asset Linkages

V1 stores asset coverage snapshot; typed link edges beyond contract inventory are not invented.

## 21. Landing Pages

`landing_page_view.unexpanded_final_url` daily metrics. Provider URL preserved. No Website canonicalization.

## 22. Conversion Actions

Snapshot metadata: id, name, status, type, category, origin, primary_for_goal, include_in_conversions_metric, counting_type.

## 23. Typed Conversion Performance

`segments.conversion_action*` + conversions / conversions_value / all_conversions. Typed rows — no generic Results-only fact. conversions ≠ all_conversions. Interaction-date segment semantics. ≠ Business Outcome / Qualified Lead. Mapping not applied in collector.

## 24. Formula Boundary

Base facts stored. CTR/CPC/CPA/ROAS remain Formula Registry. Provider ratios not canonical.

## 25. Raw Payload

RawPayloadWriter with API version, customer id, login-customer presence flag, request id, GAQL fingerprint — never developer token / Authorization / OAuth secrets.

## 26. Normalization

`GoogleAdsNormalizer` — logical records only; no table names.

## 27. Warehouse Persistence

`DatasetWritePipeline` → `WarehouseWriter`. Natural keys per Storage V1. CollectionRun is provenance only.

## 28. Streaming / Paging Recovery

Search: `nextPageToken`. SearchStream: date-slice replay + upsert idempotency. Checkpoint after durable commit.

## 29. Idempotency

Retry / stream replay / late correction upsert same NK. Late conversion/spend corrections update rows.

## 30. Progress / Monitoring

Slice/step progress when known; rows received/written. No fake stream %. Prompt 11 may show real Ads DatasetRun state.

## 31. Materialization

Coverage from written dates. Partial / failed refresh preserve prior. Zero-row success ≠ NOT_COLLECTED.

## 32. Reliability

Prompt 12 categories. No private retry loop / blocking sleep. Sibling isolation across families and vs GSC/GA4.

## 33. Privacy / Data Minimization

No GCLID/GBRAID/WBRAID/email/phone/Customer Match/click view. Aggregate GAQL only. Contract fields only.

## 34. Tests

`tests/Feature/Collection/GoogleAdsProductionCollectorTest.php`  
`tests/Unit/Collection/GoogleAdsDateSlicerTest.php`  
0 live Google Ads calls.

## 35. Provider Limitations

Verified 2026-08-13: API v25 REST Search (page size 10k) / SearchStream; search-term privacy omissions; PMax separate view; competitive IS may be null.

## 36. Reality Matrix

See Milestone 5: Google Ads Production Collector = REAL; specialist UI = NOT YET (Prompt 30).

## 37. Prompt 20 / Prompt 30 Handoff

- Prompt 20: Google Initial Backfill Orchestrator — **done** (`docs/implementation/GOOGLE_INITIAL_BACKFILL.md`)
- Prompt 27: incremental freshness
- Prompt 30: Google Ads real-data UI migration

## 38. Definition of Done

Human-confirmed Ads Binding → CollectionPlan → GADS_RF_* DatasetRuns → GAQL Search/SearchStream → date slices → raw → normalizer → WarehouseWriter → real Google Ads pool → materialization.

---

## REQUEST FAMILY MATRIX

| Registry RF | Source RF | FROM | Retrieval | Dataset(s) | Level |
| --- | --- | --- | --- | --- | --- |
| GADS_RF_ENTITY_SNAPSHOT | RF_GADS_* meta | customer, campaign(+budget), ad_group, ad_group_ad, keyword_view, asset, conversion_action | SEARCH_PAGED multi-step | account/campaign/budget/ad_group/ad/keyword/asset/conversion snapshots | REQUIRED |
| GADS_RF_ACCOUNT_DAILY | RF_GADS_ACCOUNT_DAILY | customer + segments.date | SEARCH_PAGED | google_ads_account_daily | REQUIRED |
| GADS_RF_SEARCH_STREAM | Overview transport | customer + segments.date | SEARCH_STREAM | google_ads_account_daily | REQUIRED |
| GADS_RF_CAMPAIGN_DAILY | RF_GADS_CAMPAIGN_DAILY | campaign + date | SEARCH_STREAM | google_ads_campaign_daily | REQUIRED |
| GADS_RF_KEYWORD | RF_GADS_KEYWORD_* | keyword_view + date | SEARCH_STREAM | keyword_daily + snapshot | REQUIRED |
| GADS_RF_SEARCH_TERM | RF_GADS_SEARCH_TERM + PMax | search_term_view + campaign_search_term_view | SEARCH_STREAM | google_ads_search_term_daily | REQUIRED |
| GADS_RF_LANDING_PAGE | RF_GADS_LANDING_PAGE_DAILY | landing_page_view | SEARCH_STREAM | google_ads_landing_page_daily | REQUIRED |
| GADS_RF_CONVERSION_ACTION | RF_GADS_CONVERSION_ACTION_* | conversion_action + segmented customer daily | SEARCH_PAGED | conversion snapshots + daily | REQUIRED |

## SEARCH VS SEARCHSTREAM MATRIX

| Family | Volume | Mode | Known total? | Durable retry | Batch |
| --- | --- | --- | --- | --- | --- |
| Entity snapshots | Low–Med | Search | No | Step checkpoint + NK | write_batch_size |
| Account daily | Low | Search or Stream | No | Date slice | write_batch_size |
| Campaign daily | Med | Stream | No | Date slice | write_batch_size |
| Keyword / Search term / LP | High | Stream | No | Date slice | write_batch_size |
| Conversion | Low–Med | Search | No | Date slice | write_batch_size |

## ENTITY MODEL MATRIX

| Concept | Standard | PMax | Same dataset? |
| --- | --- | --- | --- |
| Campaign | campaign | campaign | YES (daily/snapshot) |
| Ad Group | ad_group | AssetGroup (not collected as AdGroup) | NO |
| Ad | ad_group_ad | none invented | NO fake PMax ads |
| Asset | asset | asset / asset_group_asset (coverage snapshot) | coverage only |
| Search Term | search_term_view | campaign_search_term_view | SAME physical table; source_view metadata |
| Keyword | keyword_view | N/A | distinct from terms |

## SEARCH TERM / CONVERSION / MONEY / TIMEZONE / ASSET / LANDING MATRICES

See sections 12–23 and Request Family Matrix. Hard rules:

- no FX; exact micros
- no timezone rebucketing
- typed conversions only
- Website canonicalization during ingestion: **NO**
