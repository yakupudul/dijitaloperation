# EVIDENCE CANONICALIZATION

## STATUS: PASS

**Prompt:** 38  
**Date:** 2026-08-14  
**Branch:** `cursor/evidence-canonicalization-ea01`  
**Base:** Prompt 37 HEAD `f2a616da6d260c9370cbd58a947177019b44fc3d`

---

## 1. Purpose

Turn eligible **normalized pool facts** into durable, fingerprint-deduped **canonical Evidence** — source-backed analytical statements MoxDOP can reason from.

```text
Provider / Website fact
        ↓
Normalized canonical fact
        ↓
Evidence Definition
        ↓
Is it eligible?
- provenance valid?
- integrity valid?
- coverage sufficient?
- freshness known?
- scope resolvable?
- measurement semantics known?
- period explicit?
        ↓
Evidence Candidate
        ↓
Fingerprint / dedupe
        ↓
Canonical Evidence
```

## 2. Frozen Product Contract

- Evidence remains **Run-bound** (`evidence.run_id`).
- Finding stays a later consumer (Prompt 39). No Finding writes here.
- `BrandIntelligenceContext` is unchanged.
- Collection completion still does **not** emit Evidence.
- DatasetExecutors still must **not** write Evidence.

## 3. Existing Evidence Primitive Audit

| Primitive | Classification |
|---|---|
| `evidence` table / `Evidence` model | **EVOLVE** — canonical spine, additive identity columns |
| `request_fingerprint` + `fresh_until` | **REUSE** — paid CostGuard only, not identity |
| Bound collectors JSON Evidence | **LEGACY** — `is_canonical=false` |
| Probes / diagnosis `http_fetch` | **LEGACY** — technical observation ≠ canonical |
| `ai_insight` rows | **UNSAFE as source** — pipeline never writes these |
| Pool `gsc_*` / `ga4_*` facts | **CANONICAL input** — not Evidence |
| `PaidRequestFingerprint` / `RecordFingerprint` | **REUSE** — distinct from Evidence identity |
| `EvidenceV2` | **NOT CREATED** |

## 4. Canonical Truth Decision

| Question | Canonical answer |
|---|---|
| What is Evidence? | A source-backed analytical statement (`definition_id`) |
| Identity? | `(digital_asset_id, evidence_fingerprint)` |
| Eligible? | All seven gates pass |
| Facts vs Evidence? | Pool tables = facts; `is_canonical=true` rows = Evidence |
| Legacy JSON bag? | Same table, not canonical |

## 5. Evidence Definition (V1)

Code registry: `EvidenceDefinitionRegistry` version `v1`.

| ID | Dataset | Statement |
|---|---|---|
| `gsc.property.period_comparison` | `gsc_property_daily` | Clicks/impressions vs previous comparable period |
| `ga4.property.period_comparison` | `ga4_property_daily` | Sessions vs previous comparable period |

Formulas (registry only, never invented): `FORMULA_PERIOD_RELATIVE_CHANGE`, `FORMULA_GSC_CTR`.

## 6. Eligibility Gates

| Gate | Fail status |
|---|---|
| Period explicit (ISO, equal length, non-overlapping) | `ineligible_period` |
| Measurement semantics (Formula Registry IDs) | `ineligible_measurement` |
| Scope (Brand + active binding + same-Brand Goal/Offering) | `ineligible_scope` |
| Provenance (materialization + last_collected_at) | `ineligible_provenance` |
| Integrity (UI dataset gate READY) | `ineligible_integrity` |
| Freshness known (not UNKNOWN / INTEGRITY_BLOCKED) | `ineligible_freshness` |
| Coverage FULLY_COVERED for compared span | `ineligible_coverage` |

Ineligible definitions persist **0** Evidence rows.

## 7. Candidate → Fingerprint → Write

- Candidate is in-memory only.
- Identity fingerprint **v1** hashes definition, asset, grain, periods, optional Goal/Offering IDs — **not** metric values, **not** `PaidRequestFingerprint`.
- `CanonicalEvidenceWriter` upserts on `(digital_asset_id, evidence_fingerprint)`.
- Payload is bounded (metrics, period, provenance ids). No raw provider dump, no HTML, no secrets.

## 8. Additive Schema

Nullable/default columns on `evidence`: `definition_id`, `evidence_fingerprint`, `is_canonical`, `eligibility_status`, `collection_run_id`, `brand_goal_id`, `brand_offering_id`, `is_derived`, `generated_by_ai`.

Legacy rows remain valid.

## 9. Write / Read Architecture

- Write: `CanonicalEvidencePipeline` (`php artisan evidence:canonicalize {id}`)
- Activity Run `module_id = evidence-canonicalization`
- Read: `CanonicalEvidenceReadService` — canonical rows only; empty = empty; **no Demo fallback**

## 10. Boundaries

| Must not happen | Enforced |
|---|---|
| Findings / Opportunities / Recommendations / Tasks | Pipeline count guard + tests |
| AI interpretation | `generated_by_ai=false`; no AI calls |
| Collection → Evidence auto-create | No hook on DatasetExecutor / CollectionRun complete |
| WebsitePage / keyword / campaign → Offering | Unchanged Prompt 37 |
| Provider conversion → Conversion Goal | Unchanged Prompt 37 |
| BIC replacement | BIC untouched |
| EvidenceV2 | Reused `evidence` |

Optional `brand_goal_id` / `brand_offering_id` are Prompt 37 references (same Brand only). They do not change Goal/Offering identity.

## 11. Tests

`tests/Feature/Evidence/EvidenceCanonicalizationTest.php`

## 12. Reality Matrix

| Capability | State |
|---|---|
| Normalized facts | REAL (inputs) |
| Evidence Definition | REAL (V1 catalog) |
| Eligibility gates | REAL |
| Evidence Candidate | REAL (in-memory) |
| Identity fingerprint / dedupe | REAL |
| Canonical Evidence | REAL |
| Legacy JSON Evidence | LEGACY / non-canonical |
| Findings | NOT YET (Prompt 39) |
| AI | NOT YET |

## 13. Prompt 39 Handoff

Finding Intelligence may consume canonical Evidence IDs (`definition_id` + `evidence_fingerprint`) instead of copying mutable labels or raw JSON bags.
