# DataForSEO Integration

## Purpose

Agency-level DataForSEO provider configuration for MoxDOP: secure API credentials, Test connection, shared API client, and paid-request cost-guard infrastructure.

## Credential model

DataForSEO is **not** a site-scoped credential.

```
Moximu
└── DataForSEO Integration (Settings → Integrations)
    ├── API Login
    └── API Password (encrypted provider credential)
         ↓
shared authenticated DataForSEO API v3 client
         ↓
future Website collectors (capability-specific TTL + fingerprint)
```

- One agency Integration (`provider = dataforseo`)
- Credentials stored as `CoreIntegrationCredential` with `credential_type = provider`
- API Password is write-only after save (“Stored securely ✓”)
- Blank edit preserves the stored password; explicit clear removes it
- Precedence: encrypted DB → optional env fallback (`DATAFORSEO_API_LOGIN` / `DATAFORSEO_API_PASSWORD`) → missing
- No per-Customer / per-Brand / per-Website DataForSEO credentials for normal operation
- No fake ExternalResources / AssetBindings for this provider in this milestone

## Official auth (verified)

- API v3 Basic Authentication
- API login + API password from https://app.dataforseo.com/api-access
- API password ≠ website account password
- Credentials only in `Authorization` header — never query params
- Base URL: `https://api.dataforseo.com` (sandbox hostname available for infrastructure tests only)

## Test connection

Uses free `GET /v3/appendix/user_data`.

Success requires:

1. HTTP transport OK
2. DataForSEO top-level `status_code = 20000`
3. Safe account fields extractable (`login`, optional `timezone`, `money.balance`)

HTTP 200 with a non-success DataForSEO `status_code` is a failure.

Persists only a small health snapshot on Integration `config` (connection status, account login, timezone, last-fetched balance, last checked). Does **not** dump full `user_data` / rates JSON.

## Shared client responsibilities

`app/Services/Integrations/DataForSeo/*`

- credential resolution
- allowlisted endpoints only
- JSON requests / timeouts
- envelope normalization (`status_code`, `status_message`, `cost`, `tasks_count`, `tasks_error`)
- safe operator messages
- retry policy:
  - SAFE READ (GET): at most one retry on transient transport / 5xx
  - PAID CREATE (POST): **never** automatically retried (timeout after send is treated as ambiguous paid)

## Cost guard

Provider-agnostic Evidence freshness:

- `PaidRequestFingerprint` — canonical hash of provider + use_case + endpoint + result-affecting params
- Credentials / Authorization / non-result timestamps are never fingerprint inputs
- `Evidence.request_fingerprint` + `Evidence.fresh_until` (nullable, indexed)
- `EvidenceFreshnessGuard` decisions: `MISS` | `HIT_FRESH` | `BYPASS_ALLOWED`
- Collectors declare TTL when writing Evidence (`fresh_until`); no global magic TTL
- Cache HIT Run metadata records `provider_call_skipped`, `reported_cost_usd = 0`, reused Evidence id
- Provider-reported response `cost` is the source of truth for actual request cost (never fabricated)

## Endpoint allowlist

Only known endpoint identifiers are callable through the shared client.

This milestone allowlists:

- `appendix/user_data`

No raw API console. No keyword / SERP / backlink / OnPage product calls yet.

## Legacy debt

`DataForSeoConnectionProbeService` (site-scoped `CoreConnection`) remains for transitional rows.

Website Connections UI does not offer new `dataforseo` connection creation (`creatableConnectionTypes()` = WordPress only).

## Live vs automated UAT

- Automated acceptance: PHPUnit + HTTP fakes + browser UAT with seeded/non-secret state
- Real Test connection (free `user_data` only) may be performed by a human operator with real credentials
- Tests must never spend DataForSEO credits or commit credentials

## Explicit non-goals (this milestone)

Keyword research, rank tracking, backlinks, competitor intelligence, OnPage crawling, AI/GEO, scheduler-driven paid calls, customer billing, per-site credentials.
