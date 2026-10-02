# PERFORMANCE & SCALE AUDIT

Prompt 65 — Production Hardening (Performance). Measure first. Optimize second.

## 1. Purpose

Determine whether MoxDOP continues to work efficiently as Customer, Brand, DigitalAsset, provider dataset, collection, intelligence, and reporting volumes grow — without premature distributed architecture and without changing truth semantics.

## 2. Performance Principles

1. Measure before structural optimization.
2. Performance must not change truth (missing→zero, stale→current, cached→authoritative, etc.).
3. Separate control / data / execution / artifact planes.
4. Prompt 64 tenant isolation is absolute.
5. No BigQuery / ClickHouse / Elasticsearch / sharding / Customer partitions / fake SLAs.

## 3. Benchmark Environment

| Field | Value |
|---|---|
| Base | Prompt 64 HEAD `abae4e466b01940dea1e72bc8b5267f5f9b4cac5` |
| Branch | `cursor/performance-scale-audit-ea01` |
| PHP | 8.3.6 |
| Laravel | 13.24.0 |
| Default DB | sqlite (tests/app) |
| PostgreSQL | accepting on 127.0.0.1:5432; not default for this run |
| Redis | NOT available via redis-cli in image |
| CPU / RAM | 4 / ~15 GiB |
| Horizon farm | NOT_MEASURED sustained |

See also `docs/performance/PERFORMANCE_BASELINE.md`.

## 4. Existing Performance Primitive Audit

| Primitive | Classification |
|---|---|
| WarehouseWriter batch upsert (500) | GOOD / CANONICAL |
| PartitionManager RANGE_MONTHLY (PG facts) | GOOD / CANONICAL |
| Report list metadata select | GOOD / CANONICAL |
| GSC/Ads SQL GROUP BY + limit | GOOD_BUT_UNMEASURED → fixed N+1 detail path |
| Filament Finding/Task/Rec/Brand lists without with() | N_PLUS_ONE → fixed |
| Activity unbounded SQL then PHP slice | UNBOUNDED_QUERY → fixed bound |
| Warehouse countExisting per-row EXISTS | ROW_BY_ROW_WRITE → fixed OR-query |
| Unclamped per_page (Task etc.) | UNBOUNDED_QUERY → clamped |
| Horizon timeout 60 vs jobs 300 | BACKGROUND_STARVATION_RISK → timeout 300 |
| Hot daily missing (asset, resource, date) index | MISSING_INDEX → migration |
| Customer / Brand control plane | NOT_PARTITION_CANDIDATE |
| Provider daily facts | PARTITION_CANDIDATE (already RANGE_MONTHLY) |
| Cross-Brand Redis analytical cache | UNSAFE_CACHE — none introduced |
| k6/Artillery | UNKNOWN → harness added |

## 5. Control Plane vs Data Plane

Control plane: Customers, Brands, Assets, Findings, Opportunities, Tasks, Goals, Reports metadata — conventional relational tables; do not partition for Customer count alone.

Data plane: normalized provider daily facts — primary analytical scale driver; indexed + existing monthly partitions on Postgres.

## 6. Execution Plane

CollectionRun / ResourceRun / DatasetRun / RecurringOccurrence / IntelligenceTrigger / Agent runs / Activity — append-heavy ledgers; index by status + due/time; do not full-scan history for due discovery.

## 7. Artifact Plane

Raw payloads and report PDFs remain private object-storage artifacts with DB metadata. List UIs never load bodies.

## 8. Scale Profiles

See `docs/performance/BENCHMARK_PROFILES.md`. Profiles are parameterized benchmarks — not product limits.

## 9. Agency 20 Profile

Exactly 20 Customers; default 1 brand/asset; modest GSC/Ads rows. Purpose: portfolio control-plane + light data-plane.

## 10. Agency 100 Profile

Exactly 100 Customers (overridable). Purpose: larger portfolio query-count / scheduler scale — not a capacity ceiling.

## 11. High-Volume GSC Profile

High-cardinality query/page/date rows on one resource. Exercises SQL aggregation + top-N — not tiny 100-row fixtures labeled “high volume.”

## 12. High-Volume Google Ads Profile

Search-term / campaign / keyword cardinality on one account resource. Keyword and Search Term remain distinct.

## 13. Mixed Workload Profile

Foreground reads + synthetic collection/intelligence/report coexistence. No real providers/AI.

## 14. Benchmark Harness

`App\Support\Performance\*` + `php artisan moxdop:performance:benchmark`. Measures query count, duration_ms, memory delta, fixture volumes. Not a production dependency.

## 15. Database Table Inventory

See Table Scale Matrix (§282). Hot tables: `gsc_query_daily`, `gsc_page_daily`, `google_ads_search_term_daily`, `google_ads_campaign_daily`, Meta/GA4 dailies, `brand_context_activities`, execution ledgers.

## 16. Table Growth Classification

| Plane | Growth driver |
|---|---|
| CONTROL_PLANE | Customer × Brand × Asset × operator objects |
| DATA_PLANE | Resource × date × dimension cardinality × history × cadence |
| EXECUTION_LEDGER | Runs × occurrences × triggers × activity |
| ARTIFACT_METADATA | Collections × reports × files (bodies in object storage) |

## 17. Query Plan Audit

Representative aggregation SQL verified via PHPUnit on sqlite. Full Postgres `EXPLAIN (ANALYZE, BUFFERS)` on large partitions: **NOT_MEASURED** in this default environment. When run on staging PG, record planning/execution time, rows, scans, sorts, buffers.

## 18. Index Audit

Existing: NK unique + `(digital_asset_id, reporting_date)` on daily facts. Added: `(digital_asset_id, external_resource_id, reporting_date)` on hot tables; Activity `(brand_id|customer_id, occurred_at)`.

## 19. Composite Index Strategy

Equality on asset + resource, then date range — matches specialist read predicates. Order chosen from actual WHERE shapes, not cargo-cult.

## 20. Redundant Indexes

Candidates may exist between NK unique and asset_date indexes overlapping prefixes — **document only**; do not drop production indexes without operational evidence.

## 21. N+1 Audit

Fixed: Finding, Task, Recommendation, Brand responsibleUsers, GSC topQueries/devices detail loops. Customer scalar list: no N+1. Report list: no Snapshot body.

## 22. Query Count Budgets

See QUERY_PERFORMANCE_CONTRACT. Regression tests in `tests/Feature/Performance/QueryCountRegressionTest.php`.

## 23. Pagination

Clamped on Task, Report, Finding evaluations, Opportunity evaluations, BusinessOutcome observations, Activity limits. Filament uses bounded page size options.

## 24. Large COUNT Queries

Avoid exact totals when UI unused. Do not substitute PG estimates as exact. Report list still uses length-aware paginate (exact total required by Filament/list UX) on modest control-plane cardinality.

## 25. Projection / Select-Column Strategy

Report list selects metadata columns only. Agent/AI history must not deserialize full structured outputs on list (audit: no AgentRun Filament resource loading bodies).

## 26. GSC Scale

Property KPIs from property daily; queries/pages aggregated in SQL; top-N limited; position weighted in PHP only for returned top-N detail rows; semantics preserved.

## 27. Google Ads Scale

Campaign / search term / keyword aggregates in SQL with limits; currency/timezone preserved; Search Term ≠ Keyword.

## 28. Meta Scale

Same audit pattern; do not incorrectly preaggregate non-additive reach/frequency.

## 29. GA4 Scale

Event / key-event semantics preserved; SQL aggregates preferred.

## 30. GBP Scale

Location identity explicit; not partitioned.

## 31. Collection Write Throughput

PostgresWarehouseWriter: chunked insert/upsert; partition ensure per range; batch size config.

## 32. Batch Upserts

`array_chunk` + `ON CONFLICT` (PG) / Eloquent upsert. countExisting uses one OR-query per chunk.

## 33. Transaction Size

Bounded by batch size — not multi-million-row single transactions.

## 34. Write Amplification

Additional indexes increase upsert cost — accepted for measured read paths only.

## 35. Raw Payload Storage

Prompt 10: private object storage; DB holds provenance/metadata only.

## 36. Queue Workload Classification

See QUEUE_CAPACITY_CONTRACT. Classes: default vs collection.

## 37. Queue Throughput

**NOT_MEASURED** sustained (sync queue / no Redis CLI). Smoke validates config timeouts and bounded queue names.

## 38. Queue Starvation

Documented rules; Horizon separates collection vs default. Further fairness only if measured.

## 39. Horizon

Supervisors env-configurable; default timeout 300; collection memory 256.

## 40. Worker Capacity

Do not hard-code production worker counts from developer machines. Env vars: `HORIZON_*_MAX_PROCESSES`.

## 41. Fairness

One large Customer must not monopolize forever — implement smallest control only if measured.

## 42. Scheduler Due Queries

Must not scan full occurrence history; index active + scheduled_for (Prompt 61–63). Idempotency preserved.

## 43. Intelligence Scheduling Scale

Dependency index — do not scan every Skill/Rule on Evidence change. No Agent swarm.

## 44. Agent / Retrieval Scale

Prompt 54 bounds; paginated history; no full memory dump.

## 45. Dashboard

Aggregates + bounded recent activity — not every Customer nested graph.

## 46. Customers / Brands

Query count stable with eager/withCount patterns; directories use withCount.

## 47. Activity

Append-heavy; SQL bound; brand/customer+occurred indexes; pagination/limit.

## 48. Client Value Story

Live composition capped; cache ≠ Snapshot; no forever cache without generation awareness.

## 49. Report Snapshot

List metadata; detail one Snapshot; creation uses bounded read services.

## 50. PDF / Delivery

Snapshot-only PDF; one artifact reused across recipients; delivery history paginated.

## 51. Cache Audit

Existing: OAuth state hash, GA4 property context, DataForSEO directory, locks. No new cross-Brand analytical Redis cache in Prompt 65.

## 52. Cache Security

Tenant/object scope; no plaintext credentials; Prompt 64 intact.

## 53. Read Projections

None introduced as second truth. Formula Registry remains authoritative.

## 54. Partition Evaluation

Evaluated against criteria in DATA_SCALE_PARTITIONING_POLICY.

## 55. Partition Decision

**DEFER** further partitioning. Data-plane RANGE_MONTHLY **ALREADY_IMPLEMENTED**. Customer partitions **REJECT**.

## 56. Partition Migration Safety

N/A for new partitions. Existing PartitionManager remains source of truth for PG facts.

## 57. BigQuery / External Warehouse Decision

**NO** — not introduced. WarehouseWriter is future boundary.

## 58. DB Connection Pressure

Documented; PgBouncer not added. Observed peak under Horizon farm: NOT_MEASURED.

## 59. Lock Contention

Narrow locks (resource/dataset/credential/occurrence). No global MoxDOP lock. Measured waits: NOT_MEASURED.

## 60. Database Maintenance

High-churn upsert tables → Prompt 66 monitors bloat/autovacuum. No blind tuning.

## 61. HTTP Payloads

Server filter/sort/paginate; no wholesale provider tables to browser.

## 62. Chart Payloads

Aggregate grain only; do not change summary totals via downsampling without documenting sampling.

## 63. Object Storage

DB metadata discovery; stream large objects; no full-bucket listing.

## 64. Search / Autocomplete

Tenant-scoped; do not load all Search Terms into dropdowns.

## 65. JSON / JSONB

No GIN-every-JSON. Snapshot/AI JSON not loaded on lists.

## 66. Retention / Growth

Performance Prompt does not invent deletion policy. Document retention owners for Prompt 66.

## 67. Security Regression

Tenant filters retained; no cross-Brand cache; no credential cache; queue priority ≠ permission bypass.

## 68. Performance Findings

| Finding | Type | Severity | Decision |
|---|---|---|---|
| Activity unbounded SQL | UNBOUNDED_QUERY | MATERIAL | Implemented |
| Warehouse per-row EXISTS | WRITE_AMPLIFICATION | MATERIAL | Implemented |
| GSC detail N+1 | N_PLUS_ONE | MATERIAL | Implemented |
| Filament list N+1 | N_PLUS_ONE | MATERIAL | Implemented |
| Missing resource+date indexes | INDEX | MATERIAL | Implemented |
| Horizon timeout mismatch | QUEUE_STARVATION | MINOR | Implemented |
| Unclamped per_page | PAYLOAD | MINOR | Implemented |
| Sustained queue p95 | QUEUE | OBSERVATION | NOT_MEASURED / deferred |
| PG EXPLAIN large partitions | QUERY | OBSERVATION | NOT_MEASURED / deferred tooling |
| Further partitioning | OTHER | OBSERVATION | DEFER |
| BigQuery/replicas | OTHER | OBSERVATION | REJECT for now |

## 69. Implemented Fixes

Code + migration + harness + contracts + performance tests listed in git diff.

## 70. Deferred Scale Work

- Sustained Horizon throughput benchmarks on Redis
- Postgres EXPLAIN ANALYZE suite on staging volumes
- Optional simplePaginate where UX allows
- Fairness controls if starvation measured
- PgBouncer / read replicas only with evidence
- External warehouse only with evidence

## 71. Tests

`tests/Feature/Performance/*` group `performance`.

## 72. Reality Matrix

| Capability | Reality |
|---|---|
| Performance Audit | REAL |
| Benchmark Profiles | REAL |
| Query Plan Audit | PARTIAL (sqlite path; PG EXPLAIN NOT_MEASURED) |
| Index Audit | REAL |
| N+1 Audit | REAL |
| Pagination Audit | REAL |
| GSC Scale | REAL (smoke volumes) |
| Google Ads Scale | REAL (smoke volumes) |
| Collection Write Scale | REAL (code+config audit; timed rows/sec NOT_MEASURED at 1M) |
| Queue Throughput | NOT_MEASURED |
| Queue Isolation | REAL (config + contract) |
| Scheduler Scale | DOCUMENTED / regression via existing Prompt61–63 tests |
| Intelligence Scale | DOCUMENTED |
| Report Scale | REAL |
| Cache Audit | REAL |
| Partition Evaluation | REAL |
| Partition Implementation | DEFER further; ALREADY RANGE_MONTHLY data-plane |
| External Warehouse | NOT REQUIRED |
| Security Regression | REAL tests |
| Semantic Regression | REAL (no semantic shortcuts) |
| Fake Benchmarks | NONE |

## 73. Prompt66 Handoff

| Capability | Prompt65 | Prompt66 |
|---|---|---|
| Benchmarking harness | Owns | Consume baselines |
| Slow-query identification | Local EXPLAIN guidance | Production slow-query alerts |
| Queue throughput test | Contract + NOT_MEASURED | Production queue lag alerts |
| DB saturation alert | Documented | Own |
| Provider error monitoring | Out of scope | Own |
| Horizon alerting | Config audit | Own |
| Log aggregation | Out of scope | Own |
| Health dashboards | Out of scope | Own |
| Incident diagnostics | Out of scope | Own |

## 74. Definition of Done

See Prompt 65 §372 checklist — PASS requires measured-first posture, profiles, docs, safe fixes, no fake numbers, partition decision evidence-based, Prompt64 intact, no Prompt66 implementation.

---

## Mandatory Matrices

### Table Scale Matrix

| Table | Plane | Growth | Main writes | Main reads | Index | Partition candidate? | Decision |
|---|---|---|---|---|---|---|---|
| customers | CONTROL | slow | CRUD | list/detail | PK | NO | NOT_REQUIRED |
| brands | CONTROL | slow | CRUD | list/detail | FK | NO | NOT_REQUIRED |
| findings/tasks | CONTROL | moderate | domain writes | Filament lists | FK+status | NO | NOT_REQUIRED |
| gsc_query_daily | DATA | fast | Warehouse upsert | specialist aggregates | NK+asset_date+asset_resource_date | YES (time) | ALREADY RANGE_MONTHLY |
| google_ads_search_term_daily | DATA | fast | Warehouse upsert | topSearchTerms | NK+indexes | YES | ALREADY RANGE_MONTHLY |
| brand_context_activities | EXEC | fast | append | feed | brand/customer+occurred | NO (now) | INDEXED |
| report_snapshots | ARTIFACT_META | moderate | create | list metadata/detail | customer/brand | NO | NOT_REQUIRED |
| collection_runs | EXEC | fast | append | monitor | status/time | NO | NOT_REQUIRED |

### Critical Query Matrix

| Surface | Path | Scope | N+1? | Decision |
|---|---|---|---|---|
| Customers | Customer::list scalars | — | NO | OK |
| Brands | with+withCount / responsibleUsers | customer | fixed | Eager |
| Findings | FindingResource query | asset | fixed | Eager |
| Work | TaskResource query | brand/asset/assignee | fixed | Eager |
| GSC | GscPoolReadRepository | asset+resource+date | fixed | Batch detail |
| Ads | GoogleAdsPoolReadRepository | asset+resource+date | OK | SQL limit |
| Reports | ReportSnapshotReadService::list | customer/brand | NO body | Metadata select |
| Activity | ActivityReadService | brand/customer | OK | SQL limit |

### Index Matrix

| Table | Index | Supports | Redundant? | Decision |
|---|---|---|---|---|
| gsc_query_daily | NK unique | upsert | NO | KEEP |
| gsc_query_daily | asset_date | range | NO | KEEP |
| gsc_query_daily | asset_resource_date | specialist | NO | ADDED |
| google_ads_search_term_daily | asset_resource_date | specialist | NO | ADDED |
| brand_context_activities | brand/customer+occurred | feed | NO | ADDED |

### N+1 Matrix

| Surface | 10 vs 100 | N+1? | Fix | Test |
|---|---|---|---|---|
| Customers | stable | NO | scalar select | QueryCountRegressionTest |
| Brands | stable withCount | NO | with+withCount | QueryCountRegressionTest |
| Work | stable | NO | eager | QueryCountRegressionTest |
| Findings | bounded | NO | eager | QueryCountRegressionTest |

### Pagination Matrix

| Surface | Method | Exact total? | Final | Reason |
|---|---|---|---|---|
| Tasks | paginate clamped | yes | paginate | UX |
| Reports | paginate clamped | yes | paginate | UX |
| Activity | limit/offset | no | bounded limit | feed |
| GSC top queries | limit | no | top-N | specialist |
| Ads search terms | limit | no | top-N | specialist |

### GSC Scale Matrix

| Query | Aggregation | Pagination | PHP full load? | Result |
|---|---|---|---|---|
| topQueries | SQL SUM + detail batch | limit | NO | PASS |
| devices | SQL + batch | limit | NO | PASS |
| sitemaps | SQL limit 500 | dedupe | NO | PASS |

### Google Ads Scale Matrix

| Query | Grain | Pagination | PHP full load? | Result |
|---|---|---|---|---|
| topSearchTerms | search_term | limit | NO | PASS |
| campaignPerformance | campaign_id | limit | NO | PASS |

### Collection Write Matrix

| Dataset | Mode | Batch | Row-by-row? | Decision |
|---|---|---|---|---|
| Normalized facts | UPSERT/APPEND | 500 | NO | KEEP |

### Queue Matrix

| Job class | Queue | Starvation risk | Decision |
|---|---|---|---|
| Collection/Backfill/Incremental/Repair | collection | mitigated by separation | KEEP |
| Intelligence / reports / default | default | documented | KEEP |

### Queue Profile Matrix

| Profile | Throughput | Notes |
|---|---|---|
| AGENCY_20/100 mixed | NOT_MEASURED | sync tests |

### Report Query Matrix

| Operation | Full body? | N+1? | Decision |
|---|---|---|---|
| Report list | NO | NO | OK |
| Snapshot detail | one | — | OK |
| PDF | Snapshot only | — | OK |

### Cache Matrix

| Cache | Cross-tenant safe? | Decision |
|---|---|---|
| OAuth state hash | yes (user checked) | KEEP |
| DataForSEO directory | global non-customer | KEEP |
| New Brand analytical Redis | — | NOT ADDED |

### Partition Matrix

| Table | Index sufficient @ bench? | Partition benefit measured? | Decision |
|---|---|---|---|
| Control plane | YES | NO | NOT_REQUIRED |
| Provider dailies | YES + existing | Already monthly | DEFER further |
| Customer LIST partitions | — | — | REJECT |

### Partition Decision Matrix

| Decision | Allowed | Meaning |
|---|---|---|
| IMPLEMENT | conditional | measured only |
| DEFER | yes | Prompt65 choice for further work |
| NOT_REQUIRED | yes | control plane |
| REJECT | yes | Customer partitions |

### Connection Matrix

| Workload | Observed peak | Pooler required? |
|---|---|---|
| Mixed | NOT_MEASURED | NO |

### Lock Matrix

| Operation | Scope | Global? |
|---|---|---|
| Collection upsert / occurrence / OAuth | narrow | NO |

### Payload Matrix

| Surface | Bounded? | Client full filter? |
|---|---|---|
| GSC/Ads/Charts/Reports list | YES | NO |

### Performance Finding Matrix

See §68.

### Scale Profile Matrix

See BENCHMARK_PROFILES.md.

### Prompt66 Handoff Matrix

See §73.
