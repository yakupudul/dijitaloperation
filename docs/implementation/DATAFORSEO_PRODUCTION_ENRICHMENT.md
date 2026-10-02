# DATAFORSEO PRODUCTION ENRICHMENT

## STATUS: BLOCKED — Prompt 32 / Prompt 33 prerequisites missing

**Prompt:** 34  
**Date:** 2026-08-13  
**Branch:** `cursor/dataforseo-production-enrichment-ea01`  
**Base:** Prompt 33 HEAD `c7cb3b0e6a38f5e13f3bba1e56b374b9f14e1850`  
**Note:** Prompt 33 itself is a **blocker document only** (WordPress not productionized). Prompt 32 Website Production Data Foundation is **absent**.

---

## 1. Purpose

Prompt 34 productionizes DataForSEO as MoxDOP’s **paid platform enrichment provider** with hard cost control:

```text
Website / Brand need
  → Capability Registry
  → Market Context
  → Cache / Provider Generation
  → Cost Preflight + Reservation
  → Paid call ONLY if justified
  → Typed external SEO observations
  → Materialization / Integrity / Freshness
```

This is **not** a customer Connect → Discover → Bind flow.

---

## 2. Product Boundary

| Concept | Status |
|---|---|
| Customer DataForSEO connect / OAuth | FORBIDDEN |
| DataForSEO DigitalAsset | FORBIDDEN |
| DataForSEO ExternalResource / CoreAssetBinding | FORBIDDEN |
| Platform agency Integration credentials | CANONICAL |
| Cache-first paid enrichment | REQUIRED |
| Canonical Evidence::create | FORBIDDEN (Prompt 38) |
| Website UI real migration | FORBIDDEN (Prompt 35) |
| Recurring paid scheduler | FORBIDDEN (Prompt 62/63) |

---

## 3. Why DataForSEO Is Not a Primary Integration

Google / Meta / WordPress are customer-owned source systems.  
DataForSEO is **MoxDOP-paid external market intelligence**. Credentials are platform-scoped. Consumption is capability-driven, not account-import-driven.

---

## 4. Existing DataForSEO Audit

| Asset | Classification |
|---|---|
| `docs/data-contracts/DATAFORSEO_DATA_CONTRACT_V1.md` (Prompt 6) | CANONICAL |
| `DataForSeoApiClient` + `DataForSeoEndpointAllowlist` | REUSE → EVOLVE |
| `DataForSeoCredentialResolver` / `ProviderCredentialService` | REUSE |
| `DataForSeoAccountService` (free `user_data` + balance snapshot) | REUSE |
| `DataForSeoLabsMarketDirectory` | REUSE |
| `PaidRequestFingerprint` / `EvidenceFreshnessGuard` / `PaidRequestExecutor` | REUSE → EVOLVE into CostGuard |
| `RankedKeywordsCollector` + Normalizer | REUSE (Evidence path) |
| `KeywordsForSiteCollector` + Normalizer | REUSE (Evidence path; Prompt 6 required) |
| `CompetitorDomainCollector` | REUSE (Discovery; Evidence path) |
| `SeoIntelligenceRefreshService` + Job | REUSE |
| `DataForSeoConnectionProbeService` (site-scoped) | LEGACY dual-path |
| Demo Visibility DFS panel fixtures | DEMO_ONLY |
| Physical `dataforseo_*` pool tables | CANONICAL schema / **MISSING writers** |
| Collection Engine DFS DatasetExecutor | MISSING |
| Typed CostGuard + atomic budget reservation + cost ledger | MISSING |
| `relevant_pages` allowlist + collector | MISSING (Contract V1: **DEFER**) |
| Prompt 32 WebsitePage URL resolver | MISSING |
| Prompt 33 WordPress → Website ingestion | MISSING (blocker only) |

**Decision:** Do **not** build a second DataForSEO stack. When unblocked, converge Evidence-path collectors into one pool enrichment path + CostGuard wrapping `PaidRequestExecutor`.

---

## 5. Provider Verification (contract date 2026-08-13; not re-executed live)

| Topic | Contract / code truth |
|---|---|
| API | v3 Basic Auth |
| Ranked Keywords | `POST …/ranked_keywords/live` — Required |
| Keywords for Site | `POST …/keywords_for_site/live` — Required |
| Competitors Domain | `POST …/competitors_domain/live` — Conditional (Discovery) |
| Relevant Pages | `POST …/relevant_pages/live` — **DEFER** in Contract V1 |
| Locations/Languages | free GET — Required |
| User Data / balance | free GET — Required |
| Pricing | provider-derived snapshot required; **no permanent hardcoded production prices** |
| Live paid smoke this branch | **NOT EXECUTED** |
| Data Contract mismatch | NONE vs Prompt 6; **implementation blocked by foundation** |

Prompt 34 text lists Relevant Pages as initial production scope. **Prompt 6 / Unified Registry currently DEFER Relevant Pages.** Resume must either:

1. Keep Relevant Pages deferred (Contract wins), or  
2. Explicitly amend Contract V1 before enabling the endpoint.

Do **not** silently enable DEFERRED capabilities.

---

## 6–10. Authentication / Credential / Capability / Allowlist (intended)

Reuse existing platform Integration credential path.  
Allowlist already gates paid POSTs. Evolve into:

- Capability Registry (Requirement → Endpoint → Cost model → Dataset)
- Parameter allowlist (no arbitrary filters/limit/clickstream from UI)
- Global + per-capability kill switches
- Atomic cost reservation + ledger

**Not implemented in this branch.**

---

## 11–50. Deferred sections

SEO Market Context, Target Resolution, Provider Generation, Fingerprint, Cache, Pricing Snapshot, Cost Guard, Ledger, Ambiguous Charge, Ranked Keywords pool writers, Competitors pool writers, Relevant Pages, WebsitePage relations, Materialization, Freshness, Integrity, Raw Replay, Privacy, Security, Performance, Tests, full mandatory matrices:

**Deferred** until:

1. Prompt 32 Website Production Data Foundation is green (WebsitePage + URL resolution + foundation Materialization/Integrity/Freshness), and  
2. Prompt 33 WordPress production ingestion is green **or** Architect explicitly scopes Prompt 34 to domain-level Labs enrichment without WP (still requires Prompt 32 for honest Relevant Pages / Website scoping DoD).

Creating fake REAL matrices without executable foundation would be dishonest.

---

## Capability Matrix (audit snapshot)

| Requirement | Capability | Endpoint | Contract | Code today | Prompt 34 |
|---|---|---|---|---|---|
| DFS Visibility RK | Ranked Keywords | ranked_keywords/live | Required | Evidence collector | BLOCKED (pool path) |
| DFS Visibility KFS | Keywords for Site | keywords_for_site/live | Required | Evidence collector | BLOCKED (pool path) |
| Discovery competitors | Competitors Domain | competitors_domain/live | Conditional | Evidence collector | BLOCKED (pool path) |
| Future Vis pages | Relevant Pages | relevant_pages/live | **DEFER** | Missing | Stay deferred unless contract amended |
| Cost control plane | CostGuard | wraps executor | Future in §25 | Partial (fingerprint/cache/lock) | BLOCKED on foundation gate for full DoD |

---

## Cost-Gate Matrix (existing vs missing)

| Check | Today | Prompt 34 need |
|---|---|---|
| Endpoint allowlist | YES | Keep |
| Cache-first fingerprint | YES (Evidence TTL) | Evolve to durable pool cache + generation |
| Single-flight lock | YES | Keep |
| Paid POST no auto-retry | YES | Keep + CHARGE_UNKNOWN |
| Balance snapshot | YES (free user_data) | Harden min-balance gate |
| Pre-dispatch estimate | NO | Required |
| Atomic budget reservation | NO | Required |
| Persistent cost ledger | Partial (Run metadata cost) | Required typed ledger |
| Pricing snapshot age gate | NO | Required |
| Global kill switch | Config partial | Required explicit |
| Page-render paid calls | 0 (Demo/mount) | Must remain 0 |

---

## Evidence Boundary

| Concept | Prompt 34 |
|---|---|
| External data-pool observations | YES (target when unblocked) |
| Canonical Evidence rows | **NO** (Prompt 38) |
| Findings / Opportunities / Recommendations / AI | **NO** |

Existing Light V1 collectors still write Evidence — that is **legacy transitional**. Prompt 34 production path must converge to pool observations without new Evidence creation. Unblocking must retire/bypass Evidence writes for new enrichment runs.

---

## Reality Matrix (after this blocker branch)

| Capability | State |
|---|---|
| Website Production Foundation | **MISSING** |
| WordPress Production Ingestion | **MISSING** (Prompt 33 blocker only) |
| DataForSEO client / allowlist / credential / market directory | REUSE (pre-Prompt-34) |
| CostGuard full ledger / reservation | **NOT YET** |
| Pool Ranked Keywords / Competitors / Relevant Pages | **NOT YET** |
| Website Real UI | NOT YET (Prompt 35) |
| Canonical Evidence | NOT YET (Prompt 38) |
| Paid scheduler | NOT YET |

---

## Prompt 35 Handoff

Not started. Website Real Data Migration waits for foundation + sources + enrichment facts.

## Evidence Boundary (Prompt 38)

Prompt 34 must not call `Evidence::create` on the production enrichment path once unblocked.

## Definition of Done

Prompt 34 DoD **cannot** be satisfied while Prompt 32/33 are not green.  
This branch records audit + hard gate only. **STATUS: BLOCKED.**

### Unblock path

1. Complete Prompt 32 — Website Production Data Foundation  
2. Complete Prompt 33 — WordPress Site Connector Productionization (or Architect-scoped exception)  
3. Re-base Prompt 34 from exact green Prompt 33 HEAD  
4. Resolve Relevant Pages: Contract DEFER vs Prompt 34 text (Contract wins unless amended)  
5. Implement CostGuard + pool writers + Materialization/Integrity/Freshness without a second DFS stack
