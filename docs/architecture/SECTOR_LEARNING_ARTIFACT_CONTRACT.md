# Sector Learning Artifact Contract

> Prompt 53 — canonical structured contract for privacy-qualified Sector Memory artifacts.  
> Consumer handoff to Prompt 54 retrieval (injection **not** implemented in P53).

Related: [`SECTOR_LEARNING_PRIVACY.md`](../implementation/SECTOR_LEARNING_PRIVACY.md) · [`SECTOR_MEMORY_PRIVACY_POLICY.md`](SECTOR_MEMORY_PRIVACY_POLICY.md)

---

## Identity

| Field | Type | Rule |
| --- | --- | --- |
| `stable_key` | string (SHA-256) | Deterministic per `sector_code` + `artifact_kind` + contract family |
| `sector_code` | string | `IndustryOptions` catalog code |
| `artifact_kind` | enum | `SectorLearningArtifactKind` |
| `status` | enum | `SectorLearningArtifactStatus` — consumer reads `active` only |
| `current_revision_id` | bigint | Points to latest active revision |

**Sector identity source:** `OperatorConfirmedSectorIdentityResolver` (`brand.sector` → `customer.industry`). No AI inference.

---

## Revision

Each material aggregate release creates an immutable `sector_learning_revisions` row.

| Field | Purpose |
| --- | --- |
| `revision_number` | Monotonic per artifact |
| `status` | `active` \| `superseded` \| `stale` \| … |
| `dimension_contract` | JSON — allowed dimensions for this artifact |
| `time_scope` | JSON — e.g. `{granularity: month, family: outcome_observed_month_buckets}` |
| `metric_family` | Registry key (e.g. `outcome_clarity_distribution`) |
| `action_category` | **null** for MVP — action taxonomy is `BrandExperienceActionKind` via aggregate cells, not a separate marketing taxonomy |
| `aggregate_result` | Versioned JSON payload (see below) |
| `cohort_band` | `SectorLearningCohortBand` — consumer-safe band, not exact count |
| `limitations` | List of observational/privacy limitation codes |
| `privacy_policy_version` | e.g. `sector_privacy_v1` |
| `aggregation_method_version` | e.g. `sector_aggregation_v1` |
| `projection_version` | e.g. `sector_projection_v1` |
| `aggregate_fingerprint` | Idempotency hash over sector, versions, contribution fingerprints |
| `observational_label` | `MOXDOP_COHORT_OBSERVATION` |
| `summary_text` | Human-readable observational summary (no causality language) |
| `privacy_assessment` | `{disposition, reason_codes, policy_version, privacy_score: null}` |
| `internal_distinct_brands` | **Internal only** — not in consumer DTO |
| `internal_distinct_customers` | **Internal only** — not in consumer DTO |

Supersession: new revision marks prior `superseded`. Experience invalidation marks artifact `stale`.

---

## Kinds (`SectorLearningArtifactKind`)

| Kind | MVP status | Description |
| --- | --- | --- |
| `action_outcome_association` | **Released** | Observational association between action kind and outcome clarity |
| `outcome_distribution` | Defined | Not released in MVP pipeline |
| `frequency_pattern` | Defined | Not released in MVP pipeline |

No `BEST_STRATEGY`, `WINNING_CREATIVE`, `TOP_KEYWORD`, or `TOP_BRAND` kinds.

---

## Aggregate schema (`action_outcome_association`)

Schema id: `sector_aggregate_action_outcome_v1`

```json
{
  "schema": "sector_aggregate_action_outcome_v1",
  "kind": "action_outcome_association",
  "aggregator": "direction_distribution",
  "cells": [
    {
      "action_kind": "task_completed",
      "outcome_clarity": "favorable",
      "status": "visible",
      "effective_share_band": "medium"
    },
    {
      "action_kind": "external_operator_confirmed",
      "outcome_clarity": "unclear",
      "status": "SUPPRESSED_PRIVACY",
      "effective_share_band": null,
      "count": null
    }
  ],
  "suppressed_cell_count": 1,
  "expose_min": false,
  "expose_max": false,
  "causality": "causality_not_established",
  "source_label": "moxdop_cohort_observation",
  "industry_benchmark_claim": false
}
```

Rules:

- `action_kind` values are **`BrandExperienceActionKind`** enum strings only.
- `status: SUPPRESSED_PRIVACY` cells must have `count: null` — **not** zero.
- No min/max exact contributor counts in consumer payload (`expose_min` / `expose_max` always false).
- No Brand/Customer/Experience IDs in aggregate JSON.

---

## Aggregators (`SectorLearningAggregator`)

| Aggregator | Used by |
| --- | --- |
| `direction_distribution` | `action_outcome_association` |
| `category_distribution` | Future `action_kind_frequency` |
| `proportion` | Reserved |
| `count_distinct_contributors_internal` | Internal only — never consumer |

---

## Consumer DTO (`SectorMemoryConsumerDto`)

Produced by `SectorMemoryReadService` from active, Eligible revisions.

**Required consumer fields:** `artifact_stable_key`, `artifact_id`, `revision_id`, `revision_number`, `sector_code`, `artifact_kind`, `dimension_contract`, `time_scope`, `aggregate_result`, `cohort_band`, `limitations`, version pins, `observational_label`, `summary_text`, `privacy_disposition`, `privacy_reason_codes`, `updated_at`.

**Derived labels in `toArray()`:** `source_label`, `causality_status`, `industry_benchmark_claim`.

**Forbidden in DTO:** `customer_id`, `brand_id`, `experience_id`, `contributor_ids`, lineage arrays, Experience summaries, `goal_id`, `offering_id`.

Constructor throws if forbidden keys appear in `aggregate_result` or `dimension_contract`.

---

## Lineage boundary

`sector_learning_lineage_entries` links revisions to:

- `brand_experience_id`, `brand_experience_revision_id`
- `brand_id`, `customer_id`
- `contribution_fingerprint`, `effective_weight`

**Consumer contract:** lineage is **out of scope**.  
**Audit contract:** `SectorLearningAuditService` for platform operators only.  
**Agent contract:** no lineage access.

---

## Write boundary

Only `SectorLearningArtifactService` (+ `SectorLearningContributionRepository` for reads) may perform cross-brand Experience access and artifact writes. No Agent, Task, or listener direct writes.

**Contribution source:** confirmed Brand Experiences only (Prompt 52). No raw provider data.

---

## Read boundary

| Service | Audience | Returns |
| --- | --- | --- |
| `SectorMemoryReadService` | Consumer / future retrieval | `SectorMemoryConsumerDto` |
| `ReleasedSectorMemoryContextProvider` | Gateway (Eligible gate only) | Memory pack **references** |
| `SectorLearningAuditService` | Restricted audit | Lineage rows |

---

## Prompt 54 handoff

Prompt 53 delivers:

1. Persisted artifacts + revisions qualified under `sector_privacy_v1`.
2. `SectorMemoryConsumerDto::toArray()` — full consumer payload.
3. `SectorMemoryConsumerDto::toMemoryPackReference()` — minimal reference shape:

```json
{
  "artifact_id": "<stable_key>",
  "revision": "<revision_number>",
  "citation": "<summary_text>"
}
```

Prompt 54 owns:

- `MemoryContextPack` assembly
- Server-side retrieval selection and bounding
- Agent run provenance pinning
- Injection into Prompt 50 execution path

**Not implemented in Prompt 53:** retrieval injection, semantic search, similar-customer lookup, or LLM memory tools.

---

## Version compatibility

Consumer reads reject revisions where `privacy_policy_version !== SectorLearningPrivacyPolicy::VERSION`. Policy bumps require requalification and re-release.
