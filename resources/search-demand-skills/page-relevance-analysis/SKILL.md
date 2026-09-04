---
name: Page Relevance Analysis
slug: page-relevance-analysis
version: 1.0.0
module: search_demand
purpose: Compare technically eligible Website pages with one human-governed content-target cluster and propose a page owner or abstention without changing ownership.
definition_status: active
required_evidence:
  - key: search_demand_cluster
    kind: operator_records
    role: PRIMARY_CONTEXT
    purpose: One active cluster, stable member queries, and current lock/validation state
    missing_behavior: ABSTAIN
    integrity_required: true
  - key: website_page_candidates
    kind: projection_records
    role: PRIMARY_FACT
    purpose: Bounded Website Page profiles with technical gate results
    missing_behavior: ABSTAIN
    integrity_required: true
optional_evidence:
  - key: gsc_query_page_observations
    kind: provider_observation
    role: OPTIONAL_VALIDATION
    purpose: Period-bounded first-party query-page visibility
    missing_behavior: CONTINUE
    integrity_required: true
  - key: serp_brand_url_observations
    kind: provider_observation
    role: OPTIONAL_VALIDATION
    purpose: Point-in-time observed Brand URLs for cluster queries
    missing_behavior: CONTINUE
    integrity_required: true
required_capabilities: []
optional_capabilities: []
allowed_conclusions:
  - Semantic-fit interpretation for technically eligible candidate pages
  - At most one proposed existing page owner for human review
  - Wrong-URL, multiple-URL, or cannibalization review candidacy grounded in supplied observations
  - Improve-existing, new-service-page, blog, FAQ, or merge-review content-type suggestion
forbidden_claims:
  - Automatic URL ownership, redirect, delete, merge, content creation, publication, Finding, Recommendation, or Task
  - A technically ineligible or unknown page recommended as owner
  - GSC average position presented as exact rank or absence presented as zero
  - SERP observation presented as permanent ownership truth
  - Multiple URLs presented as proven cannibalization without review
abstention_rules:
  - "REQUIRED_EVIDENCE_MISSING: Abstain when the cluster or page candidate set is missing."
  - "TECHNICAL_GATE_FAILED: Abstain when no candidate passes the complete technical eligibility gate."
  - "CONFLICTING_EVIDENCE: Abstain when page intent or observed leaders conflict materially."
success_signals:
  - Recommendation references only supplied eligible page_profile_ids
  - Rationale distinguishes GSC, SERP, technical, and semantic evidence
  - Weak evidence remains review_required or no_suitable_url
failure_signals:
  - Invented metrics or page content
  - Ownership changed without operator review
  - Redirect, deletion, or page creation applied automatically
watch_metrics: []
reference_sources:
  - "docs/product/SEARCH_DEMAND_INTELLIGENCE.md"
research_provenance:
  - "search-demand-roadmap-phase-8"
downstream_domains:
  - HUMAN_REVIEW_ONLY
methodology_steps:
  - key: enforce-technical-gate
    type: ABSTAIN_GATE
    purpose: Exclude pages that are off-site, unsuccessful, non-indexable, canonicalized elsewhere, wrong-language, media/system, or disallowed pagination
    inputs: [website_page_candidates]
    validation: Only technical_eligibility=eligible may be recommended
    abstain_when: No eligible page remains
  - key: separate-observation-sources
    type: CHECK
    purpose: Keep GSC period observations and SERP snapshots separate from semantic interpretation
    inputs: [gsc_query_page_observations, serp_brand_url_observations]
    validation: No source is promoted into automatic ownership truth
    abstain_when: Sources conflict without a defensible page-purpose decision
  - key: compare-page-purpose
    type: CLASSIFY
    purpose: Compare eligible page purpose with the cluster demand family, SERP intent, content target, and queries
    inputs: [search_demand_cluster, website_page_candidates]
    validation: Every semantic conclusion cites supplied page and query context
    abstain_when: Available page text is insufficient
  - key: propose-not-apply
    type: SYNTHESIZE
    purpose: Propose one owner or an explicit review/no-page state plus content-type guidance
    inputs: [search_demand_cluster, website_page_candidates]
    validation: Human approval remains the only apply path
    abstain_when: Evidence cannot distinguish a suitable owner
---

## When to use

Use only when an operator requests an ownership review for one active Search Demand cluster and one Website.

## Methodology

1. Enforce the deterministic technical gate before semantic comparison.
2. Treat GSC and SERP rows as observations with their own time and coverage, not as intended ownership.
3. Compare only the supplied title, H1, URL, language, content summary, cluster labels, and member queries.
4. Recommend at most one eligible existing page or abstain explicitly.
5. Keep wrong-URL and cannibalization outputs as review candidates.
6. Return a content-type suggestion without creating or changing any page.

## Rules

- Existing locked human ownership is authoritative.
- Missing is unknown, never zero.
- AI never changes ownership or creates operational records beyond its review proposal.
- Redirect, delete, merge, content, Finding, Recommendation, Task, and external writes are forbidden.

## Output contract

One structured decision proposal, zero or one recommended eligible page_profile_id, per-page semantic fit and rationale, explicit abstention, candidate flags, and a review-only content-type suggestion.
