# Search Demand Intelligence

## Status

**PHASES 1–12 CODE COMPLETE / TEST AND OPERATOR UAT NOT RUN**

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
- A service may be archived but is not normally deleted.
- An operator may create a missing service while editing a Brand.

### Brand Offering

`brand_offerings` remains the canonical Brand-scoped Offering identity. It may reference a global `service_catalog_item_id`.

This is intentionally a link, not a replacement:

- Global Service = reusable agency vocabulary.
- Brand Offering = that specific Brand's supplied service and priority order.

Existing unlinked Brand Offerings remain valid for compatibility.

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
- the semantic input contains only explicitly approved Phase 11 analyses. Pending, rejected and abstained analysis records are not eligible;
- trusted application code separately proposes deterministic missing-title, missing-H1, missing-meta-description, zero-observed-internal-link, wrong-URL-candidate and cannibalization-candidate checks. The AI is forbidden from duplicating those checks;
- AI output is a review-only semantic proposal with severity, one bounded action type, Recommendation draft, content brief, stable analysis/observation/competitor references, evidence explanation, confidence, rationale, verification steps and abstention;
- allowed actions are: improve the existing page, create a new service page, create a blog/guide, create FAQ coverage, merge, adjust internal links, take no action, or mark evidence insufficient;
- exact input + Agent + Skill + AI-route fingerprints reuse a completed run. Execution uses the canonical queued Run/Activity flow;
- no proposal is a canonical Finding. Explicit operator acceptance first publishes one canonical derived Evidence record, attaches it to a Finding evaluation, creates or reconfirms the canonical Finding, and then creates a Finding-sourced Recommendation through the existing writer;
- deterministic and AI provenance stay explicit. The approved Evidence payload retains Agent/Skill/route signatures, evidence references, confidence, rationale, content brief, verification steps and approving operator;
- rejected proposals change only their review state. Abstained or `insufficient_evidence` proposals cannot be promoted;
- approval never creates a Task. The existing manual Recommendation → Task action remains the only handoff into execution.

Phase 12 does not browse, collect new evidence, change URL ownership, publish content, mutate a website or perform any external write. Result/change measurement remains Phase 13.

The Phase 12 screen is `/library/search-demand-improvements`; accepted Recommendations continue through the canonical `/recommendations` operator screen.

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
- simplified Brand create/edit form for services, priorities and multiple service areas
- Brand Business Context summary for service-area visibility

## Deferred

- Phase 13 change/result measurement after manual Task execution

## Safety

- Unknown metrics remain missing, never numeric zero.
- Marked branded/licensed queries can be excluded.
- Imported data is read-only inside MoxDOP.
- No Google, CMS, Ads or provider write is introduced.
- No provider request is triggered by creating a Brand or importing a file.
