# Operational Observability Contract

> Prompt 66 — categorical health dimensions, heartbeats, cheap probes, honest telemetry.  
> Implementation: `OperationalHealthSnapshot`, `WorkerHeartbeatService`, `OperationalTelemetryRecorder`, `OpsHealthController`, `SystemStatusWidget`  
> Config: `config/moxdop-observability.php`  
> Related: [`OBSERVABILITY_OPERATIONS.md`](../implementation/OBSERVABILITY_OPERATIONS.md) · [`OPERATIONAL_ALERT_CONTRACT.md`](OPERATIONAL_ALERT_CONTRACT.md) · [`PROVIDER_API_TELEMETRY_CONTRACT.md`](PROVIDER_API_TELEMETRY_CONTRACT.md) · [`QUEUE_CAPACITY_CONTRACT.md`](QUEUE_CAPACITY_CONTRACT.md)

## Canonical rule

Operational health is a set of **explicit dimensions** (`HEALTHY` | `DEGRADED` | `UNHEALTHY` | `UNKNOWN`). There is **no** overall numeric health score, no `ObservabilityV2`, and no autonomous remediation. Missing evidence yields `UNKNOWN` — never invent green.

---

## Dimensions (required set)

| Dimension | Primary evidence | Failure modes |
| --- | --- | --- |
| `application` | Process responds | — |
| `database` | `select 1` | `DB_UNAVAILABLE` |
| `redis` | Ping when queue/cache default is redis; else `UNKNOWN` | `REDIS_UNAVAILABLE` |
| `queue` | `AsyncWorkerHealth` pending + oldest age | Idle-with-backlog → DEGRADED |
| `worker` | Heartbeats vs `MOXDOP_OPS_EXPECTED_SUPERVISORS` | Missing expected → DEGRADED/UNHEALTHY; empty expected → UNKNOWN/heuristic |
| `scheduler` | `ops_dispatcher_heartbeats.dispatcher_key=recurring` | No row → UNKNOWN; stale → UNHEALTHY |
| `collection` | Running / failed hour / stuck candidates | Stuck or ≥3 failures/hour → DEGRADED |
| `storage` | Default disk put/delete probe | `STORAGE_UNAVAILABLE` |

Snapshot also lists open/acknowledged `OperationalAlert` rows (bounded) and **must** set `overall_score: null`.

---

## HTTP probes

| Endpoint | Auth | Cost | Returns |
| --- | --- | --- | --- |
| `/up/liveness` | none | trivial | process alive |
| `/up/readiness` | none | DB ping | ready / 503 |
| `/ops/health-snapshot` | authenticated web | bounded DB reads | dimensions + alerts |

Forbidden on probes: credentials, tokens, tenant dumps, provider polling, raw payloads.

---

## Heartbeats

| Kind | Table | Writer |
| --- | --- | --- |
| Worker | `worker_heartbeats` | `moxdop:ops:worker-heartbeat` / `WorkerHeartbeatService::beat` |
| Dispatcher | `ops_dispatcher_heartbeats` | `WorkerHeartbeatService::beatDispatcher` (also called from `moxdop:ops:evaluate-alerts`) |

Expected supervisors are **deployment configuration**. Empty list must not pretend production capacity is known.

---

## Structured logging

`OperationalTelemetryRecorder` emits `ops.{event}` with:

1. `OperationalContext` allowlist keys  
2. `SecurityRedactor` on remaining context  

Logging failure must not fail the business operation.

---

## Reuse

- Queue: `AsyncWorkerHealth` (Prompt async / Activity Center)  
- Collection: `CollectionRun` + `StuckCollectionDetector`  
- Freshness: Prompt27 `DueCollectionQueryService` (alerts; not a fake freshness score)  
- Capacity classes: Prompt65 `QUEUE_CAPACITY_CONTRACT` / Horizon  

---

## Explicit non-goals

Prometheus/Grafana/OTLP exporters, health-score tables, auto-heal bots, invented p95 SLAs (`NOT_MEASURED` when unmeasured), customer-facing public status product.
