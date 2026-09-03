---
name: Search Query Classification
slug: search-query-classification
version: 1.0.0
module: search_demand
purpose: Propose semantic classifications for observed or curated query records while preserving source identity and human authority.
definition_status: active
required_evidence:
  - key: source_queries
    kind: catalog_records
    role: PRIMARY_FACT
    purpose: Existing Search Query Library records selected by the operator
    missing_behavior: ABSTAIN
    integrity_required: true
optional_evidence:
  - key: canonical_service
    kind: catalog_record
    role: OPTIONAL_CONTEXT
    purpose: Existing canonical service relationship and aliases
    missing_behavior: CONTINUE
    integrity_required: true
required_capabilities: []
optional_capabilities: []
allowed_conclusions:
  - Demand family and search-intent proposal
  - User problem and decision-stage proposal
  - Candidate SERP intent group and content target cluster
  - Location pattern and conservative branded-expression suspicion
  - Concise service alias proposal when a real synonym is evident
forbidden_claims:
  - Search volume, traffic, conversion, trend, rank, or SERP claims without observed evidence
  - Automatic query mutation, approval, exclusion, or publication
  - A branded-expression flag presented as certain legal analysis
abstention_rules:
  - "REQUIRED_EVIDENCE_MISSING: Abstain when no source query is supplied."
  - "SEMANTIC_AMBIGUITY: Abstain per row when its meaning cannot be classified responsibly."
success_signals:
  - Every output preserves the supplied source_item_id
  - Ambiguous terms remain pending with low confidence or abstention
  - Classification fields remain distinct instead of collapsing into one label
failure_signals:
  - Missing or invented source_item_id values
  - Fabricated performance evidence
  - Silent changes to approved records
watch_metrics: []
reference_sources:
  - "docs/product/SEARCH_DEMAND_INTELLIGENCE.md"
research_provenance:
  - "search-demand-roadmap-phase-3"
downstream_domains:
  - HUMAN_REVIEW_ONLY
methodology_steps:
  - key: preserve-source
    type: ABSTAIN_GATE
    purpose: Preserve one output for every selected source record
    inputs: [source_queries]
    validation: source_item_id belongs to the supplied set
    abstain_when: Source identity is absent
  - key: classify-semantics
    type: CLASSIFY
    purpose: Separate demand family, intent, problem, stage, SERP group, and content cluster
    inputs: [source_queries, canonical_service]
    validation: Classification is supported by query text
    abstain_when: Meaning is ambiguous
  - key: flag-patterns
    type: CLASSIFY
    purpose: Detect reusable location patterns and possible branded expressions
    inputs: [source_queries]
    validation: Flags are conservative and explained
    abstain_when: Evidence is too weak
---

## When to use

When an operator selects existing Search Query Library records and requests semantic classification proposals.

## Do not use when

- No source query is selected.
- The request expects AI to overwrite approved records automatically.
- Performance or SERP facts are requested without evidence.

## Methodology

1. Preserve each source record identity.
2. Interpret only the supplied query text and catalog context.
3. Keep demand family, intent, user problem, decision stage, SERP group, and content cluster separate.
4. Flag ambiguous or possibly branded expressions conservatively.
5. Return proposals for human review; do not mutate the library.

## Rules

- Source identity must be preserved.
- Missing evidence is not zero and uncertainty is not confidence.
- Do not invent performance or SERP observations.
- Human approval is mandatory before applying any field.

## Output contract

Structured proposal rows keyed by source_item_id with semantic fields, optional alias and location pattern, branded suspicion, confidence, rationale, and abstention.
