# Intelligence Trigger Contract

> Prompt 63 — durable, idempotent wake signals for intelligence scheduling.  
> Code: `IntelligenceTrigger`, `IntelligenceTriggerService`, `IntelligenceTriggerSource`, `IntelligenceTriggerStatus`, `ScheduleIntelligenceFromEvidenceService`, `config/moxdop-intelligence-scheduling.php`.  
> Implementation: [`docs/implementation/INTELLIGENCE_SCHEDULING.md`](../implementation/INTELLIGENCE_SCHEDULING.md)

**Base:** Prompt 62 HEAD `e43e2ae` on `cursor/intelligence-scheduling-ea01`.

Triggers are **orchestration identity**, not Findings, Opportunities, Recommendations, or business truth.

---

## Canonical rule

Intelligence wakes only from allowed `IntelligenceTriggerSource` values after an Evidence analytical identity (or explicit manual/validity/finding-lineage path) is recorded. CollectionRun completion, Activity, Notification, Task, Agent results, AI candidates, Recommendations, Approvals, and QA are **FORBIDDEN** as trigger sources.

```text
Effective canonical Evidence set
  → EvidenceAnalyticalFingerprintBuilder (easet:…)
    → trigger_key = intel:{source}:asset:{id}:{fingerprint}
      → IntelligenceTrigger (PENDING → PLANNED → COMPLETED | SUPERSEDED | …)
        → IntelligenceSchedulingPlanner
```

---

## Allowed sources

| Enum value | Reason codes (examples) | Entry service method |
| --- | --- | --- |
| `EVIDENCE_ANALYTICAL_STATE_CHANGED` | `EVIDENCE_CANONICALIZED`, `EVIDENCE_ANALYTICAL_STATE_CHANGED` | `handleEvidenceCanonicalized` / `recordEvidenceAnalyticalChange` |
| `FINDING_STATE_CHANGED` | `FINDING_STATE_CHANGED` | `recordFindingStateChanged` (lineage from Phase 1) |
| `SCHEDULED_EVIDENCE_VALIDITY_RECHECK` | `SCHEDULED_EVIDENCE_VALIDITY_RECHECK` | `handleValidityRecheck` |
| `MANUAL_REEVALUATION` | `MANUAL_REEVALUATION` | `handleManualReevaluation` |

---

## Forbidden sources

Configured in `moxdop-intelligence-scheduling.forbidden_trigger_sources` and **absent** from `IntelligenceTriggerSource`:

| Forbidden token | Rationale |
| --- | --- |
| `ACTIVITY` | Activity is side-channel noise, not Evidence truth |
| `NOTIFICATION` | Notifications must not wake analyzers |
| `TASK` | Tasks are downstream work, not Evidence |
| `AGENT_RESULT` | Prevents AI → intelligence recursion |
| `AI_CANDIDATE` | Candidates must not auto-reenter scheduling |
| `COLLECTION_RUN_COMPLETED` | Run success ≠ analytical Evidence change |
| `RECOMMENDATION` | Downstream artifact |
| `APPROVAL` / `QA` | Human workflow, not Evidence analytical change |

---

## Trigger record fields

| Field | Contract |
| --- | --- |
| `customer_id` / `brand_id` / `digital_asset_id` | Tenancy scope from asset Brand; brand required or record returns null |
| `source_kind` | `IntelligenceTriggerSource` |
| `source_identity` | e.g. `digital_asset:{id}` or `finding_rule:{stableId}` |
| `source_revision_fingerprint` | Evidence set fingerprint or finding state fingerprint |
| `trigger_key` | Globally unique idempotency key |
| `changed_evidence_refs` | Fingerprint builder refs (JSON); empty for finding-state lineage |
| `metadata` | definition_ids, evidence_set_fingerprint, run ids, flags |
| `status` | `PENDING` → `PLANNED` → `COMPLETED` \| `COALESCED` \| `SUPERSEDED` \| `IGNORED` |

---

## Idempotency

| Path | Key shape |
| --- | --- |
| Evidence analytical | `intel:{source}:asset:{assetId}:{easetFingerprint}` |
| Finding state | `intel:FINDING_STATE_CHANGED:asset:{assetId}:{stableId}:{stateFingerprint}` |

Same key → return existing row (transactional). Unchanged Evidence retries do not create a second trigger.

---

## Evidence change set (logical)

No separate ChangeSet table. The change set **is** `changed_evidence_refs` plus metadata `definition_ids`. Each ref includes `definition_id`, `evidence_id`, `evidence_fingerprint`, `analytical_fingerprint`, and observation slice used for eligibility (fresh_until, integrity, eligibility_status, …).

---

## Event wiring

| Event | Listener | Behavior when scheduling enabled |
| --- | --- | --- |
| `EvidenceCanonicalized` | `QueueFindingEvaluationAfterEvidenceCanonicalized` | `ScheduleIntelligenceFromEvidenceService::handleEvidenceCanonicalized` |

When scheduling **disabled**, listener may fall back to legacy `EvaluateFindingsForAssetJob` if finding-rules config requests evaluate-after-canonicalization.

---

## Collection boundary

| Signal | Trigger? |
| --- | --- |
| CollectionRun completed | **NO** |
| Evidence canonicalized (analytical) | **YES** |
| Validity recheck schedule tick | **YES** (recheck source) |
| Manual reevaluation API | **YES** |

Metadata on Evidence-driven triggers includes `collection_run_direct_trigger => false` when created from canonicalization.
