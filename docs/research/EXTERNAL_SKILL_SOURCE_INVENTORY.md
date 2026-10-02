# External Skill Source Inventory (Prompt 48)

> **Status:** RESEARCH ARTIFACT — no production code, no runtime, no dependency change
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47)
> **Parent artifact:** [`MOXDOP_SKILL_RESEARCH_MATRIX.md`](./MOXDOP_SKILL_RESEARCH_MATRIX.md)
> **Owns:** commit ledger, repository shape, skill inventories, install/runtime surface inventory

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| Production MoxDOP Skill implementation | **NOT YET** |
| Installers / scripts executed | **none** |
| MCP servers registered | **none** |

Clones were read-only under `/tmp/moxdop-skill-research/` at shallow depth 50 and are **never committed**. Remotes are recorded here as clean `https://github.com/owner/repo` URLs; no tokenized remote appears in MoxDOP documentation.

---

## 1. Commit ledger (full SHAs)

| # | Repository | Full SHA | Branch | Tags at/near SHA | Commit date | Commit subject (short) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | https://github.com/coreyhaines31/marketingskills | `7868cb9251fad80a73d26e488a5ad5f6c4a9f335` | `main` | `v2.10.0` (latest release line) | 2026-07-27 | sync skills with manifests and README |
| 2 | https://github.com/joshbuchea/HEAD | `de1304eeb62feb6bec90c28fc78fe29d2500d606` | `master` | none | 2026-05-01 | fix broken link to deprecated doc |
| 3 | https://github.com/AgriciDaniel/claude-seo | `09d37c7b66ed3ca9c6efbdb765a805a6c76a8f01` | `main` | `v2.2.4` (latest) | 2026-07-20 | docs: credit issue report analysis |
| 4 | https://github.com/every-app/open-seo | `bd402844fae9101da9591b8eb153871773eb3c27` | `main` | `v0.1.4` (latest) | 2026-08-11 | add public product roadmap |
| 5 | https://github.com/zubair-trabzada/geo-seo-claude | `e5d4a4a4f7bb10142f558b1df1308471948fb37c` | `main` | none | 2026-08-15 | chore: update star history chart |
| 6 | https://github.com/popiliadam/platinum-seo-engine | `3192d565fbb5629460cd4d86ffd35610db4293c0` | `main` | `v2.1.0` exists | 2026-08-08 | merge: record task_id grandfathering decision |
| 7 | https://github.com/garrettjsmith/localseoskills | `405ce209775f8cb8f9dbaa511656594bb682cf9f` | `main` | none | 2026-08-15 | update version to 1.1.1 in SKILL.md |
| 8 | https://github.com/seranking/seo-skills | `fd6d1408f2e6a06454d81c07c29e0f04342eb9ba` | `main` | `v2.10.4` (latest) | 2026-06-24 | merge CI frontmatter linter |
| 9 | https://github.com/addyosmani/agent-skills | `df1edb2e05487d0aa6d93c747141e0aed1187f25` | `main` | `0.6.7` **at SHA** | 2026-08-14 | chore(release): bump plugin manifests |
| 10 | https://github.com/aaron-he-zhu/aaron-marketing-skills | `5538e686f1d6ae6ad484a3445fbd8cf4ab840397` | `main` | `v19.2.0` | 2026-08-15 | chore(badges): refresh download stats |
| 11 | https://github.com/aaron-he-zhu/seo-geo-claude-skills | `1ae40e6d98dd2626b56cd1bee8700edc9fb71789` | `main` | `v9.9.12` | 2026-07-13 | sync: umbrella v18.0.0 |
| 12 | https://github.com/garmeeh/next-seo | `738ef7e774ee6216f6e90c26a3b5b99dd5d166b4` | `main` | `next-seo@7.3.0` **at SHA** | 2026-07-29 | Release: Version Packages |

Additional SHAs referenced for license history: `9563572fc3828d1e44aa676215f9f7ccd06fc2cf` (Platinum AGPL relicense), `eab3cb8b7c021f4b2256c93b5a65f4e203d9bd12` (Platinum `v2.1.0`).

## 2. Repository shape and skill counts

`SKILL.md` count is the observed file count at the audited SHA. It may differ from the README's advertised skill count, because repositories variously count phases, extensions, mirrors, or exclude meta-skills. Both numbers are recorded where they diverge.

| Repository | `SKILL.md` files | README-advertised | Notes on the divergence |
| --- | --- | --- | --- |
| marketingskills | 49 | — | One directory per skill under `skills/` |
| HEAD | 0 | n/a | Not a skill repo; a single taxonomy README |
| claude-seo | 33 | "25 sub-skills and 18 specialist agents" | 25 core `skills/seo-*` + 8 under `extensions/` (one is a mirror of `seo-image-gen`); agents are separate files |
| open-seo | 21 | "pre-built skills" | Split across `.agents/skills` (product) and `.claude/skills` (repo maintenance); several duplicated across both trees |
| geo-seo-claude | 16 | — | 15 under `skills/` + 1 top-level `geo/SKILL.md` |
| platinum-seo-engine | 50 | "50 skills" | Matches; 8 categories |
| localseoskills | 39 | "38 open-source skills" | 38 domain skills + a dispatch/brief style meta skill |
| seo-skills | 32 | "25 of 26 skills" installable via marketplace | 32 files include extension skills; one core skill needs a local clone |
| agent-skills | 24 | "24 skills (23 lifecycle + 1 meta)" | Matches |
| aaron-marketing-skills | 120 | "120 marketing skills" | 7 disciplines × 16 + 8 protocol-layer skills; `seo-geo/` holds 16 |
| seo-geo-claude-skills | 20 | frozen 20-skill line; 16 active in umbrella | Frozen at `v9.9.12`; README maps old paths to new |
| next-seo | 0 | n/a | TypeScript library, not a skill repo |

**Corpus total observed `SKILL.md` files: 404** across the 10 skill-bearing repositories. Not every file was read line by line (see limitations in the parent matrix §36).

## 3. Repository tree highlights

| Repository | Notable top-level artifacts |
| --- | --- |
| marketingskills | `skills/` (49), `tools/`, `AGENTS.md`, `VERSIONS.md` (60 KB), `.claude-plugin/`, `validate-skills.sh`, `validate-skills-official.sh` |
| HEAD | `README.md` (29 KB), `DEPRECATED.md` (7 KB), `.prettierignore` |
| claude-seo | `skills/` (25), `extensions/` (8 vendor extensions), `agents/`, `hooks/`, `schema/`, `scripts/`, `tests/`, `data/`, `pdf/`, `install.sh`, `install.ps1`, `uninstall.sh`, `uninstall.ps1`, `pyproject.toml`, `requirements.txt`, `CHANGELOG.md` (67 KB), `CITATION.cff`, `PRIVACY.md`, `SECURITY.md` |
| open-seo | `src/`, `web/`, `drizzle/` + `drizzle-pg/`, `e2e/`, `specs/`, `badseo/`, `alchemy.run.ts`, `compose.yaml`, `Dockerfile.selfhost`, `docker-entrypoint.sh`, four `.env.*.example` variants, `package.json` |
| geo-seo-claude | `skills/` (15), `geo/`, `agents/`, `schema/`, `templates/`, `white-label/`, `examples/`, `tests/`, `scripts/`, `install.sh`, `install-win.sh`, `uninstall.sh`, `requirements.txt`, two PR-draft markdown files |
| platinum-seo-engine | `skills/` (8 categories), `commands/` (30), `hooks/`, `rules/`, `schemas/`, `scripts/` (20 dirs), `templates/`, `tests/` (23 dirs), `.mcp.json`, `mcp-tool-registry.json` (35 KB), `ctr-curve.json`, `google-update-calendar.json`, `conftest.py`, `pytest.ini`, `requirements-lock.txt` |
| localseoskills | `skills/` (39), `tasks/` (15 templates), `briefs/`, `meta/`, `specs/`, `tools/`, `docs/`, `install.sh`, `install.ps1`, `uninstall.sh`, `uninstall.ps1`, `AGENTS.md`, `VERSIONS.md`, `PRIVACY.md`, `SECURITY.md`, `CODEOWNERS` |
| seo-skills | `skills/` (32), `examples/` (27 dated deliverable dirs), `extensions/` (google, firecrawl), `schemas/`, `scripts/`, `install.sh`, `mcp.json`, `.mcp.json`, `.claude-plugin/`, `.codex-plugin/`, `.cursor-plugin/`, `CHANGELOG.md` (74 KB) |
| agent-skills | `skills/` (24), `commands/` (8), `agents/`, `evals/` (README + `cases/` + `fixtures/`), `hooks/`, `references/`, `scripts/`, `docs/`, `plugin.json`, host manifests for Claude/Codex/Gemini/OpenCode |
| aaron-marketing-skills | 7 discipline dirs (`ad/`, `email/`, `influencer/`, `launch/`, `narrative/`, `seo-geo/`, `social/`), `protocol/`, `memory/`, `evals/` (123 entries), `references/` (11 dirs incl. benchmark definitions), `commands/`, `hooks/`, `badges/`, `scripts/`, `CONNECTORS.md` (57 KB), `README.md` (87 KB), `VERSIONS.md` (88 KB), `marketplace.json`, `openclaw.plugin.json`, `skills.sh.json`, `docs/mcp-catalog.json` |
| seo-geo-claude-skills | `research/`, `build/`, `optimize/`, `monitor/`, `cross-cutting/`, `README.md` with old→new mapping |
| next-seo | `src/`, `tests/` (vitest + playwright), `examples/`, `README.md` (201 KB), `ADDING_NEW_COMPONENTS.md`, `CUSTOM_COMPONENTS.md`, `LIST.md`, `.changeset/`, `package.json`, `pnpm-workspace.yaml` |

## 4. Skill inventories (by repository)

Recorded to establish coverage, not to endorse. Names are factual identifiers.

### 4.1 `claude-seo` — 25 core skills

`seo`, `seo-audit`, `seo-plan`, `seo-flow`, `seo-technical`, `seo-page`, `seo-content`, `seo-content-brief`, `seo-cluster`, `seo-schema`, `seo-sitemap`, `seo-hreflang`, `seo-images`, `seo-image-gen`, `seo-geo`, `seo-local`, `seo-maps`, `seo-ecommerce`, `seo-programmatic`, `seo-competitor-pages`, `seo-backlinks`, `seo-drift`, `seo-sxo`, `seo-google`, `seo-dataforseo`

Extensions (8): `ahrefs`, `seranking`, `banana`, `profound`, `unlighthouse`, `bing-webmaster`, `firecrawl`, `dataforseo`.

### 4.2 `geo-seo-claude` — 16 skills

`geo` (root), `geo-audit`, `geo-citability`, `geo-crawlers`, `geo-technical`, `geo-schema`, `geo-content`, `geo-brand-mentions`, `geo-llmstxt`, `geo-platform-optimizer`, `geo-compare`, `geo-update`, `geo-report`, `geo-report-pdf`, `geo-prospect`, `geo-proposal`

Note the split: 10 analysis skills, 6 reporting/agency-sales skills. Only the analysis subset is research-relevant.

### 4.3 `platinum-seo-engine` — 50 skills in 8 categories

| Category | Count | Skills |
| --- | --- | --- |
| Ingestion | 5 | `gsc-pull`, `dfs-pull`, `sf-crawl-orchestrator`, `sf-import`, `scrapling-ops` |
| Discovery | 14 | `quick-wins`, `content-gaps`, `cannibalization`, `content-decay`, `on-page-audit`, `schema-audit`, `tech-audit`, `geo-analysis`, `competitive-analysis`, `aio-competitor-map`, `gbp-audit`, `facet-nav-audit`, `robots-policy-audit`, `hreflang-audit` |
| Planning | 6 | `topical-map`, `cluster-map`, `new-content-plan`, `internal-links`, `master-task-sync`, `migration-map` |
| Production | 5 | `new-blog`, `revise-content`, `faq-optimization`, `content-remediation`, `generate-images` |
| Publishing | 2 | `indexing-ping`, `verify-indexing` |
| Reporting | 9 | `weekly-summary`, `monthly-report`, `monitoring-weekly`, `portfolio-overview`, `portfolio-heatmap`, `portfolio-kpi-trend`, `portfolio-task-heatmap`, `portfolio-weekly-brief`, `portfolio-monthly-roundup` |
| Meta | 4 | `init-project`, `brand-onboarding`, `whats-next`, `mark-done` |
| Governance | 4 | `schema-validate`, `glossary-audit`, `drift-check`, `load-context` |

`publishing/*` performs external write actions (index submission) and is rejected outright for MoxDOP.

### 4.4 `localseoskills` — 39 skills

Analysis / domain: `local-seo-audit`, `gbp-optimization`, `gbp-posts`, `gbp-suspension-recovery`, `gbp-api-automation`, `review-management`, `local-citations`, `local-schema`, `local-landing-pages`, `local-keyword-research`, `local-competitor-analysis`, `local-content-strategy`, `local-content-briefs`, `local-link-building`, `multi-location-seo`, `service-area-seo`, `geogrid-analysis`, `ai-local-search`, `local-reporting`, `client-deliverables`, `brief`, `dispatch`

Ads: `local-search-ads`, `local-ppc-ads`, `lsa-ads`, `lsa-spy-tool`

Vendor tool skills: `google-search-console-tool`, `google-analytics-tool`, `semrush-tool`, `ahrefs-tool`, `dataforseo-tool`, `brightlocal-tool`, `local-falcon-tool`, `whitespark-tool`, `serpapi-tool`, `localseodata-tool`, `screaming-frog-tool`

Other: `apple-business-connect`, `bing-places`

### 4.5 `seo-skills` (SE Ranking) — 32 skills

`seo-page`, `seo-technical-audit`, `seo-content-audit`, `seo-content-brief`, `seo-schema`, `seo-sitemap`, `seo-hreflang`, `seo-subdomain`, `seo-drift`, `seo-sxo`, `seo-images`, `seo-google`, `seo-api`, `seo-plan`, `seo-local`, `local-gmb-visibility`, `seo-keyword-cluster`, `seo-keyword-niche`, `seo-competitor-pages`, `seo-competitor-gap-analysis`, `seo-backlink-gap`, `seo-backlinks-profile`, `seo-geo`, `seo-ai-search-share-of-voice`, `seo-ai-social-report`, `seo-gaps-to-social-campaign`, `ai-search-gaps-to-social-campaign`, `site-audit-to-social-distribution`, `seo-ads`, `seo-agency-landing-page`, `client-onboarding-proposal`, `seo-firecrawl`

### 4.6 `agent-skills` (addyosmani) — 24 skills

`idea-refine`, `interview-me`, `spec-driven-development`, `planning-and-task-breakdown`, `incremental-implementation`, `test-driven-development`, `doubt-driven-development`, `source-driven-development`, `debugging-and-error-recovery`, `observability-and-instrumentation`, `browser-testing-with-devtools`, `performance-optimization`, `security-and-hardening`, `code-review-and-quality`, `code-simplification`, `api-and-interface-design`, `frontend-ui-engineering`, `ci-cd-and-automation`, `git-workflow-and-versioning`, `deprecation-and-migration`, `documentation-and-adrs`, `shipping-and-launch`, `context-engineering`, `using-agent-skills`

None are SEO. The value is the **schema**, documented in the parent matrix §17.

### 4.7 `aaron-marketing-skills` — `seo-geo` discipline (16 of 120)

| Phase | Skills |
| --- | --- |
| survey | `keyword-research`, `competitor-analysis`, `serp-analysis`, `content-gap-analysis` |
| implement | `content-writer`, `geo-content-optimizer`, `serp-markup-builder`, `page-play-builder` |
| tune | `content-quality-auditor`, `technical-seo-checker`, `on-page-seo-checker`, `site-structure-optimizer` |
| evaluate | `domain-authority-auditor`, `rank-tracker`, `performance-monitor`, `offsite-signal-analyzer` |

Other disciplines (narrative, social, email, ad, influencer, launch — 16 each) plus an 8-skill protocol layer are out of MoxDOP scope except for the auditor-gate and tiered-data patterns.

### 4.8 `seo-geo-claude-skills` — frozen 20-skill line

`research/`: `keyword-research`, `competitor-analysis`, `serp-analysis`, `content-gap-analysis`
`build/`: `seo-content-writer`, `geo-content-optimizer`, `meta-tags-optimizer`, `schema-markup-generator`
`optimize/`: `on-page-seo-auditor`, `technical-seo-checker`, `internal-linking-optimizer`, `content-refresher`
`monitor/`: `rank-tracker`, `performance-reporter`, `backlink-analyzer`, `alert-manager`
`cross-cutting/`: `content-quality-auditor`, `domain-authority-auditor`, `entity-optimizer`, `memory-management`

### 4.9 `open-seo` — 21 skill files across two trees

Product-facing (`.agents/skills/`): `keyword-research`, `keyword-clustering`, `competitor-analysis`, `competitive-landscape`, `seo-audit`, `seo-coach`, `seo-project-setup`, `link-prospecting`, `openseo-review-web-content`, `openseo-release-notes`, `webapp-testing`, `simple-issue-description`, `merge-ready`, `deslop`, `papercuts`, `maintain-greptile-rules`

Repo-maintenance (`.claude/skills/`): `openseo-release-notes`, `openseo-review-web-content`, `merge-ready`, `deslop`, `papercuts`

Only ~9 of the 21 are SEO methodology; the rest are engineering-workflow skills. This is a reminder that a raw `SKILL.md` count overstates domain coverage.

### 4.10 `marketingskills` — 49 skills (observed subset)

`customer-research`, `copywriting`, `public-relations`, `signup`, `ads`, `product-marketing`, `marketing-plan`, `cold-email`, `sms`, `offers`, `marketing-council`, `pricing`, `onboarding`, `popups`, `marketing-psychology`, `competitors`, `co-marketing`, `site-architecture`, `ab-testing`, `lead-magnets`, `churn-prevention`, `attribution`, `sales-enablement`, `marketing-loops`, `video`, `marketing-ideas`, … (49 total)

Only `site-architecture`, `attribution`, `competitors`, and `ads` touch capabilities MoxDOP models.

## 5. Install and runtime surface inventory

Recorded so the rejection is auditable. **Nothing in this table was executed.**

| Repository | Install / runtime surface observed | MoxDOP action |
| --- | --- | --- |
| marketingskills | `.claude-plugin/` marketplace manifest; two local `validate-skills*.sh` scripts | Not run |
| HEAD | none | n/a |
| claude-seo | `install.sh`, `install.ps1`, `uninstall.sh`, `uninstall.ps1`; README documents a `curl \| bash` one-liner; 8 extension `install.sh`; `docs/MCP-INTEGRATION.md`; `scripts/setup_mcp.py`; Python `requirements.txt` / `pyproject.toml`; `hooks/` | Not run; MCP not registered |
| open-seo | Docker `compose.yaml` + `Dockerfile.selfhost` + `docker-entrypoint.sh`; Cloudflare/alchemy deploy scripts; `src/server/mcp`; MCP activation code; `npm` toolchain | Not run |
| geo-seo-claude | `install.sh`, `install-win.sh`, `uninstall.sh`; README documents a `curl \| bash` one-liner; Python `requirements.txt` | Not run |
| platinum-seo-engine | `.mcp.json` declaring 6 MCP servers (incl. one over local HTTP); `mcp-tool-registry.json`; 30 slash commands; 6 hooks; `scripts/` with 20 subdirectories; pytest suite | Not run; MCP not registered |
| localseoskills | `install.sh`, `install.ps1`, `uninstall.sh`, `uninstall.ps1`; README documents a `curl \| bash` one-liner; `tasks/` scheduler templates; `tools/` | Not run |
| seo-skills | `install.sh` (interactive, with a `curl \| bash --all` one-liner documented); `mcp.json` + `.mcp.json`; plugin manifests for three hosts; two extension installers | Not run; MCP not registered |
| agent-skills | `npx skills add …` documented; `plugin.json` + four host manifests; `hooks/`; `scripts/` | Not run |
| aaron-marketing-skills | `npx skills add …` and plugin-marketplace install documented; `marketplace.json`, `openclaw.plugin.json`, `skills.sh.json`; `.githooks/`; `scripts/install-git-hooks.sh`; `docs/mcp-catalog.json` deliberately outside the auto-registered path | Not run |
| seo-geo-claude-skills | Install instructions point to the umbrella repo | Not run |
| next-seo | `npm install next-seo`; husky hooks; playwright/vitest | Not run |

**Aggregate:** 8 of 12 repositories ship an installer, and 5 document a pipe-to-shell one-liner. 4 repositories declare or wire MCP servers. This is precisely why the MoxDOP rule is methodology-only extraction with no execution.

## 6. Maintenance signals (risk only, not evidence)

Popularity and cadence never affect the evidence ladder (parent matrix R2). They inform how stale a methodology reference may become.

| Repository | Last commit at audit | Release cadence signal | Maintenance read |
| --- | --- | --- | --- |
| aaron-marketing-skills | 2026-08-15 | `v19.2.0`; funding present | **ACTIVE** — the live SEO/GEO line |
| localseoskills | 2026-08-15 | version bumps in-skill, no tags | Active |
| geo-seo-claude | 2026-08-15 | no tags; last commit was a star-history chart refresh | Active but promotion-heavy |
| agent-skills | 2026-08-14 | `0.6.7` tagged at SHA | Active |
| open-seo | 2026-08-11 | `v0.1.4`; early-stage versioning | Active, early |
| platinum-seo-engine | 2026-08-08 | `v2.1.0` | Active |
| next-seo | 2026-07-29 | `next-seo@7.3.0` | Active, mature library |
| marketingskills | 2026-07-27 | `v2.10.0`; funding present | Active |
| claude-seo | 2026-07-20 | `v2.2.4`; funding present; public repo mirrors a private community repo | Active; dual-repo model means public lag is possible |
| seo-geo-claude-skills | 2026-07-13 | frozen at `v9.9.12` | **FROZEN / SIGNPOST** |
| seo-skills | 2026-06-24 | `v2.10.4` | Active, vendor-maintained |
| HEAD | 2026-05-01 | no tags | Slow-moving reference taxonomy (appropriate for its purpose) |

## 7. Secondary repositories (registry only)

Decisions unchanged from [`EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](./EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md); no deep re-audit was required in Prompt 48.

| Repository | Standing decision |
| --- | --- |
| https://github.com/pipeboard-co/meta-ads-mcp | REJECTED RUNTIME (MCP + writes); Meta read-only module concepts only |
| https://github.com/georgekhananaev/google-reviews-scraper-pro | PRODUCT-CONCEPT ONLY; scraper REJECTED |
| https://github.com/Panniantong/Agent-Reach | PLANNED REFERENCE (capability routing) |
| https://github.com/msitarzewski/agency-agents | PARTIALLY ADOPTED concepts |

## 8. Inventory limitations

| Limitation | Effect |
| --- | --- |
| Shallow clone depth 50 | Full authorship/license history not reconstructible |
| Skill path capture limited to the first 80 paths per repository | For `aaron-marketing-skills` (120 files) the enumerated subset is partial; the `seo-geo` discipline was enumerated in full from the tree |
| README-advertised counts differ from file counts | Both recorded in §2; neither is treated as authoritative over the other |
| Files were read selectively | Reading targeted audit/scoring/GEO/technical/governance skills; absence from this inventory is not proof of absence upstream |
| Repositories move fast | Several repositories were committed to within 48 hours of the audit; all statements are pinned to §1 SHAs |
