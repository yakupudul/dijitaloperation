# Observability Limitations

> Prompt 66 — what MoxDOP **cannot** self-monitor (honest boundaries).  
> Related: [`OBSERVABILITY_OPERATIONS.md`](../implementation/OBSERVABILITY_OPERATIONS.md) · [`MOXDOP_OPERATIONS_RUNBOOK.md`](MOXDOP_OPERATIONS_RUNBOOK.md)

## Principle

If the application process is down, misconfigured, or partitioned from its dependencies, in-app observability is incomplete by definition. Prefer external probes for liveness of the app itself. Never invent green status or measured SLOs.

---

## Cannot self-monitor (or only partially)

| Area | Limitation | Operator expectation |
| --- | --- | --- |
| App process dead | No PHP means no snapshot/alerts | External uptime check on `/up/liveness` |
| Host cron absent | Scheduler dimension UNKNOWN until heartbeat; evaluate-alerts may never run | OS cron + schedule registration |
| Horizon/Redis outside app | Depth/metrics beyond `AsyncWorkerHealth` (esp. non-database queue driver) are limited | Horizon dashboard / Redis tooling |
| PostgreSQL autovacuum / bloat | Not measured in-app | DBA metrics / Prompt65 maintenance guidance |
| Slow queries p95 | **NOT_MEASURED** unless EXPLAIN/host tooling | PG slow log / APM outside app |
| Queue throughput p95 | **NOT_MEASURED** in typical Cloud image | Measure on real worker farm |
| Provider quota remaining % | Not invented from 429-only | Trust provider-reported fields or `RATE_LIMIT_SIGNAL_ONLY` |
| Google / OpenAI / DataForSEO HTTP counters | Clients not fully wired like Meta | Partial until instrumented |
| Cross-host disk / inode | Storage probe is default disk only | Host monitoring |
| Network path to providers | Seen as timeouts/network outcomes when recorded | Provider status pages |
| Secret leakage via third-party APM | Out of app control | Redaction + egress policy |
| Multi-region failover | Not a product feature | Infra design |
| Customer-facing public status | Not shipped | External status page if needed |
| Log aggregation / SIEM | App emits `ops.*` only | Ship logs externally |
| Autonomous remediation success | Intentionally absent | Humans + runbook |

---

## UNKNOWN is valid

| Situation | Correct status |
| --- | --- |
| No dispatcher heartbeat yet | Scheduler `UNKNOWN` |
| `MOXDOP_OPS_EXPECTED_SUPERVISORS` empty | Worker capacity not claimed; may be UNKNOWN/DEGRADED heuristic |
| Redis not used by default drivers | Redis `UNKNOWN` |
| Provider counters empty | Do not alert on tiny samples; rates may be null |

---

## Forbidden compensations

- Averaging dimensions into a health score  
- Marking systems HEALTHY because “last deploy succeeded”  
- Fabricating quota % from 429 counts  
- Notify-all users when recipient list is empty  
- Spamming brand Activity with platform ops alerts  
- Claiming Prometheus/Grafana coverage that was not added  

---

## Prompt67+ expectations

External log shipping, remaining provider client instrumentation, production schedule wiring for `evaluate-alerts`, and host/DBA monitors for bloat/slow-query remain **outside** what Prompt 66 claims as complete self-observability.
