# Remaining Production Gaps

Prompt 67 — **only** PARTIAL, UNAVAILABLE, NOT_MANUALLY_VERIFIED items, deployment requirements, and blockers.

Do not list REAL capabilities here. Authoritative full table: `FINAL_CAPABILITY_REALITY_MATRIX.md`.

---

## 1. PARTIAL capabilities

| Capability | Gap | Notes |
| --- | --- | --- |
| DataForSEO | Paid live refresh / deeper hub UX | Config + cost guards; live calls NOT_MANUALLY_VERIFIED |
| WordPress site connector | Full production catalog sections / pairing depth | Credentials + WP environment required |
| AI provider hub cards | Env-key “configured” only | Not full connection lifecycle UX |
| Data freshness / incremental | Provider-dependent coverage | Demo sibling chips removed; honesty ≠ completeness |
| Integrity / reconciliation | Not all datasets covered | — |
| Meta Campaign/Ad detail (production) | Gated; creative taxonomy incomplete | No Demo narrative fill |
| GBP (thin) | Reputation intelligence / rich workspace | Local rank grid separately UNAVAILABLE |
| Client Value Story | Brand tabs PARTIAL; dashboard home narrative removed | Catalog fixtures retained for Atlas only |
| Report PDF / share / delivery | Depends on mail + object storage | Code REAL-ish; deploy PARTIAL |
| Notifications mail channel | DB notifications REAL; email depends on SMTP | — |
| AI agent execution | Subset of specialists | API keys required |
| Intelligence memory / retrieval | Website retrieval ahead of others | — |
| Sector learning / brand experience | Bounded slices | Privacy constraints |
| Skill normalization | Catalog/normalization ahead of unsafe external adoption | — |
| Provider API telemetry | Meta wired; other HTTP clients pending | Prompt 66 handoff |
| Website observations vs operator shell | Observations may exist in modules; operator Website analytics still unavailable | Treat operator Website analytics as UNAVAILABLE until wired |

---

## 2. UNAVAILABLE capabilities

| Capability | Why |
| --- | --- |
| GA4/Meta/GoogleAds/GSC residual specialist cards on real path | Cleared — needs_attention, opportunities, operations findings, business_actions, Demo clusters, etc. |
| Dashboard `recentValue` Atlas narrative | Explicitly `[]` |
| Agency `system_exceptions` / `recent_outcomes` Demo rows | Cleared |
| Website specialist analytics (production numeric assets) | `UnavailableWorkspaceShells` |
| GBP local rank grid (production) | No fabricated rankings |
| Instagram analytics (production) | No provider analytics path |
| Interactive Assistant chat runtime | Prompt 56 architecture-first only |
| Invented health scores | Forbidden; observability `overall_score=null` |

---

## 3. NOT_MANUALLY_VERIFIED (Prompt 67)

Prompt 67 did **not** execute live provider or mail UAT in this environment:

- Google OAuth (Cloud console + consent)
- Meta OAuth / Graph collect
- GA4 / GSC / Google Ads / Meta live collection against production accounts
- DataForSEO paid endpoints
- SMTP report/notification delivery
- Formal operator UAT PASS for portfolio CRUD (code-tested only)

Prior ledger UAT PASS (e.g. historical Meta connection slice) is **not** re-asserted as Prompt 67 manual verification.

---

## 4. Deployment requirements (blockers for REAL code to operate)

| Requirement | Blocks if missing |
| --- | --- |
| Provider OAuth app credentials (Google/Meta) | Connect / discover / collect |
| Queue workers (database queue and/or Horizon) | Async collect, AI guidance, delivery jobs |
| Scheduler / cron (`schedule:run`, including `moxdop:ops:evaluate-alerts`) | Recurring collection, intelligence, alerts |
| Mailer (`MAIL_*`) | Email notifications, report delivery |
| Object storage / disk for raw payloads & PDFs | Collection artifacts, report artifacts |
| `APP_KEY` (+ previous keys if rotating) | Credential decrypt |
| Optional Redis | Horizon / cache features when configured |
| PostgreSQL | Production partition semantics (SQLite OK for tests/dev subset) |

Missing deployment config must surface as **unavailable/not connected** — never Demo fallback.

---

## 5. Product blockers (future work)

1. Wire specialist operations cards to canonical Finding/Opportunity reads (optional product choice).
2. Website / GBP / Instagram operator production workspace migration from unavailable shells.
3. Assistant chat runtime implementation (only after architecture gate).
4. GBP local visibility grid productionization with real geo data (no invention).
5. Hub deep-connection models for non-Google/Meta providers when product requires.
6. Remaining provider HTTP telemetry wiring.

---

## 6. Explicit non-blockers

- Retained Atlas Explicit Demo Runtime under catalog string ids.
- `App\Livewire\Demo` namespace naming debt (historical).
- Performance/evaluation/test fixtures.
- Absence of ObservabilityV2 / Prometheus / autonomous remediation (intentional).
