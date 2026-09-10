# PROJECT_MEMORY

> **Canonical persistent product / architecture memory for MoxDOP.**  
> Historical baseline: `origin/main` @ `171e5e7` (2026-08-11). Latest scoped change: Public Discovery Stage 1 on `chatgpt/search-demand-foundation` (2026-09-07); main was not re-evaluated or modified.
> Does **not** override `docs/MASTER_SPEC.md`. See **Source priority** below.  
> Implementation truth (coded / tested / UAT / UX / async) lives in `PRODUCT_CAPABILITY_LEDGER.md`.  
> Operator long-running execution standard: `OPERATOR_ASYNC_EXECUTION.md`.

---

## Product identity

**MoxDOP** (DOP — Dijital Operasyon Platformu) is an **internal digital operations platform for Moximu**.

It is **not**:

- SaaS
- a customer / client portal
- a subscription / billing product
- a marketplace / plugin ZIP store
- a multi-tenant Workspace product

Operators are agency owners and agency staff only. Customers do **not** log in.

Canonical operational hierarchy:

```text
Customer
→ Brand
→ Digital Asset
→ Integration / External Resource / Binding
→ Run
→ Evidence
→ Finding
→ Recommendation
→ Task
→ Outcome
```

Notes:

- **AI remains advisory and evidence-grounded.** AI does not invent Findings, silently override deterministic Recommendations, or auto-open Tasks.
- **External provider integrations remain READ-ONLY.** No external write actions.
- There is **no separate Result entity**. Outcomes are observed via later Evidence / Finding lifecycle and Task outcome signals.
- Canonical operator product: root routes (`/`, `/login`, `/customers`, `/brands`, `/assets`, `/integrations`, `/activity`, `/findings`, `/recommendations`, `/tasks`, `/settings`, `/profile`, …). TailAdmin Livewire. One application.
- Single Filament technical/admin panel: id `app`, path `/admin` (ADR-044; supersedes ADR-026 path `/app`). `web` guard; `spatie/laravel-permission`.
- Legacy `/app/*` and `/system/*` prefixes are retired (HTTP 410). No parallel operator product.
- Operator Data Sources bind through ConfirmGoogle/ConfirmMeta guards. Google/Meta resource refresh on that page is Admin-only (`Roles::ADMIN`) before any provider call or inventory persistence; Meta refresh uses selected-Business `DiscoverMetaResourcesService::refreshInventory` (not broad `me/adaccounts`). Website period reads compose PeriodAware pool overlays with evidence `period_has_data` filtering.
- Staging/production: HTTPS + PostgreSQL + Redis/Horizon. `moxdop:production-check` is the production-readiness gate. The dedicated RC integration branch is the first head that contains **#202 + #199 + #200-downstream**; PR #209 alone is not that ancestry.
- Modules live under `app-modules/` + `internachi/modular` (minimal registry: id + enabled/disabled).

---

## Brand / account model

One Brand **MAY** have:

- multiple Meta Ads accounts
- multiple Google Ads accounts
- multiple Digital Assets of the same provider type

**Canonical model:**

```text
ONE provider advertising account
=
ONE corresponding Ads Digital Asset
+
its provider binding
```

Do **not** force all Brand ad accounts into one Digital Asset.

Meta Business Manager / Google Manager (MCC) accounts may appear as **provider scope / container context**, but are **not** automatically equivalent to Brand.

---

## Central integration model

The agency authenticates providers **centrally**.

### Meta

```text
one central Meta Integration / agency credential
→ discover accessible Businesses / Ad Accounts
→ operator selects relevant account(s)
→ bind selected accounts to Brand Digital Assets
```

- No Meta App per customer.
- No access token per Ad Account as the primary auth model.

### Google

Follows the corresponding **central agency-auth** model (one agency Google Integration → discover resources → bind to Digital Assets).

Google **Collect Data** is Integration-scoped at the operator entry, but planning/execution is **Brand-scoped**: one `CollectionRun` per eligible Brand, same-brand GSC/GA4/Ads siblings in that run, no silent drop of sibling Brands, no cross-brand or cross-customer mixing inside a run. Incremental refresh due selection uses that Brand’s exact preflight binding IDs across Digital Assets (not only the website/GSC anchor). Meta same-customer multi-brand backfill remains a separate contract (one run may span Brands for the same Customer).

Operator **Collect Now** / **Collect live data** for GA4, Search Console, Google Ads, and Meta Ads must start the shared Collection Engine (`ExecuteCollectionLifecycleService::runNow` → `CollectionRun` / warehouse). It must not write specialist Evidence summaries through BoundCollectorRegistry. GBP remains on the legacy bound Evidence collector. DataForSEO `HIT_FRESH` is scoped to the paid request fingerprint (including market `location_code` / `language_code`); a market change is a cache miss.

The dedicated RC integration PR is **not** a DOP Autopilot product PR. Do not put the Autopilot product-PR HTML marker in that PR body, even as a negation: the Gate treats a substring match as Autopilot and then fails when task metadata is absent. Autopilot squash-merge to `main` remains forbidden for this RC.

Site-scoped legacy connection paths may still exist for some Website connectors; the **direction of travel** is central Integration + External Resource + AssetBinding.

**Track A (issue #211):** GSC/GA4 analytical reads use the canonical PostgreSQL Data Pool (`gsc_*` / `ga4_*`), not a second metrics store. Initial backfill target is `provider_16m_available` (486 days). Evidence remains run provenance. Closed-period provider totals are compared via `moxdop:reconcile-provider-period` (live ±1% is external UAT). `core_connections` is not retired while probe/WordPress/PageSpeed paths still depend on it.

---

## Current product philosophy

```text
Provider / raw data
→ normalized operational data / Evidence
→ deterministic Findings
→ bounded Agent + Skills
→ AI interpretation
→ human Recommendation
→ human Task
→ later read-only refresh
→ Outcome
```

Hard distinctions:

| Platform / provider signal | Must not be treated as |
| --- | --- |
| Platform result | Verified business outcome |
| Meta lead | Qualified lead |
| Messaging result | Qualified customer |
| Purchase value | Verified profit (unless supported by business / CRM Evidence) |

Platform metrics are useful operational Evidence. They are **not** automatic truth about business success.

---

## Operational Taxonomy — planned foundation

**Status: PLANNED — do not implement in this memory milestone.**

Marketing entities will eventually be classified across **independent dimensions**, not one simple category string.

Example dimensions:

- Service / Offer
- Market / Geography
- Audience Segment
- Funnel Stage
- Business Goal
- Language
- Acquisition Type

Future classification should support:

- canonical terms
- aliases
- manual assignment
- AI / rule suggestions
- human approval
- provenance
- confidence
- valid-from / valid-to where needed

---

## Marketing Initiative — planned

**Status: PLANNED — do not implement yet.**

Brand-level grouping of provider entities that represent the **same commercial effort**.

Example:

```text
Mommy Makeover | Germany | Turkish Diaspora | Lead Gen
```

could later contain:

- Meta Campaign A
- Meta Campaign B
- Google Campaign X
- relevant landing-page context

Initiatives are a future organizational layer above raw provider campaign objects.

---

## Benchmark Cohort — planned

**Status: PLANNED — do not implement yet.**

Future cross-Brand comparisons should use **approved compatible taxonomy dimensions**.

Do **not** compare semantically incompatible platform metrics merely because labels look similar.

Example: Meta CTR and Google Search CTR are **not** automatically equivalent benchmark metrics.

---

## Operational Data Foundation — next foundation direction

**Status: DOCUMENTED DIRECTION ONLY — do not implement in this milestone.**

Planned building blocks:

- Provider Entity Catalog
- Historical Performance Store
- Historical backfill
- Incremental sync
- Operational Taxonomy
- Classification assignments
- Marketing Initiative foundations
- Benchmark Cohort foundations

Desired future behavior:

```text
Brand connects provider account
→ available provider history backfilled in resumable chunks
→ normalized daily facts retained
→ incremental updates continue
→ campaigns / entities are classifiable
→ historical filtering / comparison becomes possible
→ Evidence / Findings / Outcome / learning can use the history
```

Constraints:

- Historical store is **NOT RAG**.
- Do **not** use giant Evidence JSON dumps as the primary historical warehouse.
- Prefer normalized daily / entity facts with provenance.

---

## Agency Learning — future

**Status: PLANNED — no automatic self-modifying truth.**

Controlled future learning flow:

```text
Historical Evidence
+ Recommendation
+ Task
+ later Evidence
+ Outcome
→ Learning Candidate
→ human review
→ approved Agency Knowledge
```

No automatic Skill / Agent mutation from Outcomes without human approval.

---

## Outside-in Discovery status

**Latest Public Discovery slice applies to staging work branch `chatgpt/search-demand-foundation`, not main.**

Stage 1 now turns already-stored public Website HTML into reviewable information with actual canonical destinations. It uses the existing Integration collection engine for absent/stale/problem HTML and resumes the same operation after collection. The deterministic pass makes no AI or paid-provider calls. Historical AI/competitor candidates are preserved; their presence does not imply fresh external research.

Core choices (ADR-061):

- Original source time and exact URL identity matter. Missing, stale, unreadable, error-template and bounded/uninspected states remain explicit. Seven-day freshness, 500 pages / 32 MiB per pass and 5 MiB per object bound the analysis; existing collection limits are unchanged.
- Menus alone are not services; physical addresses are not automatically service areas. Same-value candidates combine provenance without resetting human decisions.
- Human approval links services to the existing Service Catalog / Brand Offering, explicitly structured areas to Brand Service Areas, and historical competitors to the Competitor Library. Existing priority, manual classification, relationships and exclusions win. Scalar replacement requires an explicit choice and matching current value.
- Approved social profiles appear in Integrations as candidates for the current authorization/resource/binding flow. Discovery never creates or binds an asset automatically.
- Receipts distinguish applied, integration-ready, observation-only and kept conflict. Historical approvals without a receipt require explicit transfer; there is no silent migration.
- There is no Agent-Reach runtime or new plugin framework. SERP/web research, social content, reviews, mentions and recurring discovery remain later stages.

The prior main implementation described bounded public crawling and optional provider competitor candidates. Do not claim that main has the new staging workflow. Do not describe either version as full digital-web intelligence. Canonical contract and verification limits: `docs/product/DISCOVERY_INTELLIGENCE.md`, `PRODUCT_CAPABILITY_LEDGER.md`.


## Operator workspace model — planned foundation

**Status: DOCUMENTED DIRECTION ONLY — not implemented; no UI built from this yet.**

`docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` defines one shared operator workspace shape across channel/module workspaces (Meta Ads, Google Ads, Website, GBP): **GLANCE → EXPLORE → DECIDE → DEEP DATA**, progressive disclosure, semantic-color-only design, no decorative charts, and the **Missing ≠ zero** rule (absent/uncollected data must never render as `0`).

It also codifies, as a UI-layer requirement, the existing platform-attribution-vs-verified-business-outcome distinction, and requires operator-facing workspaces to avoid internal jargon (Run/Evidence/ExternalResource/CoreAssetBinding) in favor of operator language — extending the pattern already used in `docs/product/integrations/WORKSPACE.md`.

Meta-specific application: `docs/product/META_ADS_EXPERT_WORKSPACE.md` (status: **BLUEPRINT / NOT IMPLEMENTED**; explicitly out of scope for PR #119).

Two decisions worth remembering from that blueprint:

- **Result Mix over forced Primary Result at account level.** When an account's campaigns have heterogeneous objectives, Overview should show a labeled breakdown across result types ("Result Mix") instead of collapsing to the current "Deferred" placeholder. Campaign/ad set/ad-level primary-result resolution is unchanged.
- **Delivered-in-selected-period is the default campaign filter**, not "Active now" — a campaign qualifies by `spend > 0 OR impressions > 0` in the selected period, sorted by material spend. Active/Paused/Archived/All remain explicit alternate filters.

A professional operator workspace (real performance-over-time, reliable multi-period comparison, fatigue-adjacent signals) is **blocked** on the Historical Performance Store / Operational Data Foundation and on `OPERATOR_ASYNC_EXECUTION.md` adoption — it cannot be honestly built on single-Run Evidence snapshots or blocking sync collection alone.

## Meta / Google intelligence (main vs unmerged)

### Google Ads Intelligence

Present on **canonical main** (module collectors, Findings, Google Ads Analyst + Skills, workspace UX).  
State details: see `PRODUCT_CAPABILITY_LEDGER.md`. Product doc often labels this “IMPLEMENTED V1” — that means a technical version slice, **not** automatically Definition-of-Done **DONE**.

### Meta Ads Intelligence

**PR #119** (`Meta Ads Intelligence + Analyst V1`) is the read-only Meta Ads Intelligence engine (collectors, Evidence, Findings, Analyst/Skills, interim specialist workspace).

Operator Ads Manager spot-check: **PASS**  
Account `act_744654160596455` · Campaign `09 | Diaspora TR | Form - Mox` · Period `2026-07-14`→`2026-08-10`.

Canonical ledger state: **UAT PASS / ACCEPTED — NOT DONE**.

Still explicit:

- **Background-ready: YES** for Collect live data + Generate AI guidance (database queue + Activity Center). Professional workspace still **NOT IMPLEMENTED**. Async Meta operator UAT is validated on the Async Operations PR (read-only).
- **Professional Meta Expert Workspace: BLUEPRINTED / NOT IMPLEMENTED** (`docs/product/META_ADS_EXPERT_WORKSPACE.md` + `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Do not call Meta Ads “complete”, “finished”, or “workspace done”

Main also has Meta **central Integration + resource discovery + binding** (connection layer).

Details: `PRODUCT_CAPABILITY_LEDGER.md`.

---

## Environments (material)

| Environment | Role |
| --- | --- |
| Cursor Cloud / local agent | **Development / automated test** only |
| PHPUnit | Isolated testing (`sqlite :memory:`) |
| Disposable browser-UAT SQLite | Synthetic browser checks only |
| **persistent UAT** | Future browser host when operator provisions infrastructure — **PREPARED / DEFERRED** (`docs/operations/PERSISTENT_UAT.md`) |
| Production | Future; **not** claimed by Async / UAT template work |

Persistent UAT decisions (when eventually used):

- Uses **MySQL 8** (not Cloud SQLite)
- Web = **Nginx + PHP-FPM**; plus separate persistent **queue worker** and **scheduler**
- One stable **`APP_KEY`** across deploys so encrypted provider credentials survive
- Provider credentials and real bindings must survive deploys; never regenerate `APP_KEY` casually
- Target hostname concept: `https://uat.dop.moximu.com` (operator DNS/host required)

**Async implementation acceptance** (queue + Activity + Cloud Meta smoke) is independent of **persistent deployment acceptance**. Operator decision (2026-08-12): do **not** provision VPS until Meta Expert Workspace UI is useful; Cursor Cloud remains development/test.

---

## Definition of Done

A feature is **NOT** considered **DONE** merely because code exists.

**DONE** requires the relevant dimensions to pass:

1. Code implemented
2. Automated tests
3. Real / provider UAT where applicable
4. Operator UX usable
5. Async / background-safe where long-running
6. Security / provenance checked
7. Known blockers resolved
8. Canonical documentation updated

Use explicit states such as:

| State | Meaning |
| --- | --- |
| `PLANNED` | Direction accepted; no meaningful product code |
| `IMPLEMENTING` | Active work; not ready to treat as main capability |
| `CODE COMPLETE` | Code on target branch; tests/UAT/UX may lag |
| `TESTED` | Automated tests cover the capability on main |
| `UAT REQUIRED` | Needs real provider / operator verification |
| `UAT PASS` | Real/provider UAT recorded as pass for the scoped slice |
| `PARTIAL` | Meaningful subset only; gaps are explicit |
| `BLOCKED` | Cannot proceed without resolving a named blocker |
| `DONE` | Meets Definition of Done for the scoped slice |

**Avoid** using “Implemented V1” as a synonym for **DONE**.

Technical version labels (for example Agent 1.0.0, “Intelligence V1”) remain valid as **version identifiers**, not completion claims.

Reconcile claims against `PRODUCT_CAPABILITY_LEDGER.md` before asserting completeness.

---

## External repository references

Reviewed external repos are **references only**. Never automatically vendor / copy them into this repository.

| Repository | Role |
| --- | --- |
| [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) | Methodology / Skills reference |
| [joshbuchea/HEAD](https://github.com/joshbuchea/HEAD) | Technical SEO taxonomy reference |
| [AgriciDaniel/claude-seo](https://github.com/AgriciDaniel/claude-seo) | SEO methodology + Recommendation framing reference |
| [every-app/open-seo](https://github.com/every-app/open-seo) | Selective implementation / workflow reference |
| [zubair-trabzada/geo-seo-claude](https://github.com/zubair-trabzada/geo-seo-claude) | Future GEO methodology reference |
| [garmeeh/next-seo](https://github.com/garmeeh/next-seo) | Structured-data taxonomy / reference |
| [pipeboard-co/meta-ads-mcp](https://github.com/pipeboard-co/meta-ads-mcp) | Meta taxonomy / reference only — **no** runtime / write adoption |
| [georgekhananaev/google-reviews-scraper-pro](https://github.com/georgekhananaev/google-reviews-scraper-pro) | Review intelligence concepts only — scraper runtime **rejected** |
| [Panniantong/Agent-Reach](https://github.com/Panniantong/Agent-Reach) | Capability / Adapter architecture reference — runtime **not** adopted |
| [OpenHands/OpenHands](https://github.com/OpenHands/OpenHands) | Future Platform Engineer research reference — **not** customer-analysis runtime |

Canonical adoption registry: `docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`.

---

## Website source boundary (accepted 2026-08-29)

- WordPress Connector = CMS inside truth. Public Discovery = externally published HTTP/HTML truth.
- A paired WordPress Website keeps Public Discovery and adds the authenticated connector family; it never replaces public verification.
- Non-WordPress Websites use public collection families.
- Connector is asset-scoped, read-only, least-data and signed. No WordPress writes, users/passwords/comments or media binaries.
- Integration screens show collection truth only. Deterministic Findings, Recommendations and manual Task handoff belong to the Website Digital Asset analysis workspace.
- Final visitor HTML is stored separately as versioned `website_html_snapshot` observations. SHA-256 change state links each observation to a content-addressed private compressed artifact; unchanged HTML does not duplicate the body. WordPress `post_content` remains distinct CMS truth.
- Public collection seeds from sitemap, existing URL inventory and published connector permalinks, then follows real same-site links within the explicit 5,000-page / 2 GB per-run and 10 MB per-response bounds. Discovered URL count is never presented as captured-HTML coverage.
- Update availability is an observed maintenance state, not a CVE/vulnerability claim.
- Code/test completion does not prove live WordPress UAT or production deployment.

Canonical decision: ADR-045. Detailed contract: `docs/product/website/WORDPRESS.md`.

---


## Intelligence Core boundary (accepted 2026-08-31)

- Intelligence Core provider-neutral identity/provenance/metric/capability layeridir; provider fact tablolarının yerine geçen ikinci bir warehouse değildir.
- Canonical dimensions: Page/URL, Search Term, Entity, Business Action, Time/Context ve Source/Provenance.
- URL join key scheme, `www`, path case ve trailing slash bilgisini korur. Redirect/canonical/CMS/rule/operator kanıtı olmadan Page identities birleştirilmez.
- Search term canonical text diacritics korur; folded text yalnız clustering candidate üretir. Source semantics alias üzerinde ayrı kalır.
- Missing ≠ zero; estimated ≠ measured; platform signal ≠ verified business outcome. Magic score ve ad-hoc formula yoktur.
- DataForSEO, GBP veya gelecekteki AI search kaynağı capability adapter ekler; mevcut source tables veya projection tüketicileri yeniden tasarlanmaz.
- Rebuildable Page/Search Term/Entity/Outcome profilleri Website Projection tarafından source-keyed read model olarak uygulanır. Bu katman provider fact tablolarını kopyalayan generic warehouse değildir ve source facts silinirse tek başına canonical truth sayılmaz.
- Mevcut Formula/Evidence/Finding/Recommendation/manual Task hattı tek otoritedir. AI Finding/Task oluşturmaz; external write yapmaz.

Canonical decision: ADR-046. Machine-readable contract: `resources/intelligence/MOXDOP_INTELLIGENCE_CORE_V1.json`.

---

## Website Intelligence Projection boundary (accepted 2026-08-31)

- Projection’ın canonical girdileri mevcut Website public/HTML, authenticated WordPress, bound GSC ve bound GA4 fact tablolarıdır. Kaynak fact tabloları authoritative kalır.
- Projection dört kimlik profili üretir: Page, Search Term, Entity ve Outcome. Her profil tek satırda source-keyed typed state, period, coverage, value state ve provenance taşır; generic EAV metric warehouse değildir.
- Varsayılan analitik pencere son tamamlanmış 90 UTC gündür. GSC/GA4 kaynakları kendi coverage ve watermark bilgisini ayrıca taşır; missing veya provider-omitted değerler sıfıra çevrilmez.
- WordPress CMS içeriği ile public visitor HTML aynı Page identity üzerinde ayrı `wordpress` ve `website` source state olarak kalır. Birinin alanı diğerinin yerine kullanılmaz.
- GSC query↔page ilişkileri provider limitleri belirtilerek korunur. GA4 Key Event, explicitly mapped Business Action altında provider-attributed signal olarak kalır; operator-verified outcome’a otomatik yükseltilmez.
- Collection tamamlandığında ilgili Website projection rebuild işi kuyruğa alınır. Projection kısmi kaynak hatasında mevcut başarılı source state’i korur; tam rebuild yok olan profilleri temizleyebilir.
- DataForSEO, GBP, Ads ve gelecekteki AI Search yeni source adapter ekler. Mevcut profile tüketicileri ve provider tabloları yeniden tasarlanmaz.
- Bu milestone backend projection/read service’tir. Operator Website sekmeleri, formula→Evidence tüketimi, test ve live UAT ayrı aşamalardır; DONE değildir.

Canonical decision: ADR-047. Implementation truth: `PRODUCT_CAPABILITY_LEDGER.md`.

---

## Search Demand foundation boundary (accepted 2026-09-02)

- MoxDOP's minimum commercial context is Customer → Brand → operator-selected Services + explicit country/city/district Service Areas.
- Global Service Catalog is reusable agency vocabulary. Existing Brand Offering remains the Brand-scoped identity and links to the catalog; neither replaces the other.
- Search Query Library is agency-wide operator knowledge with source records. It is not provider Evidence, a second Intelligence warehouse or a ranking claim.
- Brand-scoped provider queries continue to converge through `IntelligenceSearchTermIdentity`. A later Brand Query Portfolio will resolve approved Library items into that existing identity layer.
- Do not create a permanent Service × Area Cartesian product. Keep service and area relations separate; render provider request variants only when required.
- AI is reserved for bounded language classification and clustering candidates. Operator review and later SERP validation are required before URL ownership decisions. AI never invents metrics, Findings or Tasks.
- Query source observations retain provenance and missing values. Google Ads, GSC and DataForSEO observations do not overwrite one another.
- Search Demand Librarian execution is queued and persists proposals separately from Library truth. Exact reuse requires the same input, Agent, Skill definition and AI route/model fingerprint.
- AI-generated service aliases and query semantics are applied only after explicit operator approval. Rejected and abstained candidates remain auditable; abstention is never converted into a synthetic classification.
- Brand Query Portfolio references global Library identities instead of copying query text. Brand-only queries and Brand overrides are separate operator facts; global promotion is a submitted proposal, never an automatic write.
- Global, Brand and Website query scope are distinct. Portfolio application resolves through canonical Brand-scoped `IntelligenceSearchTermIdentity`; website activation is an explicit relation.
- Multi-region query variants are rendered from Brand Service Areas at use time. The default `all_brand_areas` scope and optional selected-area relations must not become a persistent Service × Area Cartesian table.
- Brand query clustering stores demand family, predicted SERP intent and content target as separate Brand-scoped layers. AI runs are queued proposals; human approval is the only apply path.
- Clusters are lockable and versioned. Incremental clustering only receives currently unclustered active portfolio queries; move, merge and split operations preserve stable item IDs and append snapshots.
- Without observed SERP evidence, a cluster remains `ai_prediction`. Semantic confidence must not be presented as SERP validation, ranking evidence or URL ownership.
- The Query–URL Visibility Map reads website-active portfolio queries and existing GSC, GA4 and Website Projection facts. It does not persist copied performance values or introduce another warehouse.
- GSC query–URL metrics are first-party measured at query/page grain. GA4 landing metrics remain page grain and are never represented as query attribution. Requested-period absence is `unobserved`/unknown, not zero.
- Search Demand SERP enrichment is a separate manual, queued and paid-consent-gated workflow behind a provider-neutral adapter. It is never invoked by Brand creation, import, page render or routine scheduling.
- Exact query/market/language/device/depth fingerprints reuse fresh SERP and keyword-metric observations. Every paid POST receives a durable pre-call marker, one queue attempt and fail-closed `CHARGE_UNKNOWN` handling when commit cannot be proven.
- DataForSEO search volume, CPC, competition and monthly trend are provider estimates and remain distinct from measured GSC/GA4 facts. Missing estimates remain unknown. Configured pre-call USD values are estimates; provider-reported cost is separate provenance.
- Optional DataForSEO query expansion creates review candidates only. Operator approval may add and activate a Brand Portfolio query; no automatic cluster membership, global promotion or Finding/Task follows.
- Observed exact-URL SERP overlap creates a threshold-provenanced cluster validation recommendation. Only human approval applies `serp_validated`, `serp_conflict` or `review_required`; that Phase 7 action never changes the separate Phase 8 URL ownership decision.
- URL ownership is a versioned human decision at Website + content-target-cluster grain. Candidate generation reads existing Website Page Projection, GSC query–page facts and stored SERP Brand URLs; it does not create a second metrics warehouse.
- The URL technical gate is fail-closed: only a same-Website public page with observed 2xx HTTP, no observed `noindex`, no canonical to another URL, matching observed language and an allowed content URL type may be proposed or verified. Missing gate evidence remains `unknown`.
- Two-period GSC leader changes and split visibility produce wrong-URL/cannibalization review candidates only. Page Relevance AI receives a bounded evidence pack, proposes at most one eligible page or abstains, and cannot change ownership.
- Human approval rechecks the live gate, records decision-time evidence and may lock ownership. Redirect, deletion, merge, new page/content, Finding, Recommendation, Task, provider spend and external write never follow automatically.
- Competitor Library identity is Brand + normalized domain. `www` is folded into the same domain while other subdomains stay distinct; candidate status is separate from role and entity-kind classification.
- Stored DataForSEO SERP/domain observations may create a bounded SERP competitor candidate with source/query/URL/time provenance. They never establish commercial competition automatically and the Phase 9 import never calls the provider.
- Commercial, SERP and content roles are independent. Business, directory, platform and authority-site kind is a separate operator classification; unknown remains allowed.
- Approved competitor links to services, Brand Service Areas, content-target clusters, appeared-on queries and observed URLs without creating a Service × Area Cartesian scope. Manual addition is an explicit human approval; pending candidates support individual/bulk review.
- Competitor page fetch/crawl is Phase 10 and Competitive Intelligence AI is Phase 11. Phase 9 creates no crawl, AI inference, Finding, Recommendation, Task, provider spend or external write.
- Competitor page collection is a queued, cluster-scoped Phase 10 operation over approved Competitor Library URLs. Selection is deterministic and bounded to 3 URLs per competitor / 20 per run; extracted links are observations and are never followed, so this is not a whole-site crawl.
- Phase 10 reuses Public Discovery's SSRF-safe bounded HTTP fetcher. It stores normalized text, title/meta/H1/headings, schema summary, bounded internal/external links and deterministic service/location expression matches with observation history.
- Raw HTML and normalized-content fingerprints detect repeats. Exact raw repeats skip parsing; unchanged normalized content appends a lightweight observation that references the prior content record instead of duplicating it. Phase 10 has no AI, Finding, Recommendation, Task, provider spend or external write.
- Competitive Intelligence Phase 11 requires a human-verified URL owner with checksum-verified stored Website HTML plus successful Phase 10 observations from approved, cluster-linked competitors. It never browses or fetches pages itself.
- The dedicated Competitive Intelligence Analyst receives bounded excerpts (Brand 16k chars, competitor 12k chars, max 8 newest unique competitor URLs), treats page/query content as untrusted data and persists exact Agent/Skill/route/input provenance.
- Phase 11 describes gaps as unanswered user needs/questions rather than word-count comparisons. Proposed competitor kind/roles, page intent, topics, structure, local trust, unnecessary/do-not-copy content and Brand differentiation remain separate review-only analysis records.
- Accepting or rejecting a Competitive Intelligence analysis changes only its review state. Competitor truth, URL ownership, Findings, Recommendations, Tasks, pages and external systems are unchanged; Phase 12 owns Finding/Recommendation creation.
- Search Demand Phase 12 selected-page AI receives current verified Brand-page evidence and Website standards. Human-approved comparable Phase 11 analyses are optional supporting context (ADR-060); pending/rejected/abstained analyses are excluded. Site-wide technical title/head/link checks now belong to the independent Website standards assessment.
- Every Phase 12 semantic proposal carries Agent/Skill/route provenance, exact analysis/observation/competitor references, evidence confidence, rationale, verification steps, one bounded action type and a non-publishable content brief. `insufficient_evidence` and abstention remain non-promotable states.
- Human acceptance is the only Phase 12 promotion path. It publishes canonical derived Evidence, attaches it to a Finding evaluation, writes/reconfirms the existing canonical Finding and creates a Finding-sourced Recommendation through the existing writer. It never creates a second Finding/Recommendation model.
- Recommendation → Task remains a separate explicit operator action. Phase 12 never creates a Task, changes URL ownership, publishes content, mutates a Website or writes externally. Change/result measurement remains Phase 13.
- Search Demand Phase 13 starts only from a completed Task linked to a human-approved Phase 12 proposal. The applied-change record stores affected URLs/clusters, application and review dates, and pre/post HTML fingerprints; it is provenance, not a separate Result entity.
- Targeted verification uses the shared read-only Website Public Crawl for exact affected URLs plus at most 99 matching page-family URLs. It never starts DataForSEO or performs a Website/CMS write.
- Deterministic technical rechecks, stored GSC/GA4 periods, stored pre/post SERP snapshots and a bounded Website Change Verification AI proposal remain separate components. Missing observations stay insufficient, and metric movement never becomes causal attribution.
- Phase 13 AI output is review-only. Human acceptance is required before the existing Task `outcome_*` fields change or a resolved/reconfirmed FindingEvaluation is appended. Task remains the only current Outcome truth; no Result/Outcome table exists.

Canonical decisions: ADR-048, ADR-049, ADR-050, ADR-051, ADR-052, ADR-053, ADR-054, ADR-055, ADR-056, ADR-057, ADR-058 and ADR-059. Canonical product contract: `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`.

---

## Website standards and improvement boundary (accepted 2026-09-06)

- Scope is the staging work branch `chatgpt/search-demand-foundation`, not main. The operator authorized implementation and direct branch saving, with no PR and no server execution.
- Integrations remain the collection/binding source. Services, areas, query/cluster and competitor Library records and human URL locks are retained.
- The Website module owns a 26-entry versioned standard catalogue, preserving all 17 diagnosis IDs. Admin controls activate/deactivate criteria and add expert review criteria with evidence/applicability/source/action/verification metadata.
- Website → Standards & Improvements evaluates stored Website profiles and HTML asynchronously with zero AI/provider calls. Missing/old evidence is unknown; optional/heuristic checks are distinguished from verified failures. There is no aggregate SEO/GEO score.
- Reuse the existing Run/improvement-proposal/human approval path. Standalone runs have nullable cluster/owner/competitive IDs; accepted technical proposals use Website source/asset subjects in the canonical Finding/Recommendation pipeline. Task creation remains manual.
- Technical groups prioritize verified-target accessibility/indexability blockers, other technical defects and advisory review, then affected URL count. Service/query/cluster coverage is separately ordered by repair need and explicit service priority.
- A blocked relevant page is a repair/review candidate, not proof that another page is needed. Existing verified owners remain locked until a human changes them; coverage matching cannot write ownership.
- Selected-page AI reviews stored content against criteria without requiring competitors. Comparable approved competitor analysis can enrich the same criterion. Exact own/rival excerpts and criterion IDs are checked; non-actionable, unsupported or abstained results cannot be promoted.
- Old or changed criterion/page/cluster/owner/competitor context cannot be approved as current. HTML observation IDs/timestamps alone do not force a second semantic call when content and other inputs are identical.
- Limits: 500 profiles, 3,000 active queries, 100 clusters, 20 candidates per cluster, 30 custom criteria, 5 MB per stored HTML and 16,000 own-page excerpt characters. Limits remain visible; unsupported whole-site conclusions are prohibited.
- Site-wide technical recommendations are rechecked with the standards assessment; Phase 13 remains cluster-scoped and does not accept standalone technical proposals. There is no automatic closure of Findings or automatic new-page/merge instruction from one page excerpt.
- All Search Demand Skill context keys are explicitly catalogued as workflow inputs; this does not create canonical Evidence or grant collection/writing capabilities.
- Validation and deployment truth are recorded in the Capability Ledger; local automated success does not establish operator/model/PostgreSQL UAT.

## Source priority

Preserve MASTER_SPEC supremacy while integrating project memory:

1. `docs/MASTER_SPEC.md` — product truth (highest)
2. Latest accepted ADRs (`docs/foundation/DECISION_LOG.md`)
3. `PROJECT_MEMORY.md` — persistent product / architecture memory (this file; does not override MASTER_SPEC)
4. Relevant `docs/product/*` / module blueprints
5. `PRODUCT_CAPABILITY_LEDGER.md` — **implementation truth** (coded / tested / UAT / UX / async)
6. `docs/IMPLEMENTATION_ROADMAP.md`
7. `docs/PROJECT_STATUS.md`
8. `AGENTS.md` / supporting references (`docs/foundation/*`, `docs/module-sdk/*`, research)

`docs/current-state/*` remains **historical** snapshot material. On conflict with the sources above, current-state loses.

When behavior or capability state changes, update `PRODUCT_CAPABILITY_LEDGER.md` in the **same PR**.  
When material product / architecture decisions change, update `PROJECT_MEMORY.md` in the **same PR**.

---

## Related canonical docs

| Doc | Role |
| --- | --- |
| `docs/MASTER_SPEC.md` | Product constitution |
| `PRODUCT_CAPABILITY_LEDGER.md` | Capability truth table |
| `OPERATOR_ASYNC_EXECUTION.md` | Operator async execution standard |
| `docs/PROJECT_STATUS.md` | Human/agent progress tracker |
| `docs/product/*` | Domain blueprints |
| `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` | Global operator workspace model (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/product/META_ADS_EXPERT_WORKSPACE.md` | Meta-specific workspace blueprint (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/foundation/DECISION_LOG.md` | ADRs |

## Global services management — 2026-09-07

Operator-authorized scope: Library navigation contains only Services, Search Queries, Query Clusters and Competitors. Other routes remain available to existing deep links. Services is the agency-wide editing surface: paginated searchable table, sector filter, edit drawer, sector CRUD and reversible deletion.

Global catalogue names are authoritative. A rename synchronizes linked Brand Offering primary names in one transaction, preserving offering IDs, priorities and goal/query relationships. A conflicting name aborts the entire edit. Brand-local rename of linked services directs the operator to Library. New Brand Offerings resolve the global catalogue, and the additive migration links legacy unlinked offerings without fuzzy matching or silently merging conflicts.

Service deletion is soft deletion: current catalogue/name/brand-offering reads hide the deleted identity, while historical foreign keys and observations remain. Restore brings the same identity and its links back. Current Brand Intelligence products/services and priority projections refresh on rename, deletion and restoration. Category keys remain stable on rename; deleting a category clears the service category, never deletes its services.

Validation: operator explicitly requested direct GitHub edits, no cloning and no tests. No test, build, browser or staging database verification is claimed. Deployment and operator acceptance remain required.


## Library imports and central locations — 2026-09-08

Scope: operator-authorized direct edits on staging work branch `chatgpt/search-demand-foundation`. Library navigation remains Services, Queries, Query Clusters, Competitors.

- Services supports bulk lines (up to 2,000), optional `service | phrase, phrase` syntax, and editable matching expressions. Expressions have service-local uniqueness; shared expressions can match multiple services. Existing global identity aliases remain separate.
- Queries supports paste, XLSX/CSV/TSV/TXT and selected stored Google Ads / Search Console resources over an explicit date range. Only existing canonical provider fact tables are read. No provider collection/API/spend follows import, and provider metrics are not copied or aggregated as query performance.
- A valid sector is mandatory. Optional service selections must be active and belong to that sector. Whole-phrase matching uses selected service expressions. Unmatched queries remain available through the sector-aware Unassigned filter. Operators can bulk assign up to 500 selected queries and create a sector/service inline.
- The agency Library identity for new writes is the location-free canonical text, independent of account, sector and market. Existing exact canonical items are reused. Multiple sectors attach to one item rather than creating another identity; the legacy scalar sector remains the primary compatibility value. Provider Intelligence identities and provider facts are unchanged. Existing historical query variants with locations are not destructively merged or rewritten by this migration.
- Country/province/district names are stripped at whole-expression boundaries, longest first; Turkish/ASCII spelling is folded only for location and phrase recognition. Original query spelling and removed expressions are visible in source records. Ambiguous names such as Of/Kale are also stripped under the operator's literal rule; the import panel says so. Location-only rows fail explicitly. Source repetition does not add identical source rows.
- The central bundled catalog has 249 ISO country/territory labels, 81 Turkish provinces and 973 districts with parent IDs and available dataset details. Original MIT files, licenses and pinned provenance live under resources/data/locations. No runtime downloads, new provider, new dependencies or Locations navigation item. CountryOptions and CityOptions delegate to the central catalog; Brand, customer HQ, prospect, public-discovery and search-profile location inputs use it. Non-Turkey free-form existing city data remains possible; no additional foreign subdivision catalog is introduced. Provider-specific geotarget IDs retain their own provider contract.
- Imports and bulk assignment use durable SearchQueryLibraryImport records and 100-row queued chunks, with bounded inputs, progress, first 20 row errors and terminal cleanup of temporary source files. Activity links to the relevant Library screen. Unknown failure never reports completion; reimport is safe. XLSX expansion is bounded to 32 MiB, 256 columns and 10,000 query rows; XML external loading and DTD/entity declarations are not accepted.
- Migration adds matching expressions, query-sector relations and import payload storage; backfills sector relations while preserving IDs and historical records.

Verification truth: source was reviewed without cloning, installing, running tests, Pint, build, browser acceptance or staging execution, as explicitly requested. Code is saved for deployment; migration, workers, runtime and operator acceptance are unverified. Not a DONE/UAT claim.


## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.


## Query Library daily management — 2026-09-08

Operator-authorized scope: inline query-name editing, download of filtered/all queries, immediate removal and related list usability, on `chatgpt/search-demand-foundation`.

- Hover/focus pencil opens the row editor; mobile keeps it visible. Enter saves, Escape cancels, inline errors retain input. Rename applies the same location stripping and canonical normalization as import, checks existing/deleted identities and rejects stale concurrent edits. ID and service/sector links persist. Original observations remain; a manual source record records previous/new input, actor and stripped locations. Linked Brand portfolio entries without text overrides refresh their Intelligence identity through the existing resolver; provider fact identities are not rewritten.
- Soft removal uses an additive deleted_at column. Removed items leave normal Library reads; Deleted supports individual/bulk restore, and the last individual removal has Undo. Imports refuse to recreate/resurrect removed identities. Existing Brand portfolio references explicitly retain their Library relation (withTrashed); removal from Library does not silently remove previously applied Brand queries.
- The default unfiltered list includes all nondeleted statuses. Status, sector, service, source, unassigned and literal folded search use one model query scope shared with authenticated CSV export. Export downloads all matching records, independent of pagination or row selection, in stable selected ordering. It is a direct streamed download with 500-row eager-loaded batches, UTF-8 BOM, semicolon delimiter and formula-cell neutralization; no Livewire base64/full-memory download. A concurrent writer can change records during a long export: no point-in-time snapshot guarantee.
- Service filter, clear-filters action, status labels, newest/A–Z/Z–A sorting, 25/50/100 page size and page correction after removal are exposed. Selection actions preserve the current filter scope and have a 500-selected-row mutation limit. Selected rows can be activated, excluded, removed or restored. Existing assignment/import jobs remain queued. New bounded status CRUD and rename are synchronous; export is a streamed read, not provider or AI work.

Verification: source inspection only. Per operator instruction no clone, tests, dependency install, formatter, build, browser or server execution. Migration, runtime, CSV opening and UI acceptance are unverified; no DONE claim.


## Query exclusion list — 2026-09-08

Operator approved the proposed exclusion-list workflow, then requested continuation. Scope remains direct staging branch `chatgpt/search-demand-foundation`; no main, PR or server deployment.

- Sorgular embeds a collapsible Exclusion list with bulk expression entry (1–2,000 lines per save), normalized deduplication, search, edit, enable/disable and deletion. Matching is whole folded word/phrase, case/Turkish-ASCII tolerant, never substring or fuzzy typo guessing. Deleting a rule never restores previously removed queries.
- Existing cleanup is a durable queued preview over all nondeleted Library queries through the start-time maximum ID, independent of current list filters. It checks canonical text and retained original source texts, showing the actual matching text/expression. All matches are paginated and initially selected; operators may uncheck individual rows or select/deselect the full preview before approval.
- Only the creator can approve their ready run. Run-row locking serializes approval and preview selection changes. Current rule fingerprint must match the preview; changed rules require a new preview. Apply processes only approved selected rows, rechecks item version, matching text and exceptions, and soft-removes with the existing model. Changed/protected/deleted rows are skipped, never silently replaced by unseen candidates. No Brand portfolio or provider fact deletion follows Library removal.
- Scan/apply execute in 100-item queued chunks with durable cursor/results/counts, overlap protection, terminal failure history and no automatic restart after partial failure. History resumes from the Sorgular panel after navigation/reload. It is a dedicated Library maintenance history, not a new provider collection or Finding/Run engine.
- New writes through SearchQueryLibraryService consult active exclusions before location stripping, preserving the existing store return contract through a specific validation exception. The main paste/Excel/CSV/stored Ads/GSC LibraryImportWorkflow catches it as a separately counted excluded row, storing every raw row and matched rule snapshot for paginated inspection. These are neither accepted queries nor generic errors/duplicates. Older callers of the shared writer also respect screening and receive the explicit validation reason.
- Individual and bulk restore expose an enabled-by-default protection checkbox. Protection stores a canonical query exception used by both preview/apply and future imports. Exceptions can be removed from their own list; removing protection does not immediately delete the query. Import raw text is checked first; only exemption identity resolution uses the location-free canonical text.
- Rule changes during processing are checked between rows; execution is not an all-or-nothing global transaction. Completed removal receipts persist and removed queries remain recoverable. Preview membership is a snapshot; new/changed data can be covered by a new scan.

Verification truth: code inspected only; operator forbade cloning and tests. No tests, formatter, build, browser or staging DB/queue execution ran. New migration, worker runtime and human acceptance remain unverified. No DONE/UAT claim.

## Manual service query clusters — 2026-09-09

Explicit operator decision supersedes the Library menu's AI-first clustering flow: a global service is the main cluster, and operators create one level of child page groups without creating another service. This scope is the authorized four-step manual roadmap on staging; no provider or AI calls are part of clustering.

- The canonical query-service pivot now carries a nullable child-cluster reference, review timestamp and monotonically increasing placement revision. No query text or service relation is copied. Null means main cluster. Existing assignments initially appear as unreviewed/New. Imports that reuse a service association leave its placement/review state intact; newly attached service associations enter the main cluster.
- The root Library route opens the bilingual manual workspace. The existing Brand AI cluster records and downstream SERP/ownership/competitor consumers remain intact; their previous workspace is retained at /library/search-demand-clusters/legacy. Global manual IDs are never passed to Brand cluster consumers. Bridging manual groups into future competitor/HTML work is a later explicitly scoped step, not implemented here.
- The workspace provides sector and service/child search, a collapsible service tree, direct/total counts, paginated active queries, contains/does-not-contain phrases (any/all), dates, New and include-children filters, page/all-filtered selection, inline central-query editing and move/new-destination dialogs. The filter set is shared with snapshot exports. A child may be created or renamed with same-service name uniqueness and an optimistic revision guard.
- Bulk moves, keep-in-main, child merge/removal, undo and CSV exports use durable operations. One INSERT SELECT captures selected membership IDs, text and placement versions in the confirmation request; no full ID array is hydrated in Livewire. Mutation and export work then runs in 250-row queued steps with receipts and counters, plus Activity and paginated history. The SQL snapshot itself is synchronous; no load benchmark or 100k latency claim has been made.
- Operations serialize per service; a failed operation can resume from pending receipts or explicitly close while preserving completed changes. Request UUIDs prevent duplicate submissions. An all-filtered selection is independent of pagination. A changed confirmation count aborts and asks for a fresh selection. Subsequent imports do not enter an already captured operation.
- Undo checks membership identity and placement revision; newer changes, removed assignments and unavailable destinations are skipped. It does not undo the central query text. Retired children retain targets and names in receipts; their target URL snapshots remain visible in history. Undo can reactivate a removed child unless its name was reused. Merge/removal includes inactive/deleted-query associations so restoring a query cannot strand it in a removed child.
- Website-specific target URLs are explicit page plans, separate from technically verified legacy ownership. They are editable per service + child-or-root + Website, restricted to that Website's configured exact host, and never trigger fetches or provider writes. Clearing a URL retains a revision tombstone; targets are not silently copied during merge.
- CSV files are prepared on private local storage in bounded chunks, with UTF-8 BOM, semicolon delimiters, formula neutralization and authenticated download. Filtered export covers all matching active queries; structure export covers the selected service and children, ignores query filters and includes empty children. Export query rows retain confirmation-time text/group names. CSV order is stable membership ID order. Temporary/export files and operation receipts currently have no automatic retention cleanup.

Verification: source review only. Operator explicitly forbids cloning, tests, dependency installation, formatting/build and PR/main actions. No PHP/Blade compilation, migrations, queue execution, browser UAT or server deployment ran. Code is reviewable on staging, not a DONE or verified 100k throughput claim. Existing import-batch caps remain unchanged; this workspace handles the accumulated library.


## Automatic account collection and query imports — 2026-09-09

Operator explicitly approved the reviewed automatic collection/import design on `chatgpt/search-demand-foundation`. No main, PR, merge, cloning, dependency installation, tests, build, or direct server deployment is authorized in this task.

- New resource-level settings govern discovered Google Ads client accounts, GSC properties and GA4 properties using their existing central smart collectors. Default collection is daily with deterministic account staggering across the day; operators can pause, choose every three days, or request the next scheduler tick. Manager/MCC containers are displayed but never collected as client accounts. Discovery here enumerates already stored external resources; it does not rerun provider discovery automatically.
- Meta Ads and GBP reuse their existing confirmed-binding collectors, scoped to the exact external resource/binding. Unbound resources explicitly show that binding is required. This feature does not create fake Brands, Assets or bindings, and does not add a resource-first Meta/GBP collector. Website crawl/WordPress schedules and paid DataForSEO refreshes are not enabled by this change.
- Laravel scheduler runs `moxdop:resources:automate` every minute, performing bounded database planning. Provider work remains in queued jobs and canonical CollectionRun/DatasetRun workers. At most two automatic account collections/planners and four active automatic query batches are admitted by default; pre-existing active collections consume available account slots. Existing worker/provider quota limits remain authoritative. Manual central smart starts and automatic starts share resource locks and reject active resource runs. Existing GA4 daily restatement schedule is replaced by this cadence to avoid a second automatic driver; its manual command remains available.
- Existing initial policies remain authoritative: GSC 486 days, GA4 configured initial window, Google Ads activity-aware historical periods. Successful dataset-family coverage is used for catch-up after long gaps; recent restatement windows remain provider-specific. GA4/GSC repair plans retain saved checkpoints. GSC optional search-type probes use the incremental window after initial discovery. Recent resource-run object hydration is bounded; coverage lookup still uses successful historical dataset records.
- Collection and query-import state are separate. Only completed query dataset runs whose exact resource is completed/partial are eligible for Library ingestion; unfinished datasets never advance the Library receipt. Dataset receipts, rather than a largest dataset ID, allow an older slow dataset to complete later without being lost. First mapping covers successfully collected existing data. Fact rows are read by `(external_resource_id, last_dataset_run_id, id)` in 100-row chunks with supporting indexes and a captured upper ID. No 10k paste/file cap applies to accumulated automatic imports.
- Matching dictionaries are reused within each bounded worker chunk. A persisted rule fingerprint avoids repeating unchanged service matching for already observed queries; manual unblock invalidates that fingerprint.
- Account mappings require a valid sector and optionally selected active services in that sector. Empty service selection uses all active services of the sector. Matching uses the shared whole-expression service dictionary; multiple matches attach multiple services, unmatched queries stay sector-scoped. Source-specific metrics remain in provider facts and are not summed/copied as Library performance.
- Every automatic batch snapshots sector, eligible service IDs and mapping revision. Cadence-only settings changes do not change mapping revision. Settings changes do not rewrite previously observed queries or an in-flight batch; interrupted old-scope imports can resume with valid original scope or explicitly close while retaining completed changes. A confirmed queued recheck reevaluates this account's existing active unassigned queries in the selected sector; it never reallocates existing service/cluster memberships or changes other sectors.
- Existing location stripping, active exclusion rules, exact canonical deduplication and soft-delete protection apply. Per-account original query observations retain first/last reporting dates, decision and canonical query ID. Repeated provider rows do not duplicate canonical queries or source observations. Raw rows excluded by current rules remain inspectable, and changing a rule does not silently delete existing queries without the existing preview/approval workflow.
- A new alias map remembers manually renamed query identities, including bounded migration of historical rename receipts, so old provider spellings resolve to the renamed query rather than recreating it. Manual query deletion remains sticky. Service chips now allow explicit assignment removal with a persistent query/service block; all shared keyword matching respects that block. Explicit manual assignment or Allow automatic matching clears the relevant block. Existing child-cluster placement/revisions are not rewritten by imports.
- Bilingual collapsible account controls appear in Google/Meta Integrations and Queries. All discovered Ads/GSC resources are also available in the manual import selector even before facts exist. Controls include cadence, enable/disable, sector/services, next run, last collection/import success, last successful batch data date, recent counts, account observation history, recheck, resume and close-interrupted. Times on this control are explicitly UTC; historical provider facts retain their own reporting clocks. Account lists paginate 20, observations 25, recent import history 10; full LibraryImport and CollectionRun history remains in Activity.
- Transient collection attempts use existing provider retries plus bounded account retry (30 minutes, then 3 hours; repeated failures require attention). Revoked access/cancellation do not cause retry storms. Completed-data imports can resume without provider recollection. Required-attention failures reuse OperationalAlert lifecycle and configured in-app recipients; successful routine refreshes do not send new notifications. Default recipient/preference policies remain unchanged.
- Source-row counters are not unique-query totals: a query can appear on multiple reporting dates. Concurrent restatement may replace a fact's last dataset owner; that row is accounted for by the later completed dataset, not imported from an unfinished one. Canonical source provenance is retained; no full fact-table snapshot or atomic cross-provider view is claimed.

Verification truth: source inspection only. No tests, lint/Pint, PHP/Blade compilation, migration execution, queue execution, browser UAT, 100k benchmark, or server deployment ran, as explicitly instructed. Deployment must apply the additive migration and keep the existing scheduler and queue/collection workers active. Snapshot/observation/receipt retention uses existing storage policy; no automated purge is introduced. This is code prepared for staging, not a DONE/live-UAT claim.


## Connector activity and standards extension — 2026-09-09

Operator-approved sequence: connector → integration ingestion/UX → deterministic standards.
The existing pairing and read endpoints remain compatible; downloadable connector version is 1.1.0.
No clone, dependency install, tests, formatter, build, browser or host execution was performed.
Source implementation is not deployment, runtime acceptance, or DONE.

Implemented:
- WordPress local non-autoloaded outbox table, 50-event signed POST batches through WP-Cron every
  five minutes, signed per-ID acknowledgements, replay protection, retry/backoff and 10,000-event cap.
  Save hooks never perform HTTP. Events in one request coalesce by type/object; separate editor
  requests remain separate audit records. Missing queue writes/overflow report a persistent gap.
- Content publish/update/status/delete, allowlisted SEO/business metadata, selected settings,
  theme/plugin maintenance and role-change events. Acting WP user ID/display name are included;
  no passwords, form entries, arbitrary metadata values, visitor clicks or binary media.
  Public content types are inventoried; internal submission/order stores are excluded.
- Safe cached/runtime health, delayed cron count, module presence, debug/registration policies,
  cache flags, adapter versions and allowlisted branch fields extend existing CMS metadata.
- Additive receiving tables; connection/installation-bound HMAC, timestamp/nonce verification,
  credential recheck under connection lock, deduplication and transactional acknowledgement.
- Website Integration Activity tab: period/type/actor filters, 25-row pagination, delivery age,
  pending count and coverage-gap warning. Global Activity merges the same stored events.
- Scheduler admits at most two event reconciliation collections, at most 50 events/changed object
  IDs per incremental scope, and defers while the asset has an active collection. Successful
  completion advances the receipt watermark; failed/partial collections do not. The existing
  CMS snapshot writer retains untouched objects on incremental refresh and removes only scoped
  missing objects. The connector must echo the exact object scope before writes are accepted.
- Daily CMS inventory reconciliation and global-settings changes use full CMS inventory; ordinary
  content changes use scoped content/media/SEO reads. Site/extensions/taxonomies are still full
  lightweight snapshots. Relevant known public URLs use existing bounded targeted crawl (max 100).
  No daily all-page browser run, paid provider or AI call is introduced.
- Standards categories: Website, Google Ads, Meta Ads. Ads categories are placeholders with explicit
  empty-state copy; their criteria are not shipped. Website definitions distinguish general vs
  WordPress inheritance. Existing expert criteria are retained but inactive; their add form is
  removed. Length heuristics default inactive. Historical evidence is preserved.
- 25 additional deterministic/advisory checks cover duplicate/multiple metadata, H1/language,
  internal target status, canonical/hreflang target status, content fingerprint matches, image
  attributes, mixed resources, WordPress debug/registration/visibility/cron/update/health policies
  and delivery freshness. Existing 500-profile assessment bound remains visible. Missing target
  HTTP, stale evidence, unsupported connector fields and truncated scans do not become passes.
  Inventory, HTML and cached Site Health observations remain distinct.

Not implemented in this delivery:
- Remote SEOPress/LiteSpeed write controls, installations, automatic plugin/theme/core updates,
  image optimization or rollback. management_enabled=false; no advertised executable action.
- Complete malware scans, backup/SMTP provider adapters, visitors/session recording, historical
  activity reconstruction, all-page browser checks or automatic whole-site standards after each event.
- Full planned SEO catalogue (e.g. complete sitemap membership, reciprocal hreflang, orphan/depth
  graph, GSC URL Inspection/cluster comparison) is not yet implemented by the new checks.
- Continuous CMS delta cursor independent of events; daily inventory is the recovery mechanism.
  Low traffic or disabled WP-Cron needs host scheduling. A coverage gap cannot reconstruct history.

Deployment: normal staging script installs additive Laravel tables and restarts workers.
Then download connector 1.1.0 from the existing connector screen and replace the installed plugin.
Existing pairing remains; WordPress init creates the local outbox. First successful heartbeat
enables reconciliation. Scheduler and collection workers must run. Verify live pairing, event
redelivery, scoped deletion, filtered history, and standards before operational acceptance.

## 2026-09-10 — Website collection integration controls

Operator requested the integration collection update after Connector 1.1.0. The Website screen
now selects collection scope explicitly; General excludes optional PageSpeed, which is separate.
Automatic WordPress event refresh continues between configurable daily/three-day inventory runs,
with pause/resume that preserves event intake and active work. Source status is independent of the
last overall run; last automatic inventory is distinguished from manual collection. Reconciliation
only acknowledges its actual 50-event batch even during a full inventory, preserving later URL
verification. Shared admission locks reduce overlapping snapshot writers. Additive settings migration
and existing scheduler/workers required. No clone/tests/build/formatter/deploy or live acceptance
performed by operator instruction; runtime/UAT remains unverified. Details in the capability ledger
and docs/product/website/WORDPRESS.md.

## 2026-09-10 — Standards management and evidence integration

After Website collection controls, operator requested standards implementation. Website standards
now expose a paginated category workspace and administrator enabled/severity/default controls.
Catalogue has 52 visible deterministic/advisory checks, including 8 additions; expert criteria stay
archived and Ads categories remain empty. New assessments expose per-standard result details,
including missing evidence, and use completed raw TLS/robots collection observations. WP inventory
freshness allows the configured three-day cadence; freshness affects result reuse. Definitions
and observations distinguish expiry, cache/setup declarations and HTML-only language/indexing
checks from actual security/indexing/performance guarantees. Existing proposal approval remains
human controlled. No clone/tests/build/formatter/deployment run; runtime/UAT unverified. Full
contract and remaining scope: docs/product/website/WEBSITE_STANDARDS_ASSESSMENT.md and ledger.
