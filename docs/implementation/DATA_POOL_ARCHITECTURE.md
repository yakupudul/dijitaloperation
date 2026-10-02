# Data Pool Architecture (Prompt 10)

Status: **REAL** (storage foundation). Provider collectors / Evidence / BigQuery: **NOT implemented by this prompt**.

## 1. Purpose

Prompt 9 established **how collection work executes**.

Prompt 10 establishes **where collected data lives**, how it is written safely, and how raw provider payloads stay distinct from normalized facts.

```text
                 MOXDOP DATA CONTRACT REGISTRY
                           ↓
                  Collection Engine
                           ↓
                      DatasetRun
                           ↓
                 Provider Collector Later
                           ↓
              ┌────────────┴────────────┐
              ↓                         ↓
        RawPayloadWriter           Normalizer Later
              ↓                         ↓
     Private Object Storage     NormalizedDatasetBatch
              ↓                         ↓
       Raw Manifest             WarehouseWriter
                                        ↓
                               PostgresWarehouseWriter
                                        ↓
                             Partitioned Normalized Facts
                                        ↓
                              Dataset Materialization
                                        ↓
                             Formula Registry Later
                                        ↓
                                  Evidence Later
```

Redis/Horizon remain **execution** infrastructure, not the normalized data store.

## 2. Storage layers (do not collapse)

| Layer | Question | V1 store |
| --- | --- | --- |
| **Control plane** | What work is happening? | `CollectionRun` / `ResourceRun` / `DatasetRun` / attempts / checkpoints |
| **Raw** | What did the provider return? | Private object storage + `raw_ingestion_objects` |
| **Normalized** | What canonical facts does MoxDOP store? | PostgreSQL typed source-specific tables |
| **Derived** | What deterministic metrics are calculated? | `MOXDOP_FORMULA_REGISTRY_V1` (query-time) |
| **Evidence** | What source-backed analytical statement can MoxDOP reason from? | Later milestone |

Hard rules: **RAW ≠ NORMALIZED ≠ DERIVED ≠ EVIDENCE ≠ FINDING**.

## 3. Contracts

| Artifact | Role |
| --- | --- |
| `MOXDOP_DATA_CONTRACT_REGISTRY_V1` | Logical datasets / requirements |
| `MOXDOP_DATA_POOL_STORAGE_V1` | Physical V1 mapping (tables, keys, partitions, write modes) |
| `MOXDOP_FORMULA_REGISTRY_V1` | Derived metrics — not silently persisted as fact columns |

Runtime loader: `DataPoolStorageRegistry`. Validator: `StorageContractValidator`.

## 4. Canonical write order

1. Provider chunk obtained (later)
2. Raw payload written when required (`FilesystemRawPayloadWriter`)
3. Normalized batch validated against storage contract
4. Partitions ensured (PostgreSQL)
5. Normalized upsert committed + `dataset_write_batches` row
6. Durable `WriteReceipt` (`checkpointSafe=true`)
7. `CheckpointManager` may advance
8. Next chunk may dispatch

**Checkpoint must not advance before durable normalized commit.**

Object storage is **outside** the DB transaction. Retries reuse deterministic object keys + checksums. Orphan-object cleanup is future maintenance.

## 5. Raw ingestion

- Disk: `raw_ingestion` (private; local in dev/tests; S3-compatible in production)
- Compression: gzip (configurable)
- Checksum: SHA-256
- Manifest: `raw_ingestion_objects` (metadata only — **no payload blob column**)
- Secrets forbidden in metadata (`SecretSanitizer`)
- Retention: **RAW RETENTION POLICY REQUIRES LATER OPERATIONAL DECISION**

## 6. Normalized pool

- Source-specific typed tables (`ga4_*`, `gsc_*`, `google_ads_*`, `meta_*`, `website_*`, `dataforseo_*`)
- **No** generic EAV / metrics mega-table
- Natural keys exclude `collection_run_id` (provenance only via `last_collection_run_id`)
- Money: exact `decimal` / `numeric` — floats rejected at writer boundary
- Currency preserved; no FX
- `reporting_date` ≠ `last_collected_at`
- Meta typed actions: `meta_typed_action_daily` rows keyed by `action_type` (no untyped Results column)

## 7. Partitioning

- PostgreSQL declarative `RANGE (reporting_date)` monthly for high-volume facts
- `PartitionManager`: idempotent, advisory-lock race-safe
- **No default partition** in V1 — missing partition preparation fails the batch (checkpoint does not advance)
- SQLite/tests: structurally equivalent **non-partitioned** tables (does not prove native partition behavior)

## 8. Materialization ≠ CollectionRun

`dataset_materializations` tracks pool usability per resource/dataset:

`NOT_COLLECTED` · `AVAILABLE` · `PARTIAL` · `STALE` · `UNAVAILABLE`

A failed refresh may mark **STALE** but must not erase previously committed facts.

## 9. Warehouse boundary / BigQuery

```text
WarehouseWriter
  ├── PostgresWarehouseWriter   ← REAL NOW
  └── BigQueryWarehouseWriter   ← POSSIBLE LATER (not implemented)
```

Collectors emit `NormalizedDatasetBatch` with **logical dataset IDs** only.

BigQuery is **not required for V1**. No BigQuery dependency.

GSC high-cardinality datasets may pressure PostgreSQL later — measure before moving.

## 10. Redis boundary

Redis: queues, locks, short-lived coordination.

Redis is **not** canonical fact storage or materialization state.

Paid DataForSEO durable replay uses raw object + normalized facts, not Redis alone.

## 11. Raw vs normalized example (provider-neutral)

Raw Meta response: actions array + spend + campaign metadata → private object.

Normalized:

- `meta_ad_daily`
- `meta_typed_action_daily` (lead=17 and messaging=9 are distinct typed rows)

Derived later: Cost per Lead Result (Formula Registry).

Evidence later: “Lead cost increased versus previous comparable period.”

## 12. Replay (documented, not UI)

Raw payload exists → normalizer version changes → reprocess raw → write normalized facts → without recalling the provider when possible.

## 13. Local vs production databases

| Environment | Relational | Raw objects |
| --- | --- | --- |
| Production (canonical pool) | **PostgreSQL** | S3-compatible private disk |
| PHPUnit fast suite | SQLite `:memory:` | Local fake disk |
| PostgreSQL integration | Real PostgreSQL (`@group postgres`) | Local fake disk |

## 14. Testing notes

- Ordinary unit/feature tests: **no Redis, no S3, no live providers**
- PostgreSQL partition/upsert proofs: `tests/Integration/DataPool` with `DB_CONNECTION=pgsql`

See also: `docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.md`.
