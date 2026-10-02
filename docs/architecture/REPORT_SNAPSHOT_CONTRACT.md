# Report Snapshot Contract

> Prompt 59 — immutable historical Report Snapshot persistence.  
> Implementation: `app/Models/ReportSnapshot`, `app/Services/ReportSnapshots/*`, `app/Support/ReportSnapshots/*`.  
> Schema rules: [`REPORT_SNAPSHOT_SCHEMA_VERSIONING.md`](REPORT_SNAPSHOT_SCHEMA_VERSIONING.md)  
> Source pinning: [`REPORT_SNAPSHOT_SOURCE_PINNING.md`](REPORT_SNAPSHOT_SOURCE_PINNING.md)  
> Live projection: Prompt 58 [`CLIENT_VALUE_STORY_CONTRACT.md`](CLIENT_VALUE_STORY_CONTRACT.md)

## Canonical rule

MoxDOP represents a **Report Snapshot** as a durable, **immutable**, Brand-owned historical freeze of a bounded report type for an explicit period. Snapshot content is server-built from canonical read services. Snapshot is **not** live Client Value Story, not Activity, not PDF/delivery, not AI narrative, and not a generic report-builder document.

---

## Report Type

| Field | Contract |
| --- | --- |
| Identity | Closed enum `ReportType` string (`report_type` column, ≤64) |
| V1 value | `client_value_story` only |
| Registry | `ReportTypeRegistry` — scope, schema, source read service, manifest type |
| Unsupported | `UNSUPPORTED_REPORT_TYPE` — no free-form type strings |
| Display | Locale-aware label (`Client Value Story` / `Müşteri Değer Hikayesi`) |

Not a widget catalog. Not arbitrary SQL/PHP report definitions.

---

## Scope

| Field | Contract |
| --- | --- |
| Ownership | `customer_id` + `brand_id` required; Customer must match Brand |
| Allowed scope (v1) | `brand` only (`ReportTypeRegistry.allowed_scope`) |
| Authorization | Optional `authorizedCustomerIds` / `authorizedBrandIds` on create/read |
| Cross-Brand | Forbidden for create, supersedes, manifest, content, and detail |

Browser supplies Brand context via UI; server re-validates ownership.

---

## Period

| Field | Contract |
| --- | --- |
| `period_start` / `period_end` | Required calendar dates |
| Ordering | `period_end` ≥ `period_start` else `INVALID_REPORT_PERIOD` |
| Semantics | Same period bounds used to compose Prompt 58 Story inside create transaction |
| Storage | Indexed with Brand for history filters |

---

## Comparison Period

| Field | Contract |
| --- | --- |
| `comparison_period_start` / `comparison_period_end` | Optional; both required together when either present |
| Ordering | End ≥ start else `INVALID_COMPARISON_PERIOD` |
| V1 execution | Not supported (`comparison_supported: false` in registry) |
| Content stub | May store `{ period_start, period_end, formula_version_id: null, result: null, supported: false }` |

Comparison metadata may exist without claiming computed comparison results.

---

## Header Snapshot

Frozen display header persisted on the row and mirrored inside `content_payload.header`:

| Field | Meaning |
| --- | --- |
| `title_snapshot` / `header.title` | Custom title or default “Client Value Story — {start} → {end}” |
| `customer_name_snapshot` | Customer display name at generate time |
| `brand_name_snapshot` | Brand display name at generate time |
| `locale` | `en` \| `tr` |
| `reporting_timezone` | IANA / app timezone string |
| `report_type` / `report_type_label` | Type id + localized label |
| Period fields | Start/end (+ optional comparison) and Story `period_label` |
| `generated_by_display` | Operator display name/email at generate time |

Renames of Brand/Customer after create **do not** rewrite header snapshots.

---

## Schema Version

| Field | Contract |
| --- | --- |
| Column | `snapshot_schema_version` (string) |
| V1 | `client_value_story_v1` (`ReportSnapshotSchemaVersion::ClientValueStoryV1`) |
| Content echo | `content_payload.schema_version` must match |
| Readability | `isReadable()` — unreadables → `UNSUPPORTED_SNAPSHOT_SCHEMA` |
| Rewrite | Old rows never rewritten to a new schema |

See [`REPORT_SNAPSHOT_SCHEMA_VERSIONING.md`](REPORT_SNAPSHOT_SCHEMA_VERSIONING.md).

---

## Content Payload

| Field | Contract |
| --- | --- |
| Column | `content_payload` JSON |
| Builder | `ClientValueStorySnapshotSerializer` only |
| Browser supply | Forbidden (`BROWSER_SNAPSHOT_CONTENT_FORBIDDEN`) |
| Detail read | Always frozen payload; `rebuilt_from_live_story: false` |
| Integrity | SHA-256 checksum over canonical JSON of this payload |
| Safety | Reject executable needles (`<?php`, `<script`, `javascript:`, `eval(`, `unserialize(`) |
| Honesty flags | `attribution_established: false`, `causality_established: false`, `ai_assisted: false` |

Payload includes story presentation, findings, opportunities, work, business outcomes, limitations, claims, status, section labels, and optional comparison stub.

---

## Source Manifest

| Field | Contract |
| --- | --- |
| Column | `source_manifest_payload` JSON |
| Source | Prompt 58 `ClientValueStorySourceManifest::toArray()` |
| Contract version | `client_value_story_manifest_v1` |
| Contents | Reference IDs only — Findings, Opportunities, Tasks, Outcome definitions, Outcome observation revisions, limitation codes, period, Brand/Customer |
| Full copies | `full_payload_copies: false` |
| Pinnable | `prompt59_pinnable: true` |
| Create assert | Manifest must match live Story manifest fields used for pin |

See [`REPORT_SNAPSHOT_SOURCE_PINNING.md`](REPORT_SNAPSHOT_SOURCE_PINNING.md).

---

## Source Fingerprint

| Field | Contract |
| --- | --- |
| Column | `source_manifest_fingerprint` (64-char hex) |
| Algorithm | SHA-256 |
| Input | Canonical JSON of ordered source identities + period (+ contract version, Brand/Customer) |
| Excludes | Snapshot id, `generated_at`, queue/job/session metadata |
| Producer | `ClientValueStorySourceManifest::fingerprint()` |
| Create assert | Packed fingerprint must equal live Story fingerprint |

Fingerprint answers: “which exact source set was frozen?”

---

## Content Checksum

| Field | Contract |
| --- | --- |
| Column | `content_checksum` (64-char hex) |
| Algorithm | SHA-256 via `ReportSnapshotChecksum` |
| Input | `CanonicalJson::encode(content_payload)` |
| Excludes | Row id, `generated_at`, `created_at`, `idempotency_key`, names outside payload |
| Verify | Detail uses `hash_equals`; tamper → `CONTENT_CHECKSUM_MISMATCH` |
| Not | A digital signature / PKI certificate |

---

## Generated By

| Field | Contract |
| --- | --- |
| Column | `generated_by` FK → `users` (restrict on delete) |
| Semantics | Operator who requested Snapshot creation |
| Relation | `generatedByUser()` |
| AI | Never an AI system user; human operator actor required by UI |

---

## Generated At

| Field | Contract |
| --- | --- |
| Column | `generated_at` timestamp |
| Also | `created_at` set at insert; **no** `updated_at` |
| Ordering | History lists: `generated_at DESC`, `id DESC` |
| Immutability | Included in immutable field set |

---

## Supersedes

| Field | Contract |
| --- | --- |
| Column | `supersedes_snapshot_id` nullable FK → `report_snapshots` (null on delete) |
| Meaning | Explicit lineage to a prior Snapshot of the **same** Customer, Brand, and report type |
| Mutates prior? | **Never** — prior row remains immutable |
| Missing / cross-Brand / type mismatch | Validation errors |
| Idempotency | Independent of supersedes; regeneration uses a new idempotency key |

---

## Immutability

| Rule | Enforcement |
| --- | --- |
| No content rewrite | Model `update()` / dirty `save()` throw `REPORT_SNAPSHOT_IMMUTABLE` |
| Corrections | New Snapshot row (± supersedes), never in-place edit |
| Live domains | May change freely; historical Snapshot unaffected |
| Delivery | Must not mutate Snapshot to “attach PDF” — Prompt 60 stores delivery separately |

---

## Forbidden

- Browser-authored content / manifest / checksum / fingerprint
- Live Story rebuild on detail
- Attribution / causality / AI-assisted true flags
- PDF / share / email / delivery tables in Prompt 59
- `ReportSnapshotV2`, `SourceManifestV2`, report-builder EAV
- Cross-Brand aggregation as a single Snapshot
- Silent FX, ROI/ROAS, provider conversion mapping into Snapshot content
