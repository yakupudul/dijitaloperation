# MoxDOP Skill Research Matrix (Prompt 48)

> **Status:** RESEARCH ARTIFACT — **no production code, no runtime, no dependency change**
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47 — Activity / Notification convergence)
> **Branch:** `cursor/external-skill-repository-audit-ea01`
> **Artifact role:** PRIMARY Prompt 48 research matrix. Satellite files carry the deep per-axis registers.

**Two facts that bound this entire document:**

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| Production MoxDOP Skill implementation from this research | **NOT YET** (Prompt 49 owns normalization; no runtime in Prompt 48) |

Clones were made **read-only** under `/tmp/moxdop-skill-research/` at shallow depth 50. Clones are **never committed**. No installer, `install.sh`, `curl | bash`, `npx skills add`, plugin marketplace command, or MCP registration was executed. No package manifest (`composer.json`, `package.json`) was touched.

### Satellite artifacts (authoritative per axis)

| File | Owns |
| --- | --- |
| [`EXTERNAL_SKILL_SOURCE_INVENTORY.md`](./EXTERNAL_SKILL_SOURCE_INVENTORY.md) | Commit ledger, repository shape, skill inventories, install/runtime surfaces |
| [`EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md`](./EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md) | Evidence levels A–H, per-capability evidence requirements, data availability, abstention rules |
| [`EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md`](./EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md) | Unsafe assumption register, magic-score inventory, GEO causality claims, missing-as-zero traps |
| [`EXTERNAL_SKILL_LICENSE_PROVENANCE.md`](./EXTERNAL_SKILL_LICENSE_PROVENANCE.md) | License classes, copy classification, attribution and copyleft review notes |
| [`MOXDOP_SKILL_CANDIDATES.md`](./MOXDOP_SKILL_CANDIDATES.md) | Candidate MoxDOP Skills by capability, with status, data, evals |

Living registry of tracked repositories and long-run adoption decisions stays in [`EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](./EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md).

---

## 1. Purpose and scope

Prompt 48 answers one question: **which externally published SEO / GEO / marketing "Agent Skill" methodology is worth expressing as a MoxDOP Skill, and under what evidence and license conditions.**

In scope:

- Methodology extraction from 12 mandatory repositories at pinned full SHAs
- Classification of every extracted concept against MoxDOP ontology and evidence rules
- License and provenance assessment sufficient to decide copy posture
- Synthesis of candidate MoxDOP Skills **by capability**, not one per repository
- Explicit rejection register

Out of scope (deliberately, and enforced):

- Any change under `app/`, `database/`, `resources/`, `composer.json`, `package.json`
- Skill runtime, loader change, registry change, migration, or seeder
- MCP server registration, external write action, crawler, scheduler
- Installing or executing any external repository artifact

## 2. Research provenance and method

| Step | What was done |
| --- | --- |
| 1 | Read-only shallow clone (depth 50) of each mandatory repository under `/tmp/moxdop-skill-research/` |
| 2 | Pin exact commit: full SHA, branch, commit date, tag list captured per repo |
| 3 | Capture license file presence and header text at the audited SHA |
| 4 | Enumerate `SKILL.md` inventory and repository shape (top-level tree, install scripts, MCP surfaces) |
| 5 | Read methodology text selectively: audit/scoring skills, GEO skills, technical skills, contributor guidance |
| 6 | Classify each extracted concept against MoxDOP ontology, evidence ladder, data contracts |
| 7 | Cross-check factual claims against primary sources (see §31) |
| 8 | Record adoption, copy, license, and candidate status using the fixed vocabularies in §5 |

Method constraints that shaped conclusions:

- **Summarize, never paste.** No long prompt corpora, no verbatim skill bodies. Short factual quotations only where the exact wording is the finding (e.g. a published score weight).
- **Repo claims are recorded as repo claims.** Where MoxDOP disagrees, both the repo claim and the MoxDOP classification appear side by side. Repo claims are never silently rewritten.
- **Stars, download badges, marketplace listings, and release cadence are not evidence.** They inform maintenance risk only.

## 3. Authority model (what overrides what)

```text
docs/MASTER_SPEC.md + accepted ADRs        ← MoxDOP architecture truth
        ↓ overrides
Primary provider / standards documentation ← API + platform truth
   (Google Search Central, Schema.org, WHATWG,
    Meta Marketing API, GA4, GSC, Google Ads docs)
        ↓ overrides
docs/product/* blueprints                  ← product behaviour truth
        ↓ overrides
This research matrix                       ← reference registry
        ↓ overrides
External repository README / SKILL.md text ← lowest authority
```

An external repository never establishes a platform fact, a MoxDOP Finding rule, or a canonical metric. It can only propose a methodology that MoxDOP then re-derives from primary sources.

## 4. Hard product rules (encoded everywhere in this artifact)

| # | Rule | Consequence for adoption |
| --- | --- | --- |
| R1 | **Methodology ≠ code copy** | Adopt the analytical question and the output shape; re-implement inside MoxDOP |
| R2 | **Stars ≠ evidence** | Popularity affects nothing in the evidence ladder |
| R3 | **Primary sources override repo claims** | Every structural check must trace to Google Search Central / Schema.org / WHATWG / provider docs |
| R4 | **Do not silently rewrite repo claims** | Record the repo claim verbatim-short, then attach the MoxDOP classification |
| R5 | **License axis ≠ quality axis** | An AGPL repo can be excellent methodology and still be copy-blocked; an MIT repo can be weak methodology |
| R6 | **Missing ≠ zero** | Absent data yields `NOT_AVAILABLE` / abstention, never a 0 contribution to a metric |
| R7 | **Vendor estimate ≠ first-party measurement** | GSC clicks ≠ GA4 sessions ≠ DataForSEO volume/rank ≠ DataForSEO ETV ≠ SE Ranking metrics |
| R8 | **Magic composite scores are inventoried and rejected as canonical MoxDOP metrics** | They may be described as external methodology only |
| R9 | **GEO vocabulary must stay disaggregated** | AI bot accessibility / mention / citation / AI Overview appearance / entity presence / citability heuristic are five different things |
| R10 | **Skill ≠ Playbook (Prompt 45)** | Content-review style checklists lean Playbook, not AI Skill |
| R11 | **No Task auto-creation** | No adopted pattern may create Tasks, Findings, or Recommendations autonomously |
| R12 | **No second Evidence / Finding / Collection / DataForSEO stack** | Reuse existing MoxDOP contracts and pool; no parallel provider client |
| R13 | **DataForSEO stays behind Prompt 34 cost / freshness control** | No new DataForSEO call path from Skill research |
| R14 | **No external write actions, no crawlers, no schedulers from this research** | Prompt 61/62 owns recurring scheduling; MoxDOP integrations stay read-only |

## 5. Controlled vocabularies (enums)

**Adoption decision**

| Enum | Meaning |
| --- | --- |
| `ADOPT_CONCEPT` | The analytical concept is accepted into MoxDOP product language |
| `ADAPT_METHODOLOGY` | Method accepted but must be re-expressed against MoxDOP Evidence/contracts |
| `REFERENCE_ONLY` | Useful to read; no committed adoption |
| `REJECT` | Explicitly refused |
| `LICENSE_RESTRICTED` | Adoption blocked or narrowed by license posture |
| `NEEDS_PRIMARY_SOURCE_VERIFICATION` | Cannot adopt until re-derived from primary documentation |
| `NEEDS_DATA_SUPPORT` | Concept fine, MoxDOP lacks the data to support it honestly |
| `FUTURE_ONLY` | Deferred beyond current roadmap scope |

**Copy classification**

| Enum | Meaning |
| --- | --- |
| `DO_NOT_COPY` | No text or code reuse |
| `RESEARCH_ONLY` | Read to understand; nothing crosses into MoxDOP |
| `REEXPRESS_FROM_PRIMARY_SOURCES` | MoxDOP writes its own text from provider/standards documentation |
| `ADAPT_WITH_ATTRIBUTION_REVIEW` | Adaptation possible; attribution and notice handling must be reviewed first |
| `COPY_PERMITTED_SUBJECT_TO_LICENSE` | Copy legally available under license terms if MoxDOP chooses |
| `LEGAL_REVIEW_REQUIRED` | Human legal review before any reuse decision |

**License class**

| Enum | Meaning |
| --- | --- |
| `LICENSE_CLEAR_FOR_RESEARCH` | Reading and methodology study unambiguous |
| `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | MIT / Apache-2.0 style; notice obligations apply on reuse |
| `LICENSE_COPYLEFT_REVIEW_REQUIRED` | AGPL/GPL family; network-service copyleft implications |
| `LICENSE_AMBIGUOUS` | Conflicting or incomplete declarations |
| `LICENSE_MISSING` | No license file at audited SHA |
| `LEGAL_REVIEW_REQUIRED` | Escalation required |

**Candidate Skill status**

| Enum | Meaning |
| --- | --- |
| `READY_FOR_NORMALIZATION` | Prompt 49 can normalize schema now |
| `NEEDS_PRIMARY_SOURCE_WORK` | Method text must be re-derived first |
| `NEEDS_DATA` | Requires collector / contract work before honest output |
| `HEURISTIC_ONLY` | Can only ever emit advisory heuristics with uncertainty labels |
| `EXPERIMENTAL` | Allowed as labelled experiment, never as canonical truth |
| `PLAYBOOK_NOT_SKILL` | Belongs to Prompt 45 Playbook surface |
| `REJECTED` | Not a MoxDOP Skill |
| `LICENSE_BLOCKED` | Blocked on license posture |

**Evidence level (A–H)** — full definitions in §28 and in [`EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md`](./EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md).

**Data availability:** `AVAILABLE` · `PARTIAL` · `NOT_AVAILABLE` · `PROVIDER_LIMITED` · `REQUIRES_NEW_COLLECTOR` · `REQUIRES_OPERATOR_INPUT` · `HEURISTIC_ONLY`

**Evidence support:** `SUPPORTED` · `PARTIAL` · `UNSUPPORTED` · `FUTURE_DATA_REQUIRED`

## 6. Audited commit ledger (full SHAs)

All SHAs are full 40-character commit identifiers at the audited state. Remotes are recorded as clean `https://github.com/owner/repo` URLs; no tokenized remote appears in this documentation.

| # | Repository | Full SHA | Branch | Tag at/near SHA | Commit date |
| --- | --- | --- | --- | --- | --- |
| 1 | https://github.com/coreyhaines31/marketingskills | `7868cb9251fad80a73d26e488a5ad5f6c4a9f335` | `main` | `v2.10.0` | 2026-07-27 |
| 2 | https://github.com/joshbuchea/HEAD | `de1304eeb62feb6bec90c28fc78fe29d2500d606` | `master` | — | 2026-05-01 |
| 3 | https://github.com/AgriciDaniel/claude-seo | `09d37c7b66ed3ca9c6efbdb765a805a6c76a8f01` | `main` | `v2.2.4` | 2026-07-20 |
| 4 | https://github.com/every-app/open-seo | `bd402844fae9101da9591b8eb153871773eb3c27` | `main` | `v0.1.4` | 2026-08-11 |
| 5 | https://github.com/zubair-trabzada/geo-seo-claude | `e5d4a4a4f7bb10142f558b1df1308471948fb37c` | `main` | — | 2026-08-15 |
| 6 | https://github.com/popiliadam/platinum-seo-engine | `3192d565fbb5629460cd4d86ffd35610db4293c0` | `main` | `v2.1.0` exists | 2026-08-08 |
| 7 | https://github.com/garrettjsmith/localseoskills | `405ce209775f8cb8f9dbaa511656594bb682cf9f` | `main` | — | 2026-08-15 |
| 8 | https://github.com/seranking/seo-skills | `fd6d1408f2e6a06454d81c07c29e0f04342eb9ba` | `main` | `v2.10.4` | 2026-06-24 |
| 9 | https://github.com/addyosmani/agent-skills | `df1edb2e05487d0aa6d93c747141e0aed1187f25` | `main` | `0.6.7` | 2026-08-14 |
| 10 | https://github.com/aaron-he-zhu/aaron-marketing-skills | `5538e686f1d6ae6ad484a3445fbd8cf4ab840397` | `main` | `v19.2.0` | 2026-08-15 |
| 11 | https://github.com/aaron-he-zhu/seo-geo-claude-skills | `1ae40e6d98dd2626b56cd1bee8700edc9fb71789` | `main` | `v9.9.12` | 2026-07-13 |
| 12 | https://github.com/garmeeh/next-seo | `738ef7e774ee6216f6e90c26a3b5b99dd5d166b4` | `main` | `next-seo@7.3.0` | 2026-07-29 |

Additional referenced SHAs (Platinum license history, §32):

| SHA | Meaning |
| --- | --- |
| `9563572fc3828d1e44aa676215f9f7ccd06fc2cf` | Platinum relicense commit introducing AGPL-3.0 |
| `eab3cb8b7c021f4b2256c93b5a65f4e203d9bd12` | Platinum `v2.1.0` — last release the README states remains MIT |

## 7. Repository classification overview

| Repository | External kind | MoxDOP mapping | Primary research value |
| --- | --- | --- | --- |
| marketingskills | Skill library (broad marketing) | Skill methodology + shared-context pattern | Skill taxonomy and shared brand/product context |
| HEAD | Reference taxonomy | Website Diagnosis subject inventory | `<head>` element enumeration + deprecation list |
| claude-seo | Skill library (SEO/GEO) | Skill methodology | Falsifiable recommendation framing; primary-source discipline |
| open-seo | Full product + agent skills | Implementation reference | Provider cost model, workflow decomposition |
| geo-seo-claude | Skill library (GEO) | Skill methodology (heuristic) | GEO check inventory; also the clearest scoring/causality anti-pattern |
| platinum-seo-engine | Runtime architecture + skills | Architecture reference (copy-blocked) | Contracts / drift / append-only event discipline |
| localseoskills | Skill library (local/GBP) | Skill methodology (local) | GBP, review, citation, geogrid check inventory |
| seo-skills (SE Ranking) | Vendor-backed skill library | Skill methodology + cost pattern | Credit/rate-limit awareness and graceful degradation |
| agent-skills (addyosmani) | Skill engineering reference | Skill **schema** reference | Trigger / process / verification / exit-criteria / evals anatomy |
| aaron-marketing-skills | Skill library (active, multi-discipline) | Skill methodology (SEO/GEO line) | Auditor-gate pattern; keyless-tier data posture |
| seo-geo-claude-skills | Signpost / frozen line | Historical reference | Confirms consolidation into the umbrella bundle |
| next-seo | Library (framework) | Structured data taxonomy reference | JSON-LD type/property coverage |

**Do not create one MoxDOP Module or one MoxDOP Skill per repository.** Integration ≠ Module ≠ Agent ≠ Skill ≠ Capability ≠ Adapter ≠ Playbook.

## 8. Mandatory repository matrix — master adoption table

| Repository | Adoption | Copy classification | License class | Candidate contribution | Primary rejection |
| --- | --- | --- | --- | --- | --- |
| marketingskills | `ADAPT_METHODOLOGY` | `REEXPRESS_FROM_PRIMARY_SOURCES` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | Skill contract + shared context pattern | Bulk skill import; generic agent framework |
| HEAD | `ADOPT_CONCEPT` | `RESEARCH_ONLY` | `LICENSE_MISSING` (README claims CC0) | Metadata / head subject inventory | Treating repo prose as Google policy |
| claude-seo | `ADAPT_METHODOLOGY` + `NEEDS_PRIMARY_SOURCE_VERIFICATION` | `REEXPRESS_FROM_PRIMARY_SOURCES` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | Falsifiable recommendation frame; check inventories | SEO Health Score as canonical metric; installers; parallel sub-agent fan-out |
| open-seo | `REFERENCE_ONLY` | `RESEARCH_ONLY` | `LICENSE_AMBIGUOUS` (LICENSE MIT; `package.json` license null) | Cost-per-request discipline; workflow decomposition | Second DataForSEO stack; MCP runtime; SPA/edge storage architecture |
| geo-seo-claude | `NEEDS_PRIMARY_SOURCE_VERIFICATION` + `NEEDS_DATA_SUPPORT` | `REEXPRESS_FROM_PRIMARY_SOURCES` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | GEO observation check inventory (experimental) | Composite GEO Score; 30–115% causal framing; citability word-count as fact |
| platinum-seo-engine | `REFERENCE_ONLY` + `LICENSE_RESTRICTED` | `LEGAL_REVIEW_REQUIRED` | `LICENSE_COPYLEFT_REVIEW_REQUIRED` (AGPL-3.0 at SHA) | Contract/drift/append-only concepts already native to MoxDOP | Any code or schema copy; MCP fleet; Screaming Frog dependency |
| localseoskills | `ADAPT_METHODOLOGY` | `REEXPRESS_FROM_PRIMARY_SOURCES` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | Local profile completeness + review intelligence questions | Scheduler / task automation / approval tiers; `curl \| bash` install |
| seo-skills | `ADOPT_CONCEPT` (cost discipline) + `REFERENCE_ONLY` (methods) | `RESEARCH_ONLY` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | Credit preflight + graceful degradation pattern | SE Ranking metrics as MoxDOP truth; vendor MCP dependency |
| agent-skills | `ADAPT_METHODOLOGY` | `REEXPRESS_FROM_PRIMARY_SOURCES` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | **Skill schema source** for Prompt 49 | Software-engineering skill content; autonomous `/build auto` posture |
| aaron-marketing-skills | `ADAPT_METHODOLOGY` | `ADAPT_WITH_ATTRIBUTION_REVIEW` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (Apache-2.0) | Auditor-gate + tiered-data-honesty patterns | Connector runtime; 120-skill topology; branded benchmark scores |
| seo-geo-claude-skills | `REFERENCE_ONLY` | `RESEARCH_ONLY` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (Apache-2.0) | Historical lineage evidence | Adopting a frozen line as current methodology |
| next-seo | `REFERENCE_ONLY` (`SECONDARY_REFERENCE`) | `RESEARCH_ONLY` | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` (MIT) | Structured-data property coverage checklist | Next.js runtime/package dependency; component port |

## 9. `coreyhaines31/marketingskills`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `7868cb9251fad80a73d26e488a5ad5f6c4a9f335` / `main` / `v2.10.0` |
| License at SHA | MIT (`LICENSE`, Copyright 2025 Corey Haines) |
| Shape | 49 `SKILL.md` under `skills/`, plus `AGENTS.md`, `VERSIONS.md`, marketplace/plugin manifests, two local validation shell scripts |
| Domain | Broad marketing (customer research, copywriting, ads, pricing, onboarding, attribution, site architecture, CRO, competitors) |

**Useful methodology**

- One skill per **discipline question**, not per tool. The library is organized by what a marketer is trying to decide.
- A shared brand/product context layer that individual skills read instead of re-collecting basics. This is the closest external analogue to MoxDOP Brand Intelligence Context.
- Explicit version history per skill (`VERSIONS.md`) treating methodology as a versioned artifact.
- Repository-level skill validation scripts — the idea that a skill library has a machine-checkable contract.

**Reusable Skill pattern**

`Skill = bounded question + required context + method steps + output shape + version`. MoxDOP already implements the versioned side via `SkillDefinition` / `SkillRegistry`; the reusable increment is the *shared context* discipline and the *machine-validated contract* discipline.

**Evidence requirements**

Most skills in this library are generative/advisory and would map to MoxDOP evidence level F (expert heuristic) at best. Only `attribution` and `site-architecture` style skills touch measurable data, and those require GA4 / GSC / Website observation evidence before MoxDOP could express them.

**Unsafe assumptions**

- Skills assume the operator supplies accurate business context; no provenance model for that context.
- Marketing advice is presented without an evidence ladder, so a heuristic reads with the same confidence as a measured fact.
- No abstention rule when context is missing.

**License / provenance**

MIT with an explicit copyright holder. Reuse is legally available subject to notice retention, but MoxDOP posture is `REEXPRESS_FROM_PRIMARY_SOURCES` because the value is the taxonomy, not the prose.

**What MoxDOP should not copy**

Bulk import of 49 skills; the marketplace/plugin packaging; the local validation shell scripts; the assumption that a skill library equals a product.

## 10. `joshbuchea/HEAD`

| Field | Record |
| --- | --- |
| SHA / branch | `de1304eeb62feb6bec90c28fc78fe29d2500d606` / `master` |
| License at SHA | **No `LICENSE` file present.** README badge and License section claim CC0 1.0 |
| Shape | `README.md` (single large taxonomy document), `DEPRECATED.md`, no skills, no code |
| Domain | HTML `<head>` element taxonomy |

**Useful methodology**

- Exhaustive enumeration of `<head>` elements grouped by purpose: recommended minimum (`charset`, `viewport`, `title`), meta, link, scripts, icons, social (Open Graph, Schema.org, JSON-LD, Dublin Core, Fediverse, OEmbed), platform-specific, app links.
- An explicit **recommended order** concept — some head elements are order-sensitive, which is a structural fact worth checking rather than a preference.
- `DEPRECATED.md` as a first-class artifact: obsolete tags are tracked separately so a checker does not recommend dead markup. MoxDOP should mirror this as a **deprecated-subject list** inside the Diagnosis catalog rather than silently dropping subjects.

**Reusable Skill pattern**

`Subject inventory + deprecation list` feeding a deterministic presence/absence checker. This is a catalog input, not a Skill on its own.

**Evidence requirements**

Presence/absence of a head element in fetched HTML is evidence level A (direct structural fact) via `WEBSITE_DIRECT_OBSERVATION`. Whether that element **matters for Google** is level D and must come from Google Search Central, not from this repository.

**Unsafe assumptions**

- The repository mixes structural enumeration with SEO opinion. Treating any recommendation line as Google policy is rule R3 violation.
- Platform-specific vendor tags (Chinese mobile browsers, legacy Apple tags) are not universally relevant; a naive checker would emit noise Findings.
- No license file at the audited SHA while the README claims CC0 — a declaration mismatch, so verbatim copying is not safe even though the intent is clearly public-domain dedication.

**License / provenance**

`LICENSE_MISSING` with README CC0 claim. Do not copy prose. Extract the **subject list as facts** (element names are not creative expression) and write MoxDOP descriptions from WHATWG / Google Search Central.

**What MoxDOP should not copy**

The README text; SEO advice lines; vendor-specific tag recommendations as required checks.

## 11. `AgriciDaniel/claude-seo`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `09d37c7b66ed3ca9c6efbdb765a805a6c76a8f01` / `main` / `v2.2.4` |
| License at SHA | MIT (`LICENSE`, Copyright 2026 agricidaniel) |
| Shape | 33 `SKILL.md` (25 core `skills/seo-*` + 8 under `extensions/`), 18 agent definitions, `install.sh` / `install.ps1`, `hooks/`, `schema/`, `tests/`, `pyproject.toml`, `requirements.txt` |
| Domain | Technical SEO, content/E-E-A-T, schema, GEO, local, e-commerce, international, image |

**Useful methodology**

- **Falsifiable recommendation framing.** Every recommendation carries the observation it rests on, its dependencies, an explicit "how would we know this failed?" check, and a leading indicator. This is the single strongest transferable idea in the whole corpus and it aligns with MoxDOP's existing `recommendation-framing` Skill.
- **Primary-source citation discipline.** The repository routinely cites Google developer documentation and web.dev rather than blog folklore, and maintains a deprecated-schema-types reference with dated retirements.
- **Deprecation currency as a check.** It flags retired rich-result types and retired sitemap extensions instead of recommending them.
- **Explicit myth reframes.** The GEO skill explicitly reframes three popular claims (llms.txt as a citation lever, mandatory content chunking, AI-specific keyword rewriting) as unsupported. Publishing negative findings is a pattern MoxDOP should copy in spirit.
- **Threshold guards against doorway pages** in multi-location work (warning and hard-stop page counts) — the concept of a *volume guard that prevents a recommendation from becoming a spam tactic*.

**Repo claims recorded as claims (R4)**

| Repo claim (short) | MoxDOP classification |
| --- | --- |
| `SEO Health Score (0-100)` composed of Technical SEO 22%, Content Quality 23%, On-Page SEO 20%, Schema 10%, Performance (CWV) 10%, AI Search Readiness 10%, Images 5% | Magic composite → **REJECT as canonical MoxDOP metric** (§24) |
| `Content Quality XX/100`, `AI Citation Readiness XX/100`, `GEO Readiness XX/100` sub-scores | Magic composites → REJECT as canonical metrics |
| Passage citability "optimal 134–167 word self-contained answer blocks" | Heuristic (level F), attributed to a third-party passage analysis — not a platform rule |
| "GEO/AEO are rebranded labels for SEO; AI features are grounded in the same ranking systems" | Consistent with Google's AI-features documentation; adopt as level D **after** direct primary-source read |
| README growth screenshot presented as "real results" | Anecdote, level H if read causally — carries no weight |

**Reusable Skill pattern**

`Observation → Why it matters → Action → Dependencies → Success signal → Failure signal → Watch metrics`. MoxDOP should keep this as the canonical recommendation shape and add an explicit evidence-level field, which the source lacks.

**Evidence requirements**

Technical/schema/sitemap/hreflang checks map to level A structural facts plus level D platform rules. Content quality and E-E-A-T judgments are level F at best and must remain advisory AI output, never deterministic Findings.

**Unsafe assumptions**

- Weighted composite scoring implies commensurability between technical facts and subjective content judgment.
- Category scores are computed even when a category has no data — a missing-as-zero risk (R6).
- 18 parallel specialist agents assume orchestration reliability MoxDOP explicitly rejects.
- `install.sh` and a `curl | bash` install path in the README — **never executed** in this research.

**License / provenance**

MIT; reuse legally available with notice. Posture remains `REEXPRESS_FROM_PRIMARY_SOURCES` because most of the value is a re-statement of Google documentation MoxDOP can and must read directly.

**What MoxDOP should not copy**

The composite scores; the installers; the sub-agent fan-out architecture; the Python runtime; MCP extension wiring; the vendor extension skills (Ahrefs, SE Ranking, Profound, Firecrawl, Bing, DataForSEO mirror).

## 12. `every-app/open-seo`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `bd402844fae9101da9591b8eb153871773eb3c27` / `main` / `v0.1.4` |
| License at SHA | `LICENSE` = MIT (Copyright 2026 Ben Senescu); **`package.json` license field is null** → declaration mismatch |
| Shape | Full TypeScript product: web app, Drizzle schema (SQLite + Postgres variants), Cloudflare/alchemy deploy, Docker self-host, e2e tests, MCP server directory, 21 `SKILL.md` under `.agents/skills` and `.claude/skills` |
| Domain | Keyword research, rank tracking, competitor insight, backlinks, site audit, AI visibility — DataForSEO-backed |

**Useful methodology**

- **Explicit per-request cost consciousness.** The product is built around bring-your-own DataForSEO key, and the hosted margin is stated as a percentage uplift on each upstream request. Cost is a first-class product concept, not an afterthought. MoxDOP already encodes this in Prompt 34; this repository is corroboration, not a source.
- **Workflow decomposition.** Six named workflows map cleanly onto capability boundaries: demand research, position tracking, competitor comparison, off-site links, site audit, AI visibility.
- **Skills as thin wrappers over a data surface.** Skills describe *which calls to make and how to read them*, keeping methodology separate from transport.
- Separation of agent-facing skills (`.agents/skills`) from repo-maintenance skills (`.claude/skills`) — a useful reminder that "skills" in a repo are not all product methodology.

**Evidence requirements**

Everything DataForSEO-sourced is level C (provider-reported). Rank, volume, and ETV are vendor estimates and must never be presented alongside GSC clicks as the same class of number (R7).

**Unsafe assumptions**

- Treating vendor keyword volume and estimated traffic value as measurement.
- AI visibility features imply observability of AI answer surfaces that is not first-party measurable.
- Self-hosting architecture assumes edge storage and a subscription/billing layer irrelevant to MoxDOP.

**License / provenance**

`LICENSE_AMBIGUOUS`: MIT file, null manifest license field. Adequate for research; any reuse needs the mismatch resolved. Recorded, not rewritten.

**What MoxDOP should not copy**

**Do not import a second provider stack.** No second DataForSEO client, no MCP server, no Drizzle schema, no Cloudflare/R2 storage, no billing architecture, no SPA. MoxDOP DataForSEO access stays behind Prompt 34 cost/freshness control (R13).

## 13. `zubair-trabzada/geo-seo-claude`

| Field | Record |
| --- | --- |
| SHA / branch | `e5d4a4a4f7bb10142f558b1df1308471948fb37c` / `main` |
| License at SHA | MIT (`LICENSE`, Copyright 2026 Zubair Trabzada) |
| Shape | 16 `SKILL.md` (`geo-audit`, `geo-citability`, `geo-crawlers`, `geo-schema`, `geo-technical`, `geo-brand-mentions`, `geo-llmstxt`, `geo-platform-optimizer`, `geo-compare`, `geo-content`, reporting/prospect/proposal/white-label), `install.sh`, `install-win.sh`, `schema/`, `templates/`, `white-label/` |
| Domain | Generative Engine Optimization |

**Useful methodology**

- The **check inventory** is genuinely useful once separated from the scoring: AI crawler accessibility in `robots.txt`, structured data presence, entity/brand presence on third-party platforms, question-shaped headings, attribution density, self-contained answer blocks.
- `geo-crawlers` isolates the one part of GEO that is directly observable: whether named AI user-agents are permitted or disallowed by `robots.txt`. That is a level A structural fact.
- Distinguishing platform surfaces (ChatGPT, Claude, Perplexity, Gemini, Google AI Overviews) rather than treating "AI search" as one thing.

**Repo claims recorded as claims (R4)**

| Repo claim (short, as published) | MoxDOP classification |
| --- | --- |
| `GEO_Score = (Citability * 0.25) + (Brand * 0.20) + (EEAT * 0.20) + (Technical * 0.15) + (Schema * 0.10) + (Platform * 0.10)` — "weighted average of six category scores", 0–100 | Magic composite → **REJECT as canonical MoxDOP metric** |
| "Research from Princeton, Georgia Tech, and IIT Delhi (2024) found that GEO-optimized content achieves 30-115% higher visibility in AI-generated responses" | **HEURISTIC / SECONDARY.** Correlational study framing (level G). Presented in the repo in a way that reads causal; MoxDOP must not restate it causally (level H if it does) |
| Citation-optimal passage length "134-167 words", attributed to a 2025 AI Overview passage analysis | Heuristic (level F/G). Usable only as a labelled advisory hint |
| "Definition patterns increase citation rate by 2.1x"; "adding statistics increases citation by 40%" | Third-party study claims (level G); not MoxDOP metrics |
| README market-size / traffic-growth / conversion-multiple table (e.g. AI-referred traffic growth, Gartner projection) | Market commentary; zero evidentiary weight for a Finding |

**Reusable Skill pattern**

`Disaggregated GEO observation set` — five distinct observable classes (see §25), each carried separately with its own availability and evidence level, and never summed.

**Evidence requirements**

Only AI-bot `robots.txt` directives, structured data presence, and llms.txt presence are directly observable today (level A). Mentions, citations, AI Overview appearance, and entity presence require collectors MoxDOP does not have (`REQUIRES_NEW_COLLECTOR` / `NOT_AVAILABLE`).

**Unsafe assumptions**

- That a composite number represents AI visibility.
- That correlational study percentages transfer to a specific site as expected outcomes.
- That llms.txt presence is a citation lever — claude-seo explicitly argues the opposite with primary-source evidence, and the two repositories therefore **conflict**. MoxDOP resolves conflicts by primary source, not by repository.
- `curl | bash` install path — not executed.

**License / provenance**

MIT. Legally reusable with notice; posture is `REEXPRESS_FROM_PRIMARY_SOURCES` given the unsettled science.

**What MoxDOP should not copy**

The composite GEO Score; the study percentages as expected outcomes; the white-label/proposal/prospecting surface (agency-sales, not analysis); the installers.

## 14. `popiliadam/platinum-seo-engine`

| Field | Record |
| --- | --- |
| SHA / branch | `3192d565fbb5629460cd4d86ffd35610db4293c0` / `main` |
| License at SHA | **AGPL-3.0** (`LICENSE`, 34 KB GNU AGPL v3 text) |
| License history | README states: "Releases up to and including v2.1.0 were published under MIT and remain so; AGPL-3.0 applies from this point forward." Relicense commit `9563572fc3828d1e44aa676215f9f7ccd06fc2cf`; `v2.1.0` at `eab3cb8b7c021f4b2256c93b5a65f4e203d9bd12` |
| Shape | 50 `SKILL.md` across 8 categories (ingestion 5, discovery 14, planning 6, production 5, publishing 2, reporting 9, meta 4, governance 4), 30 slash commands, 6 hooks, `.mcp.json` with 6 MCP servers, `mcp-tool-registry.json` (35 KB), `schemas/`, `rules/`, extensive pytest suite |
| Domain | End-to-end SEO operations pipeline |

**Useful methodology**

- **Contract-first discipline.** Every skill input/output validated against a JSON Schema; the stated principle is "less code, stricter rules, single authority, machine-readable contracts."
- **Drift detection as a first-class skill.** A `governance/drift-check` skill audits the system against its own contracts.
- **Append-only event log** (`events.jsonl`) so any workflow is reproducible and auditable months later.
- **Portfolio-level reporting** distinct from per-site reporting.
- Tests that assert *documentation and registry consistency* (skill-declared tools must exist in the registry; registry versions must match `.mcp.json`).

**Critical observation: architecture duplication**

This repository independently arrives at the architecture MoxDOP already has — schema-locked contracts, append-only events, single authority, drift checks, reproducible runs. That makes it *validating* rather than *instructive*. MoxDOP gains no architectural direction here, and the overlap raises the cost of any accidental convergence in expression.

**Evidence requirements**

Its discovery skills consume GSC (level B), DataForSEO (level C), and crawler output (level A, but via a commercial desktop crawler MoxDOP will not adopt). Any MoxDOP equivalent must be re-sourced through existing MoxDOP contracts.

**Unsafe assumptions**

- Six auto-wired MCP servers as normal operating posture.
- A commercial desktop crawler (Screaming Frog) over local HTTP as a data dependency.
- Indexing-ping / URL submission as routine automation — an external write action MoxDOP forbids.
- Scraping-oriented ingestion (`scrapling-ops`) as an accepted source.

**License / provenance**

`LICENSE_COPYLEFT_REVIEW_REQUIRED` at the audited SHA. AGPL-3.0 network-service copyleft is materially incompatible with MoxDOP's posture as an internal proprietary operations platform. **`COPYLEFT_REVIEW_REQUIRED` for current HEAD.** The README's MIT-for-≤v2.1.0 statement is recorded but is *not* a route MoxDOP should take: deliberately sourcing from an older tag to dodge the current license is a legal-review decision, not an engineering one.

**What MoxDOP should not copy**

Any code, schema file, registry JSON, test, or skill text from the AGPL HEAD. No MCP fleet. No crawler dependency. No indexing ping. No scraping ingestion.

## 15. `garrettjsmith/localseoskills`

| Field | Record |
| --- | --- |
| SHA / branch | `405ce209775f8cb8f9dbaa511656594bb682cf9f` / `main` |
| License at SHA | MIT (`LICENSE`, Copyright 2026 Garrett Smith / GMB Gorilla) |
| Shape | 39 `SKILL.md`, `briefs/`, `tasks/` (15 task templates), `install.sh` + `install.ps1` (README documents a `curl \| bash` one-liner), `meta/`, `specs/`, `tools/` |
| Domain | Local SEO: GBP optimization and posts, reviews, citations, service areas, multi-location, geogrid, LSA, plus vendor tool skills (Semrush, Ahrefs, BrightLocal, Local Falcon, Whitespark, SerpApi, DataForSEO, Screaming Frog, GSC, GA4) |

**Useful methodology**

- **Local profile completeness as an explicit checklist**: categories, hours, attributes, services, products, photos, description, and their consistency across surfaces.
- **NAP consistency across citation sources** as a structural comparison problem rather than a score.
- **Review intelligence decomposition**: volume, velocity, rating distribution, response coverage, response latency, sentiment themes.
- **Geogrid concept**: local rank varies by physical point of query, so a single "rank" is meaningless locally. This is a genuinely useful conceptual correction.
- **Persistent per-client/per-location brief** that accumulates history so a later question ("why did rankings drop in March?") is answerable — conceptually close to MoxDOP Brand Context plus Activity history.
- Separation of a vendor-tool skill (how to get data from X) from an analysis skill (what to conclude).

**Repo claims recorded as claims (R4)**

| Repo claim (short) | MoxDOP classification |
| --- | --- |
| "Scheduled tasks that run while you sleep — 15 task templates with autonomous, queue, and notify approval tiers" | **REJECT** for MoxDOP Skill scope. MoxDOP recurring work is Prompt 46 Recurring Reviews with operator-driven materialization; automatic scheduling is Prompt 61/62, not a Skill concern |
| Autonomous tier "runs, writes output, notifies" for monitoring/reporting/audits | Conflicts with R11 (no auto-creation) and Prompt 47 notification policy ownership |
| Queue tier drafts GBP posts / review responses then executes | External write action → **REJECT** outright |

**Evidence requirements**

GBP profile fields require an authorized Google Business Profile connection MoxDOP does not have in production for this purpose (`REQUIRES_NEW_COLLECTOR`). Citation consistency needs either operator input or a directory data source (`REQUIRES_OPERATOR_INPUT` / `NOT_AVAILABLE`). Geogrid requires a grid-based rank source (`NOT_AVAILABLE`).

**Unsafe assumptions**

- That autonomous execution is a feature rather than a risk.
- That scraped or vendor-estimated local rank equals visibility.
- That absence of a citation means a negative signal (missing ≠ zero).
- `curl | bash` installer — not executed.

**License / provenance**

MIT. Reuse legally available with notice; posture `REEXPRESS_FROM_PRIMARY_SOURCES` (Google Business Profile documentation is the authority for profile field semantics).

**What MoxDOP should not copy**

The scheduler and task-template engine; approval tiers as an execution model; any GBP write path; review-response drafting-to-publish; vendor tool skills as runtime; the installers.

## 16. `seranking/seo-skills`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `fd6d1408f2e6a06454d81c07c29e0f04342eb9ba` / `main` / `v2.10.4` |
| License at SHA | MIT (`LICENSE`, Copyright 2026 SE Ranking) |
| Shape | 32 `SKILL.md`, `examples/` with dated real deliverables, `extensions/` (Google, Firecrawl), `schemas/`, `install.sh`, `mcp.json` + `.mcp.json`, multi-host plugin manifests (`.claude-plugin`, `.codex-plugin`, `.cursor-plugin`) |
| Domain | Vendor-backed SEO skills: page intelligence, technical audit, content brief/audit, backlinks, hreflang, sitemap, schema, drift, SXO, local GMB visibility, keyword clustering, AI search share-of-voice |

**Useful methodology — the strongest cost-discipline pattern in the corpus**

- **Credit preflight.** A canonical three-stage preflight checks credit balance and tool availability *before* work starts; each skill publishes its expected cost (e.g. "~10–15 credits typical" for one URL page analysis).
- **Published rate-limit awareness.** Data API limited to 10 requests/second, with skills explicitly designed to pace sequentially; large skills are flagged as capable of consuming thousands of credits on large domains, with documented ceiling parameters to cap cost.
- **Graceful degradation, explicitly labelled.** Optional tooling is opt-in; skills that lose a capability "fall back to a lower-fidelity method, or note what was unavailable, rather than failing the run." Missing head fields are emitted as `(skipped — <tool> not installed)` rather than as zero or absent.
- **Hard-exception honesty.** Two skills are documented as unable to run at all without their dependency, instead of degrading into a misleading result.
- **A named fidelity trap documented as a trap:** a post-processed HTML format silently strips canonical, hreflang, and JSON-LD, so the skill pins the raw format and tells the operator how to recognize the failure ("if those head fields come back zero on a site that obviously has them, you forgot the flag"). That is a textbook **missing ≠ zero** guard and MoxDOP should adopt the *shape* of it.
- Real dated example deliverables committed alongside skills — an evaluation corpus, not marketing.

**Evidence requirements**

All SE Ranking metrics are level C (provider-reported) and vendor-specific. SE Ranking volume, authority, and AI share-of-voice numbers are **not** interchangeable with GSC or GA4 measurement (R7).

**Unsafe assumptions**

- That a vendor's authority/visibility index is an objective property of a site.
- That AI share-of-voice sampling represents AI answer behaviour generally.
- Vendor MCP server as the data transport.

**License / provenance**

MIT with a corporate copyright holder. Note the structural conflict of interest: the methodology is written to route work through the vendor's API surface. Useful as pattern, not as neutral method.

**What MoxDOP should not copy**

SE Ranking as a MoxDOP provider; the MCP transport; vendor metrics into MoxDOP metric names; the installer; the multi-host plugin packaging.

## 17. `addyosmani/agent-skills`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `df1edb2e05487d0aa6d93c747141e0aed1187f25` / `main` / `0.6.7` |
| License at SHA | MIT (`LICENSE`, Copyright 2025 Addy Osmani) |
| Shape | 24 `SKILL.md` (23 lifecycle + 1 meta `using-agent-skills`), 8 commands, `agents/`, `evals/` (README + cases + fixtures), `hooks/`, `references/`, multi-host manifests |
| Domain | **Software engineering, not SEO.** Included as the Skill-engineering reference |

**Useful methodology — this is the Prompt 49 schema source**

The published skill anatomy is:

| Section | Purpose |
| --- | --- |
| Frontmatter `name` | lowercase-hyphen identifier |
| Frontmatter `description` | what it does **plus explicit "Use when…" trigger phrasing** |
| Overview | what the skill does |
| When to Use | triggering conditions |
| Process | step-by-step workflow |
| Rationalizations | common excuses for skipping steps, each with a rebuttal |
| Red Flags | signs something is wrong |
| Verification | evidence requirements |

Stated design choices worth adopting wholesale into MoxDOP Skill schema:

- **Process, not prose.** A Skill is a workflow with steps, checkpoints, and **exit criteria** — not a reference document.
- **Verification is non-negotiable.** Every skill ends with evidence requirements; "seems right" is never sufficient.
- **Anti-rationalization table.** Explicitly enumerating the excuses an agent uses to skip a step, with counter-arguments, is a cheap and effective reliability mechanism MoxDOP does not currently have.
- **Progressive disclosure.** `SKILL.md` is the entry point; references load only when needed, bounding context cost.
- **Evals as repository artifacts.** Cases and fixtures live in the repo, making skill quality measurable rather than asserted.
- Quality bar for a skill: **specific** (actionable steps), **verifiable** (clear exit criteria with evidence requirements), **battle-tested**, **minimal**.

**Prompt 49 implications**

| addyosmani element | MoxDOP Skill schema field (Prompt 49 proposal) |
| --- | --- |
| `name` | `slug` (already present) |
| `description` + "Use when…" | `purpose` + **`triggers[]`** (new) |
| When to Use | `applicability` — plus MoxDOP's existing Evidence-based eligibility |
| Process | `method_steps[]` |
| Rationalizations | **`anti_rationalizations[]`** (new) |
| Red Flags | **`red_flags[]`** (new) |
| Verification | **`verification[]` + `evidence_requirements[]`** (new; must carry MoxDOP evidence levels) |
| — | **`abstention_rules[]`** (MoxDOP-specific; missing ≠ zero) |
| — | **`forbidden_claims[]`** (MoxDOP-specific) |
| evals/cases | **`eval_cases[]`** (new) |

**Unsafe assumptions**

- The `/build auto` posture (approve the plan once, then run autonomously) is explicitly outside MoxDOP's safety envelope.
- Skill content is software-engineering methodology and carries no SEO authority.

**License / provenance**

MIT. Posture `REEXPRESS_FROM_PRIMARY_SOURCES`: MoxDOP adopts the **schema shape** (field names and discipline), writing its own text.

**What MoxDOP should not copy**

The 24 engineering skills; the slash-command lifecycle; hooks; the autonomous build mode; the CLI install path.

## 18. `aaron-he-zhu/aaron-marketing-skills`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `5538e686f1d6ae6ad484a3445fbd8cf4ab840397` / `main` / `v19.2.0` |
| License at SHA | **Apache-2.0** (`LICENSE`, full Apache text) |
| Status | **ACTIVE** — the maintained SEO/GEO line. Commit date 2026-08-15, one day before this research |
| Shape | 120 `SKILL.md` across 7 disciplines + a protocol layer; `seo-geo/` holds **16** skills in 4 phases (survey → implement → tune → evaluate); `evals/` with 123 entries; `references/` with benchmark definitions; `CONNECTORS.md` (57 KB); `docs/mcp-catalog.json` deliberately outside the auto-registered path |
| Domain | Multi-discipline marketing; SEO/GEO is one discipline of seven |

**`seo-geo` inventory at the audited SHA**

| Phase | Skills |
| --- | --- |
| survey | `keyword-research`, `competitor-analysis`, `serp-analysis`, `content-gap-analysis` |
| implement | `content-writer`, `geo-content-optimizer`, `serp-markup-builder`, `page-play-builder` |
| tune | `content-quality-auditor`, `technical-seo-checker`, `on-page-seo-checker`, `site-structure-optimizer` |
| evaluate | `domain-authority-auditor`, `rank-tracker`, `performance-monitor`, `offsite-signal-analyzer` |

**Useful methodology**

- **Auditor gates.** Each discipline has a named benchmark and a dedicated auditor skill that acts as a **gate**, not a decorator: SEO/GEO uses CORE-EEAT → `content-quality-auditor` and CITE → `domain-authority-auditor`. The transferable idea is *a distinct reviewing step with a published rubric, separate from the producing step* — close to MoxDOP's planned Recommendation Reviewer.
- **Lifecycle phases as a first-class structure** (survey → implement → tune → evaluate) so a skill's position in the workflow is explicit.
- **Keyless-by-default tiering.** "Every skill runs at Tier 1 with data you provide"; paid tools and MCP servers are opt-in convenience, never a precondition. Paid-ads skills score from the operator's own account export rather than requiring keyed ad APIs.
- **Deliberate non-registration of MCP.** The MCP catalogue is kept *outside* the plugin-root path that the host auto-registers, so installing adds nothing to the tool list. Making integration opt-in by file layout is a good safety pattern.
- **Proxy data labelled as proxy.** Several monitor skills explicitly annotate that their inputs are proxy sources.
- **Explicit sibling-repo consolidation policy** with a documented repo family — governance maturity worth imitating in MoxDOP's own docs.

**Repo claims recorded as claims (R4)**

| Repo claim (short) | MoxDOP classification |
| --- | --- |
| Eight named benchmark frameworks with scored gates (CORE-EEAT, CITE, TALE, ECHO, SEND, ROAS, STAR, RAMP), some producing composite scores (e.g. an email quality score, an ad quality score) | Branded composites → **REJECT as canonical MoxDOP metrics**; adopt only the *gate* concept |
| `domain-authority-auditor` producing a domain rating | Vendor/heuristic authority construct, not a measurement (level F/G) |
| Download/registry badges across four skill marketplaces | Popularity signal only (R2) |

**Evidence requirements**

Survey and evaluate skills depend on external keyword/rank/backlink data (level C when vendor-sourced). Tune skills mix level A structural checks with level F content judgment. The tiering discipline is what makes this repo's honesty acceptable — MoxDOP must implement the equivalent as explicit `data_availability` per Skill.

**Unsafe assumptions**

- Composite benchmark scores presented as capability measurements.
- A domain-authority construct treated as an objective property.
- 120 skills is a scale MoxDOP explicitly rejects (few strong Agents + curated Skills).

**License / provenance**

Apache-2.0 — permissive with patent grant and explicit notice/`NOTICE` obligations on redistribution. This is the only mandatory repository where adaptation would carry material attribution mechanics, so its posture is `ADAPT_WITH_ATTRIBUTION_REVIEW`.

**What MoxDOP should not copy**

The connector runtime; the 120-skill topology; benchmark score formulas; the registry/badge machinery; the protocol/memory layer (HOT/WARM/COLD) as MoxDOP memory architecture.

## 19. `aaron-he-zhu/seo-geo-claude-skills`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `1ae40e6d98dd2626b56cd1bee8700edc9fb71789` / `main` / `v9.9.12` |
| License at SHA | Apache-2.0 |
| Status | **SIGNPOST** → `aaron-he-zhu/aaron-marketing-skills`. README states the standalone 20-skill line is preserved unchanged at tag `v9.9.12` and receives no updates |
| Shape | 20 `SKILL.md` in `research/`, `build/`, `optimize/`, `monitor/`, `cross-cutting/`; README carries an explicit old→new path mapping table |

**Why it is in scope**

It is the provenance record for §18. Anyone who finds the 20-skill line first must know it is frozen, and the mapping table shows how the taxonomy was consolidated (e.g. `build/seo-content-writer` merged into `seo-geo/build/content-writer`; `research/*` became `seo-geo/survey/*`).

**Useful methodology**

- **Signposting as maintenance hygiene.** A frozen repository that names its successor, preserves the final line at a tag, and publishes a migration table. MoxDOP should apply the same discipline to superseded internal docs.
- The `cross-cutting/` grouping (content quality, entity, memory, domain authority) recognizes that some methodology is not phase-bound.

**Evidence / unsafe assumptions / license**

Same as §18, plus one repository-specific trap: because it is frozen, its content will silently age. Citing it as current methodology would be an error. Treat as historical reference only.

**What MoxDOP should not copy**

Anything as current methodology. Use §18 for the live line.

## 20. `garmeeh/next-seo`

| Field | Record |
| --- | --- |
| SHA / branch / tag | `738ef7e774ee6216f6e90c26a3b5b99dd5d166b4` / `main` / `next-seo@7.3.0` |
| License at SHA | MIT (`LICENSE` and `LICENSE.md`, Copyright 2018 Gary Meehan) |
| Shape | TypeScript library: `src/`, `tests/` (vitest + playwright), very large README acting as reference documentation, `ADDING_NEW_COMPONENTS.md`, `CUSTOM_COMPONENTS.md`. **0 `SKILL.md`** |
| Classification | `SECONDARY_REFERENCE` — metadata / structured-data implementation only |

**Useful methodology**

- A maintained enumeration of JSON-LD entity types and their **required vs recommended property sets**, expressed as testable component APIs.
- Test cases per structured-data type — effectively a validation corpus for "what a correct JSON-LD block for type X contains."
- Contributor documentation on adding a new type, which encodes the shape of a well-formed structured-data definition.

**Evidence requirements**

Presence/absence and property completeness of a JSON-LD block in fetched HTML is level A. Whether a type or property produces a Google result is level D and comes from Google Search Central and Schema.org — **not** from this repository, which targets the framework's rendering concerns.

**Unsafe assumptions**

- That the library's supported types track Google's current rich-result eligibility. Google retires types; a library's component surface lags.
- README contains sponsored/affiliate promotional blocks at the top; a naive scrape of the README would ingest marketing copy as methodology.

**License / provenance**

MIT (declared twice consistently). Reuse legally available; posture `RESEARCH_ONLY` since MoxDOP needs type/property expectations, which must come from Schema.org and Google documentation anyway.

**What MoxDOP should not copy**

The package, any component, any TypeScript; the README as a methodology source; the framework-specific rendering assumptions.

## 21. Secondary repositories (from the existing MoxDOP audit)

Detailed re-audit is **not required** in Prompt 48 unless a unique concept surfaced. Prior decisions from [`EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](./EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md) stand unchanged.

| Repository | Standing decision | Unique concept surfaced in Prompt 48? |
| --- | --- | --- |
| https://github.com/pipeboard-co/meta-ads-mcp | **REJECTED RUNTIME** (MCP + write ops); Meta read-only module concepts only | No |
| https://github.com/georgekhananaev/google-reviews-scraper-pro | **PRODUCT-CONCEPT ONLY**; scraper **REJECTED** | No — review-intelligence *questions* are better sourced from §15 |
| https://github.com/Panniantong/Agent-Reach | **PLANNED REFERENCE** (capability routing) | No |
| https://github.com/msitarzewski/agency-agents | **PARTIALLY ADOPTED** concepts (Agent Profile / Skill methodology / Playbook) | No — §17 supersedes it as the Skill-schema source |

## 22. Methodology extraction matrix (capability × repository)

Reads as: which repositories contain usable methodology for each MoxDOP capability area, and the resulting adoption decision. `—` means the repository contributes nothing usable for that capability.

| Capability area | marketingskills | HEAD | claude-seo | open-seo | geo-seo-claude | platinum | localseoskills | seo-skills | agent-skills | aaron | next-seo | Adoption |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Website technical audit | — | — | strong | moderate | moderate | strong (copy-blocked) | — | strong | — | moderate | — | `ADAPT_METHODOLOGY` |
| Indexability analysis | — | robots/canonical subjects | strong | moderate | AI-bot directives | strong (copy-blocked) | — | moderate | — | moderate | — | `ADAPT_METHODOLOGY` |
| Metadata consistency | — | **primary source of subjects** | strong | — | — | moderate | — | strong (fidelity trap) | — | moderate | moderate | `ADAPT_METHODOLOGY` |
| Structured data audit | — | subject list | strong (deprecations) | — | moderate | moderate | local schema | strong | — | markup builder | **type/property corpus** | `ADAPT_METHODOLOGY` |
| Internal linking analysis | site architecture | — | moderate | — | — | strong (copy-blocked) | — | — | — | site-structure | — | `ADAPT_METHODOLOGY` |
| Content quality review | copywriting | — | E-E-A-T method | content review | citability | remediation | content strategy | 60-item audit | — | CORE-EEAT gate | — | `ADAPT_METHODOLOGY` → Playbook-leaning |
| Search demand analysis | — | — | moderate | strong (cost) | — | strong (copy-blocked) | local keywords | strong (credits) | — | survey phase | — | `ADAPT_METHODOLOGY` |
| Query opportunity analysis | — | — | moderate | striking distance | — | quick wins | — | moderate | — | moderate | — | `ADAPT_METHODOLOGY` |
| Local profile completeness | — | — | local layer | — | — | GBP audit | **strongest** | GMB visibility | — | — | — | `ADAPT_METHODOLOGY` |
| Local review intelligence | — | — | review signals | — | — | — | **strongest** | — | — | — | — | `ADAPT_METHODOLOGY` |
| Measurement audit | attribution | — | moderate | — | — | — | GA4/GSC tool skills | moderate | — | conversion QA analogue | — | `ADAPT_METHODOLOGY` |
| GEO observation | — | — | myth reframes | AI visibility | **check inventory** | geo-analysis | AI local search | AI share-of-voice | — | geo-content | — | `NEEDS_PRIMARY_SOURCE_VERIFICATION` + `NEEDS_DATA_SUPPORT` |
| Skill schema / engineering | validation scripts | — | — | — | — | contract tests | — | examples corpus | **primary source** | phases + gates | — | `ADAPT_METHODOLOGY` |
| Provider cost discipline | — | — | — | strong | — | moderate | — | **strongest** | — | keyless tiers | — | `ADOPT_CONCEPT` |

## 23. Reusable Skill pattern matrix

| Pattern | Source(s) | MoxDOP disposition | Notes |
| --- | --- | --- | --- |
| Trigger phrasing in skill description ("Use when…") | agent-skills, marketingskills | `ADOPT_CONCEPT` | Becomes `triggers[]` in Prompt 49 schema |
| Explicit "not for" / negative scope | Agent Reach (prior audit), agent-skills | `ADOPT_CONCEPT` | Prevents over-firing |
| Step-wise process with checkpoints and exit criteria | agent-skills, platinum | `ADOPT_CONCEPT` | Skill is a workflow, not prose |
| Verification / evidence-requirement section | agent-skills | `ADOPT_CONCEPT` | Must carry MoxDOP evidence levels A–H |
| Anti-rationalization table | agent-skills | `ADOPT_CONCEPT` | New MoxDOP field; cheap reliability win |
| Red-flag list | agent-skills | `ADOPT_CONCEPT` | New MoxDOP field |
| Progressive disclosure (entry file + lazy references) | agent-skills | `ADOPT_CONCEPT` | Matches bounded-context requirement |
| Eval cases + fixtures in repository | agent-skills, aaron, seo-skills examples | `ADOPT_CONCEPT` | Prompt 49 defines `eval_cases[]`; no runner yet |
| Falsifiable recommendation frame (observation → action → success/failure signal → watch metric) | claude-seo | `ADOPT_CONCEPT` | Already partially live in `recommendation-framing` |
| Separate auditor/gate skill with published rubric | aaron, claude-seo | `ADOPT_CONCEPT` | Maps to planned Recommendation Reviewer; **no second AI call merely for architecture** |
| Lifecycle phase labelling (survey → implement → tune → evaluate) | aaron, seo-geo-claude-skills | `ADOPT_CONCEPT` | Useful catalog metadata |
| Shared brand/product context read by all skills | marketingskills, localseoskills briefs | `ADOPT_CONCEPT` | Already MoxDOP Brand Intelligence Context |
| Cost/credit preflight before work | seo-skills, open-seo | `ADOPT_CONCEPT` | Reuse Prompt 34 guard; do not build a second one |
| Graceful degradation with explicit "skipped — unavailable" markers | seo-skills | `ADOPT_CONCEPT` | Direct implementation of missing ≠ zero |
| Hard-exception declaration (skill cannot run without dependency) | seo-skills | `ADOPT_CONCEPT` | Becomes Skill eligibility → not applicable |
| Tiered data honesty (works at base tier with operator-provided data) | aaron | `ADOPT_CONCEPT` | Becomes `data_availability` per Skill |
| Deprecated-subject list maintained separately | HEAD, claude-seo | `ADOPT_CONCEPT` | Diagnosis catalog gains a deprecation axis |
| Volume guard preventing a recommendation becoming spam | claude-seo | `ADOPT_CONCEPT` | E.g. multi-location page-count thresholds |
| Machine-validated skill contract in CI | marketingskills, platinum | `ADOPT_CONCEPT` | Prompt 49+; validate schema, not behaviour |
| Composite weighted score as headline output | claude-seo, geo-seo-claude, aaron | **`REJECT`** | §24 |
| Scheduled autonomous task tiers | localseoskills | **`REJECT`** | R11, R14; Prompt 61/62 owns scheduling |
| Auto-wired MCP server fleet | platinum, seo-skills, open-seo | **`REJECT`** | No MCP as MoxDOP core |
| External write / indexing ping / post publishing | platinum, localseoskills | **`REJECT`** | Read-only integrations |
| Parallel specialist sub-agent fan-out | claude-seo | **`REJECT`** | Few strong Agents + curated Skills |
| Desktop crawler / scraper dependency | platinum, localseoskills | **`REJECT`** | Not an accepted source class |

## 24. Scoring and composite metric inventory

Every composite score found in the corpus is inventoried here and **rejected as a canonical MoxDOP metric** (R8). Inventory ≠ endorsement. Full reasoning in [`EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md`](./EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md).

| Score (as published) | Source | Composition as published | MoxDOP decision |
| --- | --- | --- | --- |
| SEO Health Score 0–100 | claude-seo | Technical 22% · Content Quality 23% · On-Page 20% · Schema 10% · CWV 10% · AI Search Readiness 10% · Images 5% | **REJECT as canonical metric** |
| Content Quality XX/100 | claude-seo | Sub-score feeding the above | **REJECT** |
| AI Citation Readiness XX/100 | claude-seo | Sub-score | **REJECT** |
| GEO Readiness XX/100 | claude-seo | Sub-score | **REJECT** |
| Composite GEO Score 0–100 | geo-seo-claude | `Citability*0.25 + Brand*0.20 + EEAT*0.20 + Technical*0.15 + Schema*0.10 + Platform*0.10` | **REJECT as canonical metric** |
| CORE-EEAT content quality gate score | aaron | Rubric-derived content score | **REJECT as metric**; gate *concept* accepted |
| CITE domain rating | aaron | Domain authority construct | **REJECT** — not a measurement |
| Discipline quality scores (EQS / RQS / SQS and similar) | aaron | Per-discipline auditor composites | **REJECT** |
| Vendor authority / visibility indices | seo-skills, localseoskills tool skills | Vendor-proprietary | **REJECT** as MoxDOP metric; may be shown as clearly attributed vendor figures only |
| Local geogrid visibility score | localseoskills | Grid-point rank aggregate | **REJECT as metric**; grid-dependence *concept* accepted |

**Why rejection, not adjustment.** A weighted average across a structural fact (level A), a platform rule (level D), a vendor estimate (level C), and a subjective content judgment (level F) produces a number whose movement cannot be attributed to any of them. It is unfalsifiable, so it violates the falsifiability requirement MoxDOP borrowed from claude-seo in the first place. MoxDOP already deliberately shows Finding counts rather than a health score (`WEB_OVERVIEW_FINDINGS` is annotated "Not a Health Score" in `WEBSITE_DATA_CONTRACT_V1`).

**Permitted alternative.** Counts and severities of individually falsifiable Findings, each with its own evidence level and provenance, plus explicit coverage/availability statements.

## 25. GEO concept disambiguation matrix

GEO must never be a single number or a single check. These five observable classes are distinct in kind, in data availability, and in evidence level (R9).

| # | Concept | Precise question | Observable today? | Data availability | Evidence level |
| --- | --- | --- | --- | --- | --- |
| 1 | **AI bot accessibility** | Do `robots.txt` directives permit or disallow named AI/LLM user-agents? Are there conflicting rules? | **Yes** — deterministic parse of a fetched file | `AVAILABLE` (`WEBSITE_DIRECT_OBSERVATION`) | A |
| 2 | **AI mention** | Does an AI assistant's answer mention the brand at all? | No | `REQUIRES_NEW_COLLECTOR` | — (would be C at best; sampled) |
| 3 | **AI citation** | Does an AI answer link/attribute to a specific URL on this site? | No | `REQUIRES_NEW_COLLECTOR` | — (C, sampled) |
| 4 | **AI Overview appearance** | Does a Google AI Overview appear for a query, and does it include this site? | No | `PROVIDER_LIMITED` / `NOT_AVAILABLE` | — (C, sampled) |
| 5 | **Entity presence** | Does a recognizable entity for this brand exist on named third-party platforms / knowledge surfaces? | Partially | `PARTIAL` / `REQUIRES_OPERATOR_INPUT` | A for presence on a fetched page; F for "entity strength" |
| 6 | **Citability heuristic** | Is content structured as self-contained, question-answering, fact-dense passages? | Yes, as a heuristic over fetched HTML | `HEURISTIC_ONLY` | F |

Additional disaggregation notes:

- **llms.txt presence** is level A (the file is there or not). Its *effect* is contested: geo-seo-claude treats it as a lever; claude-seo argues from primary-source evidence that it is not currently a citation lever. MoxDOP records both, adopts neither as a Finding rule, and resolves only from primary documentation (§31).
- **Structured data presence** belongs to structured-data audit, not GEO. Double-counting it inside a GEO score is one reason those scores are rejected.
- Sampled observations of AI answer surfaces are **non-reproducible by nature**. Any future MoxDOP GEO collector must record the sampling method, model/surface, locale, and timestamp, and must be labelled `EXPERIMENTAL`.

## 26. Vendor metric vs first-party measurement matrix

Distinct sources are distinct metrics. Never sum, average, substitute, or reconcile silently (R7).

| Metric | Source class | Kind | Evidence level | Never equate with |
| --- | --- | --- | --- | --- |
| GSC clicks / impressions / position | `SEARCH_CONSOLE` | First-party measured (Google-reported, sampled/aggregated) | B | GA4 sessions; vendor rank |
| GA4 sessions / users / conversions | `GA4` | First-party measured (client/model-influenced) | B | GSC clicks |
| CrUX field CWV | `PAGESPEED_TECHNICAL` (field) | First-party aggregate of real users | B | Lighthouse lab scores |
| Lighthouse / PSI lab metrics | `PAGESPEED_TECHNICAL` (lab) | Synthetic lab | E | Field CWV |
| DataForSEO search volume | `DATAFORSEO` | Vendor estimate | C | Actual demand; GSC impressions |
| DataForSEO rank / SERP position | `DATAFORSEO` | Vendor observation at a point/locale | C | GSC average position |
| DataForSEO ETV (estimated traffic value) | `DATAFORSEO` | Vendor model on vendor estimates | C (compounded) | Revenue; GA4 conversions |
| SE Ranking volume / authority / AI share-of-voice | vendor | Vendor estimate / index | C | Anything first-party |
| Local geogrid rank | vendor / scraper | Sampled per grid point | C | A single site-wide rank |
| Domain authority / domain rating | vendor / heuristic | Proprietary construct | F | Any Google signal |
| AI mention/citation sampling | future collector | Sampled, non-reproducible | C (best case) | Traffic |

Presentation rules for MoxDOP: always label the source; never place a vendor estimate and a first-party measurement in the same column without a source column; never compute a delta across two source classes; never let a vendor estimate satisfy an Evidence requirement that names a first-party source.

## 27. Data availability matrix (against MoxDOP contracts)

Mapped to `docs/data-contracts/WEBSITE_DATA_CONTRACT_V1.md` source classes and subject codes where they already exist. Full per-capability detail in [`EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md`](./EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md).

| Methodology need | MoxDOP source class | Existing subject / dataset | Availability |
| --- | --- | --- | --- |
| robots.txt directives | `WEBSITE_DIRECT_OBSERVATION` | Diagnosis fetch | `AVAILABLE` |
| Canonical tag presence/target | `WEBSITE_DIRECT_OBSERVATION` | `WEB_HEALTH_CANONICAL` | `AVAILABLE` |
| HTTP status / redirect chain | `WEBSITE_DIRECT_OBSERVATION` | `WEB_HEALTH_HTTP_STATUS` | `AVAILABLE` |
| Title / meta description | `WEBSITE_DIRECT_OBSERVATION` (+ `CMS_METADATA`) | `WEB_CONTENT_TITLE`, `WEB_CONTENT_META` | `AVAILABLE` (dual provenance may conflict) |
| Heading structure | `WEBSITE_DIRECT_OBSERVATION` | `WEB_CONTENT_H` | `AVAILABLE` |
| Structured data blocks | `WEBSITE_DIRECT_OBSERVATION` | `WEB_HEALTH_SCHEMA` | `AVAILABLE` |
| Sitemap contents | `WEBSITE_DIRECT_OBSERVATION` | Diagnosis fetch | `AVAILABLE` |
| hreflang set | `WEBSITE_DIRECT_OBSERVATION` | head observation | `PARTIAL` |
| TLS / DNS / domain lifecycle | `DOMAIN_DNS_TLS` | `WEB_INFRA_TLS`, `WEB_INFRA_DNS`, `WEB_INFRA_DOMAIN` | `AVAILABLE` |
| Core Web Vitals (field) | `PAGESPEED_TECHNICAL` | `WEB_HEALTH_LCP` | `PARTIAL` (field data coverage-dependent) |
| URL inventory | `WORDPRESS_SITE_CONNECTOR` / `WEBSITE_DIRECT` | `WEB_CONTENT_INVENTORY` | `PARTIAL` (connector-dependent) |
| Internal link graph | `WEBSITE_DIRECT_OBSERVATION` + derived | — | `REQUIRES_NEW_COLLECTOR` (bounded, no general crawler) |
| Query / click data | `SEARCH_CONSOLE` | GSC contract datasets | `AVAILABLE` |
| Sessions / conversions | `GA4` | GA4 contract datasets | `AVAILABLE` |
| Keyword volume / rank / ETV | `DATAFORSEO` | DataForSEO contract | `PROVIDER_LIMITED` (Prompt 34 cost/freshness) |
| GBP profile fields | `CROSS_ASSET` | GBP product docs | `REQUIRES_NEW_COLLECTOR` |
| Reviews (volume/rating/response) | `CROSS_ASSET` | GBP product docs | `REQUIRES_NEW_COLLECTOR` — official API only, **scraper rejected** |
| Citation / directory consistency | `OPERATOR_MAINTAINED` | — | `REQUIRES_OPERATOR_INPUT` |
| Geogrid local rank | — | — | `NOT_AVAILABLE` |
| AI mention / citation / AI Overview | — | — | `NOT_AVAILABLE` (`DEMO_ONLY` fixtures exist for UX) |
| Content quality judgment | AI advisory | — | `HEURISTIC_ONLY` |

## 28. Evidence level matrix (A–H)

| Level | Name | Definition | Example | May justify a deterministic Finding? |
| --- | --- | --- | --- | --- |
| **A** | `DIRECT_STRUCTURAL_FACT` | Directly observable in a fetched artifact, reproducible from the same artifact | `rel=canonical` absent from the HTML head; `Disallow` present for a named user-agent | **Yes** |
| **B** | `DIRECT_MEASURED_METRIC` | First-party measured metric from an authorized provider | GSC clicks for a query; GA4 sessions; CrUX field LCP | **Yes**, with source labelled |
| **C** | `PROVIDER_REPORTED_ESTIMATE` | Third-party/vendor reported or modelled value | DataForSEO volume, rank, ETV; SE Ranking indices; sampled AI mentions | No — advisory context only |
| **D** | `DOCUMENTED_PLATFORM_RULE` | Stated in primary provider/standards documentation | Google's documented canonical handling; Schema.org property requirements | **Yes**, as the *rule* behind an A/B observation |
| **E** | `DERIVED_COMPUTATION` | Computed by MoxDOP from A/B/D via a registered formula | Striking-distance set; delta vs previous run; lab rating | **Yes**, if inputs and formula are recorded |
| **F** | `EXPERT_HEURISTIC` | Practitioner convention with no primary-source confirmation | Title length band; 134–167-word citability block; "authority" constructs | No — advisory, must carry uncertainty label |
| **G** | `CORRELATIONAL_STUDY_CLAIM` | Third-party study or correlation, not verified for this site | "30–115% higher visibility"; "definition patterns increase citation 2.1x"; "brand mentions 3x stronger than backlinks" | No |
| **H** | `UNSUPPORTED_CAUSAL_CLAIM` | Causal assertion without supporting evidence | "Implementing X will raise AI citations by N%" | **Never** — must not appear in MoxDOP output |

Rules: a Skill's output may not exceed the lowest evidence level among its required inputs; a Skill that would need level G/H to reach its conclusion must abstain; presenting F/G at the visual weight of A/B is itself a defect.

## 29. Evidence requirements matrix (per candidate capability)

Condensed here; authoritative version in [`EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md`](./EXTERNAL_SKILL_EVIDENCE_REQUIREMENTS.md).

| Candidate capability | Required evidence | Optional evidence | Support | Abstain when |
| --- | --- | --- | --- | --- |
| Website Technical Audit | Website direct observation (HTTP/HTML/headers), TLS/DNS | CWV field, WP connector | `SUPPORTED` | No successful fetch of the primary URL |
| Indexability Analysis | robots.txt, canonical, HTTP status, sitemap | GSC index coverage | `PARTIAL` | robots/sitemap fetch failed — never infer "no restrictions" |
| Metadata Consistency | Title/meta/heading observation | CMS metadata | `SUPPORTED` | HTML retrieved without head fields (fidelity trap, §16) |
| Structured Data Audit | JSON-LD/microdata blocks + Schema.org/Google type rules (D) | — | `SUPPORTED` | Blocks unparseable — report unparseable, not absent |
| Internal Linking Analysis | URL inventory + internal link extraction | CMS taxonomy | `FUTURE_DATA_REQUIRED` | Inventory coverage unknown — no orphan claims |
| Content Quality Review | Content body + Brand Context | GSC engagement | `PARTIAL` (F) | No Brand Context; leans Playbook (§34) |
| Search Demand Analysis | GSC query data (B) | DataForSEO volume (C, Prompt 34) | `SUPPORTED` | No GSC connection — vendor volume alone is not demand |
| Query Opportunity Analysis | GSC query + position + page mapping | Vendor rank | `SUPPORTED` | Window too short / thresholds unmet |
| Local Profile Completeness | GBP profile fields | Website NAP observation | `FUTURE_DATA_REQUIRED` | No authorized GBP data — absence ≠ incomplete |
| Local Review Intelligence | Reviews via official API | Operator context | `FUTURE_DATA_REQUIRED` | No official source — **never scrape** |
| Measurement Audit | GA4 config + GSC + Website observation | Ads linkage | `PARTIAL` | Any leg missing — name the missing leg |
| GEO Observation Analysis | robots AI directives, structured data, llms.txt presence | Entity presence | `PARTIAL` / `UNSUPPORTED` for mention/citation | Any AI-surface question — abstain, label EXPERIMENTAL |

## 30. Unsafe assumption register (summary)

Full register with per-repository attribution in [`EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md`](./EXTERNAL_SKILL_UNSAFE_ASSUMPTIONS.md).

| # | Unsafe assumption | Where it appears | MoxDOP guard |
| --- | --- | --- | --- |
| U1 | A weighted composite score measures site quality | claude-seo, geo-seo-claude, aaron | §24 rejection; Findings + coverage instead |
| U2 | Missing data contributes zero to a score | implicit in all scoring repos | R6 abstention; explicit `NOT_AVAILABLE` |
| U3 | Vendor estimates are measurements | open-seo, seo-skills, localseoskills tool skills | §26 source separation |
| U4 | Correlational study percentages predict site outcomes | geo-seo-claude | Level G cap; no causal restatement |
| U5 | llms.txt presence improves AI citation | geo-seo-claude (contradicted by claude-seo) | Primary-source-only resolution; no Finding rule |
| U6 | AI answer surfaces are reliably measurable | geo-seo-claude, open-seo, seo-skills | `NOT_AVAILABLE`; EXPERIMENTAL labelling |
| U7 | Autonomous execution / scheduling is a Skill concern | localseoskills, platinum | R11/R14; Prompt 46 + Prompt 61/62 own it |
| U8 | Popularity/stars/downloads imply correctness | aaron badges, geo-seo-claude star history | R2 |
| U9 | Repo prose is platform policy | HEAD, all SEO repos | R3 + §31 verification |
| U10 | A subjective E-E-A-T judgment can be a deterministic Finding | claude-seo, aaron | Advisory AI output only |
| U11 | One local rank exists | implicit in rank skills | Geogrid dependence acknowledged; metric rejected |
| U12 | Skill count equals capability | aaron (120), platinum (50), marketingskills (49) | Few strong Agents + curated Skills |
| U13 | Installing a skill library is safe by default | every repo with `install.sh` / `curl \| bash` | Nothing executed; no MCP registered |
| U14 | A frozen repository is current methodology | seo-geo-claude-skills | Signpost recorded; historical only |
| U15 | A library's supported types track current rich-result eligibility | next-seo | Schema.org + Google docs are authority |

## 31. Primary-source verification log (2026-08-16)

Verification means: the structural check and the *rule behind it* were traced to primary documentation rather than to repository prose. Where the repository claim is a practitioner convention with no primary-source rule, it is recorded as heuristic and **not** promoted.

| Check | Repo claim (short) | Primary-source authority | Verification status | MoxDOP evidence level |
| --- | --- | --- | --- | --- |
| `robots.txt` directive parsing / user-agent matching | Multiple repos treat robots directives as authoritative access control | Google Search Central robots documentation; REP | **VERIFIED** as structural fact + documented rule | A (observation) + D (rule) |
| `rel=canonical` presence and target | Missing canonical treated as a defect | Google Search Central canonicalization documentation | **VERIFIED** as documented rule; canonical is a *signal*, not a directive — MoxDOP wording must not overstate | A + D |
| XML sitemap presence / format / referenced URLs | Sitemap absence treated as a defect | Google Search Central sitemap documentation; `sitemaps.org` | **VERIFIED** as structural fact; "absence = defect" is **PARTIALLY_VERIFIED** (sitemaps are recommended, not required) | A + D |
| Structured data: JSON-LD preferred; type/property requirements; retired rich-result types | claude-seo and next-seo both enumerate types; claude-seo dates specific retirements | Google Search Central structured-data documentation + Schema.org vocabulary | **VERIFIED** for the mechanism (JSON-LD parsing, required properties). Specific retirement dates: **PARTIALLY_VERIFIED** — must be re-read from Google's current documentation before entering a MoxDOP catalog | A + D |
| Core Web Vitals metric set (LCP / INP / CLS), INP replacing FID, field vs lab distinction | claude-seo states the current triad and the FID removal | web.dev / Chrome documentation; CrUX documentation | **VERIFIED** | B (field) / E (lab) |
| Title and meta description presence | Presence checks | Google Search Central documentation on titles and snippets | **VERIFIED** for presence; Google may rewrite displayed titles/snippets, so "as written" ≠ "as displayed" | A + D |
| **Title length in a specific character band** | Repos publish specific length bands | No primary source specifies a character limit | **NOT VERIFIED — author/practitioner heuristic** | **F** |
| **Meta description length band** | Same | No primary source specifies a limit | **NOT VERIFIED — heuristic** | **F** |
| **Citability block of 134–167 words** | geo-seo-claude and claude-seo both cite passage analyses | No primary source; third-party analysis | **NOT VERIFIED — heuristic derived from a correlational analysis** | **F/G** |
| **llms.txt as a citation lever** | geo-seo-claude implies yes; claude-seo argues no with primary-source evidence | No primary Google documentation establishes it as a ranking/citation lever | **NOT VERIFIED — conflicting repo claims; file presence only is verifiable** | A (presence) / no rule |
| **"GEO/AEO are rebranded SEO; AI features use the same ranking systems"** | claude-seo states it, citing Google's AI-optimization guidance | Google Search Central AI-features documentation | **PARTIALLY_VERIFIED** — direction matches Google's published stance; exact wording must be re-read before MoxDOP restates it | D once re-read |
| **"30–115% higher visibility from GEO optimization"** | geo-seo-claude attributes to a 2024 Princeton / Georgia Tech / IIT Delhi study | Academic paper, not platform documentation | **NOT VERIFIED for MoxDOP use — correlational; must not be restated causally** | **G** |
| **Brand mentions "3x stronger correlation" than backlinks for AI** | geo-seo-claude README table | No primary source | **NOT VERIFIED** | **G** |
| E-E-A-T evaluation against quality-rater guidance | claude-seo scores E-E-A-T sub-factors | Google Search Quality Rater Guidelines (rater instructions, not ranking rules) | **PARTIALLY_VERIFIED** — the guidelines exist and describe rater assessment; they do not define a computable site score | **F** |
| GBP profile field semantics (categories, hours, attributes, services) | localseoskills enumerates fields | Google Business Profile documentation | **VERIFIED** for field existence; completeness *thresholds* are heuristic | A (when authorized data exists) + D |

Note on method: this log records which authority governs each check and what MoxDOP is permitted to assert. Prompt 49 must re-read the cited primary documentation at implementation time before any catalog text is written; documentation changes and dated claims (retirement dates in particular) age quickly.

## 32. License and provenance matrix

Full detail, including notice mechanics, in [`EXTERNAL_SKILL_LICENSE_PROVENANCE.md`](./EXTERNAL_SKILL_LICENSE_PROVENANCE.md).

| Repository | License at audited SHA | License class | Copy classification |
| --- | --- | --- | --- |
| marketingskills | MIT (`LICENSE`) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| HEAD | **none present**; README claims CC0 1.0 | `LICENSE_MISSING` | `RESEARCH_ONLY` (extract element names as facts) |
| claude-seo | MIT | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| open-seo | MIT file; `package.json` license **null** | `LICENSE_AMBIGUOUS` | `RESEARCH_ONLY` |
| geo-seo-claude | MIT | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| platinum-seo-engine | **AGPL-3.0** | `LICENSE_COPYLEFT_REVIEW_REQUIRED` | `LEGAL_REVIEW_REQUIRED` / effectively `DO_NOT_COPY` |
| localseoskills | MIT | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| seo-skills | MIT (SE Ranking) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |
| agent-skills | MIT | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| aaron-marketing-skills | **Apache-2.0** | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `ADAPT_WITH_ATTRIBUTION_REVIEW` |
| seo-geo-claude-skills | Apache-2.0 | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |
| next-seo | MIT (`LICENSE` + `LICENSE.md`) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |

**Platinum license timeline (recorded, not interpreted as a strategy)**

| Point | State |
| --- | --- |
| Releases ≤ `v2.1.0` (`eab3cb8b7c021f4b2256c93b5a65f4e203d9bd12`) | README states these were published under MIT and remain so |
| Relicense commit `9563572fc3828d1e44aa676215f9f7ccd06fc2cf` | Introduces AGPL-3.0 |
| Audited HEAD `3192d565fbb5629460cd4d86ffd35610db4293c0` | AGPL-3.0 — `COPYLEFT_REVIEW_REQUIRED` |

**License axis ≠ quality axis (R5).** Platinum is among the most architecturally disciplined repositories in this corpus *and* the most copy-restricted. Both statements are recorded independently.

**These notes are not legal advice.** They record observed license files and declarations at pinned SHAs to support an engineering decision to *not copy*. Any actual reuse requires human legal review.

## 33. Copy classification matrix (what may cross the boundary)

| Artifact kind | Classification | Rationale |
| --- | --- | --- |
| Repository source code (any language) | `DO_NOT_COPY` | No external code in MoxDOP; 0 lines committed |
| JSON Schemas / registries / contract files | `DO_NOT_COPY` | MoxDOP has its own contract registry |
| `SKILL.md` prose bodies | `DO_NOT_COPY` | Creative expression; MoxDOP writes its own |
| Prompt corpora | `DO_NOT_COPY` | Explicitly out of scope for this research |
| Install scripts / MCP configs | `DO_NOT_COPY` | Rejected runtime |
| Element / property / field **names** (e.g. `rel=canonical`, `viewport`, Schema.org property names, GBP field names) | `COPY_PERMITTED_SUBJECT_TO_LICENSE` | Factual vocabulary, not expression; sourced from standards anyway |
| Check *inventories* (the list of what to look at) | `REEXPRESS_FROM_PRIMARY_SOURCES` | Adopt the coverage, write the descriptions |
| Skill **schema field names** (from agent-skills) | `REEXPRESS_FROM_PRIMARY_SOURCES` | Adopt structure, author own definitions |
| Score formulas | `DO_NOT_COPY` + rejected as metrics | §24 |
| Study percentages and market statistics | `DO_NOT_COPY` | Level G/H; no MoxDOP use |
| Apache-2.0 sourced adaptations | `ADAPT_WITH_ATTRIBUTION_REVIEW` | Notice/NOTICE mechanics need review first |
| Anything from Platinum HEAD | `LEGAL_REVIEW_REQUIRED` | AGPL-3.0 |

## 34. Candidate MoxDOP Skills (synthesis by capability)

Candidates are synthesized **by capability, not per repository**. Full specifications — data requirements, evidence, permissions, abstention rules, eval sketches, license posture — are in [`MOXDOP_SKILL_CANDIDATES.md`](./MOXDOP_SKILL_CANDIDATES.md). Nothing here is implemented; MoxDOP currently ships `technical-seo-analysis`, `search-console-analysis`, `keyword-opportunity-analysis`, `recommendation-framing`, `brand-context-discovery`, `ga4-measurement-quality`, `gsc-search-demand-review` (see `docs/product/AGENT_SKILL_ARCHITECTURE.md`).

| # | Candidate Skill | Capability | Primary evidence | Status | Relationship to shipped Skills |
| --- | --- | --- | --- | --- | --- |
| C1 | Website Technical Audit | Structural site health | A + D (+B for CWV field) | `READY_FOR_NORMALIZATION` | Extends `technical-seo-analysis` |
| C2 | Indexability Analysis | Can search/AI systems access and index | A + D | `READY_FOR_NORMALIZATION` | Split out of `technical-seo-analysis` |
| C3 | Metadata Consistency | Title/meta/heading/head integrity across sources | A (+ CMS conflict handling) | `READY_FOR_NORMALIZATION` | New; HEAD-derived subject coverage |
| C4 | Structured Data Audit | JSON-LD type/property correctness and currency | A + D | `NEEDS_PRIMARY_SOURCE_WORK` | New; deprecation axis required |
| C5 | Internal Linking Analysis | Link graph, depth, orphan risk | A + E | `NEEDS_DATA` | New; bounded inventory required |
| C6 | Content Quality Review | Editorial quality and helpfulness | F (+ Brand Context) | `PLAYBOOK_NOT_SKILL` (Playbook-leaning) | Prompt 45 surface, not an AI Skill |
| C7 | Search Demand Analysis | What demand exists and where | B (+ C optional) | `READY_FOR_NORMALIZATION` | Aligns with `gsc-search-demand-review` |
| C8 | Query Opportunity Analysis | Where measured position is actionable | B + E | `READY_FOR_NORMALIZATION` | Aligns with `keyword-opportunity-analysis` |
| C9 | Local Profile Completeness | Is the local profile complete and consistent | A + D, needs GBP data | `NEEDS_DATA` | New |
| C10 | Local Review Intelligence | Review volume/velocity/response behaviour | B via official API only | `NEEDS_DATA` | New; scraping rejected |
| C11 | Measurement Audit | Is measurement trustworthy end to end | A + B | `READY_FOR_NORMALIZATION` | Extends `ga4-measurement-quality` |
| C12 | GEO Observation Analysis | Disaggregated GEO observables only | A only today | `EXPERIMENTAL` | New; §25 vocabulary mandatory |

Cross-cutting requirements for every candidate: bounded inputs; explicit `data_availability`; explicit abstention rules; evidence level per assertion; `forbidden_claims`; no Task/Finding auto-creation; no external write; no new provider client.

## 35. Rejected concepts register

| Rejected concept | Sources | Reason |
| --- | --- | --- |
| Magic SEO score as canonical metric | claude-seo | Unfalsifiable composite across incommensurable evidence levels |
| Magic GEO score as canonical metric | geo-seo-claude | Same, plus unobservable inputs |
| Magic E-E-A-T score as canonical metric | claude-seo, aaron | Rater guidance is not a computable site score |
| Magic citation/citability score as canonical metric | claude-seo, geo-seo-claude | Heuristic inputs presented as measurement |
| Vendor authority / domain rating as MoxDOP metric | seo-skills, aaron, localseoskills tool skills | Proprietary construct, not a platform signal |
| Missing-as-zero scoring | all scoring repos | R6; produces false negatives and false confidence |
| Vendor metrics as truth or as substitutes for first-party data | open-seo, seo-skills | R7 |
| Copying Platinum code / schemas / registries | platinum (AGPL HEAD) | Copyleft incompatible with MoxDOP posture |
| Sourcing from an older MIT tag to avoid current copyleft | platinum README timeline | Not an engineering decision; legal review only |
| Executing installers / `curl \| bash` / `npx skills add` | claude-seo, geo-seo-claude, localseoskills, seo-skills, agent-skills, aaron | Unreviewed remote execution |
| Registering MCP servers | platinum, seo-skills, open-seo | No MCP as MoxDOP core |
| Crawlers, scrapers, desktop-crawler dependencies | platinum, localseoskills, review scraper (secondary) | Not accepted source classes |
| Task auto-creation / autonomous execution tiers | localseoskills, platinum | R11; Prompt 43/46 own Task and Recurring Review semantics |
| Scheduler inside a Skill | localseoskills, platinum | Prompt 61/62 owns recurring scheduling |
| Second DataForSEO client or provider stack | open-seo, platinum, claude-seo extensions | R12/R13; Prompt 34 owns DataForSEO |
| Second Evidence / Finding / Collection stack | platinum, open-seo | R12; MoxDOP contracts are canonical |
| External write actions (indexing ping, GBP posts, review replies) | platinum, localseoskills | Read-only integration rule |
| Unsupported GEO causality (30–115% and similar) | geo-seo-claude | Level G restated causally becomes level H |
| HEAD taxonomy myths as Finding rules without Google verification | HEAD | R3; §31 |
| Parallel specialist sub-agent fan-out as architecture | claude-seo | Few strong Agents + curated Skills |
| Hundreds of skills as a capability claim | aaron, platinum, marketingskills | R2 / U12 |
| Frozen repository treated as current methodology | seo-geo-claude-skills | U14 |
| Framework-specific structured-data port | next-seo | Not a MoxDOP runtime candidate |

## 36. Prompt 49 handoff, research limitations, and open questions

**Prompt 49 handoff (documentation only; no runtime in Prompt 48)**

1. Normalize a MoxDOP Skill schema by intersecting the `addyosmani/agent-skills` anatomy (§17) with the MoxDOP ontology in `docs/product/AGENT_SKILL_ARCHITECTURE.md`: add `triggers[]`, `anti_rationalizations[]`, `red_flags[]`, `verification[]`, `evidence_requirements[]` (carrying levels A–H), `abstention_rules[]`, `forbidden_claims[]`, `data_availability`, `eval_cases[]`.
2. Keep the existing registry mechanics. No `skills` table, no loader change, no MCP, no execution.
3. Re-read primary documentation for every check before writing catalog text (§31), especially anything with a date attached.
4. Take candidates from §34 in status order: `READY_FOR_NORMALIZATION` first; `NEEDS_PRIMARY_SOURCE_WORK` next; `NEEDS_DATA` only as documentation; `EXPERIMENTAL` labelled and abstention-first; `PLAYBOOK_NOT_SKILL` routed to Prompt 45.
5. Carry no composite score into the schema. `score` is not a Skill output field.
6. Attribution posture: Apache-2.0-derived adaptations (§18) need notice review before any text lands.

**Research limitations**

| Limitation | Effect |
| --- | --- |
| Shallow clone depth 50 | Long license/authorship history not fully reconstructible; the Platinum timeline rests on README statements plus the identified relicense commit |
| Not every `SKILL.md` line was read | ~450 skill files across the corpus; reading was targeted at audit/scoring/GEO/technical/governance skills and contributor guidance. Absence of a concept in this matrix is not proof of its absence upstream |
| Repositories are actively moving | Several were committed to within 48 hours of the research date; conclusions are pinned to the SHAs in §6 and may not describe current HEAD |
| GEO primary science is unsettled | Repositories directly contradict each other (llms.txt). MoxDOP resolves only via primary documentation, and several GEO questions have no primary documentation at all |
| Primary-source verification is a documented log, not a re-read at implementation time | §31 records authority and permitted assertions; Prompt 49 must re-read before catalog text |
| Vendor-backed repositories carry structural conflict of interest | Method may be shaped by the vendor's API surface rather than by analytical necessity |
| Legal notes are **not legal advice** | License observations support a not-copy engineering decision only; reuse requires human legal review |
| No runtime was executed | No installer, MCP, crawler, or script ran; behavioural claims in READMEs are unverified by execution and are recorded as claims |

**Open questions for the Architect**

1. Does Indexability Analysis (C2) split cleanly out of the shipped `technical-seo-analysis`, or does it stay one Skill with sections?
2. Is Content Quality Review (C6) accepted as a Prompt 45 Playbook rather than an AI Skill?
3. Does a bounded internal-link inventory (C5) belong to the Website connector path or to a bounded direct-observation collector, given the no-general-crawler rule?
4. Does MoxDOP want a Recommendation Reviewer gate (the auditor pattern in §18/§23) in a named prompt, or does deterministic grounding validation remain sufficient?
5. Is GEO Observation Analysis (C12) allowed to ship as `EXPERIMENTAL` with only level A observables, or is it deferred entirely until an AI-surface data question is answered?
