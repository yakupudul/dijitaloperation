# Business Outcome Contract

> Prompt 57 — canonical aggregate Business Outcome persistence.  
> Implementation: `app/Services/BusinessOutcomes/*`, `app/Models/BusinessOutcome*`, enums under `app/Enums/BusinessOutcome*`.  
> CSV: [`BUSINESS_OUTCOME_CSV_IMPORT_CONTRACT.md`](BUSINESS_OUTCOME_CSV_IMPORT_CONTRACT.md)  
> Narrative: Prompt 58 owns Client Value Story.

## Canonical rule

MoxDOP represents Business Outcomes as durable Brand-owned **aggregate** business results observed or reported for **explicit periods**.

Business Outcome is **not** a CRM record, Lead, Patient, Deal, Appointment, Invoice, Payment, Goal, Conversion Goal, provider conversion, Evidence row, Brand Experience, or Client Value Story.

---

## BusinessOutcomeDefinition

| Field | Contract |
| --- | --- |
| Identity | `business_outcome_definitions.id` stable surrogate |
| Ownership | `customer_id` + `brand_id` required; Customer must match Brand |
| Kind | Closed enum: `qualified_lead`, `consultation`, `sale_or_patient`, `revenue` |
| Unit | Derived from kind: COUNT for first three; MONEY for revenue. Browser cannot override |
| Code | Brand-scoped unique string (default = kind value). Used by CSV `outcome_code` |
| Display label | Operator-facing label; changing label does not change kind |
| Semantic definition | Required human-confirmed Brand meaning (not global seed truth) |
| Reporting timezone | Optional explicit IANA timezone when Brand needs it |
| Currency | Required ISO 4217 for REVENUE definitions; forbidden for COUNT kinds |
| Status | `active` \| `archived` |
| Definition version | Integer; material semantic revise archives prior and creates new ACTIVE definition |
| Brand goal link | Optional explicit `brand_goal_id` (same Brand only). Never text-matched |
| Created by | Optional User |

**V1 constraint:** one ACTIVE definition per kind per Brand.

---

## BusinessOutcomeObservation

| Field | Contract |
| --- | --- |
| Identity | Stable row + `semantic_key` = hash(definition_id, period_start, period_end) |
| Ownership | Same Customer/Brand as Definition |
| Definition | FK to Definition; must belong to same Brand |
| Period | Explicit `period_start` / `period_end` dates; start ≤ end |
| Status | `active` \| `invalidated` |
| Current revision | FK to Observation Revision (one current truth) |

Semantic identity does **not** include value, source, user, or CSV row number.

One Brand + Definition + period ⇒ one canonical current truth. Corrections create revisions.

---

## BusinessOutcomeObservationRevision

| Field | Contract |
| --- | --- |
| Value | `value_numeric` DECIMAL(18,4); COUNT also stores `value_count` integer |
| Currency | Present for MONEY; null for COUNT |
| Completeness | `complete` \| `partial` \| `unknown` |
| Source kind | `manual` \| `csv_import` |
| Provenance | `recorded_by`, `recorded_at`; optional import batch + row number + fingerprint |
| Correction reason | Required on material correction |
| Snapshots | `definition_version_snapshot`, `semantic_definition_snapshot` |

Same fingerprint reimport ⇒ idempotent no-op. Different value same semantic key ⇒ `CORRECTION_REQUIRED` (no silent overwrite).

---

## Kind / unit / currency

| Kind | Unit | Currency | Integer | Negative V1 |
| --- | --- | --- | --- | --- |
| `qualified_lead` | count | no | yes | no |
| `consultation` | count | no | yes | no |
| `sale_or_patient` | count | no | yes | no |
| `revenue` | money | required ISO 4217 | n/a (decimal) | no |

No binary float persistence. No silent FX. No Google Ads / Meta account currency inference.

---

## Completeness

| State | Meaning |
| --- | --- |
| COMPLETE | Reporter asserts aggregate is complete for definition + period (not audited financial truth) |
| PARTIAL | Known incomplete |
| UNKNOWN | Completeness not confirmed |

UNKNOWN / PARTIAL never silently become COMPLETE. Missing observation ≠ value 0.

---

## Aggregation (`BusinessOutcomeAggregateService`)

Inputs: Brand, Definition/kind, requested date range.

Outputs (`BusinessOutcomeAggregateResult`): requested period, covered periods, value (or null), unit, currency, status, worst completeness, gaps, revision ids, limitations.

Statuses include: `complete`, `partial`, `unknown_completeness`, `no_data`, `incompatible_currency`, `overlap_conflict`, `unsupported_grain`, `invalidated_source`.

Rules:

- Sum only non-overlapping compatible observations
- Never prorate monthly into subperiods
- Never interpolate / extrapolate / zero-fill gaps
- Never silently sum mixed currencies
- NO_DATA ⇒ value null; COMPLETE zero ⇒ value `0`

---

## Forbidden

- CRM entities / person-level fields
- Provider auto-mapping / AI mapping / fuzzy label mapping
- Channel attribution / ROI / ROAS / CAC / LTV
- Auto Finding / Opportunity / Recommendation / Task / Experience / Sector Learning
- BusinessOutcomeV2 or generic metric EAV
