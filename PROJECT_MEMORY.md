# PROJECT_MEMORY

> **Canonical persistent product / architecture memory for MoxDOP.**  
> Inspected against `origin/main` @ `171e5e7` (2026-08-11).  
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

**Current implemented capability on main: LIMITED public Website Discovery.**

It can obtain:

- bounded public website / context signals
- optional supported competitor **candidates** (when DataForSEO is configured)
- Brand Context **candidates** for human Accept / Edit / Ignore

It is **NOT** yet:

- full web intelligence
- social intelligence
- Facebook / Instagram public intelligence
- YouTube intelligence
- review monitoring
- news / mention monitoring
- continuous web monitoring

**Never** describe current Website Discovery as “all digital web discovery.”

Canonical product doc: `docs/product/DISCOVERY_INTELLIGENCE.md`.

---

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

Canonical decisions: ADR-048, ADR-049, ADR-050, ADR-051, ADR-052, ADR-053, ADR-054 and ADR-055. Canonical product contract: `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`.

---

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
