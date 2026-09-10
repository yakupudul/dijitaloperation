# Website standards and improvement assessment

Product scope authorized on 2026-09-05. Implementation branch: `chatgpt/search-demand-foundation`; main and PRs are out of scope. This extends Search Demand phases 1–13 and does not replace the Integrations collection workflow or existing Library identities.

## Phase 14 — Website Standards Library

- Versioned, source-linked Website standards reuse the 17 diagnosis catalogue identities. Technical observations, heuristics and expert review criteria are explicitly distinct.
- Every definition records group, applicability, required evidence, evaluation method, interpretation, improvement action and verification guidance.
- Operators can inspect definitions. Administrators enable/disable standards and add bounded expert review criteria; arbitrary executable rules are not accepted from the UI.
- A disabled definition is omitted from subsequent assessments, without deleting historical evidence. Definition snapshots/fingerprints accompany every run.
- Missing evidence is unknown. A criterion may be not applicable. Character counts, absent optional markup and an AI opinion are never declared ranking requirements.

## Phase 15 — Independent Website assessment

- The Website asset gets a Standards & Improvements workspace. A single queued action evaluates already stored public observations and page profiles, with no provider or AI call.
- Evaluation uses the canonical Run and existing improvement proposal/review pipeline. No parallel Finding, Recommendation or Result entity is introduced.
- Technical assessment does not require queries, an owner URL or competitors. Same-standard issues are grouped with exact affected pages and evidence references.
- Service coverage joins active Brand offerings, Website-active query memberships, existing clusters, owner decisions and bounded candidate page matches. An unlinked service or an unreviewed match is visible as a knowledge gap, not a proven missing page.
- Semantic relevance and technical readiness remain separate. A relevant but blocked page remains a repair/review candidate; failing the technical gate alone cannot justify creating another page. Human owner locks remain authoritative.
- Technical priority is explained in tiers: verified target accessibility/indexability blockers, other verified defects, then advisory improvements; affected page count breaks ties. Coverage decisions are a separate list ordered by repair need and service priority. No ROI or opaque SEO score is invented.
- Page/cluster limits and missing/stale observations remain visible. Rendering does not collect data or start AI work. The operator refreshes source data through Integrations.

## Phase 16 — Shared criteria for content and competitors

- Website Improvement can evaluate a verified owner and stored HTML against applicable expert criteria without competitors. This is an explicit queued AI action with bounded text and exact input/definition/route reuse.
- Approved competitor analyses optionally deepen that evaluation. Pending/rejected/abstained analyses remain excluded.
- Competitive Intelligence receives the same criterion definitions. It must distinguish comparable intent/page types and retain criterion-level own-page and competitor evidence; an incomparable page cannot create a coverage obligation.
- Semantic proposals require an enabled criterion ID and grounded evidence. Unknown/unsupported output cannot be promoted. No automatic publication, paid SERP refresh, Task creation or ownership change.

## Acceptance and deployment

Verify catalogue integrity, applicability and missing-data behavior; blocked relevant-page handling; same-Website/same-Brand scope; independent technical execution without AI/competitors; unchanged-input reuse and changed-definition invalidation; approved-only promotion; comparable competitor evidence; authenticated operator routes and views. Use isolated PHPUnit databases only.

Keep code/test/UAT truth in `PRODUCT_CAPABILITY_LEDGER.md`. A successful local test does not claim live provider or operator UAT. Deploy through the existing `deploy/staging/deploy.sh` after checking out the published exact commit. No server deploy is performed by this task.

## Implementation notes and remaining acceptance

- New additive migration: `2026_09_05_100000_add_website_standards_assessment`; no catalogue seeding command is required. Base definitions ship as Website module JSON; settings are overlays.
- Read limits are 500 profiles, 3,000 active queries, 100 clusters, 20 candidates/cluster, 5 MB/HTML and 16,000 own-page text characters. Stored facts older than 30 days become unknown; an own-page AI review requires readable HTML within 30 days. Expert criteria are explicitly pending semantic review in the technical matrix.
- Approval checks current standard snapshots and page/source/owner context. Exact source and freshness state reuse prior technical results; new HTML invalidates reuse even before projection rebuild. Identical HTML recollection does not force another semantic model call.
- New own-page semantic actions are improve_existing/internal_linking/no_action/insufficient_evidence. New pages/merges require separate inventory-backed human scope. Existing Library records and legacy action history are retained.
- Standalone technical proposals do not enter the cluster-specific Phase 13 outcome flow. Reassess refreshed data to verify the criterion, and use existing human Finding/Task controls; no automatic closure is claimed.
- Search Demand's seven existing Skill definitions now declare finite workflow-context keys and complete abstention sections. Older Skill test expectations were updated to include the already-present Search Demand root and total of 30 shipped definitions.
- Local checks use isolated in-memory SQLite and a temporary PHP 8.4 runtime. The local binary lacks intl; international domain transliteration and the staging PostgreSQL migration need operator verification. No application dependency lock changes were made.
- Operator acceptance after deployment: evaluate one existing Website; check issue evidence/ordering and service coverage; open the same cluster's owner/content/rival screens; approve a supported proposal; refresh source data and re-evaluate. Exercise one configured model call for content quality. These are acceptance steps, not a claim that live UAT passed.

## Local verification — 2026-09-06

- The seven targeted PHPUnit files passed: 63 tests, 851 assertions. Coverage includes standards evaluation/catalogue integrity, assessment execution and approval guards, Website workspace/head diagnosis, and all 30 shipped Skill definitions. AI-dependent scenarios use fake model responses.
- All 39 changed/new PHP files passed syntax checks; Laravel Pint completed. The production frontend build and Blade compilation passed, and the authenticated standards route was verified.
- Existing Website workspace test copy was brought into line with the current screen text; assertions for retired labels were not product defects. No production data or application dependency locks were changed.
- This is targeted local verification, not a full-suite run, staging deployment, PostgreSQL migration acceptance or live-model/operator UAT. The migration also widens existing improvement/competitor route signatures to accommodate configured multi-provider routes.

## Operator revision — 2026-09-09

The current standards catalogue is deterministic only. Expert criteria remain archived and
disabled, even if an older settings overlay enabled them; no new AI request is introduced.
Website includes inherited general and WordPress checks. Google Ads and Meta Ads currently show
explicit empty category states, not implemented audit claims. Added 25 executable checks;
planned sitemap graph, full hreflang reciprocity, GSC inspection and managed repairs are still
outside this source delivery. Existing assessment bounds, human proposal promotion and
unknown-vs-failure semantics remain. Source inspection only; tests and runtime not run per user.

## Standards workspace revision — 2026-09-10

Operator requested standards after the Connector and Website integration updates.

- Deterministic catalogue now has 52 visible definitions (37 general, 15 WordPress); 7 expert
  criteria remain archived. Eight additions cover HTML canonical-target noindex, hreflang
  self/return links, WP HTTPS home setting, plain permalinks, cache declaration, outbox setup
  declaration and post-gap inventory recovery. Two existing length heuristics remain disabled
  by default. Existing enabled/disabled overlays are retained; no catalogue seed is required.
- Website / Google Ads / Meta Ads navigation, populated category counts, platform/status/search
  filters and 20-row pagination replace the unbounded card list. Ads categories remain explicitly
  empty. Active administrators can change enabled state and low/medium/high severity, and restore
  a definition's shipped defaults. Existing settings JSON stores only validated severity overrides;
  arbitrary executable checks cannot be submitted. Historical runs remain unchanged.
- Current completed raw TLS and robots.txt collection rows feed site-level assessment, preferring
  newer evidence. The TLS check is explicitly certificate expiry only, not trust-chain/host
  verification. HTTP-to-HTTPS and sitemap still require their existing diagnosis evidence; missing
  observations stay unknown. No fresh network request or paid data is started by assessment.
- Hreflang inspection reports truncation and resolves against the HTML base URL. Return-link
  checks require fresh full target HTML and observed successful HTTP; external/uncollected targets
  are unknown. This is HTML-only coverage, not sitemap or HTTP-header hreflang validation.
  Canonical noindex covers HTML robots/googlebot directives, not X-Robots-Tag or actual indexing.
- WP inventory freshness is four days, supporting the three-day inventory option; update-cache
  freshness remains two days and delivery review remains 30 minutes. Invalid/future timestamps
  and absent extension inventory cannot become passes. WP freshness state participates in cached
  result identity. Missing stored HTML contributes to incomplete coverage.
- Cache declaration is a review signal, not measured speed or cache-hit proof. Outbox setup checks
  the connector's declared installation marker, not a database write probe. Gap recovery cannot
  reconstruct lost audit events. No automatic site mutations or plugin installs are implemented.
- Assessment rows open 25-row result details with state filters, URLs, reasons and observed values,
  including unknown/pass/not-applicable outcomes. Site checks are stored explicitly on new runs;
  old reports without these details request reassessment. Proposed actions for site checks use
  site-level wording. Saved severity applies to new proposals, with verified target blockers still
  forced high. Existing human approval/Findings/Recommendations pipeline remains in place.
- Existing 500-profile, 5 MB HTML and scoped evidence bounds remain. No all-page scale claim.
  Broader sitemap graph/orphan analysis, full PHP support/vulnerability feeds, GSC URL Inspection,
  browser rendering, remote speed repairs and Ads standards are outside this change.

Verification: direct GitHub source inspection only. No clone, tests, formatter, build, browser
acceptance or server execution performed, per operator instruction. No new migration required
beyond the already committed integration migrations. Live rendering, stored-fact compatibility,
queue execution and real operator acceptance remain unverified.
