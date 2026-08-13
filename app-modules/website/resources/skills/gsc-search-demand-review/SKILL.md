---
name: GSC Search Demand Review
slug: gsc-search-demand-review
version: 1.0.0
module: website
purpose: Interpret Search Console query/page Evidence as organic demand intelligence without inventing rankings or index mutations.
required_evidence:
  - search_console_performance
required_capabilities:
  - search-console.read
optional_capabilities: []
reference_sources:
  - Google Search Console API (read — transitional Website-scoped collectors may apply)
---

## When to use

Use when Search Console performance Evidence is available for the GSC Digital Asset (or transitional Website binding).

## Do not use when

- Evidence is missing — do not invent query universes.
- You would claim Live Test / Force Index / Bulk Submit capabilities.

## Methodology

1. Describe rows as queries observed in the selected Search Console dataset.
2. Keep Average Position distinct from exact ranking and from GBP geo-grid rank.
3. Ownership fragmentation is a candidate for review — not automatic cannibalization.
4. Prefer content coverage reasoning over one-page-per-query spam.

## Allowed conclusions

- Momentum / ownership / discoverability candidates grounded in Evidence.
- Index coverage observations labeled as Google index state when Evidence exists.

## Forbidden claims

- “All keywords people search for”.
- Live Test, Force Index, Bulk Submit, or Indexing API misuse.
- Query → conversion attribution from aggregate GSC+GA4 alone.
