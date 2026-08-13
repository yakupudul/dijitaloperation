# WORDPRESS SITE CONNECTOR PRODUCTIONIZATION

## STATUS: BLOCKED — Prompt 32 prerequisite missing

**Prompt:** 33  
**Date:** 2026-08-13  
**Branch:** `cursor/wordpress-site-connector-production-ea01`  
**Attempted base:** Prompt 32 cumulative HEAD — **NOT FOUND**  
**Nearest cumulative HEAD used for this blocker branch:** Prompt 31 `4305a6f8b402887eb0277c14aa6981bc0a5ae2c6`

---

## 1. Purpose

Prompt 33 productionizes the frozen Website → Setup → WordPress Connector so a real WordPress installation can feed the **Prompt 32** canonical Website foundation through a source-specific adapter.

This document records the **pre-implementation audit** and the hard gate that prevents implementation.

---

## 2. Frozen Website Setup Contract

Frozen IA (`OverviewPage::$allowedTabs`):

Overview · Health · Visibility · Content · Performance · Infrastructure · Operations · Setup

Frozen WordPress operator flow:

Website → Setup → WordPress Connector → Pair → Discover → Collect

Demo surfaces (authoritative UI; data Demo-only today):

| Surface | Class |
|---|---|
| `App\Livewire\Demo\Website\OverviewPage` + `tabs/setup.blade.php` | FROZEN shell |
| `SiteConnectorsIndex` / `SiteConnectorShow` | DEMO_ONLY pairing UX |
| `SiteConnectorFixtures` + ZIP download | DEMO_ONLY |
| Filament `WebsiteConnectionsRelationManager` | REUSE (app-password test) |

Passive Setup render must remain **0 WordPress network calls**.

---

## 3. Legacy WordPress Audit

| Asset | Classification |
|---|---|
| `WordPressConnectionProbeService` (GET `/wp-json/`, Basic + Application Password) | REUSE |
| `tests/Feature/WordPressConnectionProbeTest.php` | REUSE |
| `CoreConnection` type=`wordpress` + encrypted `application_password` | CANONICAL transitional (asset-scoped) |
| `ConnectionScope::assetScopedTypes()` | CANONICAL |
| Demo pairing codes / ZIP / “connected sites” | DEMO_ONLY |
| Production installable companion plugin | MISSING (and must **not** be invented as mandatory) |
| Paginated posts/pages/CPT inventory collector | MISSING |
| WordPress → Prompt 32 adapter | MISSING (depends on Prompt 32 DTOs) |
| Outbound WP writes | NOT PRESENT (keep) |

Provider identity label in contracts: `WORDPRESS_SITE_CONNECTOR` (capability label).  
`WORDPRESS_SITE` ExternalResource type: **not implemented** (Prompt 32/33 would introduce if architecture requires it).

---

## 4–10. Ontology (intended; not implemented)

Intended ontology (Prompt 33):

```text
Website DigitalAsset (owner)
  ↔ human-confirmed source Binding
WORDPRESS_SITE ExternalResource (source)
  ↓
WordPress collector (read-only)
  ↓
WordPressWebsiteDataAdapter
  ↓
Prompt 32 canonical Website ingestion DTOs
  ↓
WebsitePage + observations
```

**Cannot implement** without Prompt 32:

- Canonical `WebsitePage`
- Source-reference architecture
- Canonical Website ingestion contracts / writers
- Website DatasetMaterialization wiring for Page Inventory / Technical / Content / Links / Schema / Infrastructure
- Website integrity + freshness integration for those datasets

Hard rule violated if Prompt 33 proceeds alone:

> WordPress collector must not write physical Website tables directly.  
> WordPress data must flow through Prompt 32 canonical Website ingestion.

---

## 11–14. SSRF / Pairing / Credential (audit notes only)

Existing probe is HTTPS Basic + Application Password, Evidence-oriented — **not** a full SSRF-hardened discovery/pairing lifecycle.

Prompt 33 would need:

- Reusable outbound-target validator (loopback/private/link-local/metadata blocked)
- Redirect revalidation; no credential forwarding on cross-origin redirect
- TLS verify always on
- Pairing state (unguessable, TTL, single-use, Website-bound)
- Encrypted Application Password; never in UI/queue/checkpoint/logs
- No normal WP password; no XML-RPC

**Not implemented in this branch** because foundation ingestion path does not exist.

---

## 15–57. Remaining sections (deferred)

Sections required by Prompt 33 (REST discovery, Application Password pairing, capability discovery, post types, site inventory, page inventory, pagination, adapter, collection plan, materialization, freshness, integrity, retry, privacy, security, performance, tests, matrices, reality matrix, DoD) are **deferred** until Prompt 32 lands.

Creating stub matrices that claim REAL/PASS without executable foundation would be dishonest.

---

## Prompt 32 gap checklist (blocking)

| Required by Prompt 33 | Status in repo (2026-08-13) |
|---|---|
| Website Production Data Foundation green | **MISSING** — no branch/PR/doc named Prompt 32 |
| Canonical WebsitePage entity | **MISSING** |
| Source-reference architecture | **MISSING** (contract prose only) |
| Canonical Website ingestion DTOs/services | **MISSING** |
| Website DatasetMaterializations for foundation datasets | **MISSING / empty pool writers** |
| Website integrity integration for those datasets | **MISSING** for Prompt 32 shape |
| Website freshness integration for those datasets | **MISSING** for Prompt 32 shape |
| Physical `website_*` pool tables | Present (Prompt 10) but **no production writers** for foundation |
| `website_cms_object` | Contract **DEFERRED**; no table |

Closest docs:

- `docs/data-contracts/WEBSITE_DATA_CONTRACT_V1.md` (Prompt 5 audit — FROZEN FOR COLLECTION IMPLEMENTATION)
- `docs/product/website/WORDPRESS.md` (product memory — connection, not asset)
- Prompt 31 HEAD (Meta real migration) is latest cumulative specialist work — **not** Website foundation

---

## Decision

**STATUS: BLOCKED**

Do **not**:

- Invent Prompt 32 WebsitePage / ingestion inside Prompt 33
- Write WordPress collectors directly into physical `website_*` tables
- Migrate Website analytical tabs (Prompt 35)
- Call DataForSEO (Prompt 34)
- Create Evidence / Findings / Opportunities / AI
- Implement recurring scheduler

**Unblock path:**

1. Complete **Prompt 32 — Website Production Data Foundation**
2. Re-base Prompt 33 from exact Prompt 32 cumulative HEAD
3. Resume WordPress connector productionization against Prompt 32 ingestion contracts

## Prompt 34 Handoff

Not started. DataForSEO Production Enrichment waits for Website foundation + WordPress (or other) sources as applicable.

## Prompt 35 Handoff

Not started. Website Real Data Migration waits for foundation + sources.

## Definition of Done

Prompt 33 DoD **cannot** be satisfied until Prompt 32 is green. All DoD invariants that depend on canonical Website ingestion are **N/A — blocked**.
