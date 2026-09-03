---
name: Search Demand Clustering
slug: search-demand-clustering
version: 1.0.0
module: search_demand
purpose: Propose layered Brand query clusters and bounded maintenance actions while preserving human locks, provenance, and uncertainty.
definition_status: active
required_evidence:
  - key: brand_query_portfolio
    kind: operator_records
    role: PRIMARY_FACT
    purpose: Active Brand Query Portfolio records with stable IDs and approved semantic context
    missing_behavior: ABSTAIN
    integrity_required: true
optional_evidence:
  - key: existing_clusters
    kind: operator_records
    role: CONTINUITY_CONTEXT
    purpose: Current clusters, members, versions, locks, and validation states
    missing_behavior: CONTINUE
    integrity_required: true
  - key: serp_validation
    kind: provider_observation
    role: OPTIONAL_VALIDATION
    purpose: Later SERP evidence that may support or contradict an AI-predicted grouping
    missing_behavior: CONTINUE
    integrity_required: true
required_capabilities: []
optional_capabilities: []
allowed_conclusions:
  - Demand-family proposal
  - Candidate SERP-intent grouping
  - Candidate content-target cluster
  - Representative-query and content-type proposal
  - Query move, cluster merge, or cluster split proposal for unlocked clusters
forbidden_claims:
  - SERP validation when no SERP evidence is supplied
  - Automatic mutation of a locked cluster
  - Automatic approval, URL ownership, Finding, Task, content publication, or external write
  - Invented volume, rank, traffic, conversion, or trend metrics
abstention_rules:
  - "REQUIRED_EVIDENCE_MISSING: Abstain when no active portfolio queries are supplied."
  - "LOCKED_CLUSTER: Never propose a mutation that requires changing a locked cluster."
  - "AMBIGUOUS_SEMANTICS: Mark the candidate uncertain when a defensible grouping is not clear."
success_signals:
  - Demand family, SERP intent, and content target remain three distinct layers
  - Incremental runs process only currently unclustered queries
  - Every move, merge, or split is a reviewable proposal with stable source IDs
failure_signals:
  - Keyword-overlap-only grouping
  - Mutation of locked clusters
  - Claiming SERP confirmation without observed SERP data
watch_metrics: []
reference_sources:
  - "docs/product/SEARCH_DEMAND_INTELLIGENCE.md"
research_provenance:
  - "search-demand-roadmap-phase-5"
downstream_domains:
  - HUMAN_REVIEW_ONLY
methodology_steps:
  - key: preserve-locks
    type: ABSTAIN_GATE
    purpose: Treat locked clusters and their memberships as immutable constraints
    inputs: [existing_clusters]
    validation: No proposed action mutates a locked cluster
    abstain_when: A reasonable proposal requires changing a locked cluster
  - key: separate-layers
    type: SYNTHESIZE
    purpose: Distinguish demand family, expected SERP intent, and content target
    inputs: [brand_query_portfolio, existing_clusters]
    validation: All three labels have distinct meanings and a rationale
    abstain_when: Query semantics are too ambiguous
  - key: choose-representative
    type: CLASSIFY
    purpose: Select one supplied portfolio item that best represents each proposed cluster
    inputs: [brand_query_portfolio]
    validation: Representative ID is a member ID
    abstain_when: No member is representative
  - key: propose-maintenance
    type: SYNTHESIZE
    purpose: Suggest bounded assign, move, merge, split, or metadata update actions
    inputs: [brand_query_portfolio, existing_clusters, serp_validation]
    validation: Every referenced ID exists in the supplied context
    abstain_when: Action would mutate a lock or lacks evidence
---

## When to use

Use for incremental grouping of new Brand portfolio queries or an explicit operator-requested review of existing unlocked clusters.

## Do not use when

- There are no active Brand portfolio queries.
- The requested change would mutate a locked cluster.
- The output would be applied without human review.
- SERP confirmation is expected but no SERP observation is supplied.

## Methodology

1. Preserve stable portfolio-item and cluster IDs.
2. Treat locked clusters as immutable.
3. Interpret query meaning, user problem, and likely result-page intent; do not rely on token overlap alone.
4. Keep demand family, SERP intent group, and content target cluster as separate layers.
5. Select a representative member and a plausible content type.
6. In incremental mode, act only on unclustered input items.
7. In review mode, return bounded move, merge, split, or metadata proposals for unlocked clusters.
8. Mark weak groupings uncertain and explain why.

## Rules

- AI output is an unapproved proposal.
- No SERP evidence means `ai_prediction`, never `serp_validated`.
- Never invent performance metrics.
- Never change a locked cluster.
- Human approval and version history are mandatory.

## Output contract

Structured proposals with action type, stable cluster/item IDs, three-layer classification, representative query, suggested content type, confidence, uncertainty, rationale, and abstention.
