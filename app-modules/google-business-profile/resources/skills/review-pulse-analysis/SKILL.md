---
name: Review Pulse Analysis
slug: review-pulse-analysis
version: 1.1.0
module: google-business-profile
definition_status: active
purpose: Summarize GBP review topics and response queue candidates without auto-sending replies.
required_evidence:
  - key: gbp_reviews
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: Official GBP review Evidence for topic pulse and advisory response candidates.
    missing_behavior: ABSTAIN
    integrity_required: true
    completeness_required: false
    expands_conclusions: false
optional_evidence: []
required_capabilities:
  - google-business-profile.read
optional_capabilities: []
downstream_domains:
  - ANALYSIS_ONLY
  - RECOMMENDATION_CANDIDATE
abstention_rules:
  - Abstain when gbp_reviews Evidence is unavailable — do not invent sentiment scores as truth.
  - Abstain from fabricating review text or customer identities.
  - Abstain from auto-sending, scheduling, or publishing review replies.
research_provenance:
  - existing-canonical-pre-prompt-48
reference_sources:
  - Official Google Business Profile review resources (read)
---

## When to use

Use when review Evidence is present for the GBP Digital Asset.

## Do not use when

- Review Evidence is unavailable — do not invent sentiment scores as truth.
- You would auto-send or publish replies via MoxDOP.
- You would use non-official scraped review sources as truth.

## Methodology

1. Group reviews by topic signals present in Evidence.
2. Draft response candidates only as advisory text.
3. Never auto-send or schedule external replies.
4. Use official GBP review data only — do not invent or scrape alternate review corpora.

## Allowed conclusions

- Response queue candidates with provenance.
- Topic pulse grounded in observed reviews.

## Forbidden claims

- Fabricated review text or fabricated customer identities.
- Autonomous public replies, publishes, or write-backs to GBP.
- Ranking guarantees tied to review volume or rating.
- Magic reputation/sentiment scores presented as canonical MoxDOP metrics.
- Task creation or Recommendation auto-approval.

## Abstention

- Abstain when review Evidence is missing or failed.
- Prefer Unavailable over invented sentiment or fabricated reviews.

## Output contract

Observation, why it matters, recommended action (advisory response candidate), Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

## Success signals

- Topic pulse and response candidates cite official GBP review Evidence IDs only.
- Guidance remains advisory — no auto-send, publish, or provider write.
