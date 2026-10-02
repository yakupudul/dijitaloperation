# Capability Reality Contract

Prompt 67 — authoritative definitions for claiming product capability status in MoxDOP.

**Companion docs:** `docs/implementation/DEMO_REALITY_FINAL_CONVERGENCE.md`, `docs/architecture/DEMO_ISOLATION_CONTRACT.md`, `docs/reality/FINAL_CAPABILITY_REALITY_MATRIX.md`.

---

## 1. Purpose

Prevent overclaiming. A capability may be product-designed, partially coded, Demo-illustrated, or production-backed. Only evidence-backed statuses enter the Final Capability Reality Matrix.

## 2. Status vocabulary (exclusive)

| Status | Meaning |
| --- | --- |
| **REAL** | Production path exists for the claimed slice: durable storage and/or authoritative read service; operator UI (when in scope) does not substitute Demo business truth; automated tests cover the slice; no silent Demo fallback on error or empty data. |
| **PARTIAL** | Meaningful production code exists, but a material sub-surface is empty, unavailable, Demo-only, config-dependent, or not yet wired. Document the gap explicitly. |
| **DEMO** | Capability is intentionally illustrated only under Explicit Demo Runtime (Atlas catalog string asset ids and/or DemoState session). Must not present as live Customer truth. |
| **UNAVAILABLE** | No honest production data path for the claimed slice. UI must show empty/unavailable shells — never invented metrics, ranks, health scores, or Demo fixtures. |

Do **not** use percentages, “mostly real,” or “implemented V1” as Reality Matrix statuses.

## 3. Evidence requirements for REAL

All of the following must hold for the scoped claim:

1. **Canonical source** — named model/table, read service, or collector/contract documented and present in code.
2. **Read path** — operator or system consumer reads that source (not fixtures) for production identifiers.
3. **Write path** (when applicable) — mutations go through production services/migrations; Demo session writes do not masquerade as persistence.
4. **Tests** — PHPUnit (or documented suite) asserts production behavior without requiring Demo catalog ids for the production claim.
5. **No Demo fallback** — query failure, missing binding, or empty pool yields UNAVAILABLE/empty — not `*WorkspaceFixtures`.
6. **Manual verification** — live OAuth/SMTP/paid providers remain `NOT_MANUALLY_VERIFIED` unless an explicit PASS is recorded. Absence of manual PASS does not by itself demote code-backed REAL storage/read slices, but deployment readiness must stay honest.

## 4. Evidence requirements for PARTIAL

- At least one production-backed sub-path exists **and** at least one material gap is documented (UI shell, residual domain cleared to empty, config gate, missing collector, etc.).
- PARTIAL must not hide Demo contamination on the production path.

## 5. Evidence requirements for DEMO

- Entry only via Explicit Demo Runtime (`DemoCatalog` string asset ids, Demo portfolio routes, or DemoState session for Atlas).
- Labels/provenance must remain distinguishable from REAL.
- Retained fixtures must be listed in the Production Demo Removal Audit as intentional.

## 6. Evidence requirements for UNAVAILABLE

- No production read that can honestly populate the slice.
- Production UI uses empty arrays, unavailable chips, or `UnavailableWorkspaceShells` (or equivalent).
- Must not load Demo fixtures “to look complete.”

## 7. Product vs runtime vs deployment

| Layer | Question | Example |
| --- | --- | --- |
| **Product** | Is the capability in scope and designed for MoxDOP operators? | Frozen operator specialist IA (site root; ADR-044) |
| **Runtime** | Does this environment’s code path return real, demo, or unavailable data for a given asset id? | Numeric `DigitalAsset` → real/unavailable; `ga4-atlas` → demo_catalog |
| **Deployment** | Are external credentials, queue workers, mail, object storage, and schedules configured so REAL code can operate? | Google OAuth client, Horizon, SMTP |

A capability can be **REAL in code** and still **blocked in a given deployment** without credentials. That is a deployment gap, not a license to inject Demo.

## 8. Demo isolation (summary)

See `DEMO_ISOLATION_CONTRACT.md`. Production numeric asset ids and Filament `/admin` admin flows must never read Atlas fixture narratives as business truth.

## 9. No production Demo fallback

Forbidden patterns:

- Catch → return workspace fixtures
- Empty pool → clone Demo glance/needs_attention
- Integrations hub → fake `connected` / `last_check` without provider truth
- Dashboard → ClientValueFixtures / Atlas outcomes for production home
- Specialist residual cards left as Demo provenance on `migration_mode=real`

Required patterns:

- Empty / UNAVAILABLE / truthful not_connected|configured
- Explicit `demo_catalog` only for DemoCatalog asset ids

## 10. Manual verification policy

Unless a doc explicitly records PASS for a live provider/SMTP/OAuth exercise in this environment, mark:

`Manual verification: NOT_MANUALLY_VERIFIED`

Prompt 67 did **not** manually verify live Google/Meta OAuth, paid DataForSEO, or SMTP delivery.

## 11. Authority

When docs conflict: MASTER_SPEC → accepted ADR → this contract + Final Matrix → older “IMPLEMENTED V1” marketing language.
