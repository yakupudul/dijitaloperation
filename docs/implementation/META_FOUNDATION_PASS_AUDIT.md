# Phase 0 — Implementation audit (Meta Foundation + Final Workspace)

Written before code changes for this milestone. Continue **existing** PR #122 only.

| Fact | Value |
|------|-------|
| main SHA | `f6818f068f5a19210ef2a480da9a5ecdcfe0c58b` (Async #121 merged) |
| Meta Workspace branch | `cursor/meta-ads-expert-workspace-ea01` |
| Meta Workspace head (pre-change) | `4eea48375e217c158ff77969eab136c2dc6df425` |
| Open Meta PR | https://github.com/yakupudul/dijitaloperation/pull/122 |
| Async Operations on main | **YES** |
| Meta real provider engine (#119) | **YES** (merged) |
| Competing Meta Workspace PR | **NONE** — continue #122 |
| Behind main? | **NO** |

## Current capability

| Area | State |
|------|-------|
| Historical storage | **None** — Insights live as Evidence JSON snapshots only |
| Date-query behavior | Selected period must match Evidence `requested_period` / preset; otherwise "not loaded" + Analyze |
| UI data source | `MetaAdsWorkspaceData` ← Evidence by `digital_asset_id` |
| Daily trend | Bounded Evidence `meta_ads_account_daily_trend` for one selected period — not warehouse |
| Run scope | `digital_asset_id` **NOT NULL** — blocks pre-binding import |
| Operator DB | SQLite local with real Meta Integration id=1, 31 discovered Ad Accounts |
| Real UAT account | Binding asset=1 → `act_744654160596455` (Obezite ve Estetik) |
| Screenshots (pass 2) | Real operator data against then-current HEAD |

## Provider history constraint (Meta Marketing API v26.0)

- Aggregate Insights without unique/frequency breakdowns: up to **37 months** (error 3018 beyond)
- Unique-count / hourly breakdowns: **13 months**
- Frequency breakdowns: **6 months**
- Product label: "Import Meta history" = provider-available history within these constraints
- UI: `History available from <earliest>` — never claim infinite all-time

## Design intent for this pass

1. Global MoxDOP Design System (doc + tokens + Blade primitives) applied to Meta workspace
2. Meta historical store anchored on Integration + ExternalResource (pre-binding)
3. Integration-scoped Run + Activity: "Meta history import"
4. Local date queries; no Analyze-per-date for covered ranges
5. Exact-period cache for Reach/Frequency
6. Operator visual acceptance required — **DO NOT MERGE**
