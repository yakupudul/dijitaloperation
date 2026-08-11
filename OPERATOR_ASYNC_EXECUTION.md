# OPERATOR_ASYNC_EXECUTION

> **Canonical operator-execution standard for MoxDOP.**  
> Documentation / governance only as of `origin/main` @ `171e5e7`.  
> This standard describes required direction. Existing synchronous operator flows that violate it are **technical debt** and must migrate incrementally.  
> Related: `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`, ADR-013.

---

## Core principle

An operator must **NOT** need to keep a browser page open while a long operation is running.

Any task expected to involve one or more of the following **must** execute asynchronously:

- provider collection
- historical backfill
- website crawling
- bulk sync
- long AI generation
- large discovery
- multi-step analysis

Short CRUD, form saves, and trivial reads may remain synchronous.

---

## Preferred stack

Use the existing Laravel stack. Do **not** introduce Redis, Kafka, Horizon, or similar merely to satisfy this standard.

| Concern | Preferred choice |
| --- | --- |
| Queue | Laravel Queue |
| Initial driver | **database** queue |
| Work unit linkage | `Run` where operational collection/analysis applies |
| Persistence | `jobs` / `failed_jobs` / `job_batches` where appropriate |
| Secrets | Never expose provider tokens / credentials in UI or logs summaries |

Redis / Horizon / separate worker fleets remain future scaling options only when justified by real load.

---

## Canonical UX flow

```text
Operator presses action
→ operation queued immediately
→ UI returns control
→ user can navigate away
→ worker executes
→ persistent progress / status
→ success / failure stored
→ global Activity / Operations Center shows it
→ optional notification on completion / failure
```

The operator’s open browser tab is **not** the execution runtime.

---

## Operation states

Use explicit states:

| State | Meaning |
| --- | --- |
| `queued` | Accepted; waiting for a worker |
| `running` | Worker actively executing |
| `completed` | Finished successfully for the requested scope |
| `partial` | Finished with incomplete subset / degraded coverage |
| `failed` | Terminal failure |
| `cancelled` | Stopped by operator / system where cancellation is safe |

Do not invent success when work only partially completed — use `partial` or `failed` with a clear summary.

---

## Progress model

Prefer **phase-based** progress when exact percent is not reliable.

Example phases:

```text
Preparing
→ Collecting campaigns
→ Collecting ad sets
→ Collecting ads
→ Normalizing
→ Evaluating Findings
→ Completed
```

Rules:

- Do **not** display fake precision (for example “73%” when the system cannot know).
- Phase labels should be stable enough for operators and Activity Center filters.
- Persist phase on the operation / Run so navigation away does not lose status.

---

## Retry semantics

| Failure class | Retry policy |
| --- | --- |
| Provider transient failure (timeout, 429, 5xx, temporary network) | Safe **bounded** retry with backoff |
| Validation / business failure (bad binding, unsupported account, auth revoked, schema/contract violation) | **No retry storm** — fail clearly for operator action |
| Partial provider coverage | Prefer `partial` + actionable summary over infinite retries |

Retries must remain idempotent with respect to Evidence / Finding fingerprinting where applicable.

---

## Operator inspection surface

Operators should be able to inspect at least:

- operation type
- Brand
- Digital Asset
- provider
- started_at
- duration
- status
- progress / phase
- linked Run
- error summary
- retry eligibility

**Do not expose provider secrets** (access tokens, refresh tokens, API keys, raw credential payloads).

A global **Activity / Operations Center** is the intended aggregation surface for in-flight and recent operations across Brands / assets.

---

## Current main reality (debt)

As of canonical main (`171e5e7`):

- Queue configuration and `ShouldQueue` Job classes exist
- Many Filament actions still execute work **synchronously** in the HTTP request (inline services or `(new Job)->handle(...)`)
- Having queue tables / Job classes alone does **NOT** mean background-ready

Therefore:

- Current sync collect / diagnose / discover / AI analyze / cross-asset flows that can run long are **technical debt**
- They must migrate **incrementally** to this standard
- New long-running capabilities should ship async-first rather than adding more blocking request paths

Track readiness in `PRODUCT_CAPABILITY_LEDGER.md` (**Async execution** row and per-capability **Background-ready** column).

---

## Definition alignment

A long-running capability cannot be marked **DONE** in the Capability Ledger while it still requires the operator to keep a page open, unless an explicit scoped exception is documented and accepted.

See Definition of Done in `PROJECT_MEMORY.md`.

---

## Non-goals (this standard)

- Does not require implementing historical backfill now
- Does not require Redis/Kafka
- Does not require rewriting every short CRUD action
- Does not adopt external write actions
- Does not claim current main already meets the standard end-to-end
