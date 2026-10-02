# Data Scale Partitioning Policy (Prompt 65)

## Decision summary

| Decision | Allowed? | Meaning |
|---|---|---|
| IMPLEMENT | Conditional | Only after measured need + safe migration plan |
| DEFER | Yes | Correct when indexed non-partitioned (or existing) design is healthy |
| NOT_REQUIRED | Yes | Control-plane / low-growth tables |
| REJECT | Yes | Customer-based partitions, DB-per-Customer, schema-per-Customer |

**Prompt 65 outcome:** further partitioning **DEFER**; data-plane **RANGE_MONTHLY** already implemented for provider daily facts via `PartitionManager`; **REJECT** Customer-based partitions.

## Candidate criteria (ALL required)

A table may become a partition candidate only if:

1. Materially large or forecast large
2. Mostly time-series / append-heavy
3. Common queries constrain a viable partition key
4. EXPLAIN shows meaningful pruning
5. Indexing alone is insufficient OR retention/maintenance strongly benefits
6. Unique / natural-key constraints remain correct
7. Migration can be done safely
8. ORM / WarehouseWriter behavior remains stable

## Non-candidates

Do **not** partition merely because Customer count grows:

- Customer, Brand, DigitalAsset, Finding, Opportunity, Task, Goal, Offering, Service Scope

## Allowed partition keys

- Provider fact tables: `reporting_date` RANGE (monthly granularity already used)
- Never: one partition per Customer (causes partition explosion)

## Existing implementation

`PartitionManager` + storage contract: `RANGE_MONTHLY` on GA4 / GSC / Google Ads / Meta daily fact tables. SQLite uses non-partitioned equivalents.

## Measurement requirement

Partitioning is not mandatory because this Prompt mentions it. Measure query plans, rows examined, and write amplification first.

## Migration safety (if IMPLEMENT later)

Preserve IDs, natural keys, FK semantics, WarehouseWriter behavior, integrity, freshness, tenant isolation. Validate key coverage and representative aggregates — not row counts alone. No big-bang rewrite without rollout plan.

## Defer criteria

If indexed non-partitioned PostgreSQL (plus existing RANGE_MONTHLY facts) remains healthy under AGENCY_20 / AGENCY_100 / high-volume GSC/Ads profiles → **DEFER** is a PASS.

## External warehouse escalation (future)

WarehouseWriter remains the boundary. Potential future triggers (no invented numeric thresholds):

- PostgreSQL storage growth
- Query latency despite correct indexing/partitioning
- Write throughput limits
- Analytical concurrency / retention cost

Prompt 65 does **not** introduce BigQuery, ClickHouse, Elasticsearch, read replicas, or PgBouncer without measured need.
