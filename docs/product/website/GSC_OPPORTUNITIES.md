# GSC Opportunity Intelligence

## Purpose

Surface Search Console queries that already earn meaningful impressions while
ranking near stronger result positions, so operators can prioritize on-page and
content improvements.

## Classification

**Striking distance is a MoxDOP heuristic, not a Google-defined metric.**

Default heuristic band:

| Setting | Default | Meaning |
| --- | --- | --- |
| `striking_distance_position_min` | `5.0` | Inclusive lower average position |
| `striking_distance_position_max` | `20.0` | Inclusive upper average position |
| `minimum_impressions` | `20` | Ignore tiny samples |
| `max_opportunities` | `15` | Bound list size |

Implemented as constants in
`MoxDop\Website\Opportunities\GscStrikingDistanceConfig`.

## Evidence sources

Prefer:

1. `gsc_query_page_performance` — dimensions `query` + `page` (bounded rows)
2. Fallback: `gsc_query_performance` — query-only (no page attribution)

No invented search volume. No Finding explosion — opportunities are a
view-model, not one Finding per query.

## Algorithm (adapted)

Inspired by OpenSEO `buildStrikingDistanceRows`:

1. When query×page rows exist, collapse each query to its best page
   (lowest position; impressions tie-break).
2. Keep rows inside the heuristic position band with impression floor.
3. Sort by impressions desc, then position asc, then clicks desc.
4. Bound to `max_opportunities`.

## Workspace surfaces

| Surface | Behavior |
| --- | --- |
| Performance | Full **SEO Opportunities** table |
| Overview | Compact count + top 3 |
| Health | Not used for opportunity lists |

## Non-goals

- DataForSEO / keyword volume
- Rank tracking scheduler
- Creating Findings for every opportunity row
- Claiming Google defines “striking distance”
