# Report Artifact Contract

> Prompt 60 — immutable PDF (or future artifact) bound to a Report Snapshot.  
> Implementation: `App\Models\ReportArtifact`, `GenerateReportPdfService`, `ReportPdfRenderer`, `ReportPdfRendererVersion`.  
> Config: `config/report_delivery.php` → `pdf.*`  
> Parent snapshot: [`REPORT_SNAPSHOT_CONTRACT.md`](REPORT_SNAPSHOT_CONTRACT.md)  
> Delivery consumers: [`REPORT_DELIVERY_CONTRACT.md`](REPORT_DELIVERY_CONTRACT.md), [`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`](AUTHENTICATED_REPORT_SHARE_CONTRACT.md)

## Canonical rule

A **Report Artifact** is a durable, **immutable** binary rendering of an existing Report Snapshot. Artifacts are generated **only** from Snapshot `content_payload` (+ header fields). They never query live Findings, Opportunities, Outcomes, or `ClientValueStoryReadService`. Artifact rows are separate from Snapshots — PDF generation must not mutate Snapshot content.

---

## Artifact Type

| Field | Contract |
| --- | --- |
| Column | `artifact_type` (string ≤16) |
| Enum | `ReportArtifactType` |
| V1 value | `pdf` only |
| Unsupported | No DOCX/HTML-as-artifact / ZIP packages in Prompt 60 |

---

## Snapshot Binding

| Field | Contract |
| --- | --- |
| `report_snapshot_id` | Required FK → `report_snapshots` (`restrictOnDelete`) |
| `snapshot_schema_version` | Copied from Snapshot at generate time (string) |
| `content_checksum` | Copied from Snapshot `content_checksum` at generate time |
| Unique | `(report_snapshot_id, renderer_version)` — one artifact row per Snapshot per renderer |

Re-rendering the same Snapshot + renderer returns the existing row when the storage file still exists.

---

## Renderer Version

| Field | Contract |
| --- | --- |
| Column | `renderer_version` (string ≤64) |
| V1 constant | `client_value_story_pdf_v1` (`ReportPdfRendererVersion::CLIENT_VALUE_STORY_PDF_V1`) |
| Config | `report_delivery.pdf.renderer_version` |
| Distinct from | Snapshot schema `client_value_story_v1` — renderer may evolve independently |

New renderer versions create **new** artifact rows; old files are not rewritten in place under a new version string.

---

## PDF Engine

| Field | Contract |
| --- | --- |
| Package | `barryvdh/laravel-dompdf` `^3.1` (DomPDF 3.x) |
| Entry | `ReportPdfRenderer` → `Pdf::loadHTML(...)->setPaper('a4')->output()` |
| Template | `resources/views/reports/client-value-story-pdf.blade.php` |
| Validation | Output must be non-empty and start with `%PDF` else `PDF_GENERATION_FAILED` |
| Live rebuild | **Forbidden** |

---

## Storage

| Field | Contract |
| --- | --- |
| `storage_disk` | Default `local` via `REPORT_PDF_DISK` / `report_delivery.pdf.disk` |
| Disk root | Laravel `local` disk → `storage/app/private` (private, not public web root) |
| `storage_path` | `{directory}/{snapshotId}/{rendererVersion}/{sha256}.pdf` under `report-artifacts` |
| `mime_type` | `application/pdf` |
| `byte_size` | Length of stored bytes |
| Public URL | **None** — served only via authenticated controllers |

---

## Checksums

| Field | Contract |
| --- | --- |
| `content_checksum` | Snapshot content integrity pin (SHA-256 hex) |
| `file_checksum` | SHA-256 of PDF bytes at write time |
| Stream verify | `GenerateReportPdfService::streamBytes` re-hashes bytes; mismatch → `ARTIFACT_CHECKSUM_MISMATCH` |
| Missing file | `ARTIFACT_MISSING_OR_CORRUPT` |

Not a PKI signature.

---

## Generation Metadata

| Field | Contract |
| --- | --- |
| `generated_by` | Nullable FK → `users` |
| `generated_at` | Required timestamp |
| `created_at` | Insert time; **no** `updated_at` (`$timestamps = false`) |
| `idempotency_key` | Optional unique string for generate callers |

---

## Immutability

| Rule | Enforcement |
| --- | --- |
| No in-place metadata rewrite | `ReportArtifact::update()` throws `REPORT_ARTIFACT_IMMUTABLE` when `$this->exists` |
| Broken file recovery | Service may delete the row and recreate under the same Snapshot+renderer uniqueness (not a silent content edit of a healthy artifact) |
| Snapshot mutation | Forbidden — Artifact never writes Snapshot columns |

---

## Access Paths

| Path | Auth | Owner |
| --- | --- | --- |
| `POST /reports/snapshots/{id}/pdf` | Operator `auth` | `ReportArtifactDownloadController::generateAndDownload` |
| `GET /reports/artifacts/{id}/download` | Operator `auth` | `ReportArtifactDownloadController::download` |
| Share PDF | Share session + `pdf_download` permission | `ReportShareController::downloadPdf` |

---

## Forbidden

- Browser-supplied PDF bytes
- Live Story / Findings / Outcomes queries inside the renderer
- Public disk / unauthenticated permanent PDF URLs
- Email PDF attachment as the default delivery channel (see Delivery contract)
- `ReportArtifactV2` / alternate artifact entity trees
- Claiming VOID Snapshot semantics (VOID does not exist on `ReportSnapshot` yet)
