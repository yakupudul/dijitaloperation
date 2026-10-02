# Report Snapshot Source Pinning

> Prompt 59 — how immutable Snapshots pin Prompt 58 Client Value Story sources.  
> Parent contract: [`REPORT_SNAPSHOT_CONTRACT.md`](REPORT_SNAPSHOT_CONTRACT.md)  
> Live Story: [`CLIENT_VALUE_STORY_CONTRACT.md`](CLIENT_VALUE_STORY_CONTRACT.md) · [`CLIENT_VALUE_STORY_SOURCE_AUTHORITY.md`](CLIENT_VALUE_STORY_SOURCE_AUTHORITY.md)

## Purpose

Document the relationship between a live **Client Value Story**, its underlying canonical domains, and the **Source Manifest** frozen into a Report Snapshot — so historical reports stay honest when live data later changes.

```text
Findings / Opportunities / Tasks (+ QA/Approvals projections)
  + Business Outcome Observation Revisions
  + (future) Formula Versions for comparison
        ↓ Prompt 58 ClientValueStoryReadService
  Client Value Story + ClientValueStorySourceManifest
        ↓ Prompt 59 CreateReportSnapshotService + Serializer
  Report Snapshot (content_payload + source_manifest_payload + fingerprint)
```

---

## Prompt 58 Story (live)

| Aspect | Contract |
| --- | --- |
| Nature | Deterministic **read projection** — not a writable Story table |
| Service | `ClientValueStoryReadService` |
| Scope | Authorized Customer + Brand + explicit period |
| Honesty | No attribution, no causality, no AI narrative |
| Manifest | `ClientValueStorySourceManifest` with `prompt59_pinnable: true` |

Live Story **recomputes** on each read. It is current operational demonstrable-value projection, not historical truth.

---

## Findings

| Aspect | Live Story | Snapshot pin |
| --- | --- | --- |
| Source | Canonical Finding rows intersecting period | `finding_ids` in manifest + frozen `findings[]` content |
| Period roles | created / resolved / relevant (Prompt 58 semantics) | Frozen item fields (id, title, status, timestamps, role) |
| After Snapshot | Finding may resolve/rename | Historical Snapshot unchanged |
| Full row copy in manifest? | NO — ids only | Content array holds display freeze |

Evaluation: `FINDING_STATE_CHANGE_AFTER_SNAPSHOT`.

---

## Opportunities

| Aspect | Live Story | Snapshot pin |
| --- | --- | --- |
| Source | Canonical Opportunity rows | `opportunity_ids` + frozen `opportunities[]` |
| Semantics | Always potential — never realized value | Same honesty flags |
| After Snapshot | May close/dismiss | Historical Snapshot unchanged |

Opportunities in a Snapshot never become “realized ROI” claims.

---

## Tasks (Work)

| Aspect | Live Story | Snapshot pin |
| --- | --- | --- |
| Completed work | `status=completed` + `completed_at` in period | `task_ids` + frozen `completed_work[]` |
| Active work | Incomplete tasks relevant to period | Frozen `active_work[]` |
| Lineage | Optional Recommendation → Finding/Opportunity refs on items | Frozen on content items when present |
| After Snapshot | Task title/status may change | Historical Snapshot unchanged |

Task completion ≠ business Outcome. Snapshot preserves that boundary.

---

## QA / Approvals

| Aspect | Live Story | Snapshot pin |
| --- | --- | --- |
| Projection | Prompt 58 may expose QA/Approval state on Work items | Frozen as part of work item serialization |
| Domain writes | Snapshot create does **not** write QA/Approval rows | — |
| Claims | Completed ≠ verified success ≠ client approved ≠ business result | Same authority rules as Prompt 58 source authority |
| Evaluation | `TASK_QA_CHANGE_AFTER_SNAPSHOT` | Later QA changes do not mutate Snapshot |

QA/Approvals are **projected** into Story/Snapshot content when available; they are not separate Snapshot child tables.

---

## Business Outcome Observation Revisions

| Aspect | Live Story | Snapshot pin |
| --- | --- | --- |
| Source | Prompt 57 current revisions via Outcome read/aggregate path | `outcome_definition_ids` + **`outcome_observation_revision_ids`** |
| Content | Outcome kind/value/currency/coverage/completeness | Frozen `business_outcomes[]` |
| Correction | New revision becomes live current truth | Old Snapshot keeps prior revision ids + values |
| Regeneration | New Snapshot (± supersedes) captures new revision set | Prior Snapshot untouched |

This is the primary “history stays honest after client corrects July leads” guarantee (`OUTCOME_CORRECTION_AFTER_SNAPSHOT`).

Manifest pins **revision ids**, not merely definition ids, so the exact reported values are identifiable even if definitions are later archived/versioned.

---

## Formula Versions

| Aspect | Prompt 59 v1 | Future |
| --- | --- | --- |
| Comparison execution | `supported: false` | May enable with Formula Registry |
| `formula_version_id` in comparison stub | `null` | Pin explicit formula version used for comparison |
| Snapshot rewrite | N/A | New Snapshots only — never rewrite old comparison results |

Formula versions are **not** required to create a Client Value Story Snapshot in v1. Source Manifest does not currently list formula version ids; comparison stub is the reserved pin point.

---

## Snapshot Source Manifest

`ClientValueStorySourceManifest` (contract `client_value_story_manifest_v1`) persisted as `source_manifest_payload`:

| Field | Role |
| --- | --- |
| `customer_id` / `brand_id` | Tenancy pin |
| `period.start` / `period.end` | Period pin |
| `finding_ids` | Observed set |
| `opportunity_ids` | Potential set |
| `task_ids` | Work set |
| `outcome_definition_ids` | Outcome definition set |
| `outcome_observation_revision_ids` | Exact Outcome revision set |
| `limitation_codes` | Honesty / coverage limitations |
| `full_payload_copies` | Always `false` |
| `attribution_established` / `causality_established` | Always `false` |
| `prompt59_pinnable` | Declares Prompt 59 ownership |

### Fingerprint

`fingerprint()` = SHA-256(CanonicalJson of ordered ids + period + contract + Brand/Customer + limitation codes).

Stored as `source_manifest_fingerprint`. Create path asserts serializer fingerprint equals live Story fingerprint and manifest id sets match Story manifest.

### What the manifest is not

- Not a full Finding/Opportunity/Task/Outcome payload archive (those live in `content_payload` display freeze)
- Not Evidence Run storage
- Not Activity log
- Not delivery/PDF metadata
- Not `SourceManifestV2`

---

## Pinning relationship matrix

| Domain | Live owner | Story role | Manifest pin | Content freeze | Mutating live after Snapshot |
| --- | --- | --- | --- | --- | --- |
| Finding | Finding domain | Observed | `finding_ids` | `findings[]` | Snapshot unchanged |
| Opportunity | Opportunity domain | Potential | `opportunity_ids` | `opportunities[]` | Snapshot unchanged |
| Task | Task domain | Work | `task_ids` | work arrays | Snapshot unchanged |
| QA / Approval | QA/Approval domains | Work projection | via task/work items | work item fields | Snapshot unchanged |
| Outcome Definition | Prompt 57 | Outcome identity | `outcome_definition_ids` | outcome items | Snapshot unchanged |
| Outcome Observation Revision | Prompt 57 | Reported values | `outcome_observation_revision_ids` | outcome values | Snapshot unchanged |
| Formula Version | Formula Registry | Comparison (future) | comparison stub | comparison stub | Snapshot unchanged |
| Client Value Story | Prompt 58 read | Composition | whole manifest | `story` + sections | Live recomputes; Snapshot frozen |
| Report Snapshot | Prompt 59 | Historical report | stored manifest | stored content | Immutable |

---

## Create-time consistency

Inside `CreateReportSnapshotService` transaction:

1. Apply consistent read isolation (PG REPEATABLE READ / SQLite snapshot)
2. Compose live Story for Brand + period
3. Serialize content + manifest + fingerprint + checksum
4. Assert manifest matches Story; Brand consistency; checksum/fingerprint integrity
5. Insert immutable Snapshot row

If source read fails technically → abort (`SNAPSHOT_SOURCE_READ_FAILED`). Never persist a fake empty success that pretends sources were read.

---

## Read-time authority

| Reader | Behavior |
| --- | --- |
| Snapshot detail | Frozen payload only — **never** re-call Story Read Service |
| Assistant historical | `AssistantSourceClass::ReportSnapshot` — does not override current Outcome/Finding answers |
| Live Value / Outcome questions | Still Prompt 58 / Prompt 57 sources |

Historical Snapshot and current canonical domains are **both** real; they answer different questions.

---

## Forbidden

- Treating Snapshot as writable current truth
- Rebuilding detail from live Story
- Full payload copies inside manifest
- Attribution/causality claims via pinned sources
- Silent cross-Brand source refs
- `SourceManifestV2`
- Mutating prior Snapshot when Outcome revisions change
