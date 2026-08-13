# Google Search Console

## Purpose

Organic Demand & Search Intelligence for a Brand’s Search Console property: what demand Google exposes, which Website content captures it, where visibility is changing, what Google index-state evidence exists for important URLs, and what the agency should investigate next.

## User value

Operators answer organic-search operating questions without manually stitching Search Console + Website + GA4 + Ads + GBP spreadsheets every time.

## Core concepts (ADR-043)

* **Digital Asset:** Google Search Console property (canonical type `gsc`)
* **Relationship:** *observes organic search performance for* Website
* **Connection:** Google Integration / OAuth → Search Console property
* **Capability:** Organic Demand Intelligence

GSC as Asset and GSC as Evidence provider are not mutually exclusive.

## MVP / Demo behavior

Workspace IA:

1. Overview
2. Search Performance
3. Queries & Demand (Topic Clusters · Query Explorer · Momentum · Ownership)
4. Pages
5. Indexing (Coverage · URL Inspection · Sitemaps · Reconciliation)
6. Relationships
7. Operations (Findings → Recommendations → Tasks → Outcomes)

Differentiators: topic clustering, Search Intent Ownership, Search Discoverability Funnel, Search Momentum, Demand → Content Coverage, cross-channel search intelligence.

Demo Mode uses deterministic daily fixtures + shared Demo period filters. Custom ranges recalculate aggregates. Indexing may use snapshot semantics without forcing a period filter.

## Important data / attributes

Property identity (Domain / URL-prefix), clicks/impressions/CTR/average position, observed queries, topic clusters, page directory, Google index state, sitemap evidence, Findings chain.

## Relationships

```text
Search Console (Asset)
  └── observes → Website

Provides Evidence to:
  Website Content Intelligence · Google Ads · GBP · GA4 (page context)
Connection: Google Search Console API (Connected)
```

Sibling Brand Assets — no ownership under Website.

## Main screens / workflows

Brand Digital Estate → open Search Console Asset; global Digital Assets directory; cross-links from Website / Ads / GBP / GA4 where relationships exist.

## Rules / invariants

* Read-only externally. No Search Console write, sitemap submit/remove, Force Index, generic Indexing API misuse, SERP scraping, or live rank-tracking expansion for this Demo milestone.
* No fake SEO scores. Missing ≠ zero. Observed queries ≠ all keywords. Average position ≠ GBP local rank. No query→conversion false attribution from aggregates alone.
* **Capability truth:** Existing real Website-scoped Search Console collection may remain unchanged; do not duplicate provider stores merely for Demo IA (ADR-043).

## Derived information

Brand vs non-brand, intent, topic clusters, ownership fragmentation candidates, momentum categories, discoverability funnel — labeled Derived / Demo with provenance.

## Later enhancements

Live Binding-first GSC Asset collection migration; richer cannibalization models; AI-assisted clustering (advisory only).

## Explicit non-goals

Full GSC UI clone; BI warehouse; external write; autonomous SEO agents; SERP scraping.

## Acceptance intent

Search Console is openable as a first-class Asset workspace that turns organic search into operating intelligence, while remaining honest that live provider architecture may still be Website-scoped until an explicit migration task.
