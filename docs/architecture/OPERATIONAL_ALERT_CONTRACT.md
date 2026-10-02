# Operational Alert Contract

> Prompt 66 — durable operational alerts (not Findings).  
> Implementation: `OperationalAlert`, `OperationalAlertLifecycleService`, `OperationalAlertEvaluator`, `OperationalAlertNotifier`  
> Config: `config/moxdop-observability.php` → `rules`  
> Related: [`OBSERVABILITY_OPERATIONS.md`](../implementation/OBSERVABILITY_OPERATIONS.md) · [`OPERATIONAL_OBSERVABILITY_CONTRACT.md`](OPERATIONAL_OBSERVABILITY_CONTRACT.md)

## Canonical rule

An **Operational Alert** is a durable, semantically deduplicated platform incident signal. It is **not** a Finding Rule, Recommendation, Task, or Activity item. Evaluation is deterministic (no AI). Acknowledge ≠ resolve. Zero recipients keeps the alert OPEN without notify-all.

---

## Identity

```text
semantic_key = substr(sha256(rule_key + '|' + scope_type + '|' + scope_key), 0, 64)
```

Active states for dedupe: `OPEN`, `ACKNOWLEDGED`. Re-observation increments `observation_count` and refreshes `observed` / `last_observed_at`.

---

## Lifecycle

| State | Meaning |
| --- | --- |
| `OPEN` | Condition observed; may notify once |
| `ACKNOWLEDGED` | Human saw it; still active; no resolve |
| `RESOLVED` | Recovered or closed by operator |
| `SUPPRESSED` | Enum reserved |

`resolution_kind`: `RECOVERED` | `CLOSED_BY_OPERATOR`.

Ack fields: `acknowledged_at`, `acknowledged_by_user_id`, `ack_note`. Ack must not mutate queues, credentials, datasets, or CollectionRuns.

---

## Severity

`INFO` | `WARNING` | `CRITICAL`

---

## Versioned rules

Rules live in config (not arbitrary SQL/PHP expressions):

| Field | Role |
| --- | --- |
| `key` | Stable rule id (e.g. `queue_interactive_backlog`) |
| `version` | Integer rule version |
| `type` | `OperationalAlertRuleType` |
| `enabled` | Gate |
| `severity` | Default severity |
| `signal_family` | `OperationalSignalFamily` |
| `recovery` | Human-readable recovery hint label |

Enabled v1 keys: `queue_interactive_backlog`, `worker_heartbeat_missing`, `collection_stuck`, `collection_repeated_failure`, `provider_rate_limited`, `provider_error_rate`, `credential_reconnect_required`, `dataset_stale`.

Reserved enum types without enabled config rules: scheduler lag/missing, provider quota low, report delivery failure, AI provider failure rate, dataset blocked — document as future, do not pretend evaluated.

---

## Evaluation sources

| Rule family | Source of truth |
| --- | --- |
| Queue backlog | `AsyncWorkerHealth` oldest age + pending |
| Worker unavailable | Heartbeats vs expected supervisors |
| Collection stuck / failures | `CollectionRun` + workload thresholds |
| Provider rates | `ProviderApiCounter` summaries |
| Credential reconnect | `CoreIntegration` auth_status (Google/Meta) |
| Dataset stale | Prompt27 `DueCollectionQueryService` |

Command: `php artisan moxdop:ops:evaluate-alerts`.

---

## Notifications (Prompt 47)

| Event | Activity | Notification | Preference |
| --- | --- | --- | --- |
| `OPERATIONAL_ALERT_OPENED` | **No** | Yes (if recipients) | `operation_failed` |

- At most one notification emit per alert open (`notification_emitted`)  
- Recipients: `MOXDOP_OPS_ALERT_RECIPIENT_USER_IDS` or Admin role  
- Empty recipients → persist alert, skip emit  
- Resolve notify default **off** (`notify_on_resolve=false`)  
- Notification failure must not roll back alert row  

---

## Observed payload

JSON `observed` is diagnostic metadata only — redacted via `SecurityRedactor`. No tokens, Authorization headers, or raw provider bodies.

---

## Test alert

`moxdop:ops:test-alert` opens a clearly titled `[TEST]` alert under scope `test:alert` and must not be treated as provider/queue truth.
