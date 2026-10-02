# Provider API Telemetry Contract

> Prompt 66 — provider HTTP attempt counters and honest quota visibility.  
> Implementation: `ProviderApiTelemetryService`, `ProviderApiCounter`, `ProviderRequestOutcome`, `ProviderQuotaVisibility`  
> Wired: `MetaApiClient`  
> Related: [`OBSERVABILITY_OPERATIONS.md`](../implementation/OBSERVABILITY_OPERATIONS.md) · [`OPERATIONAL_ALERT_CONTRACT.md`](OPERATIONAL_ALERT_CONTRACT.md) · [`CREDENTIAL_SECURITY_CONTRACT.md`](CREDENTIAL_SECURITY_CONTRACT.md)

## Canonical rule

Provider telemetry records **aggregated attempt outcomes** and optional quota **visibility class**. It never stores secrets, access tokens, Authorization headers, or raw response bodies. It never invents quota percentages. Telemetry failure must not break provider calls.

---

## Counter grain

Table `provider_api_counters` unique on `(provider, operation, window_started_at)`.

| Column | Meaning |
| --- | --- |
| `attempts` | Denominator |
| `successes` | 2xx-class success |
| `auth_errors` | 401/403-class |
| `rate_limits` | 429-class |
| `client_errors` | other 4xx |
| `server_errors` | 5xx |
| `timeouts` | timeout |
| `network_errors` | transport |
| `latency_sum_ms` | sum for **average** latency |

Windowing: config `provider_api.window_seconds` (default 900) for summaries; writes bucket to **5-minute** `window_started_at` for cardinality control.

Average latency = `latency_sum_ms / attempts` when attempts > 0.  
**p95 latency: NOT_MEASURED** (not stored).

---

## Outcomes

`ProviderRequestOutcome`: `SUCCESS`, `AUTH`, `RATE_LIMIT`, `PROVIDER_4XX`, `PROVIDER_5XX`, `TIMEOUT`, `NETWORK`, `APPLICATION`, `UNKNOWN`.

HTTP mapping (`classifyHttpStatus`): 401/403→AUTH, 429→RATE_LIMIT, ≥500→PROVIDER_5XX, other ≥400→PROVIDER_4XX, 2xx→SUCCESS.

---

## Rate formulas (alerts)

| Metric | Numerator | Denominator | Gate |
| --- | --- | --- | --- |
| `error_rate` | auth + server_errors + timeouts + network | `attempts` | `error_rate_minimum_attempts` (default 20) + `error_rate_threshold` (default 0.35) |
| `rate_limit_rate` | `rate_limits` | `attempts` | `rate_limit_minimum_attempts` (default 10) + `rate_limit_threshold` (default 0.25) |

Notes:

- Pure rate-limits are **not** counted in `error_rate` numerator.  
- A single failure (1/1) may compute 100% mathematically but **must not** alert below minimum attempts.  
- Evaluator scopes providers: `google`, `meta`, `openai`, `dataforseo` with operation `http`.

---

## Quota visibility

| Class | When |
| --- | --- |
| `PROVIDER_REPORTED_USAGE_AND_LIMIT` | limit + remaining present |
| `PROVIDER_REPORTED_REMAINING` | remaining only |
| `PROVIDER_REPORTED_RESET` | reset only |
| `RATE_LIMIT_SIGNAL_ONLY` | only rate-limit signal (e.g. 429) — **no fake %** |
| `NOT_EXPOSED` | provider did not expose quota |
| `UNKNOWN` | unclassified |

`MetaApiClient` currently sets `RATE_LIMIT_SIGNAL_ONLY` on rate-limit outcomes, otherwise `NOT_EXPOSED` when quota headers are not modeled.

---

## Recording API

`ProviderApiTelemetryService::recordAttempt(array $input)` accepts safe fields only (`provider`, `operation`, `outcome`, `duration_ms`, `http_status`, `attempt`, `integration_id`, `retry_after_seconds`, truncated `provider_request_id`, quota fields). Context is redacted before structured log `ops.provider.request`.

---

## Wiring status

| Client | Status |
| --- | --- |
| `MetaApiClient` | **REAL** — records on success/error/timeout/network paths |
| Google HTTP collectors | **NOT_YET** instrumented (counters still readable if written) |
| OpenAI / DataForSEO HTTP | **NOT_YET** instrumented |

---

## Retention

Config `provider_api.counter_retention_hours` (default 72). Prompt 66 does not ship an automatic prune command — operators may delete aged windows. Do not retain raw bodies “for debugging” in this table.

---

## Explicit non-goals

Per-request body archives, invented remaining%, billing-grade quota dashboards without provider-reported fields, cross-tenant Redis metric caches, Prometheus exporters.
