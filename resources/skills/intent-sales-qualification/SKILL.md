---
name: Intent Sales Qualification
slug: intent-sales-qualification
version: 1.0.0
module: sales
purpose: Classify whether observed public search text indicates purchase intent for canonical agency services without inventing identity or outreach.
definition_status: active
required_evidence:
  - key: observed_snippet
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: Search-result snippet or observed public text
    missing_behavior: ABSTAIN
    integrity_required: true
optional_evidence:
  - key: fetched_source_excerpt
    kind: evidence_type
    role: OPTIONAL_ENRICHMENT
    purpose: Safely fetched public source excerpt used to verify the snippet
    missing_behavior: CONTINUE
    integrity_required: true
required_capabilities: []
optional_capabilities: []
allowed_conclusions:
  - Purchase-intent classification grounded in observed text
  - ServiceDefinition mapping when catalog match is supported
  - Identity unknown when company is not evidenced
forbidden_claims:
  - Invented source content
  - Outreach or conversion actions
  - Identification of anonymous people without evidence
  - Guaranteed revenue outcomes
abstention_rules:
  - "REQUIRED_EVIDENCE_MISSING: Abstain when no observed snippet is present."
  - "AI_UNAVAILABLE: Return unavailable classification when no eligible AI provider exists."
success_signals:
  - Informational queries are not scored as high purchase intent
  - Unknown identity remains unknown
failure_signals:
  - Fabricated company names
  - Fake confidence scores when AI is unavailable
watch_metrics: []
reference_sources:
  - "SALES_ASSISTANT_V1_IMPLEMENTATION_B.md"
research_provenance:
  - "sales-assistant-batch-b"
downstream_domains:
  - SALES_ADVISORY_ONLY
methodology_steps:
  - key: require-observed-text
    type: ABSTAIN_GATE
    purpose: Require an observed snippet before classification
    inputs: [observed_snippet]
    validation: Snippet present
    abstain_when: Missing snippet
  - key: classify-purchase-intent
    type: CLASSIFY
    purpose: Distinguish high purchase intent from informational queries
    inputs: [observed_snippet, fetched_source_excerpt]
    validation: Stage is high_intent, informational, or unknown
    abstain_when: Text is empty
  - key: map-catalog-service
    type: SYNTHESIZE
    purpose: Map likely requested service to canonical ServiceDefinition codes
    inputs: [service_catalog]
    validation: Codes exist in catalog or remain unmapped
    abstain_when: No eligible catalog match
---

## When to use

When classifying a public Intent Signal for agency purchase intent using a search snippet and optional fetched source excerpt.

## Do not use when

- No observed snippet is available.
- The request asks to invent a company identity.
- The request asks to generate outreach or convert a Prospect.
- No eligible AI provider exists (return unavailable instead of fabricating scores).

## Methodology

1. Read the observed snippet. Treat search snippets as unverified until a source fetch exists.
2. Classify purchase intent vs informational questions.
3. Map to a canonical ServiceDefinition code when supported.
4. Leave identity unknown unless the text explicitly names a company.

## Rules

- Never invent source content.
- Never identify anonymous people without evidence.
- Never generate outreach or convert Prospects.
- Do not fake confidence when AI is unavailable.

## Allowed conclusions

- Purchase-intent classification grounded in observed text.
- ServiceDefinition mapping when a catalog match is supported.
- Identity unknown when company is not evidenced.

## Forbidden claims

- Invented source content.
- Outreach or conversion actions.
- Guaranteed revenue outcomes.

## Abstention

- `REQUIRED_EVIDENCE_MISSING`: Abstain when no observed snippet is present.
- `AI_UNAVAILABLE`: Return unavailable classification when no eligible AI provider exists.

## Dependencies

- Observed search snippet.
- Canonical ServiceDefinition catalog.

## Output contract

Structured classification: purchase_stage, intent_confidence, service_definition_code, reason, negative_signals, identity_status, identity_confidence.

## Success signals

- Informational queries are not scored as high purchase intent.
- Unknown identity remains unknown.

## Failure signals

- Fabricated company names.
- Fake confidence scores when AI is unavailable.

## References

- SALES_ASSISTANT_V1_IMPLEMENTATION_B.md

## Research provenance

- sales-assistant-batch-b
