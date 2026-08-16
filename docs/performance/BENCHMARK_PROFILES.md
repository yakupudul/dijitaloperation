# Benchmark Profiles (Prompt 65)

These profiles are **benchmark datasets**, not product capacity limits.
Do not write “MoxDOP supports exactly 100 Customers.”

## Commands

```bash
php artisan moxdop:performance:benchmark AGENCY_20 --json
php artisan moxdop:performance:benchmark AGENCY_100 --json
php artisan moxdop:performance:benchmark HIGH_VOLUME_GSC --gsc-rows=50000 --json
php artisan moxdop:performance:benchmark HIGH_VOLUME_GOOGLE_ADS --ads-rows=50000 --json
php artisan moxdop:performance:benchmark MIXED_BACKGROUND_LOAD --json
```

Overrides:

- `--customers`
- `--brands-per-customer`
- `--assets-per-brand`
- `--gsc-rows` / `--ads-rows`
- `--gsc-days` / `--ads-days`
- `--seed` (default 65)

Smoke tests use reduced volumes via `BenchmarkProfiles::resolve(..., overrides)` inside PHPUnit group `performance`.

## Profile matrix

| Profile | Customers | Brands/Customer | Assets/Brand | GSC rows (default) | Ads rows (default) | Purpose |
|---|---|---|---|---|---|---|
| AGENCY_20 | 20 | 1 | 1 | 2_000 | 2_000 | Agency portfolio |
| AGENCY_100 | 100 | 1 | 1 | 5_000 | 5_000 | Larger portfolio |
| HIGH_VOLUME_GSC | 1 | 1 | 1 | 50_000 | 0 | GSC cardinality |
| HIGH_VOLUME_GOOGLE_ADS | 1 | 1 | 1 | 0 | 50_000 | Ads search-term cardinality |
| MIXED_BACKGROUND_LOAD | 20 | 1 | 1 | 10_000 | 10_000 | Foreground + background coexistence |

Composition is parameterized — brands/assets per customer are documented defaults, not hard product rules.

## Fixture rules

- Synthetic only
- No real customer data
- No real credentials / provider tokens / PII
- No real provider HTTP
- No paid AI calls

## CI

- Group `performance` smoke tests run with small row counts
- Full 50k+ profiles are optional / dedicated — not every default PHPUnit invocation
