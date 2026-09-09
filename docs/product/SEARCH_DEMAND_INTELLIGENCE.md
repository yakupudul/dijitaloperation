# Search Demand Intelligence

## Status

**Staging branch: phases 1–13 present; Website standards phases 14–16 implemented with targeted automated validation. Real operator/provider UAT is not run.**

Current scope and limits: `website/WEBSITE_STANDARDS_ASSESSMENT.md` and ADR-060. Capability states below apply to `chatgpt/search-demand-foundation`; this is not a claim about main.

This document defines the shared commercial context and reusable Search Query Library that later Website search-demand, SERP, content-ownership and competitor analysis will consume.

## Product purpose

MoxDOP must know four operator-owned facts before it can interpret Website, GSC, GA4, Google Ads or DataForSEO data:

1. Customer
2. Brand
3. Services offered by the Brand
4. Countries, cities and districts served by the Brand

The system must then retain the agency's reusable search-query knowledge without paying a provider for the same discovery on every Brand.

## Canonical boundaries

### Global Service Catalog

`service_catalog_items` and `service_catalog_names` are agency-wide reusable service identities.

- One service has one stable ID.
- Primary names and aliases are separate name claims.
- A service may be archived or reversibly deleted from the global Services screen (2026-09-07 operator decision).
- An operator may create a missing service while editing a Brand.

### Brand Offering

`brand_offerings` remains the canonical Brand-scoped Offering identity. It may reference a global `service_catalog_item_id`.

This is intentionally a link, not a replacement:

- Global Service = reusable agency vocabulary.
- Brand Offering = that specific Brand's supplied service and priority order.

Legacy unlinked Brand Offerings are attached by the global Services migration; collisions abort without silently merging brand relationships.

### Brand Service Area

`brand_service_areas` stores explicit Brand scope at country, city and optional district grain. Multiple rows are allowed. Missing city means country scope; missing district means city scope.

Service areas update the compatible Brand `primary_country` / `target_markets` projection and the structured Brand Intelligence Context. They do not silently create service × area analysis jobs.

### Search Query Library

`search_query_library_items` is the reusable normalized query identity. `search_query_library_source_records` retains every source observation and its available metrics.

Supported operator inputs:

- one manual query
- newline-separated pasted queries
- CSV / TSV / TXT
- XLSX first worksheet
- source classification for Google Ads, Search Console and DataForSEO exports

Recognized optional import dimensions include service, sector, language, market, demand family, country/city/district, period, impressions, clicks, conversions, cost, search volume, CPC and competition.

Provider facts and imported metrics are not collapsed into one synthetic value. Every source record remains attributable.

## Relationship to Intelligence Core

The two concepts are deliberately distinct:

| Concept | Scope | Purpose |
| --- | --- | --- |
| Search Query Library | Agency-wide | Reusable operator research and imported observations |
| `IntelligenceSearchTermIdentity` | Brand-scoped | Canonical identity for provider observations joined inside Intelligence Core |

When a library query is applied to a Brand, the Brand Query Portfolio resolves it into the existing Brand-scoped Intelligence Search Term identity. No second Website adapter or generic metrics warehouse is introduced.

## Brand Query Portfolio

`brand_query_portfolio_items` is the Brand-scoped application layer:

- a global query is referenced by `search_query_library_item_id`; its text is not copied;
- a Brand-only query keeps its own normalized identity and may be submitted for global human review without automatic promotion;
- Brand text, demand-family, market/language, location and branded-state overrides are explicit and do not mutate the global item;
- applicable Brand services remain relations;
- the default area scope means “all active Brand areas”; an operator may select a subset;
- `{location}` variants are rendered on demand and are never persisted as a Service × Area Cartesian set;
- each applied query resolves to the canonical Brand-scoped `IntelligenceSearchTermIdentity`;
- website activation/exclusion is a separate relation, so global, Brand and Website scope remain distinguishable.

## No Cartesian explosion

Ten services and ten locations must not automatically create 100 permanent commercial scopes or 100 pages.

Later Brand Query Portfolio work will:

- select relevant library queries by Brand services and markets;
- keep applicable services and areas as relations;
- render provider request variants only when required;
- let SERP evidence and human review decide whether multiple locations need separate content.

## AI Search Demand Librarian

AI will be used for bounded classification where human language understanding is material:

- demand family
- search intent
- user problem
- decision stage
- candidate SERP intent group
- candidate content target cluster
- branded/licensed-name suspicion

AI output is a candidate with confidence, rationale, abstention and version provenance. It never overwrites operator facts, invents volume/rankings, creates Findings, publishes content or opens Tasks automatically.

The Phase 3 runtime adds:

- the code-defined `Search Intelligence Analyst` profile;
- separate query-generation and query-classification Skills;
- a queued `search_demand.librarian` AI route using the central agency Integration credential chain;
- structured candidates for service alias, demand family, search intent, user problem, decision stage, location pattern, candidate SERP group, candidate content cluster and branded/licensed suspicion;
- persistent run/candidate records with agent, Skill, model, route and input fingerprints;
- exact-fingerprint reuse so an identical completed request does not call the provider again;
- operator bulk approve/edit/reject controls inside `/library/search-queries`.

Generated and classified output remains a proposal. Approval is the only path that applies semantic fields or a service alias. Rejection preserves the proposal and review provenance. Abstained candidates cannot be bulk-approved.

AI work is queued and returns control to the operator. OpenAI requests retain the platform `store=false` policy. The agent has no browsing, tool, provider-spend, Finding, Recommendation, Task, CMS or other external-write capability.

SERP evidence will validate content-target grouping later. Semantic similarity alone is not URL ownership proof.

## AI Search Demand Clustering

Phase 5 adds a Brand-scoped, versioned clustering layer on top of the Brand Query Portfolio:

- demand family, expected SERP intent group and content target cluster remain separate fields;
- every cluster has a representative portfolio query, suggested content type, rationale, confidence and validation state;
- incremental runs read only active portfolio queries without a current cluster membership;
- review runs may propose metadata changes, query moves, merges or splits;
- all AI actions persist as pending candidates and require explicit operator approval;
- a locked cluster and its membership cannot be changed until an operator unlocks it;
- every create, lock, move, merge and split records a version snapshot with stable member IDs;
- manual move, merge and split controls use the same lock and version boundaries as AI-approved actions.

Until observed SERP evidence exists, approved clusters remain `ai_prediction`. The reserved later states are `serp_validated`, `serp_conflict` and `review_required`; Phase 5 does not infer any of these from semantic similarity. Cluster confidence is classification confidence, not a ranking or performance metric.

Exact AI reuse is keyed by Brand input, current cluster state, Agent version, Skill signature/fingerprint and resolved provider/model route. Cluster proposals cannot create Findings, Recommendations, Tasks, content, redirects or external writes.

## Query–URL Visibility Map

Phase 6 joins only existing canonical and measured layers; it does not create another metrics warehouse:

- the Query Library connects through website-active Brand Query Portfolio items and their canonical Brand search-term identities;
- GSC `gsc_query_page_daily` supplies measured query–URL pairs, clicks, impressions, CTR and impression-weighted average position for an explicit period;
- existing Website Page identities and Page Profiles supply preferred URL, observed HTTP status, robots and HTML coverage;
- GA4 `ga4_landing_page_daily` supplies page-grain sessions and engaged sessions, explicitly labelled as landing-page behavior rather than query attribution;
- a separate comparison period produces absolute deltas only when both period values exist;
- query, cluster, service, area, text and observed/unobserved filters are available;
- the operator can inspect query and resolved-URL details plus cluster summaries.

An active Website query with no GSC query–URL row in the requested period is `unobserved`; it is not assigned a zero. Missing source bindings, missing periods, unresolved Page identities and absent GA4 values remain explicit coverage/unknown states. GSC provider row limits still apply, and average position is not presented as a rank tracker.

## DataForSEO / SERP enrichment

Phase 7 adds an explicit paid, queued observation workflow behind a provider-neutral `SearchDemandSerpEnrichmentAdapter` contract:

- the operator selects one Website plus a service or cluster scope; the run is capped at 20 website-active portfolio queries and never materializes a Service × Area Cartesian set;
- Website SEO location and language are mandatory, device is explicit and organic depth is exactly 10 or 20;
- DataForSEO Google Organic Live Regular observations persist the first organic URLs, ranks, titles, descriptions, result feature types, provider task ID, request fingerprint and retrieval time;
- current Brand-domain rank and URL are derived only from the observed organic result set; absence remains unknown/not observed rather than rank zero;
- Google Ads Search Volume Live persists provider-estimated search volume, CPC, competition and monthly trend separately from measured GSC and GA4 facts;
- optional Keyword Ideas expansion is a third explicit paid request. Returned terms are review candidates; approve adds/activates a Brand Portfolio query, reject preserves provenance, and neither path assigns a cluster automatically;
- each uncached SERP query uses one Live SERP POST, as required by the provider; exact result-affecting fingerprints, a configurable freshness window and per-query paid-request locks reuse fresh observations and prevent concurrent identical paid POSTs;
- deployment-configured price rates may provide a pre-run USD estimate. Missing rates display unknown; only provider-reported response cost is persisted as reported cost;
- a durable paid-attempt marker is committed before each POST. If response/fact commit cannot be proven, the run closes `CHARGE_UNKNOWN`; queue jobs have one attempt and do not automatically retry;
- raw request/response payloads are retained per run without credentials.

Observed pairwise exact-URL Jaccard overlap over the first ten organic results creates a `serp_validated`, `serp_conflict` or `review_required` recommendation. Thresholds and method are persisted with the recommendation. Only operator approval changes the cluster validation state; no membership, URL owner, content, Finding, Recommendation or Task is changed automatically.

The Phase 7 screen is `/library/search-demand-enrichment`. Creating a Brand, importing queries, opening the page or rendering a plan cannot trigger a provider call.

## URL ownership and Page Relevance

Phase 8 adds one human-governed URL ownership decision per Website and content-target cluster:

- candidate pages come from the existing Website Page Projection and are bounded to 20 records per review;
- first-party GSC query–page observations, point-in-time Brand SERP URLs, the current human owner and deterministic title/H1/slug term matches are retained as separate candidate sources;
- the technical gate requires the same Website, a public page observation, successful 2xx HTTP, no observed `noindex`, no canonical to another URL, an observed matching language and an allowed content URL type;
- attachment/system URLs and pagination are ineligible. Archive/category pages are allowed only when the cluster explicitly targets that content type;
- missing technical evidence produces `unknown`, never a silent pass. Only `eligible` pages can be proposed or human-verified as owner;
- two-period GSC leader changes or visibility split below the configured dominance threshold produce a cannibalization **review candidate**, not a proven diagnosis;
- a current GSC leader different from a verified target produces a wrong-URL candidate. The system does not decide whether Google or the prior target decision is wrong;
- the queued Page Relevance Skill compares only supplied eligible pages with cluster/query context and may propose at most one owner, abstain, or suggest improve/new service page/blog/FAQ/merge review;
- AI output remains a proposal. Human approval rechecks the live technical gate, records the owner and evidence snapshot, and may lock it. Locked ownership cannot be changed until a human unlocks it;
- ownership and lock changes append immutable versions. No redirect, deletion, merge, page creation, Finding, Recommendation, Task, provider spend or external write follows automatically.

The Phase 8 screen is `/library/search-demand-ownership`. The Phase 6 Visibility Map reads the verified target/status without copying GSC, SERP or Website facts into the ownership record beyond the decision-time evidence snapshot.

## Competitor Library and discovery

Phase 9 adds one Brand-scoped competitor identity per normalized domain and keeps candidate state separate from competitor classification:

- a competitor is `pending`, `approved` or `rejected`; rejected candidates retain their source provenance;
- commercial, SERP and content-competitor roles are independent booleans because the same domain can hold more than one role;
- business, directory, platform and authority-site kinds are separate from those roles. `unknown` remains valid until a human classifies the entity;
- a DataForSEO SERP/domain observation establishes only SERP competition candidacy. It never establishes that the domain sells the same service in the same area, and its observed SERP role cannot be erased while that source remains linked;
- the importer reads existing `search_demand_serp_*` and `dataforseo_competitor_domain_snapshot` facts only. It never starts a provider request and is bounded to 100 distinct domains per operator action;
- source rows retain Website, provider record, query, rank, market/language/device and observation time where available;
- competitor URLs and appeared-on queries remain explicit relations. Service, Brand Service Area and content-target cluster relations are also many-to-many rather than a permanent Cartesian expansion;
- manual creation is an explicit human approval and may record roles, entity kind, URLs and scope relations immediately;
- pending candidates support individual or bulk approval/rejection. Roles, entity kind and scope relations remain operator-editable after review;
- domain normalization lowercases the host and treats `www` as the same domain. Other subdomains remain distinct identities;
- existing Brand Context `known_competitors` remains broad operator business context and is not silently overwritten or treated as this evidence-linked library.

The Phase 9 screen is `/library/search-demand-competitors`. Phase 9 does not crawl competitor pages, invoke AI, classify page intent, create Findings/Recommendations/Tasks or write externally; those remain later phases.

## Competitor page collection

Phase 10 collects a bounded set of exact URLs from approved competitors linked to one content-target cluster:

- URL selection is deterministic, hash-deduplicated and limited to three URLs per competitor and twenty URLs per run;
- stored SERP provenance and best observed rank influence selection order, but do not turn the collection into a ranking or commercial-competitor claim;
- collection runs asynchronously through the canonical Activity/Run flow and reuses the Public Discovery HTTP fetcher, including public-IP checks on every redirect, timeouts, response limits and read-only requests;
- only selected URLs are requested. Extracted internal and external links are retained as page structure but are never followed, so Phase 10 cannot become a whole-site crawl;
- HTML is normalized into visible text, title, meta description, H1/H2–H6 headings, JSON-LD schema summary, bounded internal/external links and deterministic service/location expression matches;
- each observation records HTTP context, raw HTML hash, normalized-content fingerprint and observation time. Exact raw repeats skip parsing; semantically unchanged normalized content reuses the prior content observation rather than duplicating fields;
- every fetch attempt appends page history, including failed and unchanged observations. A redirect outside the approved competitor domain fails closed;
- Phase 10 performs no AI analysis, page-intent classification, semantic comparison, Finding, Recommendation, Task, provider spend or external write.

The Phase 10 screen is `/library/search-demand-competitor-pages`; its stored observations are the competitor-side evidence for Phase 11.

## Competitive Intelligence

Phase 11 adds a dedicated `Competitive Intelligence Analyst`, `competitive-page-analysis` Skill and `search_demand.competitive_intelligence` AI route:

- each run is scoped to one Website and one active content-target cluster;
- a human-verified Phase 8 URL owner and its checksum-verified stored Website HTML are required;
- only successful Phase 10 observations from approved, cluster-linked competitors are eligible, deduplicated by competitor URL and bounded to the eight newest pages;
- Brand text is bounded to 16,000 characters and competitor text to 12,000 characters per page, while headings, schema types, link counts and observed service/location expressions remain explicit evidence;
- page content and query text are untrusted data and cannot supply instructions to the agent;
- output proposes competitor kind/roles, page intent, topics, subtopics, user questions, content structure, local trust signals, missing user needs, unnecessary sections, do-not-copy cautions and Brand-specific differentiation ideas;
- missing coverage is described as unanswered user needs or questions, never as a word-count contest;
- each conclusion includes concise evidence explanation, confidence and abstention. Unknown or contradictory evidence stays uncertain;
- exact input + Agent + Skill + AI-route fingerprints reuse a completed run rather than spending again;
- execution uses the canonical queued Run/Activity flow and persists a separate analysis run plus one review record per competitor observation;
- operator acceptance/rejection changes only the analysis review state. It does not mutate competitor kind/roles, URL ownership or any other canonical fact.

Phase 11 never browses, fetches new competitor content, creates a Finding, Recommendation or Task, copies competitor prose, changes a page or writes externally. Phase 12 owns the Finding/Recommendation interpretation layer.

The Phase 11 screen is `/library/search-demand-competitive-intelligence`.

## Finding and Recommendation planning

Phase 12 adds a dedicated `Website Improvement Analyst`, `website-improvement-planning` Skill and `search_demand.website_improvement` AI route:

- each queued run is scoped to one Website, one active content-target cluster and its human-verified URL owner;
- semantic input requires stored verified own-page content and enabled Website criteria. Approved comparable Phase 11 analysis is optional; pending/rejected/abstained or obsolete criterion context is excluded;
- the independent Website standards assessment handles technical title/head/link checks without AI. Selected-page planning retains separately labelled wrong-URL/cannibalization signals; the AI must not duplicate deterministic checks;
- AI output is a review-only semantic proposal with severity, one bounded action type, Recommendation draft, content brief, stable analysis/observation/competitor references, evidence explanation, confidence, rationale, verification steps and abstention;
- legacy action records remain readable. The new own-page contract allows improve_existing, internal_linking, no_action and insufficient_evidence. FAQ coverage may be added to the existing page. Creating or merging pages requires an independent inventory-backed human scope decision; one excerpt cannot justify it;
- exact input + Agent + Skill + AI-route fingerprints reuse a completed run. Execution uses the canonical queued Run/Activity flow;
- no proposal is a canonical Finding. Explicit operator acceptance first publishes one canonical derived Evidence record, attaches it to a Finding evaluation, creates or reconfirms the canonical Finding, and then creates a Finding-sourced Recommendation through the existing writer;
- deterministic and AI provenance stay explicit. The approved Evidence payload retains Agent/Skill/route signatures, evidence references, confidence, rationale, content brief, verification steps and approving operator;
- rejected proposals change only their review state. Abstained, no_action and insufficient_evidence proposals cannot be promoted. Approval checks current criteria and source context; changed evidence requires another review;
- approval never creates a Task. The existing manual Recommendation → Task action remains the only handoff into execution.

Phase 12 does not browse, collect new evidence, change URL ownership, publish content, mutate a website or perform any external write. Result/change measurement remains Phase 13.

The Phase 12 screen is `/library/search-demand-improvements`; accepted Recommendations continue through the canonical `/recommendations` operator screen.

## Change and Outcome tracking

Phase 13 closes the Search Demand execution loop without introducing a separate Result entity:

- an implementation record can be opened only for a completed Task that came from a human-approved, cluster-scoped Phase 12 proposal; standalone Website standards proposals are rechecked through another standards assessment;
- the record keeps the implementation summary, affected Website URLs, affected query-cluster IDs, application/review dates and the latest stored pre-change HTML fingerprint per URL;
- a separate operator action starts a read-only Public Crawl for the exact affected URLs plus bounded matching Website page-family members, capped at 100 URLs. It does not invoke DataForSEO, publish content or follow this feature into a whole-site crawl;
- after the targeted CollectionRun is complete or partial, a queued verification captures collection-linked post-change HTML fingerprints and rechecks missing title, H1, meta description and observed internal-link conditions deterministically;
- wrong-URL, cannibalization and semantic findings remain non-deterministic. The Website Change Verification Analyst compares only checksum-valid stored before/after observations and proposes whether content changed, the intended change is observable, and the original condition appears resolved, still observed or unclear;
- GSC query–page and GA4 landing-page facts are compared across explicit pre/post periods for affected URLs and the affected cluster. Stored SERP snapshots before/after application are compared separately; Phase 13 never triggers paid SERP collection;
- missing source binding, absent rows or unmatched periods remain `insufficient_data`, not zero. If `review_after_at` has not arrived, the overall proposal is `too_early` even though technical components can already be inspected;
- the exact before/after evidence, Agent, Skill and AI route fingerprint reuses an equivalent run. AI output is review-only and cannot mutate Task or Finding truth;
- operator acceptance writes the selected signal to the existing Task `outcome_*` fields. A resolved/still-observed Finding evaluation is appended only after this human gate. Rejection changes no Task Outcome or Finding lifecycle state;
- supported Phase 13 Task Outcome signals are `technically_fixed`, `content_change_verified`, `visibility_increased`, `visibility_decreased`, `no_change_observed`, `too_early` and `insufficient_data`;
- visibility movement is explicitly observational (`causal_attribution: false`) and is never proof that the recorded implementation caused the movement.

The Phase 13 screen is `/library/search-demand-changes`.

## Independent Website standards (phases 14–16)

`/library/website-standards` is the versioned criterion library. Website assets expose `tab=standards` for queued stored-data evaluation, grouped issues and service/query/owner coverage. Technical review does not require a cluster, competitor or AI. Existing query and competitor Library screens remain and receive direct links with the selected Website and cluster preserved. See `website/WEBSITE_STANDARDS_ASSESSMENT.md` for scope and limits.

Phase 11 also records comparable intent/page type and criterion-level own/rival excerpts. Unsupported comparisons cannot create coverage obligations or be promoted.

## Operator surfaces

- `/library/services`
- `/library/search-queries`
- `/library/brand-query-portfolios`
- `/library/search-demand-clusters`
- `/library/search-demand-visibility`
- `/library/search-demand-enrichment`
- `/library/search-demand-ownership`
- `/library/search-demand-competitors`
- `/library/search-demand-competitor-pages`
- `/library/search-demand-competitive-intelligence`
- `/library/search-demand-improvements`
- `/library/search-demand-changes`
- simplified Brand create/edit form for services, priorities and multiple service areas
- Brand Business Context summary for service-area visibility

## Deferred

- causal experiments, automatic CMS publication, automatic redirects/deletions and automatic paid SERP refreshes

## Safety

- Unknown metrics remain missing, never numeric zero.
- Marked branded/licensed queries can be excluded.
- Imported data is read-only inside MoxDOP.
- No Google, CMS, Ads or provider write is introduced.
- No provider request is triggered by creating a Brand or importing a file.

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
