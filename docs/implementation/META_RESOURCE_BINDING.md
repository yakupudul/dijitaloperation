# META RESOURCE SELECTION & ASSET BINDING

**Prompt:** 23  
**Status:** CODE READY  
**Depends on:** Prompt 21–22  
**Canonical surface:** `/app/integrations` → Meta → Resources & Bindings  
**Base HEAD:** `e5d1297` (Prompt 22)

---

## 1. Purpose

Allow authorized human operators to confirm which discovered Meta Ad Account
`CoreExternalResource` belongs to which MoxDOP Meta Ads `DigitalAsset`.

Discovery never binds automatically. Binding never starts collection.

---

## 2. Prompt 22 Resource Inventory Boundary

Prompt 22 owns:

- Production Meta authorization
- Persistent `META_BUSINESS` inventory
- Human Business discovery-context selection
- Persistent `META_AD_ACCOUNT` inventory (`resource_type=meta_ads`)
- Refresh / reconciliation

Prompt 23 consumes that persisted inventory only. Page render and Binding
confirmation perform **zero** Meta Graph HTTP calls by default.

---

## 3. Business Selection vs Binding

| Concept | Meaning |
| --- | --- |
| Business selection | Discovery/access context (`CoreIntegrationDiscoveryContext`, purpose=`discovery_context`) |
| Binding | Technical mapping Meta Ads DigitalAsset ↔ META_AD_ACCOUNT via `CoreAssetBinding` |

Changing Business selection does **not** create, replace, or delete Bindings.

---

## 4. Meta Ad Account Semantics

Canonical provider resource for Meta Ads Binding:

- Semantic type: **META_AD_ACCOUNT**
- Stored `resource_type`: `meta_ads` (capability-aligned)
- Identity: canonical `act_*` ExternalResource id (Prompt 22 normalization)

Owned vs client/shared edges are access context labels only — they do not
change Binding identity.

---

## 5. Meta Ads DigitalAsset Semantics

MoxDOP managed asset representing Meta advertising capability for a Brand.

It is **not** the provider row. Discovery never creates it.
Prompt 23 may create one only after explicit confirmation, and only when the
Brand has no appropriate unbound Meta Ads asset (otherwise reuse).

---

## 6. CoreAssetBinding

Canonical technical mapping:

```
Meta Ads DigitalAsset
  ↔ CoreAssetBinding (capability=meta_ads, status=active|disabled)
  ↔ META_AD_ACCOUNT ExternalResource
```

Provenance on `configuration`:

- `confirmed_by_user_id`, `confirmed_at`
- `origin` = `meta_integration_selection`
- `mode`, `created_asset`, optional `replaced_previous_binding_id`
- on close: `closed_by_user_id`, `closed_at`, `closed_reason`

---

## 7. Provider Resource Hierarchy

```
META_BUSINESS
  ↓ owned_ad_accounts / client_ad_accounts
META_AD_ACCOUNT
```

This is provider hierarchy / access context — **not** CoreAssetBinding and
**not** Asset Relationship.

---

## 8. Human Confirmation

Mandatory. Forbidden auto-bind signals include name/domain/Business/Brand/
Instagram handle similarity and single-resource availability.

UI: Select Ad Account → Brand / Meta Ads asset → **Confirm Connection**.

Effect stated: this Ad Account is the provider account for this Meta Ads asset —
not “connect the entire Meta Business”.

---

## 9. Brand / Customer Context

Operator selects Brand on the frozen Integrations surface (global entry).
Server reloads Brand / DigitalAsset / ExternalResource / Integration and
rejects cross-Brand asset targets.

---

## 10. DigitalAsset Creation Policy

| Moment | Creates DigitalAsset? |
| --- | --- |
| Discovery | NO |
| Business selection | NO |
| Radio / UI selection | NO |
| Confirm (no unbound Meta Ads asset on Brand) | YES (transactional with Binding) |
| Confirm (unbound Meta Ads asset exists) | NO — reuse |

---

## 11. Binding Eligibility

Must all be true for a **new** active Binding:

1. Operator authorized (ADMIN)
2. Resource provider = META
3. Resource type = META_AD_ACCOUNT (`meta_ads`)
4. Resource status = available
5. Resource `integration_id` = current Meta Integration
6. Integration active + provider META
7. Target DigitalAsset type = `meta_ads`
8. Brand ownership matches plan
9. Cardinality / conflict checks pass

Partial later discovery does not hide already-available inventory.

---

## 12. Binding Cardinality

| Rule | Decision |
| --- | --- |
| Active META_AD_ACCOUNT per Meta Ads DigitalAsset | **1** |
| Active Meta Ads DigitalAssets per META_AD_ACCOUNT | **1** |
| Cross-Brand shared Ad Account | **NO** (conflict) |
| Cross-Customer | **NO** (Brand/asset ownership validation) |
| Shared-account semantics | **NOT SUPPORTED** |

Ambiguity remaining: **NONE**

---

## 13. Duplicate Protection

Application + DB:

- Partial unique `(digital_asset_id, capability) WHERE status='active'`
- Partial unique `(external_resource_id) WHERE status='active'`
- Partial unique `(digital_asset_id, external_resource_id) WHERE status='active'`
- Exact active duplicate confirm → **idempotent** (return existing)
- `lockForUpdate` inside transaction for race safety

---

## 14. Conflict Handling

| Conflict | Operator message |
| --- | --- |
| Asset already bound to different account | Requires explicit replace confirmation |
| Account already bound to different asset | Rejected |
| Wrong Integration / tenant / types | Rejected |
| Resource access lost | Rejected for **new** Binding; historical Binding preserved |
| Exact duplicate active | Success (idempotent) |

No raw DB exceptions exposed to operators.

---

## 15. Rebinding / Replacement

Supported via explicit `allowReplace` / UI checkbox.

Behavior:

1. Close old Binding (`status=disabled`) — **do not** mutate `external_resource_id`
2. Create/activate new Binding to the new ExternalResource
3. Historical facts / materializations remain on the old ExternalResource

---

## 16. Historical Data Safety

Rebinding must **not** make Account A facts appear as Account B facts.

Old Materialization stays on old `external_resource_id`.
New account does not inherit AVAILABLE state.

---

## 17. Unbinding

Disconnect Ad Account from Meta Ads asset:

- Sets Binding `disabled`
- Does **not** revoke Meta authorization
- Does **not** delete Business selection
- Does **not** delete ExternalResource inventory
- Does **not** delete historical normalized data

---

## 18. Instagram Relationship Boundary

| Concept | Status |
| --- | --- |
| Frozen Instagram DigitalAsset (demo specialist) | DEMO only — not production Binding |
| Provider Instagram ExternalResource discovery | NOT in Prompt 22 inventory |
| Meta Ads Data Contract requires IG resource relation for Prompt 24 | NO |
| Placement / handle heuristics | FORBIDDEN |
| Instagram organic integration | NOT IMPLEMENTED |
| Relation decision | **INSTAGRAM RESOURCE RELATION DEFERRED / UNSUPPORTED** |

---

## 19. Facebook Page Relationship Boundary

Pages are not the Meta Ads Binding root. No speculative Page Binding added.
Creative actor metadata remains Prompt 24 analytical concern.

---

## 20. Frozen Integration UX

Canonical: `/app/integrations/meta` → Resources & Bindings

Shows Business context, Ad Account candidates (name, masked ID, Business,
owned/client, currency, timezone), Confirm Connection modal, current Binding
with separate Data state.

No new top-level navigation.

---

## 21. Collection Eligibility Handoff

`MetaAdsBindingEligibility` (read-only):

eligible when active confirmed Binding + accessible Ad Account + active Meta
Integration.

Prompt 24 owns collectors. Prompt 23 creates **0** Insights/campaign calls.

---

## 22. Legacy Mapping Convergence

| Legacy | Classification |
| --- | --- |
| Filament MetaAdsConnectionsRelationManager inline bind | **EVOLVE** → ConfirmMetaResourceBindingService |
| Empty Binding `configuration` | **EVOLVE** → confirmation provenance |
| Edit mutates `external_resource_id` in place | **REMOVE** (replaced by close+create rebind) |
| Demo connector unbind session state | **KEEP** demo-only; not production Binding truth |
| selected_ad_account dual-write fields | **NONE found** requiring migration |

No dual-write competing Binding truth after Prompt 23.

---

## 23. Tenant / Security Boundaries

- ADMIN-only confirm / replace / unbind
- Server reloads Integration / Resource / Asset / Brand
- Cross-Integration / forged IDs rejected
- No token / App Secret in Binding commands
- Synthetic fixtures only in tests

---

## 24. Tests

`tests/Feature/MetaResourceBindingTest.php` (+ updated Hot Path / Google regression).

Covers eligibility, Business≠Binding, human confirm, reuse/create, idempotency,
conflicts, rebind history, unbind, Instagram non-invention, zero Graph calls,
Prompt 24 eligibility foundation, frozen UI.

---

## 25. Reality Matrix

| Capability | Status |
| --- | --- |
| Human Ad Account selection | REAL |
| Meta Ads DigitalAsset mapping | REAL |
| Human-confirmed CoreAssetBinding | REAL |
| Duplicate/conflict protection | REAL |
| Explicit rebind (history-safe) | REAL |
| Instagram supported resource relation | DEFERRED / UNSUPPORTED |
| Instagram organic | NOT IMPLEMENTED |
| Meta Ads Production Collector | NOT YET (24) |
| Meta analytical pool / backfill | NOT YET (24–25) |

---

## 26. Prompt 24 Handoff

Collectors must resolve:

DigitalAsset → active CoreAssetBinding → META_AD_ACCOUNT → Credential Broker

without creating Bindings themselves. Use `MetaAdsBindingEligibility`.

---

## 27. Definition of Done

All Prompt 23 invariants satisfied — see final report META BINDING INVARIANTS.

---

## Binding Ontology Matrix

| Concept | Provider / MoxDOP? | Role | Selectable? | Bindable? | Creates DigitalAsset? | Creates CoreAssetBinding? | Examples |
| --- | --- | --- | --- | --- | --- | --- | --- |
| META_BUSINESS | Provider | Discovery/access context | Yes (context) | **No** | No | No | Acme Business |
| META_AD_ACCOUNT | Provider | Analytical Binding root | Yes | **Yes** | No (by itself) | Via human confirm | act_… Ads |
| Meta Ads DigitalAsset | MoxDOP | Managed asset | N/A | Target | Only on confirm if needed | N/A | Acme · Meta Ads |
| CoreAssetBinding | MoxDOP | Technical mapping | N/A | N/A | No | Yes (confirm) | asset↔account |
| Asset Relationship | MoxDOP semantic | e.g. Ads→Website | N/A | N/A | No | **No** | traffic target |

## Business vs Ad Account Matrix

| Action | Business | Ad Account |
| --- | --- | --- |
| discover | Yes | Yes |
| select for discovery context | Yes | No |
| bind to DigitalAsset | **No** | **Yes** |
| analytical collection root | No | Yes (after Binding + Prompt 24) |
| provider hierarchy | Parent | Child |
| DigitalAsset creation | No | Only via confirmed Binding flow |

## Binding Eligibility Matrix

| Auth | Perm ready | Business ctx | Ad discovered | Ad accessible | DigitalAsset valid | Existing Binding | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| No | * | * | * | * | * | * | Block / authorize |
| Yes | Yes | Optional* | Yes | Yes | Yes | None | Confirm → ACTIVE |
| Yes | Yes | * | Yes | No | Yes | None | Block new Binding |
| Yes | Yes | * | Yes | Yes | Wrong type | None | Reject |
| Yes | Yes | * | Yes | Yes | Yes | Same pair | Idempotent success |
| Yes | Yes | * | Yes | Yes | Yes | Different account | Conflict unless replace |
| Yes | Yes | Change | Yes | Yes | Yes | Active | Binding unchanged |

\*Business context required for discovery; not required for Binding identity once Ad Account is persisted.

## Cardinality Matrix

| Dimension | Decision |
| --- | --- |
| DigitalAsset → active META_AD_ACCOUNT | 1 |
| META_AD_ACCOUNT → active Meta Ads DigitalAssets | 1 |
| cross-Brand | denied |
| cross-Customer | denied |
| shared account | not supported |
| conflict | actionable reject / explicit replace |

## Rebind Matrix

| Scenario | Allowed? | Confirmation? | Old Binding | Old data | New Materialization |
| --- | --- | --- | --- | --- | --- |
| First bind | Yes | Yes | n/a | n/a | none |
| Exact duplicate | Yes (idempotent) | Yes | unchanged | unchanged | unchanged |
| Replace A→B | Yes | Explicit replace | disabled | preserved on A | not inherited |
| Silent replace | No | — | — | — | — |

## Instagram Relation Matrix

| Concept | Provider relation? | Stable ID? | Required frozen? | Required Meta Ads contract? | ExtResource hierarchy? | Asset Rel? | CoreAssetBinding? | Deferred? | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| IG placement on ads | Placement only | No account ID | No | No | No | No | No | Yes | Placement ≠ account |
| Handle / name match | No | No | No | No | No | No | No | Yes | Heuristic forbidden |
| IG organic asset | Demo only | N/A | No for Prompt 23 | No | No | No | No | Yes | Out of scope |
| Page→IG official edge | Not inventoried in Prompt 22 | Unknown | No | No | No | No | No | **UNSUPPORTED** | No production discovery |

## Binding State Matrix

| State | Binding | Collection | Data freshness |
| --- | --- | --- | --- |
| No Binding | none | not eligible | none |
| Active Binding | active | foundation eligible | may still be none |
| Resource access lost | may remain active historically | not newly bindable / collect blocked | old data may exist |
| Integration auth invalid | Binding retained | collect blocked later | unchanged |
| Binding replaced | old disabled, new active | new resource not collected | old stays on old resource |
| Binding disconnected | disabled | not eligible | historical retained |
| Collection not run | active possible | not_run | none |
| Data available / stale | independent | independent | independent |

## Legacy Convergence Matrix

| Legacy mapping | Current semantics | Canonical equivalent | Safe migration? | Human confirm? | Legacy writes after 23? | Removal |
| --- | --- | --- | --- | --- | --- | --- |
| Filament inline persistBinding | Direct CoreAssetBinding create/update | ConfirmMetaResourceBindingService | Done (code path) | Yes | No dual-write | n/a |
| In-place external_resource_id edit | Destructive rebind | disable + create | Replaced | Explicit replace | No | Prompt 23 |
| Demo connector session bind | DemoState only | unchanged demo | N/A | Demo | Demo only | later demo cleanup |

---

*End of Prompt 23 document.*
