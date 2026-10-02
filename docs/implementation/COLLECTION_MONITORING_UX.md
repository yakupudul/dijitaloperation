# Collection Monitoring UX (Prompt 11)

Status: **REAL** (persistent progress + Integrations monitoring). Provider collectors: **unchanged / NOT REAL**.

## Product location

**Primary:** Settings → Integrations (hub embeds `MonitoringPanel`).

**No** new top-level navigation (`Collection` / `Runs` / `Jobs` / `Queue`).

Frozen IA preserved.

## Transport architecture

| Layer | Role |
| --- | --- |
| **Database** | Canonical CollectionRun / ResourceRun / DatasetRun / attempts / progress |
| **Redis / Horizon** | Execute background jobs (not operator truth) |
| **Broadcast (`CollectionRunChanged`)** | Optional near-live **invalidation** (private channel `collection-runs.{uuid}`) |
| **Polling (`wire:poll`)** | Mandatory safe fallback + reconciliation |
| **Browser** | Viewer / controller only — never owns execution |

**Reverb:** optional. `BROADCAST_CONNECTION=null|log` works. Collection continues if WebSockets are down.

## Progress semantics

| Progress type | Denominator known? | Percentage allowed? | Displayed data | Example |
| --- | --- | --- | --- | --- |
| `DATASET_PLAN_COMPLETION` | Planned executable DatasetRuns | YES | completed/total datasets | GA4 2/3 → **66.7% datasets** |
| `COUNTED` | `progress_total` | YES | current/total | 25/100 → 25% |
| `PAGE_BASED` / `CHUNK_BASED` | Only if total known | YES iff total | pages/chunks | 12/20 → 60% |
| `INDETERMINATE` | NO | **NONE** | Collecting… + rows | 24,820 records |
| `STAGE_BASED` | Stage label only | NO | stage name | Persisting |

**Never** invent % for unknown denominators. **Never** weight datasets by volume as “API download %”.

Partial / failed / cancelled runs must **not** be shown as unqualified green 100% success.

## Status mapping

| Domain status | Operator label | Tone | Terminal? | Retry? |
| --- | --- | --- | --- | --- |
| queued | Queued | slate | no | no |
| running | Collecting | blue | no | no |
| retrying | Retrying | amber | no | auto |
| completed | Completed | emerald | yes | no |
| partial | Partially completed | amber | yes | yes |
| failed | Failed | rose | yes | yes |
| cancellation_requested | Cancelling… | amber | no | no |
| cancelled | Cancelled | slate | yes | no |
| skipped / not_eligible | Skipped / Not eligible | slate | yes | no |

Status uses **icon + label + color** (not color alone).

## Materialization distinction

```text
Latest refresh: Failed
Existing data: Stale · available through Aug 12
```

Failed refresh ≠ data disappeared (`DatasetMaterialization`).

## Operator actions

| Action | Backend | Notes |
| --- | --- | --- |
| Refresh status | Monitor query only | Does **not** start collection |
| Cancel | `CancellationService` | Cooperative; completed data preserved |
| Retry failed dataset | `ResumeDatasetRunService` | Successful siblings not recollected |
| Collect Data | existing / Prompt 9 start | Not redefined here |

## Security

- Private broadcast channels authorized via `CollectionRunPolicy`
- Minimal event payload (`uuid`, `status`)
- Error messages sanitized (tokens redacted)
- No raw checkpoints / object keys / credentials in operator UX
- Cross-tenant CollectionRun access denied

## Local development

```bash
php artisan serve
php artisan horizon          # or queue:work for collection queue
npm run dev                  # Filament theme / Vite
# optional:
php artisan reverb:start
```

Reverb is **not** required for monitoring correctness.

## Sibling isolation example

```text
Mid-flight:
  GSC query×page → RETRYING
  GA4             → Running (continues)
  Google Ads      → Running (continues)
  CollectionRun   → Running (1 retrying surfaced)

After retry exhausted:
  GSC query×page → FAILED
  GA4             → COMPLETED
  Google Ads      → COMPLETED
  CollectionRun   → PARTIAL
```

A required dataset failure with sibling successes is **PARTIAL**, not a blanket run failure. Successful siblings are not blocked or recollected.

## Foundation gap corrected

Prompt 9 `ProgressReporter` previously forced `progress_total = null` for all non-`counted` modes. Prompt 11 allows known totals for `page_based` / `chunk_based` so legitimate percentages work. Documented as **PROMPT 9 FOUNDATION GAP CORRECTED**.

## Reality

- Persistent Collection Monitoring: **REAL**
- Historical Collection Runs: **REAL**
- Live Monitoring: **REAL** via polling (+ optional broadcast invalidation)
- Reverb: **OPTIONAL**
- Provider production collectors: **UNCHANGED**
