# External Skill License and Provenance (Prompt 48)

> **Status:** RESEARCH ARTIFACT — no production code, no runtime, no dependency change
> **Research date:** 2026-08-16
> **Base MoxDOP HEAD:** `d705f8bd00bbd0ad8f0ff50c4c9404eacc8a6147` (Prompt 47)
> **Parent artifact:** [`MOXDOP_SKILL_RESEARCH_MATRIX.md`](./MOXDOP_SKILL_RESEARCH_MATRIX.md)
> **Owns:** license classes, copy classification, attribution mechanics, copyleft review notes

> **These notes are NOT legal advice.** They record observed license files and declarations at pinned commits to support an engineering decision to **not copy**. Any actual reuse of external material requires human legal review.

| Fact | Value |
| --- | --- |
| External repository code committed into MoxDOP | **0 lines** |
| External prose copied into MoxDOP | **none** |
| Production MoxDOP Skill implementation | **NOT YET** |

**License axis ≠ quality axis.** A permissively licensed repository may carry weak methodology; a copyleft repository may carry the strongest architecture in the corpus. Both statements are recorded independently and neither influences the other.

---

## 1. License class vocabulary

| Class | Meaning | Practical consequence for MoxDOP |
| --- | --- | --- |
| `LICENSE_CLEAR_FOR_RESEARCH` | Reading and methodology study unambiguous | Research proceeds; reuse still evaluated separately |
| `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | MIT / Apache-2.0 style | Reuse legally available **subject to notice retention**; MoxDOP still prefers re-expression |
| `LICENSE_COPYLEFT_REVIEW_REQUIRED` | GPL / AGPL family | Network-service copyleft implications; no copy without legal review |
| `LICENSE_AMBIGUOUS` | Conflicting or incomplete declarations | Research allowed; reuse blocked until resolved |
| `LICENSE_MISSING` | No license file at the audited commit | No verbatim copying regardless of README claims |
| `LEGAL_REVIEW_REQUIRED` | Escalation required before any decision | Engineering does not decide |

## 2. Copy classification vocabulary

| Class | Meaning |
| --- | --- |
| `DO_NOT_COPY` | No text or code reuse under any circumstance in this milestone |
| `RESEARCH_ONLY` | Read to understand; nothing crosses into MoxDOP |
| `REEXPRESS_FROM_PRIMARY_SOURCES` | MoxDOP authors its own text from provider/standards documentation |
| `ADAPT_WITH_ATTRIBUTION_REVIEW` | Adaptation possible; notice/attribution mechanics reviewed first |
| `COPY_PERMITTED_SUBJECT_TO_LICENSE` | Copy legally available under license terms if MoxDOP chooses |
| `LEGAL_REVIEW_REQUIRED` | Human legal review before any reuse decision |

## 3. License matrix (observed at audited commits)

| # | Repository | Audited SHA | License file observed | Declared license | Copyright holder as stated | License class | Copy classification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | https://github.com/coreyhaines31/marketingskills | `7868cb9251fad80a73d26e488a5ad5f6c4a9f335` | `LICENSE` (1069 B) | **MIT** | Corey Haines (2025) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| 2 | https://github.com/joshbuchea/HEAD | `de1304eeb62feb6bec90c28fc78fe29d2500d606` | **none** | README badge + License section claim **CC0 1.0** | — | `LICENSE_MISSING` | `RESEARCH_ONLY` |
| 3 | https://github.com/AgriciDaniel/claude-seo | `09d37c7b66ed3ca9c6efbdb765a805a6c76a8f01` | `LICENSE` (1069 B) | **MIT** | agricidaniel (2026) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| 4 | https://github.com/every-app/open-seo | `bd402844fae9101da9591b8eb153871773eb3c27` | `LICENSE` (1068 B) | **MIT** in file; **`package.json` `license` is null** | Ben Senescu (2026) | `LICENSE_AMBIGUOUS` | `RESEARCH_ONLY` |
| 5 | https://github.com/zubair-trabzada/geo-seo-claude | `e5d4a4a4f7bb10142f558b1df1308471948fb37c` | `LICENSE` (1072 B) | **MIT** | Zubair Trabzada (2026) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| 6 | https://github.com/popiliadam/platinum-seo-engine | `3192d565fbb5629460cd4d86ffd35610db4293c0` | `LICENSE` (34523 B, GNU AGPL v3 text) | **AGPL-3.0** | Free Software Foundation license text; project author per README | `LICENSE_COPYLEFT_REVIEW_REQUIRED` | `LEGAL_REVIEW_REQUIRED` (effectively `DO_NOT_COPY`) |
| 7 | https://github.com/garrettjsmith/localseoskills | `405ce209775f8cb8f9dbaa511656594bb682cf9f` | `LICENSE` (1084 B) | **MIT** | Garrett Smith / GMB Gorilla (2026) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| 8 | https://github.com/seranking/seo-skills | `fd6d1408f2e6a06454d81c07c29e0f04342eb9ba` | `LICENSE` (1067 B) | **MIT** | SE Ranking (2026) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |
| 9 | https://github.com/addyosmani/agent-skills | `df1edb2e05487d0aa6d93c747141e0aed1187f25` | `LICENSE` (1068 B) | **MIT** | Addy Osmani (2025) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `REEXPRESS_FROM_PRIMARY_SOURCES` |
| 10 | https://github.com/aaron-he-zhu/aaron-marketing-skills | `5538e686f1d6ae6ad484a3445fbd8cf4ab840397` | `LICENSE` (11347 B, Apache text) | **Apache-2.0** | Per repository | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `ADAPT_WITH_ATTRIBUTION_REVIEW` |
| 11 | https://github.com/aaron-he-zhu/seo-geo-claude-skills | `1ae40e6d98dd2626b56cd1bee8700edc9fb71789` | `LICENSE` (11347 B, Apache text) | **Apache-2.0** | Per repository | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |
| 12 | https://github.com/garmeeh/next-seo | `738ef7e774ee6216f6e90c26a3b5b99dd5d166b4` | `LICENSE` **and** `LICENSE.md` (1068 B each) | **MIT** (consistent in both) | Gary Meehan (2018) | `LICENSE_PERMISSIVE_WITH_ATTRIBUTION` | `RESEARCH_ONLY` |

## 4. Platinum SEO Engine — license timeline (the one copyleft case)

| Point in history | SHA | State |
| --- | --- | --- |
| Releases up to and including `v2.1.0` | `eab3cb8b7c021f4b2256c93b5a65f4e203d9bd12` | README states these releases "were published under MIT and remain so" |
| Relicense commit | `9563572fc3828d1e44aa676215f9f7ccd06fc2cf` | Introduces AGPL-3.0 |
| Audited HEAD | `3192d565fbb5629460cd4d86ffd35610db4293c0` | **AGPL-3.0** — `LICENSE` contains the full GNU AGPL v3 text; README badge and License section both state AGPL-3.0 |

README statement as published (short): *"Releases up to and including v2.1.0 were published under MIT and remain so; AGPL-3.0 applies from this point forward."* The README also advertises commercial/agency licensing for private modifications and white-labelling.

### 4.1 MoxDOP position

| Question | Position |
| --- | --- |
| May MoxDOP copy from the audited HEAD? | **No.** `COPYLEFT_REVIEW_REQUIRED`. AGPL-3.0 network-service copyleft is materially incompatible with MoxDOP's posture as an internal proprietary operations platform |
| May MoxDOP source from the older MIT-licensed tag instead? | **Not an engineering decision.** Deliberately selecting a pre-relicense tag to avoid current terms is a legal question, escalated, not resolved here |
| May MoxDOP read the repository? | Yes — reading a public repository is not reuse. All conclusions in this research came from reading, and no artifact was copied |
| Does the copyleft status reduce the methodology's quality? | **No.** Platinum is among the most architecturally disciplined repositories audited. License and quality are independent axes (R5) |
| What is the practical outcome? | The overlap with MoxDOP's existing contract/drift/append-only architecture means there is nothing MoxDOP needs from it anyway — the copy restriction costs MoxDOP nothing |

## 5. Declaration mismatches (recorded, not rewritten)

| Repository | Mismatch | Effect on MoxDOP posture |
| --- | --- | --- |
| `joshbuchea/HEAD` | README badge and License section claim CC0 1.0, but **no `LICENSE` file exists** at the audited SHA | Intent is clearly a public-domain dedication, but a missing license file means the dedication is not formally attached. **No verbatim copying.** Element and attribute *names* are factual vocabulary and are sourced from WHATWG / Google documentation instead |
| `every-app/open-seo` | `LICENSE` file is MIT; `package.json` `license` field is **null** | Adequate for research. Any reuse requires the mismatch resolved upstream or clarified in writing. Recorded as observed, not corrected on the repository's behalf |
| `AgriciDaniel/claude-seo` | Public MIT repository mirrors a private community repository with early-access content | The public repository's license governs the public repository. Content differences between the two are not observable and are not assumed |
| `garmeeh/next-seo` | Two license files (`LICENSE` and `LICENSE.md`) | Both MIT and consistent; no conflict, noted for completeness |

## 6. Attribution mechanics by license family

| Family | Repositories | Obligations relevant to any hypothetical reuse |
| --- | --- | --- |
| **MIT** | marketingskills, claude-seo, open-seo (file), geo-seo-claude, localseoskills, seo-skills, agent-skills, next-seo | Copyright notice and permission notice must be retained in copies or substantial portions. Practically: verbatim reuse requires carrying the notice; re-expression from primary sources avoids the question entirely |
| **Apache-2.0** | aaron-marketing-skills, seo-geo-claude-skills | Notice retention, statement of changes on modified files, `NOTICE` file propagation where one exists, and an explicit patent grant. The change-statement and `NOTICE` mechanics make adaptation materially heavier than MIT — hence `ADAPT_WITH_ATTRIBUTION_REVIEW` rather than a plain permissive classification |
| **AGPL-3.0** | platinum-seo-engine (HEAD) | Source-availability obligations extend to network-service use of modified versions. Incompatible with MoxDOP's posture; escalate rather than evaluate |
| **CC0 claimed, no file** | HEAD | Cannot rely on the claim; treat as `LICENSE_MISSING` |

## 7. Copy classification by artifact kind

| Artifact kind | Classification | Rationale |
| --- | --- | --- |
| Source code (PHP, TypeScript, Python, shell) | `DO_NOT_COPY` | No external code in MoxDOP |
| JSON Schemas, tool registries, contract files | `DO_NOT_COPY` | MoxDOP has its own contract registry under `docs/data-contracts/` |
| `SKILL.md` prose bodies | `DO_NOT_COPY` | Creative expression; MoxDOP authors its own |
| Prompt corpora / system-prompt text | `DO_NOT_COPY` | Explicitly out of scope; not reproduced anywhere in this research set |
| README prose | `DO_NOT_COPY` | Includes marketing and, in one case, affiliate content |
| Install scripts, MCP configs, plugin manifests | `DO_NOT_COPY` | Rejected runtime |
| Test suites and fixtures | `DO_NOT_COPY` | MoxDOP writes PHPUnit tests per ADR-038 |
| Element / property / field **names** (`rel=canonical`, `viewport`, Schema.org property names, GBP field names, CWV metric names) | `COPY_PERMITTED_SUBJECT_TO_LICENSE` | Factual vocabulary defined by standards bodies and platforms, not by these repositories |
| Check **inventories** (which subjects to examine) | `REEXPRESS_FROM_PRIMARY_SOURCES` | Adopt the coverage; author the descriptions from primary documentation |
| Skill **schema field names** (from `addyosmani/agent-skills`) | `REEXPRESS_FROM_PRIMARY_SOURCES` | Adopt the structure; author MoxDOP definitions |
| Score formulas | `DO_NOT_COPY` **and rejected as metrics** | See parent matrix §24 |
| Study percentages, market statistics | `DO_NOT_COPY` | Level G/H; no MoxDOP use |
| Anything from Platinum HEAD | `LEGAL_REVIEW_REQUIRED` | AGPL-3.0 |
| Apache-2.0 sourced adaptations | `ADAPT_WITH_ATTRIBUTION_REVIEW` | Notice + change-statement mechanics |

## 8. Provenance hygiene rules applied in this research set

| # | Rule | How it was satisfied |
| --- | --- | --- |
| P1 | No API tokens or credentials in recorded remotes | All remotes recorded as `https://github.com/owner/repo`; tokenized clone remotes were not transcribed into any MoxDOP file |
| P2 | Clones never committed | Clones live under `/tmp/moxdop-skill-research/` and are outside the repository; nothing under `/tmp` was modified |
| P3 | Every claim pinned to a full SHA | Commit ledger in [`EXTERNAL_SKILL_SOURCE_INVENTORY.md`](./EXTERNAL_SKILL_SOURCE_INVENTORY.md) §1 |
| P4 | Repository claims attributed, not absorbed | Claims recorded as short-form quotations attributed to the repository, with the MoxDOP classification attached |
| P5 | No long copyrighted corpora reproduced | Only short factual excerpts where the exact wording *is* the finding (e.g. a published weight table, a published formula) |
| P6 | License text read, not reproduced | License headers were read to confirm identity; full texts are not reproduced here |
| P7 | No installer, script, or MCP registration executed | Install surface inventoried in the source inventory §5 and left unexecuted |
| P8 | No dependency or manifest change | `composer.json` and `package.json` untouched |

## 9. Decision summary (license axis only)

| Decision | Repositories |
| --- | --- |
| Read freely; re-express from primary sources | marketingskills, claude-seo, geo-seo-claude, localseoskills, agent-skills |
| Read freely; research only, nothing crosses | HEAD, open-seo, seo-skills, seo-geo-claude-skills, next-seo |
| Adaptation possible after attribution review | aaron-marketing-skills |
| Escalate before any reuse | platinum-seo-engine |

**Net effect:** for every mandatory repository, the chosen MoxDOP path (re-express from primary sources, or research-only) means **no license obligation is triggered at all**. The license analysis therefore constrains nothing MoxDOP currently intends to do — it exists so that a future reviewer can see the constraint was checked rather than assumed.

## 10. Limitations

| Limitation | Effect |
| --- | --- |
| **Not legal advice** | These are engineering observations of license files and declarations at pinned commits |
| Shallow clone depth 50 | Full license history not reconstructible; the Platinum timeline rests on README statements plus the identified relicense commit |
| Per-file license headers not exhaustively audited | Repository-level `LICENSE` was read; individual files may carry differing headers or vendored third-party material with separate terms |
| Contributor licensing / CLA posture not assessed | Whether contributions were properly licensed to each project was not examined |
| Third-party content inside repositories not traced | Some repositories embed images, fonts, or vendored data with independent terms |
| Declarations can change | Platinum demonstrates that a repository can relicense; conclusions are pinned to the audited SHAs |
| Trademark and brand usage not assessed | Vendor names (SE Ranking, Screaming Frog, Semrush, Ahrefs, DataForSEO, Google) appear in this research as factual references only |
