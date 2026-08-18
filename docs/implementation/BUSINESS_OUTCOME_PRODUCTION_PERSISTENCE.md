# BUSINESS OUTCOME PRODUCTION PERSISTENCE

**Prompt:** 57  
**Status:** PASS  
**Branch:** `cursor/business-outcome-production-persistence-ea01`  
**Base:** Prompt 56 HEAD `a2e089a06e784e055b88fcbfeb4cb3c15de9a52c`

## 1. Purpose

Make Demo Qualified Lead / Consultation / Sale·Patient / Revenue into production **Brand-owned aggregate** Business Outcomes with manual + strict CSV entry, revision-safe corrections, deterministic aggregation, and Assistant source authority — **without** becoming a CRM and without Client Value Story (Prompt 58) or Report Snapshots (Prompt 59).

Contracts: [`docs/architecture/BUSINESS_OUTCOME_CONTRACT.md`](../architecture/BUSINESS_OUTCOME_CONTRACT.md), [`docs/architecture/BUSINESS_OUTCOME_CSV_IMPORT_CONTRACT.md`](../architecture/BUSINESS_OUTCOME_CSV_IMPORT_CONTRACT.md).

## 2. Existing Business Outcome Primitive Audit

| Primitive | Location | Aggregate? | Demo? | Decision |
| --- | --- | --- | --- | --- |
| `BusinessOutcomeFixtures` | `app/Support/Demo/` | fake totals | YES | DEMO_ONLY — retired from production Brand Value reads |
| `DemoState` outcome overrides | session | fake | YES | DEMO_ONLY — `saveBusinessOutcomes` no longer persists fakes |
| Brand Value cards | `_brand-value.blade.php` + `BrandShow` | display | was Demo | Migrated data source → `BusinessOutcomeReadService` |
| `ClientValueFixtures` story numbers | Demo | projection | YES | CLIENT_VALUE_PROJECTION — **Prompt 58** |
| Provider conversions (Ads/Meta/GA4) | collectors / pool | provider | NO | PROVIDER_CONVERSION_NOT_BUSINESS_OUTCOME |
| Brand Goals / Conversion Goals | Prompt 37 | intent | NO | CONVERSION_GOAL_NOT_BUSINESS_OUTCOME |
| Brand Experience later outcome text | Prompt 52 | narrative | NO | BRAND_EXPERIENCE_NOT_BUSINESS_OUTCOME; optional revision pin added |
| Lead/Patient/Deal models | — | — | — | NONE (CRM_LIKE forbidden) |
| Production BO models | **CREATED** | YES | NO | CANONICAL |

No prior production `business_outcomes` table. `CommercialGrowthIntelligenceTest` updated: BO tables allowed; CRM tables still forbidden.

## 3. Existing Value / Demo Audit

| Surface | Current source | Demo? | Prompt57 migrate? | Prompt58 owner? |
| --- | --- | --- | --- | --- |
| Brand → Value → outcomes | `BusinessOutcomeReadService::forValueSurface` (numeric Brand) / empty for Demo catalog id | Demo retired | YES (data) | NO |
| Brand → Value → story | `ClientValueFixtures` | YES | NO | YES |
| Brand → Value → overview summary business numbers | ClientValueFixtures | YES | NO | YES |
| Reports composer | ClientValueFixtures | YES | NO | YES / Prompt 59 |
| Dashboard | no BO CRM | — | NO | — |

Frozen IA / design unchanged. Empty state: “No reported Business Outcome data for this period.”

## 4. Canonical Business Outcome Decision

**CREATE** canonical domain:

- `BusinessOutcomeDefinition`
- `BusinessOutcomeObservation`
- `BusinessOutcomeObservationRevision`
- `BusinessOutcomeImportBatch`

One truth. **No BusinessOutcomeV2.** No generic metric EAV.

## 5. Business Outcome vs Goal

Goal (Prompt 37) = intended business direction. Business Outcome = reported aggregate result for a period. Optional explicit `brand_goal_id` only; never required; never text-matched.

## 6. Business Outcome vs Conversion Goal

Conversion Goal = desired conversion. Business Outcome Definition = measured reported business result. Distinct identities.

## 7. Business Outcome vs Provider Conversion

Google Ads conversion / Meta lead / GA4 key event / form submit / conversion value **never** auto-map to Qualified Lead / Consultation / Patient / Revenue.

## 8. Business Outcome vs Evidence

BO is canonical business data. No automatic Evidence mirroring. Future promotion must pin exact Observation Revision + completeness/currency/source.

## 9. Business Outcome vs Brand Experience

Optional same-Brand pin: `brand_experience_revisions.business_outcome_observation_revision_id`. Observational only; causality always `causality_not_established`. No auto remapping of old Experiences.

## 10. Business Outcome vs CRM

No Lead / Contact / Patient / Deal / Pipeline / Appointment / Invoice / Payment entities or nav.

## 11. Aggregate-Only Decision

Qualified Lead / Consultation / Sale·Patient = period counts. Revenue = period money total. No person-level rows.

## 12. Canonical Outcome Kinds

`qualified_lead`, `consultation`, `sale_or_patient`, `revenue` via `BusinessOutcomeKind` + `BusinessOutcomeKindRegistry`.

## 13. Qualified Lead

COUNT aggregate. Default label “Qualified Lead”. Semantics Brand-confirmed.

## 14. Consultation

COUNT. Definition must state booked vs attended vs completed — not assumed.

## 15. Sale / Patient

COUNT. Display may say “Patient” or “Sale”; kind remains `sale_or_patient`.

## 16. Revenue

MONEY + ISO currency. Basis (gross/net/collected/…) is semantic definition text — not an accounting subsystem.

## 17. Business Outcome Definition

See architecture contract. Brand-owned; Customer consistency enforced.

## 18. Definition Semantics

`semantic_definition` required. Templates may seed placeholder confirmation text; not universal truths.

## 19. Definition Versioning

Material revise: archive old ACTIVE, create new definition_version. Observations snapshot definition version + semantic text.

## 20. Goal / Conversion Goal Relationship

Optional explicit FK only. No auto relation. No provider-event mapping.

## 21. Observation Identity

Brand + Definition + period_start + period_end (`semantic_key`).

## 22. Observation Revision

History-safe; one current revision; corrections require reason.

## 23. Reporting Period

Explicit dates. Never `created_at`. Never free-text “this month” stored.

## 24. Reporting Timezone

Optional on Definition; do not guess from Customer address.

## 25. Value Semantics

Counts: non-negative integers. Revenue: DECIMAL(18,4) ≥ 0. Zero allowed when explicit.

## 26. Currency

Revenue requires matching definition currency. No silent FX. No Ads account currency inference.

## 27. Completeness

`complete` | `partial` | `unknown`. No confidence score. No default UNKNOWN→COMPLETE.

## 28. Manual Entry

`BusinessOutcomeObservationService::record` — authorized Brand/Definition/period/value/completeness/currency; `recorded_by` + Manual source.

## 29. CSV Import

`BusinessOutcomeCsvImportService` — Brand-scoped, strict schema, preview then atomic commit.

## 30. CSV Schema

Allowlist: `outcome_code,period_start,period_end,value,currency,completeness`.

## 31. CSV Security

UTF-8; size/row limits; private handling; no whole-file logging; unknown columns rejected.

## 32. Import Validation

Before commit; row-level codes; batch blocked on any error.

## 33. Import Preview

Read-only; `writes = 0`.

## 34. Import Idempotency

Checksum + row fingerprints; reimport does not duplicate.

## 35. Import Atomicity

V1: all-or-nothing commit.

## 36. Corrections

`correct()` creates new revision; old retained; aggregate uses current only.

## 37. Invalidation

`invalidate()` excludes from aggregate; prefer over hard delete.

## 38. Overlapping Periods

Concurrency-safe lock; overlapping Brand-total observations rejected.

## 39. Aggregation

`BusinessOutcomeAggregateService` — set-based compatible non-overlapping sums.

## 40. Coverage

Gaps listed; PARTIAL_COVERAGE / UnsupportedGrain when monthly cannot answer subperiod.

## 41. Missing vs Zero

NO_DATA null ≠ COMPLETE `0`.

## 42. No Proration

Monthly never prorated to arbitrary subperiod.

## 43. No Channel Attribution

No channel columns; Brand-level totals only.

## 44. No ROI / ROAS

Prompt 57 does not compute ROI/ROAS/CAC/LTV. Formula Registry later.

## 45. Evidence Boundary

No auto Evidence spam.

## 46. Brand Experience Boundary

Optional exact revision pin; no causality; no auto Experience create on BO write.

## 47. Sector Memory Boundary

No direct BO→Sector. Prompt 53 Brand Experience qualification remains.

## 48. Future Assistant Integration

`AssistantSourceClass::BusinessOutcome` + `BusinessOutcomeLookup` capability. Deterministic via Read Service. No provider conversion fallback. No LLM required for fact lookup.

## 49. Frozen Brand Value Surface

Outcomes cards read real aggregates / empty state. Story/overview narrative remains Demo until Prompt 58.

## 50. Demo Retirement

Fake outcome numbers not migrated. Kind labels remain valid templates. Runtime Demo fallback removed for outcomes cards.

## 51. Authorization

Reuse Brand/Customer authorization. Server selects Brand for CSV.

## 52. Tenancy

Cross-Brand and cross-Customer forbidden. Same-Customer Brands isolated.

## 53. Privacy

Aggregate-only; no PII/health required; revenue Brand-confidential; CSV unknown columns rejected.

## 54. Security

No mass-assignment of Customer/current revision/source; forged Brand refs rejected.

## 55. Performance

Indexes on customer/brand/definition/period/status/import; paginated history; streamed CSV parse bounds.

## 56. Tests

`tests/Feature/BusinessOutcomes/BusinessOutcomeProductionPersistenceTest.php` covers kinds/CRM boundary, manual, missing vs zero, currency, overlap, corrections, CSV privacy/idempotency, aggregation/no-proration, brand isolation, Assistant source, Experience lineage, no auto domain writes.

## 57. Reality Matrix

| Capability | State |
| --- | --- |
| Business Outcome Domain | REAL |
| Definitions / four kinds | REAL |
| Manual + CSV | REAL |
| Corrections / Revisions / Coverage / Completeness | REAL |
| Aggregation / Missing vs Zero / Currency | REAL |
| Silent FX / Provider mapping / Attribution | FORBIDDEN |
| CRM / Leads / Patients / Deals / Pipelines / Appointments / Invoices / Payments | NOT IMPLEMENTED |
| Auto Finding/Opportunity/Recommendation/Task/Experience | FORBIDDEN |
| Client Value Story | NOT YET / Prompt 58 |
| Report Snapshots | NOT YET / Prompt 59 |

### Matrices (summary)

**Outcome kind:** all four; units COUNT/COUNT/COUNT/MONEY; automatic provider mapping **NO**.

**Provider conflation:** Google Ads conversion, conversion value, Meta lead/purchase, GA4 key event, form/phone/WhatsApp click → automatically same as BO? **NO**.

**CRM boundary:** Lead/Contact/Patient/Deal/Pipeline/Appointment/Invoice/Payment/salesperson/follow-up → Prompt57 **NO**.

**CSV fields:** allowlisted six only; name/email/phone/lead_id/patient_id/deal_id/appointment_id/invoice_id/address **FORBIDDEN**.

**Assistant source:** qualified leads / consultations / patients / revenue questions → Business Outcome; provider fallback **NO**; LLM **NO**.

## 58. Prompt 58 Handoff

Prompt 58 composes Client Value Story from canonical BO aggregates + operational/marketing data. Prompt 57 exposes `BusinessOutcomeReadService` / aggregate results only — no narrative, MoM story, or ROI framing.

## 59. Definition of Done

Prompt 57 PASS when aggregate BO domain is production-persistent, Demo outcome values retired from Brand Value data reads, Assistant recognizes Business Outcome source class, CRM/provider-mapping/attribution/ROI remain absent, and Prompt 58/59 remain owners of Value Story / Report Snapshots. See final report invariants checklist.
