# SERVICE SCOPE PRODUCTION PERSISTENCE

## STATUS: PASS (commercial domain; Prompt 35 sequence soft-gate noted)

**Prompt:** 36  
**Date:** 2026-08-13  
**Branch:** `cursor/service-scope-production-persistence-ea01`  
**Base:** Prompt 35 HEAD `461d6a5467966320de8d38c0288977aa7bf0ced5` (blocker-only Website migration)

**Note:** Prompts 32–35 are not green for Website. Service Scope is **technically independent** of Website/WordPress/DataForSEO. Prompt 35 regression = Website remains Demo/blocked unchanged.

---

## 1. Purpose

Persist canonical Customer Service Scope so MoxDOP answers:

> What does our agency actually do for this customer?

without inventing parallel commercial truth.

## 2. Frozen Product Contract

Demo surfaces (still Demo Mode fixtures for Atlas Demo IDs):

- Customer Detail → Relationship → Service Scope cards (`CommercialContextFixtures`)
- Brand → Business → Agency scope
- Asset scope chips

Production persistence + Filament Multiselect sync + DB read services are REAL.

## 3. Existing Customer Service Primitive Audit

| Concept | Path | Decision |
|---|---|---|
| `customers.services` JSON | Customer model | **PROJECT** one-way from ACTIVE/PAUSED scopes |
| `customers.services_received` text | Customer model | **PROJECT** via AgencyServiceOptions |
| `AgencyServiceOptions` | code catalog | **REUSE** — codes seed `service_definitions` |
| `CommercialContextFixtures` | Demo | **DEMO_ONLY** — Demo Mode Atlas only |
| Brand `products_services` | BrandIntelligenceContext | **NOT** agency Service Scope |
| Service / ServiceScope models | — | **CREATED** |

## 4. Canonical Truth Decision

| Question | Canonical |
|---|---|
| What Service? | `service_definitions.code` |
| In scope? | `customer_service_scopes` row |
| Status? | `customer_service_scopes.status` |
| Brand applicability? | `brand_applicability_mode` + pivot |
| Owner? | `owner_user_id` |
| Cadence? | `cadence` / `reporting_cadence` |
| Inclusions / Exclusions? | child tables |
| Legacy Multiselect? | projection from ACTIVE/PAUSED scopes |

Independently writable legacy truth: **NONE** after Filament afterSave sync + projection.

## 5–12. Domain summary

- **ServiceDefinition:** reusable catalog (`available` / `archived`)
- **CustomerServiceScope:** customer engagement row
- **Status:** draft → active ⇄ paused → ended (ended retained; no ordinary hard delete)
- **Brand modes:** `customer_wide` (empty pivot ≠ no brands) · `specific_brands` (≥1 same-Customer Brand)
- **Owner:** User FK; nullable; no hidden current-user fallback
- **Cadence:** typed enum; creates **0** schedules/tasks
- **Inclusions/Exclusions:** ordered child rows; exact conflict rejected; XSS stripped

## 13–16. Lifecycle / History / Legacy

- Ended scopes remain queryable
- History: created_at/updated_at + lifecycle timestamps (no second audit framework)
- Legacy migration: `migrateLegacyCustomerServices()` — codes only; no owner/Brand/cadence guessing; idempotent
- Filament Create/Edit Customer Multiselect → `syncActiveCustomerWideFromCodes`

## 17–23. Authorization / Application / Reads / Demo

- Application services: `CustomerServiceScopeService`, `CustomerServiceScopeReadService`, `CommercialServiceContextProvider`
- Production reads: DB only; empty = real empty; **no Demo fallback**
- Demo Mode Atlas: fixtures remain for Demo string IDs (documented DEMO_ONLY)

## 24–28. Boundaries

Service Scope ≠ DigitalAsset / Binding / Goal / Finding / Opportunity / Recommendation / Task / Playbook / Invoice.  
Creating/pausing/ending scope: **0** provider mutations, **0** Task/Playbook/Goal/Evidence creation.

## 29. Future consumers

`CommercialServiceContextProvider` for Prompt 37+.

## 30. Tests

`tests/Feature/ServiceScope/ServiceScopeProductionPersistenceTest.php`

## 31. Reality Matrix

| Capability | State |
|---|---|
| Service Definition | REAL |
| Customer Service Scope | REAL |
| Status / Brand / Owner / Cadence / In/Exclusions | REAL |
| Customer/Brand reads | REAL |
| Legacy projection | CONVERGED |
| Demo Mode Atlas fixtures | DEMO_ONLY (Demo path) |
| Production Demo fallback | NONE |
| Pricing / Goals / Evidence / Findings / AI | NOT IN SCOPE |

## 32. Definition of Done

Commercial persistence DoD: YES for implemented path.  
Prompt 35 Website green: NO (soft sequence) — Website unchanged Demo/blocked.
