# Performance Baseline (Prompt 65)

## Environment (this measurement run)

| Field | Value |
|---|---|
| Git SHA | `abae4e466b01940dea1e72bc8b5267f5f9b4cac5` |
| PHP | 8.3.6 |
| Laravel | 13.24.0 |
| Database driver | sqlite (default `sqlite`) |
| CPU count | 4 |
| RAM available (bytes) | 14620008448 |
| Queue connection | database |
| Horizon default timeout | 300 |
| Warehouse batch size | 500 |
| Redis / Horizon farm throughput | NOT_MEASURED (no redis-cli / sustained workers in image) |
| PostgreSQL EXPLAIN ANALYZE (large) | NOT_MEASURED on this sqlite default path |

## AGENCY_20 measured (overrides: gsc-rows=200, ads-rows=200)

| Metric | Value |
|---|---|
| Customers | 20 |
| GSC rows inserted | 200 |
| Ads rows inserted | 200 |
| customer_list_query.queries | 1 |
| customer_list_query.duration_ms | 1.069 |
| finding_eloquent_query.queries | 1 |
| finding_eloquent_query.duration_ms | 0.481 |
| task_eloquent_query.queries | 1 |
| task_eloquent_query.duration_ms | 0.406 |
| gsc_top_queries.queries | 2 |
| gsc_top_queries.duration_ms | 0.534 |
| gsc_top_queries.rows_returned | 20 |
| gsc_aggregate_sql.queries | 1 |
| gsc_aggregate_sql.duration_ms | 0.21 |
| ads_search_terms.queries | 1 |
| ads_search_terms.duration_ms | 0.377 |
| task_paginate_clamp.queries | 1 |
| task_paginate_clamp.duration_ms | 0.49 |
| task_paginate_clamp.per_page | 100 |
| report_list.queries | 1 |
| report_list.duration_ms | 0.5 |

## Production HTTP p50/p95

NOT_MEASURED — no load generator against production-like Postgres+Horizon.


## Queue wait p50/p95

NOT_MEASURED — queue connection sync in this run.


## Partition decision

- further_partitioning: DEFER
- control_plane_customer_partition: REJECT
- data_plane: ALREADY RANGE_MONTHLY


No invented SLAs. Recommended targets are PROPOSED only.
