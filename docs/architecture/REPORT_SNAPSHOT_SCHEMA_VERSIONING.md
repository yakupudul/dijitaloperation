# Report Snapshot Schema Versioning

> Prompt 59 — `CLIENT_VALUE_STORY_V1` serialization and checksum rules.  
> Parent contract: [`REPORT_SNAPSHOT_CONTRACT.md`](REPORT_SNAPSHOT_CONTRACT.md)  
> Implementation: `ClientValueStorySnapshotSerializer`, `ReportSnapshotSchemaVersion`, `CanonicalJson`, `ReportSnapshotChecksum`

## Purpose

Define how Report Snapshot **content schemas** are versioned so historical rows remain readable, new writers stay bounded, and checksums stay deterministic — without in-place upgrades or parallel `ReportSnapshotV2` entities.

---

## CLIENT_VALUE_STORY_V1 serialization

| Item | Value |
| --- | --- |
| Schema id | `client_value_story_v1` |
| Enum | `ReportSnapshotSchemaVersion::ClientValueStoryV1` |
| Report type | `client_value_story` |
| Serializer | `App\Support\ReportSnapshots\ClientValueStorySnapshotSerializer` |
| Constant | `ClientValueStorySnapshotSerializer::SCHEMA` |
| Writable for new Snapshots | YES (`isWritableForNewSnapshots()`) |
| Readable | YES (`isReadable()`) |

### Content shape (v1)

```text
schema_version
report_type
header { customer_id, brand_id, names, type/label, title, period*, locale, timezone, generated_by_display }
story { Prompt 58 presentation array (source_manifest stripped) }
findings[]
opportunities[]
completed_work[]
active_work[]
business_outcomes[]
limitations[]
claims[]
status
attribution_established = false
causality_established = false
ai_assisted = false
section_labels { observed, potential, work, outcomes, limitations }
comparison? { period_start, period_end, formula_version_id, result, supported }
```

### Validation gates

| Check | Failure |
| --- | --- |
| `schema_version` ≠ `client_value_story_v1` | `UNSUPPORTED_SNAPSHOT_SCHEMA` |
| `report_type` ≠ `client_value_story` | `INVALID_REPORT_TYPE` |
| Missing `header` / `story` / `business_outcomes` | `INVALID_SNAPSHOT_PAYLOAD` |
| Attribution or causality true | `ATTRIBUTION_FORBIDDEN` |
| Executable content needles | `EXECUTABLE_CONTENT_FORBIDDEN` |
| Title empty / >200 after sanitize | `INVALID_REPORT_TITLE` |

### Source of truth at freeze

Serializer consumes Prompt 58 `ClientValueStory` DTO produced by `ClientValueStoryReadService` inside the create transaction. It does not invent Findings, Outcomes, or narrative text beyond deterministic Story templates already present in the DTO.

---

## Canonical JSON

`App\Support\ReportSnapshots\CanonicalJson`:

1. Recursively normalize arrays
2. For associative arrays: `ksort` keys
3. For lists: preserve order; normalize each element
4. Encode with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`

Used by:

- `ClientValueStorySourceManifest::fingerprint()`
- `ReportSnapshotChecksum::hash()` / `verify()`

Semantically equal payloads with different key insertion order must hash identically.

---

## Money

| Rule | Contract |
| --- | --- |
| Source | Prompt 57 Business Outcome aggregates as projected by Prompt 58 |
| Representation | Frozen outcome item fields (`value`, currency when MONEY) as serialized by Story DTOs |
| Missing vs zero | Missing remains absent/null semantics from Story; explicit zero remains `"0"` / 0 |
| FX | No silent currency conversion at Snapshot time |
| Provider money | Never map Ads/Meta/GA4 conversion value into Snapshot Outcomes |

---

## Dates

| Rule | Contract |
| --- | --- |
| Period fields | Calendar dates `Y-m-d` on Snapshot row and header |
| Item timestamps | Frozen as emitted by Story item `toArray()` (Finding/Opportunity/Task/Outcome periods) |
| Reporting timezone | Stored string on Snapshot; does not rewrite historical timestamps |
| Comparison dates | Optional; stored even when comparison `supported: false` |

---

## Localization

| Rule | Contract |
| --- | --- |
| Allowlist | `en`, `tr` (other → `en`) |
| Default title (en) | `Client Value Story — {period_start} → {period_end}` |
| Default title (tr) | `Müşteri Değer Hikayesi — {period_start} → {period_end}` |
| Custom title | Optional; `strip_tags`, trim, max 200 |
| Type label | `ReportType::displayLabel($locale)` |
| Section labels | English operator section constants frozen in payload (`WHAT WE OBSERVED`, …) |

Locale is part of the frozen Snapshot — changing operator UI locale later does not rewrite historical rows.

---

## Old-schema readability

| Rule | Contract |
| --- | --- |
| Persist forever | Old Snapshot rows remain queryable |
| Read path | `ReportSnapshotReadService::detail` checks `snapshot_schema_version->isReadable()` |
| Unknown schema | Fail closed with `UNSUPPORTED_SNAPSHOT_SCHEMA` — do not guess-parse |
| Checksum | Still verified for readable schemas |
| Migration | Prefer additive readers; never DELETE historical content to “clean” schemas |

V1 is currently the only schema; readability is `true` for that case.

---

## Future-schema rules

| Rule | Contract |
| --- | --- |
| Add schema | New `ReportSnapshotSchemaVersion` enum case + dedicated serializer/validator |
| Writers | Only schemas with `isWritableForNewSnapshots() === true` may be used for **new** inserts |
| Old rows | Never UPDATE `snapshot_schema_version` or rewrite `content_payload` to a new schema |
| Dual-write | Forbidden |
| Parallel entities | No `ReportSnapshotV2` table/model |
| Comparison / delivery | May introduce new schema versions or additive optional keys; old rows stay valid |
| Registry | `ReportTypeRegistry` points each type at its current writable schema |

---

## Checksum rules

| Rule | Contract |
| --- | --- |
| Algorithm | SHA-256 hex |
| Input | Canonical JSON of **content payload array only** |
| Timing | Computed at serialize time; stored on insert; re-verified on detail |
| Mismatch on create | `CONTENT_CHECKSUM_MISMATCH` (internal integrity) |
| Mismatch on read | `CONTENT_CHECKSUM_MISMATCH` (tamper / corruption) |
| Determinism | Same payload ⇒ same hash across calls (`hash()` stable) |
| Not covered | Digital signatures, HMAC secrets, browser-supplied checksum trust |

Fingerprint (manifest) and checksum (content) are complementary: fingerprint identifies **which sources**; checksum protects **what was shown**.

---

## Forbidden

- In-place schema upgrade of existing Snapshot rows
- Trusting browser `schema_version` / checksum / content
- Treating unreadable schemas as empty success
- Embedding live URLs that re-resolve current canonical truth into frozen content (serializer strips live-only `source_manifest` from presentation)
- Claiming comparison results when `supported: false`
