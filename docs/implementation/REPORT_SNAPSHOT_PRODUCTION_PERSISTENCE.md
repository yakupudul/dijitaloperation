# REPORT SNAPSHOT PRODUCTION PERSISTENCE

## STATUS: PASS (Prompt 59)

**Prompt:** 59  
**Canonical path:** `docs/implementation/REPORT_SNAPSHOT_PRODUCTION_PERSISTENCE.md`  
**Contracts:** [`docs/architecture/REPORT_SNAPSHOT_CONTRACT.md`](../architecture/REPORT_SNAPSHOT_CONTRACT.md) · [`docs/architecture/REPORT_SNAPSHOT_SCHEMA_VERSIONING.md`](../architecture/REPORT_SNAPSHOT_SCHEMA_VERSIONING.md) · [`docs/architecture/REPORT_SNAPSHOT_SOURCE_PINNING.md`](../architecture/REPORT_SNAPSHOT_SOURCE_PINNING.md)  
**Depends on:** Prompt 58 Client Value Story (`3e11da643044b592efe6767c1ca553b23adc51e7`) · Prompt 57 Business Outcomes · Prompt 56 Future Assistant · Prompt 55 Evaluation  
**Base HEAD:** Prompt 58 `3e11da643044b592efe6767c1ca553b23adc51e7`  
**Branch:** `cursor/report-snapshot-production-persistence-ea01`

| Fact | Value |
| --- | --- |
| Model / table | `App\Models\ReportSnapshot` / `report_snapshots` |
| Report type (v1) | `client_value_story` (`ReportType`) |
| Schema | `client_value_story_v1` (`ReportSnapshotSchemaVersion`) |
| Create / Read | `CreateReportSnapshotService` / `ReportSnapshotReadService` |
| Serializer | `ClientValueStorySnapshotSerializer` |
| Live source | Prompt 58 `ClientValueStoryReadService` + `ClientValueStorySourceManifest::fingerprint()` |
| Content checksum | SHA-256 via `ReportSnapshotChecksum` + `CanonicalJson` |
| Consistent read | DB transaction; PostgreSQL `REPEATABLE READ`; SQLite transaction snapshot |
| Idempotency | `idempotency_key` unique |
| Supersedes | Optional `supersedes_snapshot_id` (same Brand + type) |
| PDF / share / delivery | **NOT YET** / Prompt 60 |
| AI / provider calls / ReportSnapshotV2 / SourceManifestV2 | **NONE** |
| Tests | `tests/Feature/ReportSnapshots/ReportSnapshotProductionPersistenceTest.php` (13) |

---

## 1. Purpose

Make frozen Brand → Value → Reports and Customer → Reports into **production-persistent, immutable Report Snapshots** of the Prompt 58 Client Value Story — with server-built content, pinned Source Manifest, deterministic checksums, Assistant historical source authority, and evaluation case keys — **without** PDF/share/email/delivery (Prompt 60), without AI narrative, without provider calls, and without inventing `ReportSnapshotV2` or `SourceManifestV2`.

```text
Prompt 58 live Client Value Story projection
  → Prompt 59 immutable Report Snapshot (pin + freeze)
    → Prompt 60 PDF / share / delivery (handoff only)
```

## 2. Existing Report Primitive Audit

| Primitive | Location | Demo? | Decision |
| --- | --- | --- | --- |
| `ClientValueFixtures` report preview | `app/Support/Demo/` | YES | DEMO_ONLY — catalog brand preview retained |
| Brand → Value → Reports composer | `_report-composer.blade.php` | mixed | Production Brands → create/view Snapshots |
| Customer → Reports list | `CustomerDetail` | was fixtures | Migrated to `ReportSnapshotReadService` |
| Writable `report_snapshots` | — | — | **CREATED** (canonical) |
| `report_deliveries` / share tokens / PDFs | — | — | **NOT CREATED** (Prompt 60) |
| Report builder EAV / widget catalog | — | — | **FORBIDDEN** |
| `ReportSnapshotV2` | — | — | **DO NOT CREATE** |

## 3. Existing Demo Report Audit

Demo catalog brand keeps fixture-based report **preview** for layout continuity. Production numeric Brands never fall back to Demo report history. Customer Reports for production Customers list real Snapshot rows only (`fake_reports: false`). Empty history is truthful empty — not Demo filler.

## 4. Frozen Product Surface Audit

| Surface | Prior source | Prompt 59 source |
| --- | --- | --- |
| Brand → Value → Reports create | fixtures / preview | `CreateReportSnapshotService` (production Brand) |
| Brand → Value → Reports history | fixtures | `ReportSnapshotReadService::listForBrand` |
| Brand → Value → Reports detail | fixtures | `ReportSnapshotReadService::detail` (frozen payload) |
| Customer → Reports | fixtures | `forCustomerReportsPresentation` |
| Demo catalog Reports | fixtures | Preview only — no production Snapshot writes |
| PDF / download / share / email UI | stubs | Shown unavailable; owner `prompt_60` |

Layout preserved. No new top-level nav. No redesign.

## 5. Canonical Report Snapshot Decision

**CREATE** one immutable domain row per Snapshot:

- Model `ReportSnapshot`, table `report_snapshots`
- Enums `ReportType`, `ReportSnapshotSchemaVersion`
- Services `CreateReportSnapshotService`, `ReportSnapshotReadService`
- Support `ClientValueStorySnapshotSerializer`, `ReportSnapshotChecksum`, `CanonicalJson`, `ReportTypeRegistry`

No `ReportSnapshotV2`. No delivery tables. No generic report-builder schema.

## 6. Report Snapshot vs Client Value Story

| Dimension | Client Value Story (P58) | Report Snapshot (P59) |
| --- | --- | --- |
| Nature | Live read projection | Immutable historical pin |
| Mutability | Recomputes on each read | Frozen content + manifest |
| Owner | `ClientValueStoryReadService` | `CreateReportSnapshotService` / Read Service |
| Detail rebuild | N/A | **Forbidden** (`rebuilt_from_live_story: false`) |

Prompt 58 remains live. Prompt 59 never replaces live Story as current truth.

## 7. Report Snapshot vs Activity

Activity is the operational event log. Snapshots are period-scoped historical report artifacts. Activity is not used as Snapshot content source.

## 8. Report Snapshot vs PDF / Delivery

Snapshot persistence ≠ delivery. PDF, download, secure share, email, and recipient tables are **Prompt 60**. Detail DTO exposes `delivery.pdf|download|share|email = false`, `owner: prompt_60`.

## 9. Report Snapshot vs AI Narrative

Zero AI/LLM during create or read. Content is deterministic serialization of Prompt 58 Story DTOs. `ai_assisted: false` always. No provider HTTP.

## 10. Scope

Authorized Customer + Brand. Browser supplies scope/period options only. Server resolves Brand ownership and rejects unauthorized Brand/Customer lists. Cross-Brand Snapshot creation and detail access forbidden.

## 11. Report Type

Bounded enum `ReportType`. V1 ships only `client_value_story`. Unsupported types raise `UNSUPPORTED_REPORT_TYPE`. Not a free-form report catalog.

## 12. Report Type Registry

`ReportTypeRegistry` maps type → allowed scope (`brand`), snapshot schema, source read service (`ClientValueStoryReadService`), manifest type (`client_value_story_manifest_v1`), comparison support (`false` for v1), delivery-may-be-supported-later (`true`), presentation contract.

## 13. Period

Explicit `period_start` / `period_end` (dates). End must be ≥ start (`INVALID_REPORT_PERIOD`). Period drives Prompt 58 Story composition inside the create transaction.

## 14. Comparison Period

Optional `comparison_period_start` / `comparison_period_end`. Invalid range → `INVALID_COMPARISON_PERIOD`. V1 serializer records comparison metadata with `supported: false` and null result — no comparison formula execution.

## 15. Header Snapshot

Frozen header fields on the row and in content: title, customer/brand name snapshots, locale, reporting timezone, report type label, period labels, generated-by display. Brand/Customer renames after create do not mutate historical header.

## 16. Schema Version

`snapshot_schema_version` = `client_value_story_v1`. Code-owned `ReportSnapshotSchemaVersion` enum. Old rows remain readable; new versions never rewrite old rows (see schema versioning contract).

## 17. Content Payload

Typed JSON `content_payload` built only by `ClientValueStorySnapshotSerializer`. Includes schema_version, header, story presentation, findings, opportunities, completed/active work, business outcomes, limitations, claims, status, attribution/causality flags (`false`), section labels, optional comparison stub.

## 18. Source Manifest

`source_manifest_payload` pins Prompt 58 `ClientValueStorySourceManifest` (references only — no full payload copies): finding/opportunity/task IDs, outcome definition IDs, observation revision IDs, limitation codes, period, Brand/Customer. `prompt59_pinnable: true`.

## 19. Source Fingerprint

`source_manifest_fingerprint` = `ClientValueStorySourceManifest::fingerprint()` (SHA-256 over canonical JSON of ordered source identities + period). Create path asserts fingerprint matches live Story manifest.

## 20. Content Checksum

`content_checksum` = SHA-256 of canonical JSON of **content payload only** (`ReportSnapshotChecksum`). Excludes row id, generated_at, created_at, idempotency. Detail verifies unless explicitly skipped in privileged paths.

## 21. Canonical JSON

`CanonicalJson` recursively normalizes associative key order (`ksort`) and encodes with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Used for fingerprints and checksums so semantically equal payloads hash identically.

## 22. Generated By

`generated_by` FK → `users`. Actor is the authenticated operator creating the Snapshot. Required; restrict-on-delete.

## 23. Generated At

`generated_at` timestamp set at create. No `updated_at`. List/history ordered by `generated_at DESC`, `id DESC`.

## 24. Supersedes

Optional `supersedes_snapshot_id` → prior Snapshot. Must exist, same Customer/Brand, same report type. Does **not** mutate or delete the prior row. Used for explicit regeneration after corrections.

## 25. Idempotency

Optional `idempotency_key` (unique, ≤128). Pre-check + `lockForUpdate` inside transaction return the existing row. Different key ⇒ new Snapshot even if period/fingerprint match.

## 26. Immutability

Model blocks `update()` on existing rows and dirty saves of material fields (`REPORT_SNAPSHOT_IMMUTABLE`). Corrections create a new Snapshot (optionally superseding) — never rewrite history.

## 27. Consistent Read Isolation

Create wraps Story read + serialize + insert in `DB::transaction`. PostgreSQL: `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`. SQLite: transaction snapshot semantics. Prevents mid-create source drift across Findings/Outcomes/Tasks reads.

## 28. CreateReportSnapshotService

Canonical write boundary. Rejects browser-supplied `content_payload` / manifest / checksum / fingerprint (`BROWSER_SNAPSHOT_CONTENT_FORBIDDEN`). Builds Story via Prompt 58, serializes, validates, asserts manifest match + Brand consistency + checksum/fingerprint, then inserts. Source read failures → `SNAPSHOT_SOURCE_READ_FAILED` (never freeze fake zeros from technical failure).

## 29. ReportSnapshotReadService

Canonical read boundary. List for Customer/Brand (paginated, auth-filtered). Detail always returns frozen payload — never calls `ClientValueStoryReadService`. Customer Reports presentation builds real history cards + Brand deep-links; no Demo fallback.

## 30. ClientValueStorySnapshotSerializer

`CLIENT_VALUE_STORY_V1` serializer. Freezes Story DTO arrays, strips live-only fields, asserts no executable content, computes checksum + fingerprint. Validate rejects wrong schema/type and attribution/causality true.

## 31. CLIENT_VALUE_STORY_V1 Serialization

Schema id `client_value_story_v1`. Content shape owned by serializer + schema versioning contract. Money/dates/locale rules applied at freeze time (see architecture schema doc).

## 32. Money Semantics

Revenue values frozen as Story outcome strings/decimals from Prompt 57 aggregates. No silent FX. Currency remains Brand Outcome currency. Missing ≠ zero.

## 33. Date Semantics

Period and comparison dates are calendar dates (`Y-m-d`). Finding/Opportunity/Work timestamps frozen as serialized in Story items. Reporting timezone stored on Snapshot for display context.

## 34. Localization

Locale allowlist `en` | `tr` (else `en`). Title default: “Client Value Story — {start} → {end}” / Turkish equivalent. Custom title optional (≤200, stripped tags).

## 35. Old-Schema Readability

`ReportSnapshotSchemaVersion::isReadable()` — v1 readable. Unknown/future unreadables surface `UNSUPPORTED_SNAPSHOT_SCHEMA` without rewriting rows.

## 36. Future-Schema Rules

New schemas may be added as enum cases. Writable-for-new only for current writers. Old rows never rewritten to new schema. No dual-write. No silent upgrade-in-place.

## 37. No Browser-Supplied Content

Browser may send period, locale, title, comparison, timezone, supersedes, idempotency, report_type. Server owns all business truth fields.

## 38. Authorization

Create/list/detail honor `authorizedCustomerIds` / `authorizedBrandIds` when provided. Unauthorized → `UNAUTHORIZED_BRAND` / `UNAUTHORIZED_CUSTOMER`. UI requires signed-in actor for create.

## 39. Tenancy / Cross-Brand Isolation

Snapshot Customer/Brand must match Brand under create. Manifest/content Brand mismatch → `CROSS_BRAND_SOURCE_REF` / `CROSS_BRAND_CONTENT`. Supersedes across Brand forbidden. Detail cannot be read with other Customer auth.

## 40. Customer Multi-Brand History

Customer Reports lists Snapshots across authorized Brands for that Customer. Each row retains Brand name snapshot. Deep-link to Brand → Value → Reports with `snapshot` query param.

## 41. No Blind Aggregation

No cross-Brand Outcome/Story rollup in Customer Reports. Presentation includes `aggregation_note` / `no_blind_aggregation` copy. Brand cards link to per-Brand report surfaces.

## 42. Brand / Customer Name Freeze

`customer_name_snapshot` / `brand_name_snapshot` / `title_snapshot` persist display names at generate time. Later renames do not alter historical Snapshots.

## 43. Outcome Correction After Snapshot

Correcting Business Outcome observations updates live Story; old Snapshot content and revision IDs remain. New Snapshot (new idempotency key, optional supersedes) captures corrected values. Evaluation key `OUTCOME_CORRECTION_AFTER_SNAPSHOT`.

## 44. Finding / Opportunity Freeze

Finding/Opportunity status/title changes after Snapshot do not mutate frozen content arrays. Evaluation key `FINDING_STATE_CHANGE_AFTER_SNAPSHOT`.

## 45. Work / Task Freeze

Completed work titles/status/`completed_at` frozen in payload. Later Task edits do not rewrite Snapshot. Evaluation key `TASK_QA_CHANGE_AFTER_SNAPSHOT` covers QA/Approval projection freeze via Story items.

## 46. QA / Approvals Pinning

QA/Approval projections are part of Prompt 58 Work item serialization when present. Snapshot pins those projected fields; it does not create/mutate QA or Approval domains.

## 47. Formula Version Pinning

Comparison stub may carry `formula_version_id: null` in v1. No Formula Registry execution in Prompt 59. Future comparison support may pin formula versions without rewriting old Snapshots.

## 48. Empty / No-Data Snapshots

Snapshots with no Findings/Work/Outcomes are allowed. Limitations array records truthful gaps. Never invent zeros for missing Outcomes. Technical source failure aborts create.

## 49. Demo Retirement

Production Customer Reports and production Brand report history no longer use Demo fixture report lists. Demo catalog brand retains preview composer without production Snapshot history claims.

## 50. Frozen UI Migration

`BrandShow::createReportSnapshot` / history / detail; `CustomerDetail` reports presentation; `_report-composer.blade.php` production create/view + Prompt 60 unavailable delivery affordances; i18n `operator.reports.*`.

## 51. Assistant Integration

`AssistantSourceClass::ReportSnapshot` + `AssistantCapabilityId::ReportSnapshotLookup`. Historical answers use Snapshot Read Service. Must not override current Business Outcome / Finding canonical domains. Provenance: `ai_used: false`, `overrides_current_canonical_domains: false`.

## 52. Intelligence Evaluation Integration

Prepared keys via `IntelligenceEvaluationCaseCatalog::reportSnapshotPreparedCaseKeys()` — including immutability, outcome correction, finding change, task/QA change, current vs historical, cross-Brand access, manifest integrity, no Demo, no attribution. Exercised by feature tests (not silently rewritten).

## 53. No PDF / Share / Email / Delivery

No `report_pdfs`, `report_recipients`, `report_share_tokens`, `report_deliveries` tables. UI marks delivery unavailable. Prompt 60 owns delivery.

## 54. No AI / Provider Calls

Create/read paths do not call LLM providers or business data providers. No auto Finding/Opportunity/Task/Outcome writes during Snapshot create.

## 55. No ReportSnapshotV2 / SourceManifestV2

Forbidden class/table names asserted in tests. Extend via schema version enum + serializer — not parallel V2 entities.

## 56. Privacy

Snapshots are Brand-confidential historical reports. Revenue/Outcomes remain Brand-scoped. No full payload dumps in application logs. Executable content needles rejected.

## 57. Security

Mass-assignment of content/manifest/checksum from browser rejected. Auth lists enforced. Immutable model guards. Cross-Brand supersedes/content rejected. Restrict FKs on Customer/Brand/User.

## 58. Performance

Indexes: customer+generated_at, brand+generated_at, scope+type, brand+period, schema, fingerprint, checksum. List selects omit heavy payloads. Pagination capped (1–100, default 20). Story composition reuses Prompt 58 set-based reads inside one transaction.

## 59. Tests

`tests/Feature/ReportSnapshots/ReportSnapshotProductionPersistenceTest.php` — 13 cases: registry/boundaries, server-built create, auth/period, immutability, outcome correction, finding/opportunity freeze, work/brand rename freeze, idempotency, checksum tamper, customer multi-Brand history, no-data/no-delivery, Assistant historical vs current, no side-effect domain writes.

## 60. Prompt 60 Handoff

Own PDF rendering, download, secure share tokens, email/delivery, recipient audit. Consume immutable Snapshot rows as inputs. Must not mutate Snapshot content, invent live rebuild, or claim delivery inside Prompt 59.

## 61. Definition of Done

| Gate | Status |
| --- | --- |
| Base Prompt 58 HEAD `3e11da643044b592efe6767c1ca553b23adc51e7` recorded | YES |
| Branch `cursor/report-snapshot-production-persistence-ea01` | YES |
| `report_snapshots` + `ReportSnapshot` immutable model | YES |
| `client_value_story` / `client_value_story_v1` only | YES |
| Create + Read services + serializer + checksum + registry | YES |
| Consistent read isolation + idempotency + supersedes | YES |
| Customer/Brand UI real history; Demo preview retained | YES |
| Assistant ReportSnapshot source + lookup capability | YES |
| Evaluation prepared case keys present | YES |
| No PDF/share/delivery/AI/provider/V2 | YES |
| Feature tests 13 passing | YES |
| Sections 1–61 + matrices 345–362 + Reality Matrix 363 present | YES |
| Architecture contracts written | YES |
| Prompt 60 remains delivery owner | YES |

---

## MANDATORY MATRICES (345–362)

## 345. Existing Report Primitive Matrix

| Primitive | Location | Semantic | Decision |
| --- | --- | --- | --- |
| Demo report fixtures | `ClientValueFixtures` | Preview narrative | DEMO_ONLY |
| Brand Reports UI | `_report-composer` / `BrandShow` | Operator surface | MIGRATE production data path |
| Customer Reports UI | `CustomerDetail` | History list | MIGRATE to Snapshots |
| `report_snapshots` | migration `2026_08_16_023850` | Canonical immutable pin | CREATE / KEEP |
| Delivery / PDF / share tables | — | Delivery | PROMPT_60 |
| Report builder EAV | — | Generic builder | FORBIDDEN |
| `ReportSnapshotV2` | — | Parallel entity | DO NOT CREATE |

## 346. Frozen Report Surface Matrix

| Surface | Prompt 59 data owner | Demo fallback |
| --- | --- | --- |
| Brand create Snapshot | `CreateReportSnapshotService` | NO (production Brand only) |
| Brand Snapshot history | `listForBrand` | NO |
| Brand Snapshot detail | `detail` frozen payload | NO |
| Customer Snapshot history | `forCustomerReportsPresentation` | NO |
| Demo catalog preview | fixtures | YES (preview only) |
| PDF / share / email controls | Prompt 60 unavailable | N/A |

## 347. Report Type Registry Matrix

| Type id | Scope | Schema | Source read | Comparison v1 | Delivery later |
| --- | --- | --- | --- | --- | --- |
| `client_value_story` | brand | `client_value_story_v1` | `ClientValueStoryReadService` | false | true |

## 348. Snapshot vs Live Story Matrix

| Concern | Live Story (P58) | Snapshot (P59) |
| --- | --- | --- |
| Recompute on read | YES | NO |
| Pin observation revisions | refs only | YES (frozen) |
| Detail uses Read Story service | YES | NO |
| Attribution / causality | false | false (validated) |
| Writable truth | NO | NO (immutable row) |

## 349. Header Field Matrix

| Field | Source at create | Mutable later |
| --- | --- | --- |
| `title_snapshot` | custom title or locale default | NO |
| `customer_name_snapshot` | Customer name | NO |
| `brand_name_snapshot` | Brand name | NO |
| `locale` | input allowlist | NO |
| `reporting_timezone` | input / app timezone | NO |
| `period_start` / `period_end` | input dates | NO |
| comparison dates | optional input | NO |

## 350. Content Payload Matrix

| Key | Required | Notes |
| --- | --- | --- |
| `schema_version` | YES | `client_value_story_v1` |
| `report_type` | YES | `client_value_story` |
| `header` | YES | frozen display header |
| `story` | YES | presentation array |
| `findings` / `opportunities` | YES | arrays (may be empty) |
| `completed_work` / `active_work` | YES | Task-backed |
| `business_outcomes` | YES | Prompt 57 kinds |
| `limitations` / `claims` / `status` | YES | Story honesty |
| `attribution_established` | YES | must be false |
| `causality_established` | YES | must be false |
| `ai_assisted` | YES | false |
| `comparison` | optional | stub; `supported: false` |

## 351. Source Manifest Matrix

| Field | Full copy? | Purpose |
| --- | --- | --- |
| `finding_ids` | NO (ids) | Pin observed Findings |
| `opportunity_ids` | NO (ids) | Pin potential |
| `task_ids` | NO (ids) | Pin work |
| `outcome_definition_ids` | NO (ids) | Pin definitions |
| `outcome_observation_revision_ids` | NO (ids) | Pin exact Outcome revisions |
| `limitation_codes` | codes | Honesty flags |
| `full_payload_copies` | false | Contract invariant |
| `prompt59_pinnable` | true | Handoff from P58 |

## 352. Fingerprint / Checksum Matrix

| Artifact | Algorithm | Input | Excludes |
| --- | --- | --- | --- |
| Source fingerprint | SHA-256 | Canonical manifest identities + period | Snapshot id, generated_at, session |
| Content checksum | SHA-256 | Canonical content payload | Row metadata, idempotency |
| Equality | `hash_equals` on verify | — | Soft compare forbidden |

## 353. Immutability Matrix

| Operation | Allowed |
| --- | --- |
| Insert new Snapshot | YES |
| Update content/period/manifest/checksum | NO (`REPORT_SNAPSHOT_IMMUTABLE`) |
| Soft-delete content rewrite | NO |
| Superseding new Snapshot | YES (new row) |
| Live domain correction | YES (does not touch old Snapshot) |

## 354. Idempotency / Supersedes Matrix

| Scenario | Result |
| --- | --- |
| Same `idempotency_key` retry | Return existing row |
| New key, same period | New row |
| `supersedes_snapshot_id` same Brand+type | Link only; prior unchanged |
| Supersedes missing / cross-Brand / type mismatch | Validation error |

## 355. Consistent Read Isolation Matrix

| Driver | Mechanism |
| --- | --- |
| PostgreSQL | Transaction + `REPEATABLE READ` |
| SQLite | Transaction snapshot |
| Failure mid-read | Abort; `SNAPSHOT_SOURCE_READ_FAILED` |

## 356. Authorization / Tenancy Matrix

| Check | Error code |
| --- | --- |
| Brand not in authorized list | `UNAUTHORIZED_BRAND` |
| Customer not in authorized list | `UNAUTHORIZED_CUSTOMER` |
| Browser content fields | `BROWSER_SNAPSHOT_CONTENT_FORBIDDEN` |
| Manifest Brand mismatch | `CROSS_BRAND_SOURCE_REF` |
| Content Brand mismatch | `CROSS_BRAND_CONTENT` |
| Detail other Customer | `UNAUTHORIZED_CUSTOMER` |

## 357. Demo Retirement Matrix

| Surface | Demo after P59 |
| --- | --- |
| Production Brand Snapshot history | NONE |
| Production Customer Reports | REAL rows only |
| Demo catalog report preview | RETAINED |
| Fake report list fallback on empty | FORBIDDEN |

## 358. UI Surface Matrix

| Action | Component | Service |
| --- | --- | --- |
| Create Snapshot | `BrandShow::createReportSnapshot` | Create service |
| List Brand history | Brand Value Reports | `listForBrand` |
| View detail | `?snapshot=` | `detail` |
| Customer history | Customer Reports tab | `forCustomerReportsPresentation` |
| PDF/share/email | composer unavailable | Prompt 60 |

## 359. Assistant Source Matrix

| Question class | Source class | Capability |
| --- | --- | --- |
| Historical report / last Snapshot | `report_snapshot` | `report_snapshot_lookup` |
| Current Qualified Leads | `business_outcome` | Fact lookup (P57) |
| Live Value Story summary | `client_value_story` | P58 capability |
| Snapshot overrides current Outcome? | — | NO |

## 360. Evaluation Case Matrix

| Case key | Intent |
| --- | --- |
| `REPORT_SNAPSHOT_REMAINS_IMMUTABLE` | Update blocked |
| `OUTCOME_CORRECTION_AFTER_SNAPSHOT` | Old freeze / new capture |
| `FINDING_STATE_CHANGE_AFTER_SNAPSHOT` | Finding freeze |
| `TASK_QA_CHANGE_AFTER_SNAPSHOT` | Work/QA freeze |
| `CURRENT_VS_HISTORICAL_REPORT` | Assistant distinction |
| `CROSS_BRAND_REPORT_ACCESS` | Auth isolation |
| `SNAPSHOT_SOURCE_MANIFEST_INTEGRITY` | Fingerprint/manifest |
| `SNAPSHOT_NO_DEMO` | No Demo production history |
| `SNAPSHOT_NO_ATTRIBUTION` | Flags remain false |

## 361. Delivery Boundary Matrix (Prompt 60)

| Capability | Prompt 59 | Prompt 60 |
| --- | --- | --- |
| Persist Snapshot | YES | consume |
| PDF bytes | NO | YES (future) |
| Secure share token | NO | YES (future) |
| Email / recipients | NO | YES (future) |
| Mutate Snapshot content | NO | NO |

## 362. Domain Write / AI Boundary Matrix

| Action during create | Allowed |
| --- | --- |
| Insert `report_snapshots` row | YES |
| Write Findings / Opportunities / Tasks / Outcomes | NO |
| LLM / AI provider call | NO |
| Business provider HTTP | NO |
| Auto Experience / Sector / Notification | NO |

## 363. Reality Matrix

| Capability | Status |
| --- | --- |
| Report Snapshot domain (`report_snapshots`) | **REAL** |
| `CreateReportSnapshotService` / `ReportSnapshotReadService` | **REAL** |
| `client_value_story` + `client_value_story_v1` | **REAL** |
| Source Manifest pin + fingerprint | **REAL** |
| Content checksum (SHA-256 / CanonicalJson) | **REAL** |
| Immutability / idempotency / supersedes | **REAL** |
| Consistent read isolation | **REAL** |
| Customer + Brand production UI history | **REAL** |
| Demo catalog preview (non-production history) | **DEMO RETAINED** |
| Assistant `ReportSnapshot` source + lookup | **REAL** |
| Evaluation prepared case keys | **REAL** |
| PDF / share / email / delivery | **NOT YET / Prompt 60** |
| AI narrative / provider calls | **NONE** |
| `ReportSnapshotV2` / `SourceManifestV2` | **NONE** |
| Comparison formula execution | **NOT YET** (`supported: false`) |
| Feature tests (13) | **REAL** |

See also Milestone 5 Capability Reality Matrix (Reports / Client Value rows → update toward REAL for Snapshot persistence; delivery remains NOT YET).
