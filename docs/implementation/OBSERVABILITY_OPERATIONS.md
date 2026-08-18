# OBSERVABILITY & OPERATIONS

## STATUS: REAL (Prompt 66)

**Prompt:** 66  
**Canonical path:** `docs/implementation/OBSERVABILITY_OPERATIONS.md`  
**Contracts:** [`OPERATIONAL_OBSERVABILITY_CONTRACT.md`](../architecture/OPERATIONAL_OBSERVABILITY_CONTRACT.md) · [`OPERATIONAL_ALERT_CONTRACT.md`](../architecture/OPERATIONAL_ALERT_CONTRACT.md) · [`PROVIDER_API_TELEMETRY_CONTRACT.md`](../architecture/PROVIDER_API_TELEMETRY_CONTRACT.md)  
**Runbook / limits:** [`MOXDOP_OPERATIONS_RUNBOOK.md`](../operations/MOXDOP_OPERATIONS_RUNBOOK.md) · [`OBSERVABILITY_LIMITATIONS.md`](../operations/OBSERVABILITY_LIMITATIONS.md)  
**Depends on:** Prompt 65 Performance baselines · Prompt 64 Security/redaction · Prompt 47 Notifications · Prompt 27 DueCollectionQueryService · AsyncWorkerHealth · CollectionRun · Horizon  
**Branch:** `cursor/observability-operations-ea01`  
**Base HEAD:** Prompt 65 `204a9bb` (`docs: refresh Prompt65 AGENCY_20 baseline measurements`)

| Fact | Value |
| --- | --- |
| Config | `config/moxdop-observability.php` |
| Tables | `operational_alerts`, `worker_heartbeats`, `provider_api_counters`, `ops_dispatcher_heartbeats` |
| Health score | **NONE** (`overall_score` always `null`) |
| ObservabilityV2 / Prometheus / Grafana | **NONE** |
| Autonomous remediation | **NONE** |
| Provider HTTP telemetry wired | **Meta** (`MetaApiClient`) — Google/OpenAI/DataForSEO counters evaluated when present |
| Quota honesty | `RATE_LIMIT_SIGNAL_ONLY` / `NOT_EXPOSED` — no fake % |
| Notifications | `DomainEventType::OperationalAlertOpened` → preference `operation_failed`; **no** Activity spam |
| SystemStatusWidget | Real dimensions; `$isDiscovered = false`; no score |

---

## 1. Purpose

Prompt 66 gives MoxDOP durable, honest operational visibility: versioned alerts, provider API counters, worker/dispatcher heartbeats, cheap liveness/readiness, and a multi-dimension health snapshot — without inventing overall scores, fake quotas, ObservabilityV2, Prometheus/Grafana stacks, or autonomous remediation.

```text
Signals (queue / worker / collection / provider / credential / dataset / infra)
  → OperationalAlertEvaluator (deterministic rules)
    → OperationalAlertLifecycleService (semantic dedupe)
      → OperationalAlert (+ optional Prompt47 Notification)
  → OperationalHealthSnapshot dimensions (never averaged)
```

---

## 2. Scope

In scope:

- Configured thresholds + versioned alert rules
- Durable Operational Alert lifecycle (open / ack / resolve)
- Worker + dispatcher heartbeats
- Provider API attempt counters + rate summaries
- Stuck / repeated CollectionRun detection
- Dataset stale signals via Prompt27 `DueCollectionQueryService`
- Liveness / readiness / authenticated health snapshot
- Structured ops logging with Prompt64 redaction
- SystemStatusWidget real dimensions (undiscovered)
- PHPUnit coverage + architecture contracts + runbook

Out of scope:

- ObservabilityV2 / MonitoringV2 / AlertingV2 / SystemHealthScore
- Prometheus, Grafana, OpenTelemetry exporters, Datadog agents
- Autonomous remediation / auto-heal / auto-scale
- Invented p95/p50 SLAs or fake quota percentages
- New top-level Filament “Observability” nav product
- Changing Prompt27 freshness truth or Prompt65 benchmarks

---

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Prompt 65 performance / queue contracts; Prompt 64 redaction; Prompt 47 notifications |
| 3 | This implementation doc + three architecture contracts + runbook |
| 4 | `config/moxdop-observability.php` + env placeholders |
| 5 | Canonical domain state (`CollectionRun`, Prompt27 freshness, `CoreIntegration` auth status, Horizon/queue) |

---

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | No overall health score — dimensions stay explicit |
| R2 | No ObservabilityV2 / Prometheus/Grafana product add-ons in Prompt 66 |
| R3 | No autonomous remediation — alerts notify; humans act |
| R4 | Alerts are durable rows with semantic identity — not Finding Rules |
| R5 | Acknowledge ≠ resolve; ack never mutates queue/credential/dataset |
| R6 | Zero notification recipients → Alert stays OPEN; no notify-all |
| R7 | OperationalAlertOpened does **not** create brand Activity spam |
| R8 | Quota: `RATE_LIMIT_SIGNAL_ONLY` when only 429; `NOT_EXPOSED` when unknown; never invent % |
| R9 | Error/rate-limit rates use explicit denominator + minimum sample |
| R10 | Stuck detection is workload-aware (backfill ≠ incremental) |
| R11 | Dataset stale uses Prompt27 due query — not max(date) history scans |
| R12 | Telemetry/redaction failures must not break business paths |
| R13 | No secrets in logs, counters, observed JSON, or health endpoints |
| R14 | Expected worker supervisors are deployment env — never laptop defaults as production truth |
| R15 | Unmeasured production SLOs remain `NOT_MEASURED` |

---

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Base | Prompt 65 `204a9bb` |
| Branch | `cursor/observability-operations-ea01` |
| Config | `config/moxdop-observability.php` |
| Migration | `2026_08_16_080000_create_observability_operations_tables.php` |
| Tests | `tests/Feature/Observability/ObservabilityOperationsTest.php` |

---

## 6. Prompt 65 Boundary / Input Audit

| Prompt 65 topic | Prompt 66 stance |
| --- | --- |
| Benchmark harness / profiles | Consume baselines; do not re-benchmark as product truth |
| Queue throughput / wait p95 | Production lag alerts via age thresholds; p95 remains **NOT_MEASURED** unless measured |
| DB saturation | Readiness DB check + snapshot dimension; no autovacuum automation |
| Horizon config audit | Reuse Horizon supervisors; heartbeats + expected list |
| Provider error monitoring | Own via `ProviderApiTelemetryService` |
| Health dashboards | Bounded snapshot + undiscovered widget — not Grafana |
| Retention owners | Documented; no blind deletion |

---

## 7. Existing Observability Primitive Audit

| Primitive | Classification | Prompt 66 |
| --- | --- | --- |
| `AsyncWorkerHealth` | GOOD / CANONICAL queue idle heuristic | Reused by evaluator + snapshot |
| `CollectionRun` status / activity | GOOD / CANONICAL | Stuck + failure detectors |
| `DueCollectionQueryService` (P27) | GOOD / CANONICAL freshness | Dataset stale alerts |
| Horizon supervisors | GOOD / DEPLOYMENT | Expected supervisors via env |
| Filament SystemStatusWidget (pre) | PLACEHOLDER risk | Replaced with real dimensions |
| Prometheus / Grafana | ABSENT | Still absent |
| Health score tables | ABSENT | Still forbidden |

---

## 8. Existing Queue / Horizon Audit

| Signal | Source | Notes |
| --- | --- | --- |
| Pending jobs / oldest age | `AsyncWorkerHealth` on `database` driver | Redis/Horizon depth not fully mirrored when driver ≠ database |
| Workload classes | Prompt65 `QUEUE_CAPACITY_CONTRACT` | Interactive age vs background age config keys |
| Horizon timeouts | Config (300s) | Unchanged by Prompt 66 |
| Worker expected capacity | `MOXDOP_OPS_EXPECTED_SUPERVISORS` | Empty → UNKNOWN / degraded heuristic only |

---

## 9. Existing Collection Monitoring Audit

| Signal | Source |
| --- | --- |
| Running / failed runs | `CollectionRun` |
| Stuck candidates | `StuckCollectionDetector` (workload thresholds) |
| Repeated failures | Failed `finished_at` window + min count |
| Live monitoring UX | Prior collection monitoring docs — alerts are additive |

---

## 10. Existing Activity / Notification Audit

| Path | Prompt 66 |
| --- | --- |
| Domain events → Activity | `OperationalAlertOpened` → `shouldCreateActivity = false` |
| Domain events → Notification | Kind `operational_alert_opened`; preference `operation_failed` |
| Recipients | Explicit user IDs or Admin role; empty = no emit |

---

## 11. Existing Performance Baseline Intake

| Baseline | Status |
| --- | --- |
| Agency 20 / 100 profiles | Documented under Prompt 65 |
| Queue throughput p50/p95 | **NOT_MEASURED** in this Cloud image (no sustained Horizon farm claim) |
| Provider latency p95 | **NOT_MEASURED** — counters store `latency_sum_ms` average only |
| Prompt 66 does not invent measured numbers | Affirmed |

---

## 12. Frozen Product Surface Audit

| Surface | Decision |
| --- | --- |
| New top-level Observability nav | **NOT ADDED** |
| SystemStatusWidget on main Dashboard | Undiscovered (`$isDiscovered = false`) |
| `/ops/health-snapshot` | Auth required; internal diagnostic |
| `/up/liveness`, `/up/readiness` | Public cheap checks; no tenant/secrets |

---

## 13. Canonical Architecture Decision

Operational truth is **categorical dimensions + durable alerts**, not a single score. Provider telemetry is **aggregated counters**. Heartbeats prove process liveness for configured supervisors. Freshness/stuck/failure reuse canonical Collection and Prompt27 services. Notifications reuse Prompt47 projection with anti-spam policy.

---

## 14. No ObservabilityV2 / No Prometheus Stack

| Forbidden class / stack | Status |
| --- | --- |
| `ObservabilityV2` / `MonitoringV2` / `AlertingV2` | Absent (test-locked) |
| `SystemHealthScore` | Absent |
| Prometheus / Grafana / OTLP exporters | Not added |

---

## 15. No Health Score / No Autonomous Remediation

`OperationalHealthSnapshot::snapshot()` always returns `'overall_score' => null`. Evaluator opens/resolves alerts only — never restarts workers, rotates keys, purges queues, or mutates credentials.

---

## 16. Signal Families

Enum `OperationalSignalFamily`: `COLLECTION`, `DATASET`, `QUEUE`, `WORKER`, `SCHEDULER`, `PROVIDER_API`, `CREDENTIAL`, `REPORTING`, `INTELLIGENCE`, `AI_PROVIDER`, `DATABASE`, `STORAGE`.

Enabled rules in config currently cover QUEUE, WORKER, COLLECTION, PROVIDER_API, CREDENTIAL, DATASET.

---

## 17. Health Status Taxonomy

`OperationalHealthStatus`: `HEALTHY` | `DEGRADED` | `UNHEALTHY` | `UNKNOWN`.  
Never mapped to a numeric average.

---

## 18. Operational Context Allowlist

`App\Support\Observability\OperationalContext` allowlists diagnostic keys only (`correlation_id`, ids, `provider`, `operation`, `status`, `error_code`, `duration_ms`, …). No credentials, Authorization headers, raw bodies, or free-text PII.

---

## 19. Structured Telemetry Recorder

`OperationalTelemetryRecorder` writes `ops.{event}` via Laravel Log after `SecurityRedactor`. Failures are swallowed so business operations continue.

---

## 20. Security Redaction Boundary

Alert `observed` JSON and provider telemetry contexts pass through Prompt64 `SecurityRedactor`. Health endpoints never return credentials. Counter tables have no secret columns.

---

## 21. Config and Environment

Primary: `config/moxdop-observability.php`.  
Env placeholders in `.env.example` (no secrets): `MOXDOP_OBSERVABILITY_ENABLED`, worker stale/expected supervisors, queue interactive age, alert notify flags, recipient user IDs.

Rules array is versioned (`key` + `version`) with deterministic `type` — no SQL/PHP expressions.

---

## 22. Migration / Tables

Migration `2026_08_16_080000_create_observability_operations_tables.php` creates:

| Table | Role |
| --- | --- |
| `operational_alerts` | Durable alerts + lifecycle |
| `worker_heartbeats` | Worker last_seen |
| `provider_api_counters` | Windowed outcome counters |
| `ops_dispatcher_heartbeats` | Scheduler/dispatcher pulse |

No health-score tables. No EAV metrics megatable.

---

## 23. OperationalAlert Model

`App\Models\Observability\OperationalAlert` — semantic_key unique; rule metadata; severity/state enums; scope; observed JSON; observation_count; ack/resolve fields; `notification_emitted`.

Not a Finding. Not a Notification row.

---

## 24. WorkerHeartbeat Model

`WorkerHeartbeat` — unique `worker_id`; optional supervisor / queue_class / hostname / pid; `last_seen_at`; metadata JSON (non-secret).

---

## 25. ProviderApiCounter Model

`ProviderApiCounter` — unique `(provider, operation, window_started_at)`; attempts/successes/auth/rate_limit/4xx/5xx/timeout/network; `latency_sum_ms` (for average, not p95).

---

## 26. OpsDispatcherHeartbeat Model

`OpsDispatcherHeartbeat` — unique `dispatcher_key` (e.g. `recurring`); `last_seen_at`. Snapshot marks UNKNOWN until first beat.

---

## 27. Alert Severity / State / Rule Type Enums

| Enum | Values (implemented) |
| --- | --- |
| Severity | INFO, WARNING, CRITICAL |
| State | OPEN, ACKNOWLEDGED, RESOLVED, SUPPRESSED |
| RuleType | Includes evaluated types + reserved (`SCHEDULER_*`, `PROVIDER_QUOTA_LOW`, `REPORT_DELIVERY_FAILURE`, `AI_PROVIDER_FAILURE_RATE`, `DATASET_BLOCKED`) |

Reserved types exist for future rules; config `rules` enables a bounded subset today.

---

## 28. Provider Request Outcome

`ProviderRequestOutcome`: SUCCESS, AUTH, RATE_LIMIT, PROVIDER_4XX, PROVIDER_5XX, TIMEOUT, NETWORK, APPLICATION, UNKNOWN.  
HTTP classifier in `ProviderApiTelemetryService::classifyHttpStatus`.

---

## 29. Provider Quota Visibility

| Value | Meaning |
| --- | --- |
| `PROVIDER_REPORTED_USAGE_AND_LIMIT` | Limit + remaining known |
| `PROVIDER_REPORTED_REMAINING` | Remaining only |
| `PROVIDER_REPORTED_RESET` | Reset only |
| `RATE_LIMIT_SIGNAL_ONLY` | Only 429 (or equivalent) observed |
| `NOT_EXPOSED` | Provider did not expose quota |
| `UNKNOWN` | Unclassified |

Never invent quota percentages from 429-only signals.

---

## 30. OperationalAlertLifecycleService

`observeCondition` → open or update active alert; `resolveIfActive`; `acknowledge`. Redacts observed; notifies on first open; logs via telemetry recorder.

---

## 31. Semantic Identity / Deduplication

`semanticKey = sha256(ruleKey|scopeType|scopeKey)` truncated to 64 chars. Active OPEN/ACKNOWLEDGED rows update `observation_count` instead of duplicating.

---

## 32. Acknowledge vs Resolve

Acknowledge sets state ACKNOWLEDGED + actor/note; does **not** set `resolved_at`. Resolve sets RESOLVED + `resolution_kind` (`RECOVERED` | `CLOSED_BY_OPERATOR`). Ack never clears queue backlog or credentials.

---

## 33. OperationalAlertNotifier

Emits at most one Domain Event per open (`notification_emitted`). `notify_on_resolve` defaults **false**. Recipient failure does not roll back alert persistence.

---

## 34. DomainEvent OperationalAlertOpened

`DomainEventType::OperationalAlertOpened = OPERATIONAL_ALERT_OPENED`  
Category: `operations`  
Preference key: `operation_failed`  
Subject kind: `operational_alert`

---

## 35. Prompt47 Notification Wiring

`NotificationPolicyRegistry` maps kind `OperationalAlertOpened`.  
`NotificationRecipientResolver` uses payload `recipient_user_ids`.  
`NotificationProjector` labels subject as operational alert title/fallback.

---

## 36. No Activity Spam Policy

`shouldCreateActivity(OperationalAlertOpened) === false` — platform ops alerts must not flood brand Activity feeds.

---

## 37. OperationalAlertEvaluator

Deterministic (no AI). Evaluates: queue backlog, worker health, stuck collections, repeated failures, provider rates, credentials reconnect, stale datasets. Invoked by `moxdop:ops:evaluate-alerts`.

---

## 38. Versioned Rules Registry

Config `rules[]` entries: `key`, `version`, `type`, `enabled`, `severity`, `signal_family`, optional hold/min/window, `recovery` label. Thresholds come from sibling config keys / env.

---

## 39. Queue Backlog Rule

`queue_interactive_backlog` — `AsyncWorkerHealth` oldest age ≥ interactive threshold + hold, with pending jobs > 0. Scope `queue:default`.

---

## 40. Worker Unavailable Rule

`worker_heartbeat_missing` — only when `expected_supervisors` configured and snapshot status `UNHEALTHY`. CRITICAL. Empty expected list does not open this alert.

---

## 41. Stuck Collection Rule

`collection_stuck` — any `StuckCollectionDetector` candidates. WARNING. Scope `collection:stuck`.

---

## 42. Repeated Collection Failure Rule

`collection_repeated_failure` — default ≥3 failed CollectionRuns in 3600s (config overrides). WARNING.

---

## 43. Provider Rate Limit Rule

`provider_rate_limited` — per provider (`google`, `meta`, `openai`, `dataforseo`) operation `http`; min attempts + rate_limit_rate threshold. WARNING.

---

## 44. Provider Error Rate Rule

`provider_error_rate` — numerator = auth + 5xx + timeout + network (**excludes** pure rate_limits and ordinary 4xx). Min attempts + error_rate threshold. WARNING.

---

## 45. Credential Reconnect Rule

`credential_reconnect_required` — `CoreIntegration` Google/Meta config auth_status in `{RECONNECT_REQUIRED, REFRESH_REQUIRED, REVOKED, EXPIRED}`. CRITICAL. Scope `integration:{id}`.

---

## 46. Dataset Stale Rule

`dataset_stale` — Prompt27 due items in `{Stale, IntegrityBlocked, ActionRequired}`. Excludes treating provider-limited-only as system failure in comment policy. WARNING. Hold seconds config present for operators.

---

## 47. StuckCollectionDetector

Workload thresholds: Incremental / default / InitialBackfill|Replay. Progress clock = `last_activity_at ?? started_at`. Caps candidate scan at 200 running runs.

---

## 48. WorkerHeartbeatService

`beat`, `beatDispatcher`, `snapshot`. Expected supervisors from config; otherwise UNKNOWN/DEGRADED via queue idle heuristic + optional fresh heartbeats.

---

## 49. ProviderApiTelemetryService

`recordAttempt` (5-minute buckets), `rateSummary`, `classifyHttpStatus`, `classifyQuotaVisibility`. Non-critical; never throws out of provider path.

---

## 50. MetaApiClient Telemetry Wiring

`MetaApiClient` records attempts with outcome + duration + integration_id. On 429 → `RATE_LIMIT_SIGNAL_ONLY`; otherwise default `NOT_EXPOSED` when no quota headers modeled. Google/OpenAI/DataForSEO HTTP clients are **not** yet instrumented the same way (evaluator still reads counters if written).

---

## 51. OperationalHealthSnapshot Dimensions

Dimensions: `application`, `database`, `redis`, `queue`, `worker`, `scheduler`, `collection`, `storage` + open alerts list (limit 25). `overall_score` always null.

---

## 52. Liveness / Readiness / Health Snapshot Routes

| Route | Auth | Behavior |
| --- | --- | --- |
| `GET /up/liveness` | none | `{status:HEALTHY, check:liveness}` |
| `GET /up/readiness` | none | DB `select 1`; 503 if unhealthy; no credentials |
| `GET /ops/health-snapshot` | `web`+`auth` | Full snapshot JSON |

Controller: `OpsHealthController`.

---

## 53. SystemStatusWidget

Filament widget uses real snapshot dimensions; title “MoxDOP Operations”; undiscovered on Dashboard; never shows numeric health score or hard-coded “All Systems Operational”.

---

## 54. Artisan Commands

| Command | Role |
| --- | --- |
| `moxdop:ops:evaluate-alerts` | Evaluate rules; beats dispatcher `recurring`; optional `--snapshot` |
| `moxdop:ops:worker-heartbeat` | Record worker heartbeat |
| `moxdop:ops:test-alert` | Open clearly marked TEST alert |

**Schedule:** `routes/console.php` registers `moxdop:ops:evaluate-alerts` every five minutes (alongside async stale-run and delivery dispatchers). Dispatcher heartbeat freshness is updated by that command via `beatDispatcher('recurring')`.

---

## 55. Reuse Matrix (P27 / Async / Horizon)

| Dependency | Reuse |
| --- | --- |
| `AsyncWorkerHealth` | Queue backlog + queue dimension |
| `CollectionRun` | Stuck + failures + collection dimension |
| `DueCollectionQueryService` | Dataset stale |
| Horizon / supervisors | Expected capacity env; capacity contract from Prompt 65 |
| `SecurityRedactor` | Logs + observed |
| Prompt47 DomainEventEmitter | Alert notifications |

---

## 56. Partition / Quota Honesty

| Case | Visibility |
| --- | --- |
| Only 429 / rate-limit outcome | `RATE_LIMIT_SIGNAL_ONLY` |
| No quota fields | `NOT_EXPOSED` |
| Provider reports limit+remaining | `PROVIDER_REPORTED_USAGE_AND_LIMIT` |

No fabricated remaining%. Partition/DB bloat monitoring remains operator/Postgres-side (Prompt65 handoff) — app does not invent autovacuum metrics.

---

## 57. Retention

| Store | Policy |
| --- | --- |
| `provider_api_counters` | Config `counter_retention_hours` (default 72) — cleanup command not shipped in Prompt 66; operators may prune |
| `operational_alerts` | History retained (resolved rows kept) |
| Heartbeats | Latest row per worker/dispatcher (upsert) |

---

## 58. Tests

`tests/Feature/Observability/ObservabilityOperationsTest.php` covers: rate denominator/min sample, quota visibility honesty, alert dedupe + ack≠resolve, stuck workload awareness, telemetry redaction + no score, liveness/readiness safety, worker heartbeat, zero-recipient no notify-all, no ObservabilityV2/health-score classes.

---

## 59. Explicit Non-Goals

- Observability SaaS / marketplace plugins  
- Customer-facing status page product  
- Auto-restart Horizon / auto-purge jobs  
- Cross-tenant analytical Redis caches  
- Fake “99.9% uptime” claims  
- Replacing Prompt27 freshness semantics  

---

## 60. File Matrix

| Path | Role |
| --- | --- |
| `config/moxdop-observability.php` | Rules + thresholds |
| `database/migrations/2026_08_16_080000_create_observability_operations_tables.php` | Tables |
| `app/Models/Observability/*` | Models |
| `app/Services/Observability/*` | Services |
| `app/Console/Commands/Observability/*` | Commands |
| `app/Http/Controllers/Ops/OpsHealthController.php` | Health HTTP |
| `app/Filament/App/Widgets/SystemStatusWidget.php` | UI dimensions |
| `routes/web.php` | Routes |
| `app/Services/Integrations/Meta/MetaApiClient.php` | Provider telemetry hook |
| `tests/Feature/Observability/ObservabilityOperationsTest.php` | PHPUnit |

---

## 61. Alert Rule Matrix

| Rule key | Type | Severity | Enabled | Primary signal |
| --- | --- | --- | --- | --- |
| `queue_interactive_backlog` | QUEUE_BACKLOG | WARNING | yes | Oldest job age |
| `worker_heartbeat_missing` | QUEUE_WORKER_UNAVAILABLE | CRITICAL | yes | Expected supervisors |
| `collection_stuck` | COLLECTION_STUCK | WARNING | yes | No-progress candidates |
| `collection_repeated_failure` | COLLECTION_REPEATED_FAILURE | WARNING | yes | Failed runs window |
| `provider_rate_limited` | PROVIDER_RATE_LIMITED | WARNING | yes | rate_limit_rate |
| `provider_error_rate` | PROVIDER_ERROR_RATE | WARNING | yes | error_rate |
| `credential_reconnect_required` | PROVIDER_AUTH_FAILURE | CRITICAL | yes | Integration auth_status |
| `dataset_stale` | DATASET_STALE | WARNING | yes | Prompt27 due states |
| Reserved enum types | various | — | no config rule | Future |

---

## 62. Reality Matrix

| Capability | Status |
| --- | --- |
| Config rules/thresholds (no health score) | REAL |
| OperationalAlert persistence + lifecycle | REAL |
| WorkerHeartbeat / OpsDispatcherHeartbeat | REAL |
| ProviderApiCounter + MetaApiClient recording | REAL (Meta wired) |
| Google/OpenAI/DataForSEO HTTP recording | PARTIAL / NOT_YET wired |
| Alert evaluator command | REAL |
| Scheduled evaluate-alerts in `routes/console.php` | REAL (everyFiveMinutes) |
| Liveness / readiness | REAL |
| Auth health snapshot | REAL |
| SystemStatusWidget real dimensions | REAL (undiscovered) |
| Prompt47 notify + no Activity spam | REAL |
| Quota honesty (429 / NOT_EXPOSED) | REAL |
| ObservabilityV2 / Prometheus / Grafana | ABSENT (intentional) |
| Autonomous remediation | ABSENT (intentional) |
| Measured production p95 queue/provider | NOT_MEASURED |
| Architecture contracts + runbook + limitations | REAL (this docs set) |

---

## 63. Prompt67 Handoff

| Capability | Prompt 66 | Prompt 67+ |
| --- | --- | --- |
| Durable ops alerts + snapshot | Owns | Consume for ops UX / deeper surfaces |
| Provider counter pipeline | Meta wired; API ready | Wire remaining HTTP clients |
| Cron for `evaluate-alerts` | Documented | Deployment automation / schedule registration |
| Log aggregation / SIEM | Structured `ops.*` logs | External shipper (out of app) |
| Slow-query production alerts | Not claimed | Postgres/log-based if measured |
| Autovacuum / table bloat | Not self-monitored | Host/DBA tooling |
| Customer status page | Out of scope | Product decision elsewhere |
| Remediation playbooks | Human runbook | Still human unless future ADR |

---

## 64. Definition of Done

Prompt 66 is **DONE** when Reality Matrix statuses match implemented code on base Prompt 65 HEAD `204a9bb`: `config/moxdop-observability.php` defines versioned rules without a health score; four observability tables exist; services cover telemetry, provider counters, heartbeats, stuck detection, alert lifecycle/notifier/evaluator, and multi-dimension snapshot with `overall_score=null`; commands `moxdop:ops:evaluate-alerts|worker-heartbeat|test-alert` exist; routes `/up/liveness`, `/up/readiness`, `/ops/health-snapshot` (auth) work without secrets; SystemStatusWidget shows real dimensions and stays undiscovered; MetaApiClient records provider telemetry; `OperationalAlertOpened` uses Prompt47 with `operation_failed` preference and no Activity spam; quota visibility uses `RATE_LIMIT_SIGNAL_ONLY` / `NOT_EXPOSED` without fake %; AsyncWorkerHealth, CollectionRun, DueCollectionQueryService, and Horizon are reused; no ObservabilityV2/Prometheus/Grafana/autonomous remediation; PHPUnit `ObservabilityOperationsTest` covers core behaviors; architecture contracts + operations runbook + limitations docs exist; unmeasured SLOs remain `NOT_MEASURED`.
