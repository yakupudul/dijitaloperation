# Search Demand Intelligence

## Status

**FOUNDATION CODE COMPLETE / TEST AND OPERATOR UAT NOT RUN**

This document defines the shared commercial context and reusable Search Query Library that later Website search-demand, SERP, content-ownership and competitor analysis will consume.

## Product purpose

MoxDOP must know four operator-owned facts before it can interpret Website, GSC, GA4, Google Ads or DataForSEO data:

1. Customer
2. Brand
3. Services offered by the Brand
4. Countries, cities and districts served by the Brand

The system must then retain the agency's reusable search-query knowledge without paying a provider for the same discovery on every Brand.

## Canonical boundaries

### Global Service Catalog

`service_catalog_items` and `service_catalog_names` are agency-wide reusable service identities.

- One service has one stable ID.
- Primary names and aliases are separate name claims.
- A service may be archived but is not normally deleted.
- An operator may create a missing service while editing a Brand.

### Brand Offering

`brand_offerings` remains the canonical Brand-scoped Offering identity. It may reference a global `service_catalog_item_id`.

This is intentionally a link, not a replacement:

- Global Service = reusable agency vocabulary.
- Brand Offering = that specific Brand's supplied service and priority order.

Existing unlinked Brand Offerings remain valid for compatibility.

### Brand Service Area

`brand_service_areas` stores explicit Brand scope at country, city and optional district grain. Multiple rows are allowed. Missing city means country scope; missing district means city scope.

Service areas update the compatible Brand `primary_country` / `target_markets` projection and the structured Brand Intelligence Context. They do not silently create service × area analysis jobs.

### Search Query Library

`search_query_library_items` is the reusable normalized query identity. `search_query_library_source_records` retains every source observation and its available metrics.

Supported operator inputs:

- one manual query
- newline-separated pasted queries
- CSV / TSV / TXT
- XLSX first worksheet
- source classification for Google Ads, Search Console and DataForSEO exports

Recognized optional import dimensions include service, sector, language, market, demand family, country/city/district, period, impressions, clicks, conversions, cost, search volume, CPC and competition.

Provider facts and imported metrics are not collapsed into one synthetic value. Every source record remains attributable.

## Relationship to Intelligence Core

The two concepts are deliberately distinct:

| Concept | Scope | Purpose |
| --- | --- | --- |
| Search Query Library | Agency-wide | Reusable operator research and imported observations |
| `IntelligenceSearchTermIdentity` | Brand-scoped | Canonical identity for provider observations joined inside Intelligence Core |

When a library query is applied to a Brand in a later phase, it will resolve into the existing Brand-scoped Intelligence Search Term identity. No second Website adapter or generic metrics warehouse is introduced.

## No Cartesian explosion

Ten services and ten locations must not automatically create 100 permanent commercial scopes or 100 pages.

Later Brand Query Portfolio work will:

- select relevant library queries by Brand services and markets;
- keep applicable services and areas as relations;
- render provider request variants only when required;
- let SERP evidence and human review decide whether multiple locations need separate content.

## AI boundary (next phase)

AI will be used for bounded classification where human language understanding is material:

- demand family
- search intent
- user problem
- decision stage
- candidate SERP intent group
- candidate content target cluster
- branded/licensed-name suspicion

AI output is a candidate with confidence, rationale, abstention and version provenance. It never overwrites operator facts, invents volume/rankings, creates Findings, publishes content or opens Tasks automatically.

SERP evidence will validate content-target grouping later. Semantic similarity alone is not URL ownership proof.

## Operator surfaces

- `/library/services`
- `/library/search-queries`
- simplified Brand create/edit form for services, priorities and multiple service areas
- Brand Business Context summary for service-area visibility

## Deferred

- AI Search Demand Librarian runtime and human-review queue
- Brand Query Portfolio application
- query clustering and cluster versioning
- query ↔ URL visibility map
- DataForSEO SERP sampling and competitor result sets
- URL ownership decisions
- competitor crawl and comparison
- Finding → Recommendation → manual Task → Outcome loop

## Safety

- Unknown metrics remain missing, never numeric zero.
- Marked branded/licensed queries can be excluded.
- Imported data is read-only inside MoxDOP.
- No Google, CMS, Ads or provider write is introduced.
- No provider request is triggered by creating a Brand or importing a file.
