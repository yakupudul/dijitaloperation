# Brand Experience Contract

> Prompt 52 — structured Brand Experience schema for Brand Memory content.

## Identity & ownership

- Stable id: `brand_experiences.id`
- Customer + Brand required; Customer derived from Brand tenancy
- Exact Brand scope for all reads/writes
- Cross-Brand / same-Customer other Brand / same-sector: **FORBIDDEN**

## Lifecycle

`draft` | `confirmed` | `superseded` | `invalidated`

- Confirmed ⇒ eligible Brand Memory content (not “success”)
- Draft / superseded / invalidated ⇒ not active Brand Memory
- No SUCCESS/FAILED lifecycle status

## Revision

Immutable `brand_experience_revisions` pinned by `current_revision_id`.  
Material correction ⇒ superseding Experience (new row) + prior `superseded`.

## Structured fields

| Field | Contract |
| --- | --- |
| Context | `BrandExperienceContextSnapshot` schema `brand_experience_context_v1` |
| Goal | Optional `brand_goals` FKs + label snapshot |
| Market | Optional ISO country (`CountryOptions`) + label snapshot |
| Offering | Optional `brand_offerings` FKs + label snapshot |
| Channel | Optional `BrandExperienceChannel` (= DigitalAsset type keys) |
| DigitalAsset | Optional FK; Brand-owned |
| Observed Situation | Bounded summary + optional Finding/Opportunity + period + Evidence roles |
| Action | `task_completed` \| `external_operator_confirmed`; `action_occurred_at` required |
| Observed Later Outcome | Summary + `outcome_observed_at` **after** Action; clarity enum |
| Evidence Quality | Categorical assessment + reason codes + policy version |
| Causality | Always `causality_not_established` |

## Temporal fields

- `created_at` ≠ `action_occurred_at` ≠ `outcome_observed_at`
- Situation / outcome periods optional but explicit when present

## Evidence revisions

Links store `evidence_id` + `evidence_fingerprint` + closed `role`.  
Historical Experience stays pinned when Evidence later changes.

## Provenance

- Origin: `operator_captured` \| `system_assisted_capture`
- Recorder: nullable User
- Action Task / Recommendation refs bounded (Recommendation = context only)
- AI never trusted origin of confirmed Experience

## Causality disclaimer

Observing an Outcome after an Action records a **temporal / experience** relationship.  
It does **not** establish that the Action caused the Outcome.

## Future Prompt 53

`SectorLearningContributionCandidate` exposes structured eligibility signals only.  
Privacy qualification and aggregation remain Prompt 53.
