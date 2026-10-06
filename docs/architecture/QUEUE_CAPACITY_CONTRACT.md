# Queue Capacity Contract (Prompt 65)

## Workload classes (bounded)

MoxDOP uses a small set of queues — not dozens of names:

| Class | Queue (current) | Examples |
|---|---|---|
| INTERACTIVE / NORMAL | `default` | Notifications, intelligence plan execution (config), report jobs sharing default |
| BACKGROUND_HEAVY / COLLECTION | `collection` | Dataset runs, backfill, incremental, repair materialization |

Horizon supervisors (`config/horizon.php`):

- `supervisor-1` → `default` — timeout `HORIZON_DEFAULT_TIMEOUT` (960), maxProcesses env-configurable. It auto-scales, and a scale-down SIGKILLs a busy worker after this timeout, so it must outlast every job that lands on `default` (longest 900 s) and stay below the redis `retry_after` (1800) — `QueueTopologyContractTest`. The 1500 s / 870 s / 600 s analysis jobs (live verification, data consistency, scheduled discovery, source erase, Meta geo, channel analyst) are routed to `heavy` by `AppServiceProvider::routeHeavyJobs` (a `class => queue` map; `Queue::route()` ignores its second argument for a list)
- `supervisor-collection` → `collection` — timeout `HORIZON_COLLECTION_TIMEOUT` (300), maxProcesses env-configurable

Do not rename queues without need. Do not create 30 queue types.

## Isolation & starvation

1. Heavy historical **Backfill** must not indefinitely starve normal **Incremental** collection or interactive work.
2. **Automatic AI** must not indefinitely starve **manual** Agent runs (same or sibling queues — prefer priority by queue separation when measured).
3. Report delivery must not remain blocked forever behind unrelated Backfill.
4. One large Customer must not monopolize every heavy slot indefinitely (bounded per-resource dispatch when starvation is measured).

## Provider concurrency

Increasing workers never bypasses provider rate limits or paid-call gates (DataForSEO, Ads, Meta, GA4, GSC).

## Worker capacity & DB connections

Worker recommendations must account for PostgreSQL connection capacity:

`HTTP workers + queue workers + scheduler + AI jobs ≤ realistic DB max_connections` (with headroom).

PgBouncer is **not** added without measured connection pressure.

## Backpressure & retry

- Collection jobs: tries/backoff per Horizon + job config
- Idempotent dataset writes via natural keys / WarehouseWriter
- Repair remains non-destructive (Prompt 62)

## Benchmark expectation

Queue throughput p50/p95 wait must record environment. If Horizon/Redis worker farm is unavailable in the measurement environment, report **NOT_MEASURED** — never invent production throughput.
