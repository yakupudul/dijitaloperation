# Sector Memory Privacy Policy

> Prompt 53 — versioned product disclosure-control defaults for cross-brand Sector Learning.  
> Implementation: `App\Support\SectorLearning\SectorLearningPrivacyPolicy`  
> Gate: `App\Services\SectorLearning\ProductionSectorLearningPrivacyGate`

Related: [`SECTOR_LEARNING_PRIVACY.md`](../implementation/SECTOR_LEARNING_PRIVACY.md) · [`INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md`](INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md)

---

## Privacy objectives

1. **Prevent contributor re-identification** in consumer Sector Memory (no Customer/Brand/Experience IDs, names, URLs, keywords, campaign assets, or Experience free text).
2. **Require multi-tenant diversity** — usable Sector aggregates need sufficient distinct Brands **and** Customers, not raw Experience volume.
3. **Limit dominant contributors** — no single Brand or Customer may exceed 20% effective share after bounding.
4. **Suppress small cells** without implying zero (`SUPPRESSED_PRIVACY` ≠ 0).
5. **Keep observational honesty** — cohort patterns are not causality, not external industry benchmarks, and not legal anonymity guarantees.

**Explicit non-claims:** This policy is **NOT** formal k-anonymity, **NOT** differential privacy, and provides **no privacy score**. Decisions are PASS/BLOCK + reason codes only (`documented_as: product_disclosure_control_policy`).

---

## Default thresholds (`sector_privacy_v1`)

| Parameter | Value | Applies to |
| --- | --- | --- |
| `min_distinct_brands` | **5** | Cohort release |
| `min_distinct_customers` | **5** | Cohort release |
| `min_categorical_cell_brands` | **3** | Per categorical cell |
| `min_categorical_cell_customers` | **3** | Per categorical cell |
| `min_numeric_aggregate_brands` | **10** | Numeric metric families |
| `min_numeric_aggregate_customers` | **10** | Numeric metric families |
| `max_single_brand_effective_share` | **0.20** | Post-bounding cohort |
| `max_single_customer_effective_share` | **0.20** | Post-bounding cohort |

Version pins also include `sector_projection_v1` and `sector_aggregation_v1`.

---

## Why Brand + Customer

| Risk | Mitigation |
| --- | --- |
| One Brand with many Experiences dominates counts | **Median brand reduction** — one effective unit per Brand per cohort key |
| One Customer owns many Brands in same sector | **Customer rebalance** — cap effective share at 20% |
| “5 Experiences” from one Brand | Blocked — need **5 distinct Brands** and **5 distinct Customers** |
| One agency Customer, many Brands | Dual threshold + customer share cap |

Brand count alone is insufficient. Customer count alone is insufficient. Both axes are enforced.

---

## Bounding (`sector_bounding_v1`)

`SectorLearningContributionBounder`:

1. Group contributions by Brand; pick **median fingerprint** contribution per Brand (deterministic, not favorable).
2. Assign effective weight 1.0 per Brand unit.
3. If any Customer’s raw share > 0.20, reduce weights for that Customer’s Brand units via closed-form rebalance.
4. Recompute brand/customer shares for gate checks.

Bounding runs **before** aggregation and gate qualification on bounded cohort statistics.

---

## Small-cell suppression

For categorical cells (e.g. `action_kind × outcome_clarity`):

- If cell has < 3 distinct Brands **or** < 3 distinct Customers → `status: SUPPRESSED_PRIVACY`.
- Suppressed cells must **not** expose exact counts and must **not** be stored/displayed as `0`.
- Complementary disclosure: when visible + suppressed cells could reconstruct exact counts, additional suppression may apply (`COMPLEMENTARY_DISCLOSURE_RISK`).

---

## Numeric cohort rules

When `requires_numeric_cohort=true` (numeric metric families in `SectorLearningMetricRegistry`):

- Cohort must have ≥ **10** distinct Brands **and** ≥ **10** distinct Customers.
- MVP `action_outcome_association` uses categorical `outcome_clarity_distribution` (numeric gate not required).
- Exact spend, revenue, blind CPC averages, and cross-provider metric mixes are **blocked** metric families.

---

## Blocked fields

`BLOCKED_IDENTIFIER_KEYS` (consumer + projection guard):

- Identity: `customer_id`, `brand_id`, `customer_ids`, `brand_ids`, `contributor_ids`, `experience_id`, `revision_id`
- Names/contact: `customer_name`, `brand_name`, `email`, `phone`, `address`
- Web/ads: `domain`, `url`, `campaign_*`, `ad_*`, `creative_*`, `keyword`, `search_term`, `landing_page_url`
- Brand-scoped: `goal_id`, `offering_id`, `provider_resource_id`
- Text: `notes`, `free_text`, `situation_summary`, `action_summary`, `outcome_summary`
- Internal: `contributor_brand_id_internal`, `contributor_customer_id_internal`

Gate also blocks flags: `raw_provider_rows`, `raw_evidence_copy`, `free_text`, `raw_keyword`, `raw_creative`, `raw_url`, `city`, `postcode`, `exact_date`, `expose_min_max`, `rare_combination`, `mixed_currency`, `mixed_attribution`.

---

## Allowed dimensions

`SAFE_DIMENSIONS` / `SectorLearningSafeDimensionRegistry`:

| Dimension | Granularity | AI inference |
| --- | --- | --- |
| `sector_code` | IndustryOptions catalog | **No** |
| `channel` | BrandExperienceChannel enum | **No** |
| `market_code` | ISO country | **No** |
| `action_kind` | BrandExperienceActionKind enum | **No** |
| `outcome_clarity` | BrandExperienceOutcomeClarity enum | **No** |
| `time_bucket` | Month (`YYYY-MM`) | **No** |

Forbidden dimensions include `city`, `goal_id`, `offering_id`, raw action text.

---

## Consumer DTO boundary

`SectorMemoryConsumerDto` / `SectorMemoryReadService`:

- **Includes:** artifact identity, sector code, kind, dimension contract, time scope, aggregate result (sanitized), cohort **band** (not exact counts), limitations, policy/aggregation/projection versions, observational label, summary, disposition, reason codes.
- **Excludes:** contributor IDs, lineage, Experience records, exact cohort counts, privacy score.
- **Labels:** `source_label: moxdop_cohort_observation`, `causality_status: causality_not_established`, `industry_benchmark_claim: false`.

---

## Limitations

Released artifacts carry limitations such as:

- `MOXDOP_COHORT_OBSERVATION`
- `OBSERVATIONAL_ONLY`
- `CAUSALITY_NOT_ESTABLISHED`
- `NOT_EXTERNAL_INDUSTRY_BENCHMARK`
- `NON_RANDOM_CUSTOMER_SAMPLE`
- `PRIVACY_QUALIFIED`
- `COHORT_QUALIFIED`

---

## Gate dispositions (selected)

| Disposition | Typical cause |
| --- | --- |
| `eligible` | All checks pass |
| `blocked_one_brand_insufficient` | ≤1 Brand |
| `blocked_small_cohort` | < 5 Brands or Customers (or numeric < 10) |
| `blocked_dominant_contributor` | Share > 0.20 after bounding |
| `blocked_raw_customer_data` | Blocked identifier key present |
| `blocked_identifying_dimension` | City, unsafe dimension, rare combo |
| `blocked_privacy_not_qualified` | Post-aggregation failure, incomplete candidate |
| `blocked_sector_unknown` | Missing/AI-inferred sector |

Historical Prompt 51 stub `blocked_pipeline_not_implemented` is superseded by production gate logic.

---

## Restricted lineage

`sector_learning_lineage_entries` retains Brand/Customer/Experience IDs for audit, deletion, and recompute. Accessible via `SectorLearningAuditService` only — **not** part of consumer contract or Agent retrieval.

---

## Policy change process

Bump `SectorLearningPrivacyPolicy::VERSION`. Revisions with mismatched `privacy_policy_version` are excluded from consumer reads until requalified and re-released.
