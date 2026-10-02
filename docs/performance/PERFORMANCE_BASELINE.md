# Performance Baseline (Prompt 65)

## Environment (this measurement run)

| Field | Value |
|---|---|
| Git SHA | `15e9195c6caada3c94ebaac30603c92550e6685c` |
| PHP | 8.3.6 |
| Laravel | 13.24.0 |
| Database driver | sqlite (default `sqlite`) |
| CPU count | 4 |
| RAM available (bytes) | 14399901696 |
| Queue connection | database |
| Horizon default timeout | 300 |
| Warehouse batch size | 500 |
| Redis / Horizon farm throughput | NOT_MEASURED (no redis-cli / sustained workers in image) |
| PostgreSQL EXPLAIN ANALYZE (large) | NOT_MEASURED on this sqlite default path |

## AGENCY_20 measured (overrides: gsc-rows=200, ads-rows=200)

Command: `php artisan moxdop:performance:benchmark AGENCY_20 --gsc-rows=200 --ads-rows=200 --json`

| Metric | Value |
|---|---|
| Customers | 20 |
| Brands / Assets | 20 / 20 |
| GSC rows inserted | 200 |
| Ads rows inserted | 200 |
| customer_list_query.queries | 1 |
| customer_list_query.duration_ms | 1.222 |
| finding_eloquent_query.queries | 1 |
| finding_eloquent_query.duration_ms | 0.474 |
| task_eloquent_query.queries | 1 |
| task_eloquent_query.duration_ms | 0.376 |
| gsc_top_queries.queries | 2 |
| gsc_top_queries.duration_ms | 0.65 |
| gsc_top_queries.rows_returned | 20 |
| gsc_aggregate_sql.queries | 1 |
| gsc_aggregate_sql.duration_ms | 0.235 |
| ads_search_terms.queries | 1 |
| ads_search_terms.duration_ms | 0.366 |
| task_paginate_clamp.queries | 1 |
| task_paginate_clamp.duration_ms | 0.515 |
| task_paginate_clamp.per_page | 100 |
| report_list.queries | 1 |
| report_list.duration_ms | 0.496 |

## Production HTTP p50/p95

NOT_MEASURED — no load generator against production-like Postgres+Horizon.

## Queue wait p50/p95

NOT_MEASURED — queue connection database/sync in this run; Redis CLI unavailable.

## Partition decision

- further_partitioning: DEFER
- control_plane_customer_partition: REJECT
- data_plane: ALREADY RANGE_MONTHLY

No invented SLAs. Recommended targets are PROPOSED only.

## Measurement note

Re-verified on HEAD `15e9195` after Prompt 64 base `abae4e4`. Full 50k HIGH_VOLUME profiles and sustained Horizon throughput remain optional/dedicated and are not claimed here.
