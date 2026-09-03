---
name: Search Query Generation
slug: search-query-generation
version: 1.0.0
module: search_demand
purpose: Generate reusable search-query candidates for a canonical service and bounded market context without inventing demand metrics.
definition_status: active
required_evidence:
  - key: canonical_service
    kind: catalog_record
    role: PRIMARY_FACT
    purpose: Human-maintained canonical service and approved aliases
    missing_behavior: ABSTAIN
    integrity_required: true
optional_evidence:
  - key: market_context
    kind: operator_context
    role: OPTIONAL_CONTEXT
    purpose: Language, market, sector, and location supplied by the operator
    missing_behavior: CONTINUE
    integrity_required: false
  - key: existing_queries
    kind: catalog_records
    role: DEDUPLICATION_CONTEXT
    purpose: Existing library examples used to reduce duplicate suggestions
    missing_behavior: CONTINUE
    integrity_required: true
required_capabilities: []
optional_capabilities: []
allowed_conclusions:
  - Candidate query text grounded in the selected service
  - Candidate demand family, intent, user problem, and decision stage
  - Candidate location template using the literal {location} token
  - Conservative brand or licensed-expression suspicion
  - Concise service alias suggestion
forbidden_claims:
  - Search volume or demand magnitude without observed provider evidence
  - Ranking, SERP, traffic, conversion, or trend claims
  - Automatic approval or publication
  - Guaranteed commercial outcomes
abstention_rules:
  - "REQUIRED_EVIDENCE_MISSING: Abstain when the canonical service is missing or ambiguous."
  - "AMBIGUOUS_MARKET: Avoid market-specific claims when market context is absent."
success_signals:
  - Candidates cover materially different user problems instead of superficial word permutations
  - Location variants use one reusable pattern instead of a Cartesian list
  - Every candidate is clearly marked as an unobserved AI proposal
failure_signals:
  - Invented metrics
  - Near-duplicate keyword stuffing
  - Brand or licensed terms presented as generic demand
watch_metrics: []
reference_sources:
  - "docs/product/SEARCH_DEMAND_INTELLIGENCE.md"
research_provenance:
  - "search-demand-roadmap-phase-3"
downstream_domains:
  - HUMAN_REVIEW_ONLY
methodology_steps:
  - key: validate-service
    type: ABSTAIN_GATE
    purpose: Require one canonical service with a clear meaning
    inputs: [canonical_service]
    validation: Service exists and is active
    abstain_when: Service is missing or ambiguous
  - key: map-user-problems
    type: SYNTHESIZE
    purpose: Identify distinct problems and decision stages relevant to the service
    inputs: [canonical_service, market_context]
    validation: Each problem is plausibly related to the service
    abstain_when: Relationship cannot be supported
  - key: form-candidates
    type: SYNTHESIZE
    purpose: Express demand as concise query candidates and reusable location patterns
    inputs: [canonical_service, market_context, existing_queries]
    validation: No invented observations or metrics
    abstain_when: Candidate is a duplicate or unsupported brand phrase
---

## When to use

When an operator selects a canonical service and explicitly asks AI to propose queries for later human review.

## Do not use when

- No canonical service is selected.
- The request is for volume, ranking, or SERP facts without provider evidence.
- The output would be applied without human review.

## Methodology

1. Start from the meaning of the canonical service and approved aliases.
2. Identify distinct user problems, intents, and decision stages.
3. Produce concise candidates; avoid superficial permutations.
4. Use `{location}` for reusable local templates instead of expanding every place.
5. Mark brand/licensed expressions conservatively and explain uncertainty.

## Rules

- Generated queries are candidates, never observed demand facts.
- Never attach fabricated metrics.
- Prefer semantic coverage over query count.
- Keep location patterns reusable.
- Human approval is mandatory.

## Output contract

Structured candidate rows with query text, optional service alias, demand family, search intent, user problem, decision stage, candidate SERP group, candidate content cluster, location pattern, branded suspicion, confidence, rationale, and abstention.
