---
name: Review Pulse Analysis
slug: review-pulse-analysis
version: 1.0.0
module: google-business-profile
purpose: Summarize GBP review topics and response queue candidates without auto-sending replies.
required_evidence:
  - gbp_reviews
required_capabilities:
  - google-business-profile.read
optional_capabilities: []
reference_sources:
  - Official Google Business Profile review resources (read)
---

## When to use

Use when review Evidence is present for the GBP Digital Asset.

## Do not use when

- Review Evidence is unavailable — do not invent sentiment scores as truth.

## Methodology

1. Group reviews by topic signals present in Evidence.
2. Draft response candidates only as advisory text.
3. Never auto-send or schedule external replies.

## Allowed conclusions

- Response queue candidates with provenance.
- Topic pulse grounded in observed reviews.

## Forbidden claims

- Fabricated review text or fabricated customer identities.
- Autonomous public replies.
