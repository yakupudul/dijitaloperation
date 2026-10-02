# MoxDOP Operations Runbook

> Prompt 66 — human incident playbooks. Alerts notify; humans remediate.  
> No autonomous remediation. No secrets in this document.  
> Related: [`OBSERVABILITY_OPERATIONS.md`](../implementation/OBSERVABILITY_OPERATIONS.md) · [`OBSERVABILITY_LIMITATIONS.md`](OBSERVABILITY_LIMITATIONS.md)

## How to use

1. Confirm signal via `php artisan moxdop:ops:evaluate-alerts --snapshot` and/or `GET /ops/health-snapshot` (authenticated).  
2. Open/ack the matching `OperationalAlert` (ack ≠ fixed).  
3. Follow the scenario below.  
4. Re-run evaluate; alert should `RECOVERED` when condition clears.  
5. Prefer structured `ops.*` logs + canonical domain rows over inventing metrics.

**Schedule note:** Register `moxdop:ops:evaluate-alerts` on the deployment scheduler (not yet in `routes/console.php`). Also run `moxdop:ops:worker-heartbeat` from worker processes/supervisors.

---

## Collection Failure

| | |
| --- | --- |
| **Signals** | Rule `collection_repeated_failure`; collection dimension `failed_last_hour`; failed `CollectionRun` rows |
| **Triage** | Inspect recent failed UUIDs from alert `observed.sample_uuids`; check trigger type, provider, last error code (redacted logs) |
| **Likely causes** | Provider 5xx/auth, credential reconnect, queue worker down, bad resource binding |
| **Actions** | Fix root cause (credential/reconnect, provider status, worker); do **not** delete CollectionRun history; re-dispatch only via existing collection commands/UI |
| **Done when** | Failure count below rule window; alert resolves |

---

## Stuck

| | |
| --- | --- |
| **Signals** | Rule `collection_stuck`; `StuckCollectionDetector` candidates; collection dimension `stuck_candidates` |
| **Triage** | Compare `trigger_type` vs thresholds (incremental vs backfill). Long backfill with fresh `last_activity_at` is **not** stuck |
| **Likely causes** | Dead worker mid-job, job timeout, provider hang, lost heartbeat progress updates |
| **Actions** | Confirm Horizon/queue workers; inspect job failures; allow backfill more time if still progressing; only intervene with existing safe collection controls |
| **Done when** | No candidates above workload-aware no-progress policy |

---

## Dataset Stale

| | |
| --- | --- |
| **Signals** | Rule `dataset_stale`; Prompt27 due rows in STALE / INTEGRITY_BLOCKED / ACTION_REQUIRED |
| **Triage** | Use Due Collection / freshness UX — do **not** compute freshness via `MAX(date)` scans |
| **Likely causes** | Missed incremental, integrity block, reconnect required, intentional hold |
| **Actions** | Follow Prompt27 repair/incremental paths; clear integrity blocks properly; reconnect credentials if ACTION_REQUIRED is auth-related |
| **Done when** | Due query no longer returns those states for the alert scope |

---

## Reconnect

| | |
| --- | --- |
| **Signals** | Rule `credential_reconnect_required` (CRITICAL); integration `auth_status` RECONNECT/REFRESH/REVOKED/EXPIRED |
| **Triage** | Scope `integration:{id}`; provider Google or Meta |
| **Likely causes** | User revoked app, refresh failed, expired token, wrong app |
| **Actions** | Operator completes OAuth reconnect via existing Google/Meta authorize flows; never paste tokens into chats/logs; Prompt64 brokers only |
| **Done when** | Integration status active; alert resolves on next evaluate |

---

## 429 (Rate limit)

| | |
| --- | --- |
| **Signals** | Rule `provider_rate_limited`; counters `rate_limits`; quota visibility often `RATE_LIMIT_SIGNAL_ONLY` |
| **Triage** | Confirm `rate_limit_rate` and sample size ≥ minimum attempts; do **not** invent remaining% |
| **Likely causes** | Burst concurrency, shared app limits, aggressive backfill |
| **Actions** | Reduce worker concurrency / stagger collection; respect Retry-After when logged; wait for provider window; do not bypass paid-call gates |
| **Done when** | Rate below threshold for window |

---

## Queue backlog

| | |
| --- | --- |
| **Signals** | Rule `queue_interactive_backlog`; queue dimension; `AsyncWorkerHealth` oldest age |
| **Triage** | Pending count vs oldest age (age matters more than raw count); check queue driver |
| **Likely causes** | Workers stopped, Horizon down, starvation by heavy collection, DB queue growth |
| **Actions** | Restore workers/Horizon; separate heavy `collection` vs `default` per Prompt65 capacity contract; avoid blindly flushing jobs |
| **Done when** | Oldest age below interactive threshold + hold |

---

## Worker unavailable

| | |
| --- | --- |
| **Signals** | Rule `worker_heartbeat_missing` (CRITICAL) when expected supervisors configured; worker dimension UNHEALTHY |
| **Triage** | Compare `MOXDOP_OPS_EXPECTED_SUPERVISORS` to fresh heartbeats; empty expected → alert should not open |
| **Likely causes** | Supervisor crash, deploy without workers, heartbeat cron missing |
| **Actions** | Start Horizon/queue workers; ensure `moxdop:ops:worker-heartbeat` from each supervisor; fix expected list for **this** deployment |
| **Done when** | All expected supervisors have fresh heartbeats |

---

## Scheduler missing

| | |
| --- | --- |
| **Signals** | Scheduler dimension UNHEALTHY/UNKNOWN via `ops_dispatcher_heartbeats`; lag if evaluate command not cron’d |
| **Triage** | No heartbeat row → UNKNOWN (do not invent cron status). Stale beat → UNHEALTHY |
| **Likely causes** | Host cron missing `schedule:run`; evaluate-alerts not scheduled; dispatcher not beating |
| **Actions** | Ensure OS cron runs `php artisan schedule:run`; schedule `moxdop:ops:evaluate-alerts` (and other due dispatchers already in `routes/console.php`) |
| **Done when** | Dispatcher heartbeat age within `dispatcher_stale_seconds` |

---

## Report failure

| | |
| --- | --- |
| **Signals** | Report delivery job failures / artifact errors (enum `REPORT_DELIVERY_FAILURE` reserved — **not** enabled as Prompt 66 config rule yet) |
| **Triage** | Use report delivery occurrence/status tables and logs; snapshot/PDF paths |
| **Likely causes** | Storage failure, share/OTP issues, queue backlog delaying delivery |
| **Actions** | Check storage dimension; requeue via existing report commands; verify authenticated share contracts (Prompt 60) without printing secrets |
| **Done when** | Delivery succeeds; storage HEALTHY |

---

## AI provider

| | |
| --- | --- |
| **Signals** | Evaluator includes `openai` in provider rate loops when counters exist; rule type `AI_PROVIDER_FAILURE_RATE` reserved / not enabled |
| **Triage** | Confirm whether `provider_api_counters` have `provider=openai` rows; if none, status is unknown — do not invent error rates |
| **Likely causes** | API key invalid, upstream 5xx, timeouts, rate limits |
| **Actions** | Fix deployment AI credentials (env only); reduce concurrent AI jobs; never log prompts with secrets |
| **Done when** | Error/rate-limit rates below policy **or** counters absent and product path healthy |

---

## Redis

| | |
| --- | --- |
| **Signals** | Redis dimension UNHEALTHY when queue/cache default is redis; UNKNOWN when not required |
| **Triage** | Confirm `queue.default` / `cache.default`; ping failure → `REDIS_UNAVAILABLE` |
| **Likely causes** | Redis down, wrong host, network policy |
| **Actions** | Restore Redis; if deployment uses database queue intentionally, UNKNOWN is acceptable |
| **Done when** | Ping OK when Redis is required |

---

## Storage

| | |
| --- | --- |
| **Signals** | Storage dimension UNHEALTHY (`STORAGE_UNAVAILABLE`); report/PDF/artifact failures |
| **Triage** | Default filesystem disk write/delete probe failed |
| **Likely causes** | Disk full, permissions, misconfigured S3/local disk |
| **Actions** | Free space / fix IAM/permissions; verify artifact download paths; do not store secrets on public disks |
| **Done when** | Storage probe OK; artifact operations succeed |

---

## Safe test

`php artisan moxdop:ops:test-alert` — opens a marked TEST alert for notification plumbing. Resolve/ignore after verifying recipients; it is not a real outage.
