# PLAYBOOKS PRODUCTION PERSISTENCE

## 1. Purpose

Prompt 45 moves frozen Demo Playbooks to one canonical, versioned production persistence domain. A Playbook is a reusable agency SOP / knowledge asset — not Task, Work, QA, Approval, Recommendation, or AI Skill.

## 2. Frozen Playbook Product Audit

Surfaces: Settings → Operations → Playbooks catalog; Playbook detail (`/app/settings/playbooks/{id}`); Work knowledge context link; Global search; Recurring review Demo still references playbook_id (Prompt 46). No Task→Playbook selection UI. No create/edit UI (read + seed/service write). No version history UI.

## 3. Demo Playbook Audit

Four fixtures in `AgencyExecutionFixtures::playbooks()` with stable keys `pb-weekly-gads`, `pb-monthly-seo`, `pb-meta-creative`, `pb-website-health`. Classified as CURATED_PRODUCT_CONTENT / CANONICAL_DEFAULT_CONTENT. Atlas-specific purpose wording generalized. Fake default owners not migrated. `related_ai_skill` stored as knowledge label only.

## 4. Existing Playbook Primitive Audit

No prior `playbooks` table. Demo arrays were sole source. AI Skills remain separate registries. QA guidance text is knowledge, not Prompt 44 QA criteria.

## 5. AI Skill Boundary Audit

Settings AI Skills ≠ Playbooks. Playbook stores optional `related_ai_skill_label` knowledge note only — no tool permissions, schemas, or Agent records.

## 6. QA Checklist Boundary Audit

Frozen `qa_guidance` / checklist steps are Playbook knowledge/instructions. They do not create `qa_reviews` or QA criteria rows.

## 7. Canonical Playbook Decision

Tables: `playbooks`, `playbook_revisions`, `playbook_instructions`, `playbook_references`, `playbook_revision_services`, `playbook_revision_asset_types`, `playbook_revision_execution_scopes`.

## 8. Playbook Identity

Stable `id` + optional unique `stable_key`. Title mutable and not unique identity.

## 9. Playbook Lifecycle

`active` | `archived`. Archive preserves revisions.

## 10. Revision / Version Architecture

Immutable revisions with monotonic `revision_number`. `playbooks.current_revision_id` points to current.

## 11. Revision Immutability / History

Edit/Save creates a new revision; prior retained. Applicability/instructions/references versioned on revision.

## 12. Knowledge

JSON `knowledge` on revision (purpose, when_to_use, when_not_to_use, methodology, qa_guidance, related_ai_skill_*). Non-executable. Escaped on render.

## 13. Instructions

Ordered `playbook_instructions` child rows (checklist + procedural lead-in).

## 14. Instruction Ordering

Explicit `position`. Reorder requires new revision.

## 15. References

Kinds: `external_url` | `internal_route`. Versioned on revision.

## 16. External URL Safety

http/https only via validator. No javascript:/data:. Backend never fetches.

## 17. File References

Not in frozen Playbook surface — PARTIAL / not implemented. No second file store.

## 18. Service Definition Applicability

Modes `any` | `explicit` with `playbook_revision_services` → `service_definitions.id`. Empty ≠ ALL.

## 19. Customer Service Scope Awareness

`PlaybookApplicabilityResolver` reads Prompt 36 scopes dynamically. Playbook never mutates scopes.

## 20. Execution Scope Applicability

Modes `any` | `explicit` for Prompt 43 kinds: customer / brand / digital_asset.

## 21. DigitalAsset Type Applicability

Canonical `DigitalAssetTypes` keys only. No WordPress/DataForSEO/provider resource types.

## 22. Applicability Resolver

Local DB. Typed output with reason codes. No score. No first Brand/Asset fallback. Customer-level without Brand → SERVICE_SCOPE_UNKNOWN (no Brand aggregation).

## 23. Applicability States

`IN_CURRENT_SCOPE` | `OUTSIDE_CURRENT_SCOPE` | `SERVICE_SCOPE_UNKNOWN` | `SERVICE_NOT_RELEVANT`.

## 24. Task Integration Boundary

Frozen product has no Task→Playbook selection. Not added.

## 25. QA Boundary

Playbook ≠ QA. No auto criteria/reviews.

## 26. Recommendation Boundary

No auto create/select.

## 27. Client Request Boundary

No automatic relation.

## 28. AI Skill Boundary

No skills/agents/embeddings/execution.

## 29. Default Playbook Seeding

`DefaultPlaybookCatalog` + `playbooks:seed-defaults` + DatabaseSeeder. Idempotent by `stable_key`.

## 30. Demo → DB Migration

Catalog seed only. Runtime fixtures retired from Settings/Search/Work knowledge.

## 31. Read Architecture

`PlaybookReadService` list/detail/history. DB only.

## 32. Write Architecture

`PlaybookService` create/revise/archive/restore. No Livewire domain writes.

## 33. Revision Idempotency

`idempotency_key` + content fingerprint (identical current content reuses revision).

## 34. Concurrency

Optimistic `expectedCurrentRevisionId` + row lock.

## 35. Activity

`PlaybookActivityRecorder` uses BrandContextActivity only when Brand context supplied (canonical Activity is brand-scoped). Revision history remains authoritative.

## 36. Authorization

Admin / Team Member manage. Internal only.

## 37. Tenancy / Workspace Scope

Playbooks are agency/global — not Customer-owned. Context only for applicability.

## 38. Security

Safe URLs; escaped Blade; no eval/shell; no SSRF fetches; no credentials schema.

## 39. Privacy

SOP content agency-confidential. Activity omits full bodies.

## 40. Performance

Eager-load current revision relations; list limited; history limited.

## 41. Frozen UI Migration

Same Settings catalog/detail IA. DB-backed. Subtitle updated.

## 42. Demo Retirement

Production reads: DB only. `AgencyExecutionFixtures::playbooks()` remains seed-source documentation only (not Settings/Search runtime).

## 43. Tests

`tests/Feature/Playbooks/PlaybooksProductionPersistenceTest.php` + AgencyExecutionSystem updates.

## 44. Reality Matrix

Playbook Domain/Persistence/Identity/Revisions/Knowledge/Instructions/References/Applicability/Read/Write: REAL. Demo fallback: NONE. Auto Task/QA/Approval/Recommendation/AI: NO. Recurring Reviews: REAL (P46; schedules bind Playbook + revision snapshot; instructions not auto-converted to checks).

## 45. Prompt 46 Handoff

Recurring Review Persistence may reference Playbook stable_key / revision. Do not invent Review = Playbook.

## 46. Definition of Done

Satisfied when curated Playbooks are REAL DB records, versioned, applicability-safe, Demo-free at runtime, and quality gates pass.

---

## MANDATORY MATRICES (summary)

### Frozen Playbook Audit Matrix

| Surface | Fields | Demo before | Production |
|---|---|---|---|
| Settings catalog | name, service, cadence | fixtures | REAL list |
| Playbook detail | knowledge, checklist, instructions, refs, asset chips | fixtures | REAL detail |
| Work knowledge | playbook link, qa_guidance | fixtures | REAL read |
| Global search | title | fixtures | REAL search |
| Task relation | — | none | none |
| Create/edit UI | — | none | service-only (no UI redesign) |

### Demo Classification Matrix

| Key | Classification | Migrate |
|---|---|---|
| pb-weekly-gads | CURATED_PRODUCT_CONTENT | YES |
| pb-monthly-seo | CURATED_PRODUCT_CONTENT | YES |
| pb-meta-creative | CURATED_PRODUCT_CONTENT | YES |
| pb-website-health | CURATED_PRODUCT_CONTENT | YES |

### Asset Type Mapping Matrix

| Demo label | Canonical type | Action |
|---|---|---|
| website | website | KEEP |
| google_ads | google_ads | KEEP |
| meta_ads | meta_ads | KEEP |
| gsc | gsc | KEEP |
| WordPress | — | NOT A DIGITALASSET |
| DataForSEO | — | NOT A DIGITALASSET |

### Playbook vs Domain Matrix

Playbook auto-creates Task/QA/Approval/Recommendation/Finding/Opportunity/Evidence/Service Scope/AI Skill: **NO** for all.
