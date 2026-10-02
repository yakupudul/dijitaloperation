# MOXDOP Data Freshness Policy Registry V1

Machine-readable freshness, maturity, reprocessing, and incremental collection policy for production datasets.

- JSON: `MOXDOP_DATA_FRESHNESS_POLICY_V1.json`
- Schema: `MOXDOP_DATA_FRESHNESS_POLICY_V1.schema.json`
- Implementation: `docs/implementation/DATA_FRESHNESS_INCREMENTAL_COLLECTION.md`

This registry **references** Data Contract Dataset IDs and Integrity profiles. It does not redefine formulas, request fields, or storage mappings.

## Registry identity

| Field | Value |
| --- | --- |
| `freshness_policy_registry_id` | `MOXDOP_DATA_FRESHNESS_POLICY` |
| Version | `1` |
| Datasets covered | 44 (aligned with Integrity Registry V1) |

## Global prohibitions

- No single global `last_sync` truth across providers or assets.
- No numeric freshness score.
- No global reprocess window — windows are dataset-specific only.

## Global policies (summary)

| Policy | Value |
| --- | --- |
| Watermark per Resource × Dataset | yes |
| Freshness per Resource × Dataset | yes |
| `MAX(fact_date)` is not verified watermark | yes |
| Zero-row successful day advances coverage | yes |
| Failed collection does not advance verified watermark | yes |
| Current open reporting day not automatically complete | yes |
| Reprocessing may overlap existing coverage | yes |
| Catch-up derived from coverage evidence, not scheduler history | yes |
| Recurring scheduler in this registry | **no** (Prompt 61/62) |
| Planner builds provider-neutral intervals only | yes |

## Collection modes

| Mode | Meaning |
| --- | --- |
| `HISTORICAL_INCREMENTAL` | Daily reporting watermark + catch-up / reprocess |
| `CURRENT_SNAPSHOT` | `last_collected_at` SLA refresh |
| `PERIOD_OBSERVATION` | Reserved; not used in V1 production set |
| `CONTROLLED_ON_DEMAND` | Operator-triggered only |
| `STATIC_OR_SLOW_METADATA` | Not on daily incremental cadence |

## Freshness states (enum)

`FRESH` · `DUE` · `STALE` · `PARTIAL` · `ACTION_REQUIRED` · `PROVIDER_LIMITED` · `INTEGRITY_BLOCKED` · `UNKNOWN` · `FRESH_WITH_LIMITATION`

## Incremental work reasons (enum)

`NEW_COVERAGE` · `CATCH_UP` · `LATE_DATA_REPROCESS` · `GAP_RECOVERY` · `SNAPSHOT_REFRESH` · `CONTRACT_UPGRADE` · `MANUAL_REPLAY`

## Non-applicable datasets (explicit)

| Dataset | Reason |
| --- | --- |
| `gsc_search_appearance_daily` | Static/slow metadata; not on daily incremental cadence |
| `gsc_url_inspection_snapshot` | Controlled on-demand inspection; not recurring incremental |

All other production datasets have applicable incremental or snapshot refresh policy.

## Loader / validation

`App\Services\DataPool\Freshness\DataFreshnessPolicyLoader` loads JSON, validates schema metadata flags, duplicate IDs, and `incremental_applicable=false` entries require `non_applicable_reason`.

Config: `config/moxdop-data-freshness.php` (`MOXDOP_DATA_FRESHNESS_POLICY_PATH` override).
