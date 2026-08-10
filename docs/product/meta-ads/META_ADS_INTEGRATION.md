# META ADS CENTRAL INTEGRATION + RESOURCE BINDING V1

> Status: **IMPLEMENTED V1** (connection layer only — not intelligence)

## Purpose

First production-safe Meta Ads **connection** layer for MoxDOP:

```
ONE / FEW agency-level Meta credentials
        ↓
discover Meta advertising resources
        ↓
ExternalResource (meta_ads / Meta Ad Account)
        ↓
bind selected Meta Ad Account
        ↓
Meta Ads Digital Asset
```

This milestone is **not** Meta Ads intelligence.

## Canonical distinction

| Concept | Ownership |
|---------|-----------|
| **Meta** Integration (`provider=meta`) | Core Integration — auth, Graph client, discovery |
| **Meta Ads** Module (`meta-ads`) | Business domain — Digital Asset presentation + binding UX |
| **Meta Ad Account** | ExternalResource (`resource_type=meta_ads`) |
| **Meta Ads Digital Asset** | Brand-managed property bound via AssetBinding |

Do **not** turn campaigns / ad sets / ads / creatives into Digital Assets.

## Official API facts (authoritative)

Reviewed against Meta Marketing API versioning docs (2026-08):

- **API version:** `v26.0` (centralized in `MetaApiConfig` / `config('moxdop.meta.api_version')`)
- **Host:** `https://graph.facebook.com` only (not operator-configurable)
- **Auth strategy (internal agency tool):** encrypted long-lived user token or Business system-user token (DB-first; optional `META_ACCESS_TOKEN` env fallback)
- **Least-privilege read permissions:** `ads_read`, `business_management`
- **Health check:** `GET /{version}/me?fields=id,name` (non-mutating)
- **Discovery paths:**
  - `GET /me/adaccounts`
  - `GET /me/businesses` → `/{business-id}/owned_ad_accounts`
  - `GET /me/businesses` → `/{business-id}/client_ad_accounts`
- Deduplicate by stable `act_{account_id}`
- Client is **GET-only** (no post/delete/mutate helpers)

Official Meta documentation is authoritative for API behavior.  
`pipeboard-co/meta-ads-mcp` remains a HIGH/VALUE methodology reference only — **not installed**, not trusted for current API facts, write behavior rejected.

## IMPLEMENTED

- Central Meta Integration card in Settings → Integrations
- Encrypted `access_token` via `CoreIntegrationCredential` (provider type)
- Non-mutating Test connection
- Operator-triggered Discover resources
- ExternalResource persistence (`resource_type=meta_ads`)
- Safe Ad Account metadata (name, currency, timezone, account status, business context, discovery paths)
- Rediscovery upsert + unavailable marking only when a discovery path succeeds
- Temporary total discovery failure preserves existing resources/bindings
- Meta Ads Digital Asset type (`meta_ads`) + `app-modules/meta-ads/` module
- Canonical AssetBinding (one active `meta_ads` capability binding)
- Read-only Graph client + URL host validation for pagination
- Operator setup help (permissions + token guidance)
- Integrations Workspace Meta card status: Not configured / Configured / Healthy / Needs attention

## Validation notes

- Automated / fake-provider coverage: PASS
- Browser UAT (desktop / responsive / dark): PASS
- Real Meta read-only UAT: **OPERATOR FOLLOW-UP** (no production Meta credential in the finalization environment)

## NOT IMPLEMENTED (deliberate)

- Meta Ads Insights / campaign / ad set / ad / creative collectors
- Findings, Recommendations automation, Tasks automation
- Meta Ads Analyst / Skills / `meta_ads.ai_guidance`
- Lead Ads personal data
- Write operations (campaign/budget/bid/audience/creative/ad)
- Webhooks / continuous polling
- Capability Router / Playbooks / RAG / embeddings

## Operator flow

1. Settings → Integrations → Meta → Set up / Configure (token)
2. Test connection
3. Discover resources → Meta Ad Accounts listed
4. Customer → Brand → Digital Assets → Meta Ads → Connections → Bind Ad Account

## Future (document only)

Likely next product milestone (selection after review):

**META ADS INTELLIGENCE + ANALYST V1**

Potential Evidence: account summary, campaign / ad-set / ad performance, creative metadata, aggregate actions.  
Potential Skills: account-performance-audit, campaign-performance-analysis, delivery-analysis, creative-performance-analysis, measurement-result-review.  
Future: `meta_ads.ai_guidance`.

Do not scaffold those runtimes in this milestone.
