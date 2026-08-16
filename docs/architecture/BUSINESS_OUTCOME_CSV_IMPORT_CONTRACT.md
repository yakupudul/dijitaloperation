# Business Outcome CSV Import Contract

> Prompt 57 — strict aggregate-only CSV for Brand-scoped Business Outcomes.  
> Service: `BusinessOutcomeCsvImportService`  
> Domain contract: [`BUSINESS_OUTCOME_CONTRACT.md`](BUSINESS_OUTCOME_CONTRACT.md)

## Purpose

Allow operators to import **aggregate period totals** for existing Business Outcome Definitions.

CSV is **not** a CRM import, lead list, patient list, deal export, or invoice dump.

## Brand scope

Operator selects Customer/Brand in the application **before** import.

- CSV must **not** contain Brand or Customer columns.
- Rows cannot redirect to another Brand.
- `outcome_code` resolves only to ACTIVE definitions owned by the selected Brand.

## Strict headers (allowlist)

Exactly these headers (order flexible; extras rejected):

| Header | Required | Type | Notes |
| --- | --- | --- | --- |
| `outcome_code` | yes | string | Exact Brand definition code (e.g. `qualified_lead`) |
| `period_start` | yes | `YYYY-MM-DD` | Inclusive |
| `period_end` | yes | `YYYY-MM-DD` | Inclusive; ≥ start |
| `value` | yes | canonical decimal/integer | Locale-independent (e.g. `31500.00`) |
| `currency` | conditional | ISO 4217 | Required for REVENUE; empty for COUNT |
| `completeness` | yes | token | `complete` \| `partial` \| `unknown` |

Unknown columns ⇒ `UNKNOWN_COLUMN` (batch blocked).

## Forbidden columns (privacy / CRM boundary)

Rejected as unknown headers (non-exhaustive intent):

`name`, `first_name`, `last_name`, `email`, `phone`, `patient`, `patient_id`, `lead`, `lead_id`, `contact`, `contact_id`, `deal`, `deal_id`, `appointment`, `invoice`, `address`, `medical_record`, `treatment`, channel attribution columns.

Person-level rows are never accepted.

## Encoding / limits

| Constraint | Value |
| --- | --- |
| Encoding | UTF-8 |
| Max file size | 512_000 bytes (`MAX_BYTES`) |
| Max rows | 500 (`MAX_ROWS`) |
| Formats | CSV only (no XLSX / ZIP) |
| MIME | Validated with extension; client MIME not trusted alone |

## Flow

1. **Upload / parse**
2. **Validate** — row-level diagnostics; no observation writes
3. **Preview** — read-only (`writes = 0`)
4. **Explicit commit** — atomic; entire batch must be valid

Partial commit of 93/100 rows is **not** V1 policy.

## Validation errors (examples)

| Code | Meaning |
| --- | --- |
| `UNKNOWN_COLUMN` | Non-allowlisted header |
| `UNKNOWN_OUTCOME_CODE` | Code not an active Brand definition |
| `INVALID_PERIOD` | Bad dates or end < start |
| `COUNT_MUST_BE_INTEGER` | Decimal count |
| `INVALID_COUNT` / negative | Out of range |
| `REVENUE_CURRENCY_REQUIRED` | Missing currency on money row |
| `CURRENCY_MISMATCH` | Differs from definition currency |
| `INVALID_COMPLETENESS` | Missing/unknown token |
| `OVERLAPPING_PERIOD` | Conflicts with active observation |
| `DUPLICATE_ROW` | Duplicate semantic key in file |
| `CORRECTION_REQUIRED` | Same period, different value vs current |

No raw exceptions to operators. Do not log whole CSV contents.

## Idempotency

Row fingerprint includes Brand, Definition, period, value, currency, completeness, import source identity.

- Re-upload same valid file ⇒ no duplicate observations
- Same semantic observation + same value ⇒ no-op
- Same semantic observation + different value ⇒ correction conflict (not silent overwrite)

## Batch provenance

`business_outcome_import_batches` stores status, checksum, Brand, User, counts, validated/committed timestamps, optional idempotency key.

CSV bytes are not stored in the database.

## Security

| Rule | Status |
| --- | --- |
| Brand permission required | YES |
| Cross-Brand import | FORBIDDEN |
| Formula injection neutralization for preview cells | Required when rendering |
| Credentials / provider tokens in CSV | Not accepted |
| Raw CSV in application logs | FORBIDDEN |

## Explicit non-goals

- Creating definitions from CSV
- Fuzzy matching outcome codes
- Provider conversion import
- Person-level CRM sync
- Channel columns / attribution
