# External Skill Unsafe Assumptions (Prompt 48)

> **Status:** RESEARCH ARTIFACT — no production code, no runtime, no dependency change
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47)
> **Parent artifact:** [`MOXDOP_SKILL_RESEARCH_MATRIX.md`](./MOXDOP_SKILL_RESEARCH_MATRIX.md)
> **Owns:** unsafe assumption register, magic-score inventory, GEO causality claims, missing-as-zero traps, safety-envelope violations

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| Production MoxDOP Skill implementation | **NOT YET** |

This artifact exists because the corpus is *methodologically useful and epistemically unsafe at the same time*. Repository claims are recorded as claims and never silently rewritten; the MoxDOP classification is attached alongside.

---

## 1. Unsafe assumption register

| # | Unsafe assumption | Where observed | Why unsafe | MoxDOP guard |
| --- | --- | --- | --- | --- |
| U1 | A weighted composite score measures site quality | claude-seo, geo-seo-claude, aaron-marketing-skills | Averages across incommensurable evidence levels; movement is unattributable; unfalsifiable | Composites rejected as canonical metrics (§2); Findings + coverage instead |
| U2 | Missing data contributes zero to a score | Implicit in every scoring repository | Produces false negatives and false confidence simultaneously | Abstention + explicit availability enum (§4) |
| U3 | Vendor estimates are measurements | open-seo, seo-skills, localseoskills vendor tool skills | Vendor volume/rank/authority are models, not observations | Source-class separation; evidence level C ceiling |
| U4 | Correlational study percentages predict outcomes for a specific site | geo-seo-claude | Study population ≠ this asset; correlation ≠ causation | Level G cap; no causal restatement (§3) |
| U5 | llms.txt presence improves AI citation | geo-seo-claude asserts a lever; claude-seo argues the opposite with primary-source evidence | Repositories directly contradict each other; no primary documentation resolves it | Presence observable (level A); **no Finding rule**; conflict recorded |
| U6 | AI answer surfaces are reliably measurable | geo-seo-claude, open-seo AI visibility, seo-skills AI share-of-voice | Answers are non-deterministic, personalized, and unversioned; sampling is not measurement | `NOT_AVAILABLE`; `EXPERIMENTAL` labelling; sampling metadata mandatory |
| U7 | Autonomous execution / scheduling belongs inside a skill | localseoskills approval tiers, platinum publishing skills | Creates unreviewed side effects and external writes | Prompt 46 owns Recurring Reviews; Prompt 61/62 owns scheduling; Skills create nothing |
| U8 | Popularity implies correctness | aaron registry/download badges, geo-seo-claude star-history chart, claude-seo test-count badge | Distribution metrics say nothing about method validity | Stars ≠ evidence; popularity informs staleness risk only |
| U9 | Repository prose is platform policy | HEAD, and every SEO repository to some degree | Authors summarize, editorialize, and age | Primary sources override repo claims; verification log in parent matrix §31 |
| U10 | A subjective E-E-A-T judgment can be a deterministic Finding | claude-seo, aaron `content-quality-auditor` | Quality-rater guidance describes human rater assessment, not a computable site property | Level F; advisory AI output only |
| U11 | A single local rank exists | Implicit in rank-tracking skills; geogrid skills partly correct it | Local results vary by query point, device, and personalization | Grid dependence acknowledged; the aggregate metric is rejected |
| U12 | Skill count equals capability | aaron (120), platinum (50), marketingskills (49), localseoskills (39) | Inventory size measures surface area, not analytical power | Few strong Agents + curated versioned Skills |
| U13 | Installing a skill library is safe by default | 8 of 12 repositories ship installers; 5 document `curl \| bash`; 4 wire MCP | Unreviewed remote execution and tool registration | Nothing executed; no MCP registered |
| U14 | A frozen repository is current methodology | seo-geo-claude-skills (frozen at `v9.9.12`) | Content ages silently once maintenance stops | Signpost recorded; historical reference only |
| U15 | A library's supported types track current platform eligibility | next-seo | Platforms retire types faster than libraries remove components | Schema.org + Google documentation are the authority |
| U16 | The operator's supplied business context is accurate and needs no provenance | marketingskills, localseoskills briefs | Unattributed context becomes indistinguishable from measured fact | Brand Context carries provenance; operator input is a labelled source class |
| U17 | Skills may hold credentials or route through vendor transports as a matter of course | seo-skills MCP, platinum MCP fleet, claude-seo extensions | Expands the credential surface and couples methodology to a vendor | No credentials in Skill context; Integrations own transport |
| U18 | A retrieved document faithfully represents the page | seo-skills documents the counter-case explicitly; other repos assume fidelity | Post-processed HTML silently drops canonical, hreflang, and JSON-LD | Fidelity assertion before analysis; stripped fields are a run defect |
| U19 | More parallel agents produce more reliable analysis | claude-seo (up to 15 specialist agents) | Fan-out multiplies unverified assertions and coordination failure modes | One strong domain Analyst + curated Skills |
| U20 | Scraped or crawler-derived data is an acceptable substitute for authorized data | platinum `scrapling-ops`, localseoskills vendor scrapers, secondary review-scraper repo | Terms-of-service exposure, unstable provenance, no reproducibility guarantee | Not an accepted source class |
| U21 | A drift/governance layer makes downstream claims true | platinum | Contract discipline guarantees consistency, not correctness of the underlying method | Contracts and evidence levels are separate axes |
| U22 | Deprecated markup remains harmless to recommend | Any repo without a deprecation axis | Recommending retired types wastes work and can mislead | Deprecation axis required in the catalog |
| U23 | Threshold numbers are industry standards | Title/meta length bands, completeness percentages, citability word counts across repos | These are conventions that propagate by repetition | Level F with explicit uncertainty labelling |
| U24 | Absence of a citation/directory listing is a negative signal | localseoskills citation skills | Absence may reflect data gaps, not the business's actual footprint | `REQUIRES_OPERATOR_INPUT`; missing ≠ negative |

## 2. Magic score inventory (inventoried, then rejected)

Inventory is required so that MoxDOP reviewers recognize these numbers when they encounter them externally. Inventory ≠ endorsement. **None may become a canonical MoxDOP metric.**

| Score as published | Source | Published composition | Inputs' evidence levels | Decision |
| --- | --- | --- | --- | --- |
| SEO Health Score 0–100 | claude-seo | Technical SEO 22% · Content Quality 23% · On-Page SEO 20% · Schema 10% · Performance (CWV) 10% · AI Search Readiness 10% · Images 5% | A, F, A, A+D, B/E, F, A | **REJECT as canonical metric** |
| Content Quality XX/100 | claude-seo | Sub-score of the above | F | **REJECT** |
| AI Citation Readiness XX/100 | claude-seo | Sub-score | F/G | **REJECT** |
| GEO Readiness XX/100 | claude-seo | Sub-score | F/G | **REJECT** |
| Composite GEO Score 0–100 | geo-seo-claude | `GEO_Score = (Citability * 0.25) + (Brand * 0.20) + (EEAT * 0.20) + (Technical * 0.15) + (Schema * 0.10) + (Platform * 0.10)` | F, unobservable, F, A, A+D, unobservable | **REJECT as canonical metric** |
| CORE-EEAT content quality gate score | aaron-marketing-skills | Rubric-derived content score gating publication | F | **REJECT as metric**; gate *concept* accepted |
| CITE domain rating | aaron-marketing-skills | Domain authority construct | F | **REJECT** |
| Per-discipline auditor scores (email/ad/creator quality scores) | aaron-marketing-skills | Discipline rubric composites | F | **REJECT** |
| Vendor authority / visibility indices | seo-skills, localseoskills vendor tool skills | Vendor-proprietary | C/F | **REJECT** as MoxDOP metric; may be displayed as an attributed vendor figure |
| Geogrid visibility score | localseoskills | Aggregate across grid-point ranks | C | **REJECT as metric**; grid-dependence *concept* accepted |
| Local pack / SXO composite ratings | seo-skills, claude-seo | Mixed rubric | F | **REJECT** |

### 2.1 Why rejection rather than reweighting

| Problem | Explanation |
| --- | --- |
| Incommensurability | A canonical-tag observation (level A) and a content-quality judgment (level F) are not the same kind of quantity; weighting implies they are |
| Unattributable movement | A score dropping 6 points communicates nothing actionable; the operator must decompose it anyway, which means the components were the real output all along |
| Unfalsifiability | The score cannot be wrong, which contradicts the falsifiability discipline MoxDOP borrowed from claude-seo |
| Missing-as-zero coupling | A composite needs a value per category; the cheapest available value for an absent category is zero, which manufactures a defect |
| False comparability across assets | Two sites with different available data produce scores that look comparable and are not |
| Score-chasing | A headline number becomes the objective, displacing the underlying operational question |

### 2.2 Permitted MoxDOP alternative

| Instead of | MoxDOP presents |
| --- | --- |
| A composite health score | Counts and severities of individually falsifiable Findings, each with source class, observation time, and evidence level |
| A category sub-score | Per-subject coverage: checked / not applicable / unavailable, plus observed defects |
| A readiness percentage | An explicit checklist of observed states with availability annotations |
| An authority rating | The measured inputs MoxDOP actually has (GSC, GA4), labelled by source |

MoxDOP's existing data contract already encodes this posture: `WEB_OVERVIEW_FINDINGS` is annotated "Not a Health Score" in `docs/data-contracts/WEBSITE_DATA_CONTRACT_V1.md`.

## 3. GEO causality and study-claim register

Claims are recorded as published (short form), then classified. No claim below may be restated causally in MoxDOP output.

| Claim as published (short) | Source | Attribution given by the repo | MoxDOP level | Permitted MoxDOP use |
| --- | --- | --- | --- | --- |
| "GEO-optimized content achieves 30-115% higher visibility in AI-generated responses" | geo-seo-claude (`geo-citability`, echoed in `geo-audit`) | "Research from Princeton, Georgia Tech, and IIT Delhi (2024)" | **G** — correlational study claim; **H if restated causally** | May be cited in research documentation as an external study claim; **never** as an expected outcome, target, or Finding |
| AI systems preferentially cite passages "134-167 words long", self-contained, fact-rich, answering a question in the first 1–2 sentences | geo-seo-claude; the word band also appears in claude-seo | "Bortolato 2025 analysis of AI Overview passages" | **F/G** | Advisory structural hint with an explicit uncertainty label; never a threshold Finding |
| "Definition patterns increase citation rate by 2.1x" | geo-seo-claude | 2024 study reference | **G** | Research note only |
| "Adding statistics to passages increases citation by 40%" | geo-seo-claude | GEO study 2024 | **G** | Research note only |
| README market table: GEO services market size and projection, "+527% AI-referred traffic growth", "4.4x higher conversion", "Gartner: -50% search traffic by 2028", "brand mentions 3x stronger correlation than backlinks", "only 23% of marketers investing" | geo-seo-claude README | Mixed / unattributed | **G**, some unverifiable | **No MoxDOP use**; market commentary |
| llms.txt is a GEO lever | geo-seo-claude (`geo-llmstxt`) | — | Contested | Presence is level A; effect claim withheld |
| llms.txt is **not** currently a citation lever; content chunking is not required; AI-specific keyword rewriting is unnecessary | claude-seo (`seo-geo` myth reframes, with an in-repo primary-source evidence reference) | Primary-source argument | Direction consistent with Google's published stance | Adopt the *practice of publishing negative findings*; re-derive the specific conclusions from Google documentation before stating them |
| "GEO/AEO are rebranded labels for SEO; AI features are grounded in the same ranking systems as classic Search; pages must be indexed and snippet-eligible to appear in AI features" | claude-seo, citing Google's AI-optimization guidance | Primary-source citation | **D once re-read** | Strong candidate for MoxDOP's GEO framing — but Prompt 49 must read Google's documentation directly, not this repository |

### 3.1 The direct contradiction, recorded

Two mandatory repositories, both MIT, both active, take opposite positions on llms.txt. Neither is authoritative. This is the clearest demonstration in the corpus that **repository consensus does not exist and cannot be substituted for primary sources**. MoxDOP records both positions, adopts neither as a rule, and treats only file presence as observable.

### 3.2 Non-reproducibility of AI-surface observation

Any future MoxDOP AI-surface observation must record surface/product, model or version identifier if exposed, locale and language, query text, timestamp, and sampling method — and must be labelled `EXPERIMENTAL`. Without that metadata the observation is not interpretable later, which makes it unusable as Evidence.

## 4. Missing-as-zero trap catalog

| Trap | Concrete manifestation | Detection | MoxDOP guard |
| --- | --- | --- | --- |
| Composite gap-filling | A category with no data contributes 0, dragging the score down | Score present while a category's availability is unknown | Composites rejected; per-category availability required |
| Retrieval-stripped fields | Post-processed HTML removes canonical, hreflang, JSON-LD; the audit reports them as missing | Head fields all zero on a site that obviously has them (the SE Ranking repository documents exactly this failure and its recognition signal) | Fidelity assertion before analysis; run defect, not site defect |
| Error read as absence | `robots.txt` request times out; the audit reports "no restrictions" | Fetch outcome collapsed to a boolean | Three-state outcome: found / absent / error |
| Partial inventory read as complete | Orphan-page claims from a partial crawl | Absence claim with unknown coverage | Coverage must be known before any absence claim |
| Unavailable capability shown as passing | "No issues" emitted when the check never ran | Clean result with no evidence trail | Skill eligibility → **not applicable**, distinct from clean |
| Unparseable read as absent | Malformed JSON-LD reported as "no structured data" | Parse failure not surfaced | Report unparseable explicitly |
| Zero-volume read as no demand | Vendor returns no volume for a term | Vendor null treated as 0 | Vendor null = unknown; GSC answers the site-specific question |
| Absent citation read as negative | Directory listing not found | Presence check without coverage knowledge | `REQUIRES_OPERATOR_INPUT` |
| Missing review data read as no reviews | No authorized review source | Review count defaulting to 0 | Capability unavailable |

## 5. Safety-envelope violations observed in the corpus

Recorded so the boundary is explicit and auditable.

| Violation class | Where observed | MoxDOP position |
| --- | --- | --- |
| External write action | platinum `publishing/indexing-ping`, `verify-indexing`; localseoskills GBP post and review-response execution | **Prohibited.** Integrations are read-only |
| Autonomous scheduled execution | localseoskills 15 task templates with autonomous/queue/notify tiers | **Prohibited** as a Skill concern; Prompt 46 + 61/62 own recurring work |
| Auto-registered tool servers | platinum `.mcp.json` with 6 MCP servers; seo-skills `mcp.json`; open-seo MCP surface | **Prohibited.** No MCP as MoxDOP core. Note: aaron-marketing-skills deliberately keeps its catalogue *outside* the auto-registered path — the one good counter-example in the corpus |
| Pipe-to-shell installation | claude-seo, geo-seo-claude, localseoskills, seo-skills (and CLI installs in agent-skills, aaron) | **Not executed.** Never a MoxDOP path |
| Scraping / anti-detection retrieval | platinum `scrapling-ops`; vendor scraper tool skills; secondary review-scraper repo | **Prohibited** source class |
| Commercial desktop crawler dependency | platinum Screaming Frog over local HTTP; localseoskills `screaming-frog-tool` | **Prohibited** dependency |
| Credential breadth inside skill scope | claude-seo tiered credential sets; seo-skills API keys; localseoskills 11 vendor tool skills | Credentials stay in Integrations; never in Skill context |
| Self-directed multi-agent orchestration | claude-seo parallel specialist fan-out | **Prohibited.** Few strong Agents |
| Skill-initiated record creation | platinum `master-task-sync`, `mark-done`; localseoskills task templates | **Prohibited.** No Task/Finding/Recommendation auto-creation |

## 6. Conflict register (repository vs repository, repository vs MoxDOP)

| Conflict | Positions | Resolution |
| --- | --- | --- |
| llms.txt effect | geo-seo-claude: lever · claude-seo: not a lever, with primary-source argument | Primary sources only; presence observable, effect withheld |
| Composite scoring | claude-seo / geo-seo-claude / aaron: headline scores · MoxDOP: Findings and coverage | MoxDOP position stands; `WEB_OVERVIEW_FINDINGS` is explicitly "Not a Health Score" |
| Automation posture | localseoskills / platinum: autonomous execution as a feature · MoxDOP: operator-controlled | MoxDOP position stands (R11/R14) |
| Provider strategy | open-seo / seo-skills / platinum: multiple provider stacks and MCP transports · MoxDOP: single governed DataForSEO path behind Prompt 34 | MoxDOP position stands (R12/R13) |
| Skill scale | aaron 120 / platinum 50 / marketingskills 49 · MoxDOP: few Agents + curated Skills | MoxDOP position stands |
| Title/meta length thresholds | Multiple repos publish specific bands · No primary source specifies limits | Heuristic (level F), labelled |
| Vendor authority metrics | seo-skills / aaron treat authority ratings as meaningful · MoxDOP: proprietary construct | Level F; not a MoxDOP metric |
| GEO as one score vs six observables | geo-seo-claude: one score · MoxDOP: six disaggregated concepts | MoxDOP position stands (parent matrix §25) |

## 7. Reviewer checklist (for any future adoption PR)

| # | Question | Fail condition |
| --- | --- | --- |
| 1 | Does any assertion depend on a composite score? | Any score field in a Skill output |
| 2 | Is every assertion's evidence level recorded? | Unlabelled assertion |
| 3 | Does any check treat absence or error as zero? | Boolean fetch outcome; default-0 metric |
| 4 | Is any vendor figure presented as measurement? | Vendor and first-party numbers in one unlabelled column |
| 5 | Is any GEO claim aggregated or causal? | Single GEO number; percentage uplift language |
| 6 | Was any platform rule taken from a repository rather than primary documentation? | Citation to a repo for a platform fact |
| 7 | Does the change introduce a provider client, MCP server, crawler, or scheduler? | Any new transport or scheduling path |
| 8 | Can the Skill create a Task, Finding, Recommendation, or Notification? | Any side-effecting write |
| 9 | Is external text copied rather than re-expressed? | Verbatim prose from a repository |
| 10 | Is any Apache-2.0 or AGPL-sourced material involved without license review? | Missing notice/legal review |
| 11 | Does the Skill declare abstention rules and forbidden claims? | Missing fields |
| 12 | Do eval cases cover missing-required-evidence and missing ≠ zero? | Happy-path-only evals |

## 8. Limitations

| Limitation | Effect |
| --- | --- |
| Claims recorded from selective reading | Additional unsafe assumptions may exist in unread skill files |
| Evidence levels are MoxDOP's assignment | External repositories do not use this ladder; classification is interpretive by design |
| Study claims were not independently verified | Cited studies were not retrieved or evaluated; they are recorded as attributions made by the repository, which is exactly why they cap at level G |
| GEO science is unsettled | Some questions have no primary documentation to resolve them at all |
| No runtime executed | Behavioural claims in READMEs are unverified by execution and are recorded as claims |
| Not legal advice | Licensing consequences are in [`EXTERNAL_SKILL_LICENSE_PROVENANCE.md`](./EXTERNAL_SKILL_LICENSE_PROVENANCE.md) |
