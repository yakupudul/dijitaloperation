# Query Performance Contract (Prompt 65)

## Purpose

Binding rules for MoxDOP application reads so scale work cannot weaken Prompt 0–64 semantics or Prompt 64 tenant isolation.

## Scope-first querying

1. Constrain the canonical scope early: Brand / DigitalAsset / External Resource / dataset / date range.
2. Tenant filters must remain in SQL predicates — never rely on PHP post-filtering alone for authorization.
3. Control-plane lists (Customer, Brand, Task, Finding) stay relational; data-plane facts stay scoped to resource + date.

## Pagination

1. Large operational and provider lists are server-paginated or top-N limited.
2. `per_page` is clamped server-side (typically 1–100). Unsafe browser values must not create unbounded queries.
3. Prefer `paginate` when the frozen UI needs numbered pages; `simplePaginate` / cursor are allowed when exact totals are unused.
4. Exact `COUNT(*)` totals must not be estimated and labeled exact.
5. Forbidden: `Model::all()` / unbounded `get()` for high-cardinality provider tables.

## Query-count budgets (PROPOSED baselines)

| Surface | Budget (queries) | Notes |
|---|---|---|
| Customer list (scalar columns) | ≤ 2 | No relationship N+1 |
| Brand directory (`with` + `withCount`) | ≤ 3 | Stable vs row growth |
| Finding Filament list | ≤ 3 | Eager `digitalAsset` |
| Task / Work Filament list | ≤ 4 | Eager brand/asset/assignee |
| Recommendation list | ≤ 3 | Eager `digitalAsset` |
| Report list | ≤ 3 | Metadata columns only |
| GSC topQueries | ≤ 3 | Aggregate + one detail batch |

Budgets are **PROPOSED** regression guards from measured smoke tests — not product SLAs.

## Index evidence

1. Indexes are added only for measured query predicates/sorts.
2. Composite order follows equality → range → sort (EXPLAIN when Postgres available).
3. No GIN-every-JSONB strategy.
4. No “index every column”.

## Aggregation

1. High-cardinality GSC / Ads / Meta / GA4 aggregates run in SQL / read repositories.
2. PHP must not load full account/date raw rows to compute charts or top-N lists.
3. Semantics preserved: GSC impressions ≠ search volume; average position ≠ exact rank; Meta reach not incorrectly summed; Keyword ≠ Search Term.

## N+1

1. List surfaces that touch relationships must eager-load or batch-read.
2. Over-fetching entire relationship graphs is also a bug.
3. Local environment enables `Model::preventLazyLoading()`; performance tests assert query counts.

## Cache

1. Cache is disposable optimization — never source truth.
2. Tenant/object scope required in keys.
3. Forbidden: plaintext credential cache; cross-Brand cache; stale analytical values presented as current without generation/freshness visibility.

## Unbounded reads

Forbidden for production paths:

- Full Snapshot body on report list
- Full AI structured output on Agent history list
- Full raw provider payload from object storage for list UI
- Full Search Term / Query table into PHP for account-wide processing
