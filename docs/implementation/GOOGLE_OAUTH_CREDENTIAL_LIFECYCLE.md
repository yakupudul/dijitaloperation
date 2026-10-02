# GOOGLE OAUTH & CREDENTIAL LIFECYCLE

**Prompt:** 14  
**Status:** CODE READY (external Google Cloud console remaining)  
**Verification date (official docs):** 2026-08-13  
**Canonical surface:** `/integrations` → Google Integration  
**Branch convention:** attaches to Prompt 13 `CoreIntegration` + `CoreIntegrationCredential`

---

## 1. Purpose

Productionize Google authorization and credential lifecycle for MoxDOP:

- application-level OAuth client configuration (deployment secrets)
- secure web-server OAuth authorization-code flow
- connector-aware minimum / incremental scopes
- encrypted offline refresh credentials
- concurrent-safe access-token refresh
- explicit revoke / reauthorize without destroying domain data

Prompt 14 does **not** discover resources, bind assets, or collect provider data.

---

## 2. Canonical Google Integration Boundary

| Concept | Owner |
| --- | --- |
| Integration (authorization plane) | `CoreIntegration` (`provider = google`) |
| Credential (tenant OAuth tokens) | `CoreIntegrationCredential` (`kind = authorization`) |
| Connector capability | `GoogleConnectorRegistry` + `GoogleScopeRegistry` |
| Access token access path | `GoogleCredentialBroker` → `GoogleOAuthService` |
| Authorization attempt (ephemeral) | `GoogleOAuthAuthorizationAttempt` |
| Frozen operator UI | `/integrations` / `/integrations/google` |

There is **no** competing long-lived `GoogleOAuthConnection` product entity.

Hard rule: **Google authorization ≠ Connector enablement ≠ Resource discovery ≠ Collection.**

---

## 3. Application Configuration

Deployment/application config (not per-Customer OAuth tokens):

| Key | Config path | Purpose |
| --- | --- | --- |
| `GOOGLE_CLIENT_ID` | `config('moxdop.google.client_id')` | OAuth client ID |
| `GOOGLE_CLIENT_SECRET` | `config('moxdop.google.client_secret')` | OAuth client secret |
| `GOOGLE_REDIRECT_URI` | `config('moxdop.google.redirect_uri')` | Optional override; default = named callback route |
| `GOOGLE_ADS_DEVELOPER_TOKEN` | `config('moxdop.google.developer_token')` | Ads API app token |
| `GOOGLE_OAUTH_STATE_TTL_MINUTES` | `oauth_state_ttl_minutes` | Attempt expiry (default 15) |
| `GOOGLE_ACCESS_TOKEN_REFRESH_SKEW_SECONDS` | `access_token_refresh_skew_seconds` | Refresh skew (default 60) |
| `GOOGLE_OAUTH_REFRESH_LOCK_SECONDS` | `refresh_lock_seconds` | Lock wait hint (default 20) |
| `GOOGLE_INCLUDE_GBP_SCOPE` | `include_gbp_scope` | Gate GBP scope (default false) |

Health check: `GoogleOAuthConfigurationHealth` + `php artisan moxdop:google-oauth:check`  
Never prints secret values. Never exposes secrets through the operator product or Filament `/admin`.

Production HTTPS: health checker flags non-HTTPS redirect URIs outside local/testing.

---

## 4. Tenant Credential Boundary

Stored only in `CoreIntegrationCredential.encrypted_payload` (Laravel encrypted cast):

- `access_token`
- `refresh_token`
- `expires_at` / access-token expiry
- `refresh_token_expires_at` (nullable = unknown, not infinite)
- `granted_scopes` / `requested_scopes`
- lifecycle timestamps / safe error codes

**Never** stored in tenant credential rows:

- OAuth client secret
- Google Ads developer token
- authorization code
- raw OAuth state secret

---

## 5. OAuth Web-Server Flow

Official Google OAuth 2.0 for Web Server Applications (verified 2026-08-13):

```
Operator (/integrations)
  → GoogleOAuthService::beginAuthorization
  → Google authorization URL (code, offline, include_granted_scopes, state)
  → Google consent
  → integrations.google.callback
  → state consume + code exchange
  → encrypted credential persist
  → clean redirect → /integrations/google
```

- Backend owns token exchange (no browser/localStorage tokens).
- Library: Laravel `Http` facade against Google OAuth endpoints (no Socialite; no second OAuth stack).
- Client factory role: `GoogleOAuthService` + `GoogleOAuthRedirectUriResolver` (single configuration path).

---

## 6. Scope Registry

Canonical: `App\Services\Integrations\Google\GoogleScopeRegistry`  
Constants: `App\Support\Integrations\Google\GoogleScopes`  
Coverage: `GoogleScopeCoverageService`

Identity scopes (`openid` / `email` / `profile`) are **not** requested automatically.

---

## 7. Connector Scope Matrix

| Connector | Capability | Current official scope | Classification | Read-only available? | Provider broader than MoxDOP? | Requested initially? | Incremental? | Verification | Code enforcement |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Search Console | `search_console` | `https://www.googleapis.com/auth/webmasters.readonly` | Sensitive | YES | NO | YES (default Connect) | YES | May need OAuth verification | `GoogleScopeRegistry` + Broker |
| GA4 | `ga4` | `https://www.googleapis.com/auth/analytics.readonly` | Sensitive | YES | NO | YES (default Connect) | YES | May need OAuth verification | same |
| Google Ads | `google_ads` | `https://www.googleapis.com/auth/adwords` | Restricted | NO (provider has no read-only OAuth scope) | YES (scope can authorize writes; product forbids writes) | YES (default Connect) | YES | Ads API + developer-token approval separate | same |
| GBP | `google_business_profile` | `https://www.googleapis.com/auth/business.manage` | Sensitive/manage | NO | YES | Only if `GOOGLE_INCLUDE_GBP_SCOPE=true` | YES | GBP API access often separate | gated |

**Sources (2026-08-13):**  
Search Console authorizing docs; Google OAuth scopes list; Google Ads OAuth overview; GBP basic setup.

**Application writes allowed:** NO for all Connectors (product architecture).

---

## 8. Minimum / Incremental Authorization

- Default Connect requests the union of enabled/default capabilities (GSC + GA4 + Ads; GBP gated).
- Capability-targeted authorize (e.g. Ads only) requests **missing** scopes only.
- `include_granted_scopes=true` preserves prior grants.
- `prompt=consent` only when refresh token is missing / force reauth / explicit force_consent — not on every request.
- Requested scopes and granted scopes are persisted separately; **requested ≠ granted**.

---

## 9. Authorization Attempt / State Security

Model/table: `google_oauth_authorization_attempts`

| Field | Notes |
| --- | --- |
| `state_hash` | SHA-256 of raw state; raw state not stored |
| `integration_id` | Bound Integration |
| `requested_by_user_id` | Bound operator |
| `requested_scopes` | JSON list |
| `capability` | Optional connector context |
| `return_context` | Internal route name only (`demo.integrations.google`) |
| `expires_at` | Short TTL |
| `consumed_at` | One-time |

Cache key compatibility remains for in-flight legacy attempts during rollout.

Open redirects rejected: only validated internal return contexts.

---

## 10. Callback Lifecycle

Route: `GET /integrations/google/callback` (`integrations.google.callback`) — OAuth callback only, not a product page.

Order:

1. Parse provider response  
2. Reject `error` (e.g. `access_denied`) without corrupting prior credentials  
3. Validate state hash → pending unexpired attempt  
4. Validate user ownership  
5. Atomically consume attempt  
6. Exchange authorization code  
7. Persist tokens safely (refresh-token preservation rule)  
8. Recompute coverage / auth status  
9. Redirect cleanly to `/integrations/google` (flash only)

Authorization code is never persisted or logged.

---

## 11. Token Persistence

- Encrypted at rest via Laravel encrypted casts on credential payload.
- Hidden from `toArray` / JSON / Livewire / UI read models.
- Application key rotation: standard Laravel `APP_KEY` / encryptor strategy — no custom crypto vault.
- Operational requirement: rotating `APP_KEY` requires decrypt/re-encrypt of encrypted columns (Laravel standard).

---

## 12. Access-Token Expiry

- Expiry is a normal lifecycle event.
- Does **not** mean Integration Disconnected.
- Broker/OAuth service refreshes when within skew window using refresh token.
- No persisted Integration state `REFRESHING`.

---

## 13. Refresh Token Lifecycle

| Rule | Behavior |
| --- | --- |
| Durable offline access | Required for background collection |
| Absent in response | **Must not** null an existing valid refresh token |
| New non-empty token | Atomic replace |
| Initial auth without refresh | `auth_status = refresh_required` (ACTION_REQUIRED) |
| `refresh_token_expires_at = null` | Unknown / not supplied — **not** infinite |
| invalid_grant | Transition to `refresh_required`; no stampede retry |

---

## 14. Concurrent Refresh Protection

- `GoogleOAuthService::refreshAccessToken` uses `DB::transaction` + `lockForUpdate` on credential row.
- Double-check after lock: reuse if another worker already refreshed.
- Cache locks alone are insufficient on array/file drivers; DB lock is the correctness path.

---

## 15. Partial Scope Grants

Example: requested GSC+GA4+Ads; granted GSC+GA4 only.

- Integration remains authorized for granted capabilities (`connected` when refresh token present).
- Ads Connector reports scope required / action required via coverage service.
- Declining incremental Ads consent does not destroy existing GA4/GSC grant.

---

## 16. Reauthorization

- Same `CoreIntegration` row.
- Preserves ExternalResources, Bindings, CollectionRuns, data pool, materializations.
- Merges granted scopes from provider token response truth.
- Force consent when recovering a missing/invalid refresh token.

---

## 17. Revocation / Disconnect Semantics

| Action | Meaning | Provider revoke call? |
| --- | --- | --- |
| Connector disable (if present) | Local capability off | NO |
| Revoke Google access (UI) | Revoke OAuth grant at Google + clear local secrets | YES (`revokeAuthorization`) |
| Legacy `disconnect` alias | Same as revoke Google access | YES |

UI wording on frozen Google page: **Revoke Google access** (not “disconnect Search Console only”).

On successful revoke:

- credential secrets cleared; status `revoked`
- ExternalResources / Bindings / history / data **retained**

On revoke failure: do not claim revoked; do not clear the only refresh token.

---

## 18. Google Ads Developer Token Boundary

| Item | Owner | Secret? | Tenant-specific? | Needed for OAuth? | Needed for Ads API? |
| --- | --- | --- | --- | --- | --- |
| OAuth access/refresh | Tenant credential | YES | YES (agency Integration) | YES | YES |
| Ads OAuth scope (`adwords`) | Scope registry | N/A | grant on Integration | YES | YES |
| Developer token | App config / env | YES | NO | NO | YES |
| Customer ID | ExternalResource (Prompt 15+) | NO | YES | NO | YES (API calls) |
| login-customer-id | Request/resource hierarchy (later) | NO | context | NO | Manager hierarchy |

Developer token never enters OAuth payload, UI, queues, or logs.

Access level (test/production): **EXTERNAL / MANUAL** — code cannot upgrade Google Ads API approval.

---

## 19. Connection State

Canonical statuses (`GoogleAuthStatus`):

| Status | Meaning |
| --- | --- |
| `not_configured` | App OAuth client incomplete |
| `authorization_required` | Configured; no usable auth credential |
| `connected` | Refresh token present; usable |
| `refresh_required` / reauth alias | Action required (missing refresh, invalid_grant, etc.) |
| `revoked` | Explicitly revoked |
| `error` / `disabled` | Error / locally disabled Integration |

Access-token expiry alone does **not** flip to disconnected.  
Connected ≠ resources discovered ≠ data available.

---

## 20. Collection Engine Credential Resolution

```
Job payload: Integration / DatasetRun IDs only
  → executor
  → GoogleCredentialBroker::accessTokenFor($integration, $capability)
  → valid access token in memory
```

Failures:

- auth/refresh invalid → `GoogleAuthenticationException` (AUTHENTICATION)
- missing scope → `GoogleAuthorizationException` (AUTHORIZATION / SCOPE_REQUIRED)

Workers never open OAuth URLs or touch browser sessions.

---

## 21. Security / Secret Handling

- OAuth CSRF via hashed state (not form CSRF alone)
- Replay / expiry / cross-user callback rejected
- No tokens in URLs, Livewire public props, JS, logs, Activity payloads
- Token endpoint bodies not persisted raw into general metadata
- Exception messages sanitized before persistence

---

## 22. External Google Cloud Production Checklist

Manual (Cursor cannot complete):

| Item | Status |
| --- | --- |
| OAuth consent screen configuration | UNKNOWN / MANUAL VERIFICATION REQUIRED |
| Production publishing / verification | UNKNOWN / MANUAL |
| Authorized redirect URI registered | MANUAL (must match resolver / `GOOGLE_REDIRECT_URI`) |
| Domain ownership/verification | MANUAL |
| Required APIs enabled (Analytics, Search Console, Ads, GBP if used) | MANUAL |
| Sensitive/restricted scope verification | MANUAL |
| Privacy policy / homepage / terms | MANUAL where Google requires |
| Google Ads developer-token access level | MANUAL |
| GBP API access (if enabled) | MANUAL |

**CODE READY ≠ EXTERNAL GOOGLE CONFIGURATION COMPLETE**

---

## 23. DPoP Decision

**DEFERRED — SECURITY HARDENING**

- Audited Google guidance mentions advanced token binding options in some contexts.
- Official PHP stack used here (Laravel Http + token endpoints) does not cleanly ship DPoP for this flow without hand-rolled crypto.
- Do not block production authorization on custom DPoP.

---

## 24. Cross-Account Protection Decision

**DEFERRED TO SECURITY HARDENING**

Not essential for initial production authorization; would require additional event infrastructure beyond Prompt 14 scope.

---

## 25. Legacy OAuth Convergence

| Component | Old responsibility | Canonical replacement | Reused? | Deprecated? | Writes remaining? | Removal |
| --- | --- | --- | --- | --- | --- | --- |
| `GoogleOAuthService` | Start/callback/refresh | same (evolved) | YES | NO | YES (canonical) | — |
| Cache-only OAuth state | Ephemeral state | DB attempt + hash (+ cache compat) | EVOLVE | cache-only path compat | Compat read | after rollout |
| Always `prompt=consent` | Force consent | Conditional consent | REPLACED | YES | NO | — |
| `disconnect` clearing bindings/resources | Destructive disconnect | Revoke secrets only; preserve domain | REPLACED | YES | NO | — |
| Demo UI stub disconnect | Fake UX | Real Connect/Reauth/Revoke | REPLACED | YES | NO | — |
| Socialite / google/apiclient | N/A | Not introduced | N/A | N/A | NO | — |
| Env client secret in credential row | Forbidden anti-pattern | App config only | N/A | N/A | NO | — |
| Filament `/admin` authorize | Admin path | Same routes/services; frozen UI primary | KEEP_INTERNAL | NO | Uses canonical service | — |

**Remaining duplicate authorization paths:** NONE (one callback handler, one exchange path).

---

## 26. Tests

Primary: `tests/Feature/GoogleOAuthLifecycleTest.php`  
Regression: Google central/architecture/onboarding/operating-layer suites.

Coverage includes: config health, scope matrix/union/incremental/no-identity, auth URL, state entropy/hash, callback security, token encryption/serialization, refresh-token preservation (mandatory), concurrent refresh, invalid_grant, partial grants, revoke success/failure, queue payload secrecy, zero live discovery.

Automated live Google authorization: **0**.

---

## 27. Reality Matrix

| Capability | Classification |
| --- | --- |
| Canonical Google Integration | REAL |
| Google OAuth configuration | REAL |
| Google OAuth authorization (code flow) | REAL |
| OAuth state protection | REAL |
| Secure credential storage | REAL |
| Offline authorization | REAL |
| Refresh-token lifecycle | REAL |
| Access-token refresh | REAL |
| Refresh concurrency | REAL |
| Scope coverage | REAL |
| Incremental reauthorization | REAL |
| Revocation | REAL |
| Google Ads developer-token boundary | REAL |
| Google Cloud external verification | UNKNOWN / MANUAL |
| Live Resource Discovery | NOT YET (Prompt 15) |
| Resource Selection | NOT YET (Prompt 16) |
| Production Google binding workflow | NOT YET |
| GSC / GA4 / Ads collectors | NOT YET |

---

## 28. Prompt 15 Handoff

After OAuth success:

- Integration: Authorized (when refresh token present)
- Resources: Not discovered
- Next: Prompt 15 — Google Resource Discovery via Credential Broker tokens

No discovery inside OAuth callback.

---

## 29. Definition of Done

See Prompt 14 §188 checklist. All authorization invariants must be YES for PASS.

---

## Token Matrix

| Secret/Value | Owner | Storage | Encrypted? | Persisted? | Expiry | Refreshable? | UI? | Queue? | Logs? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| OAuth Client ID | App config | env/config | NO (public id) | YES | N/A | N/A | NO | NO | NO |
| OAuth Client Secret | App config | env/secret manager | at rest by deploy | YES | N/A | N/A | NO | NO | NO |
| Access Token | Tenant credential | encrypted payload | YES | YES (cached) | YES | via refresh | NO | NO | NO |
| Refresh Token | Tenant credential | encrypted payload | YES | YES | nullable unknown | N/A | NO | NO | NO |
| Google Ads Developer Token | App config | env/secret manager | deploy | YES | N/A | N/A | NO | NO | NO |
| OAuth State | Attempt | hash only | hashed | hash YES / raw NO | TTL | N/A | NO | NO | NO |
| Authorization Code | Transient | memory only | N/A | NO | short | N/A | NO | NO | NO |

---

## State Matrix

| Situation | Integration state | Connector state | Operator action | Collection | Historical data |
| --- | --- | --- | --- | --- | --- |
| No app config | `not_configured` | N/A | Configure env/secrets | AUTH fail | retained |
| Configured, no auth | `authorization_required` | Unauthorized | Connect | AUTH fail | retained |
| Authorized | `connected` | Per granted scopes | Manage / discover later | OK if scope+resource | retained |
| Access token expired, refreshable | `connected` | Unchanged | None | Auto-refresh | retained |
| Missing Connector scope | `connected` (if refresh OK) | Scope required | Grant additional access | SCOPE/AUTHZ fail | retained |
| Refresh invalid | `refresh_required` | Action required | Re-authorize | AUTHENTICATION | retained |
| User denied incremental scope | Prior grant kept | New capability missing | Retry grant later | Prior caps OK | retained |
| Provider revoked | `refresh_required` on refresh | Action required | Re-authorize | AUTHENTICATION | retained |
| MoxDOP revoked | `revoked` | Unauthorized | Re-authorize | AUTH fail | retained |
| Transient token endpoint failure | unchanged | unchanged | Retry (transient) | Transient retry | retained |

---

## Refresh Matrix

| Condition | Access usable? | Refresh attempted? | Lock? | Retry? | Action required? | Credential state |
| --- | --- | --- | --- | --- | --- | --- |
| Valid + outside skew | YES | NO | NO | NO | NO | unchanged |
| Near/expired + valid refresh | After refresh | YES | YES | NO stampede | NO | access updated |
| Refresh omits new refresh_token | YES | YES | YES | NO | NO | refresh preserved |
| invalid_grant | NO | YES once | YES | NO rapid loop | YES | `refresh_required` |
| Transient 5xx/network | NO (this call) | YES | YES | Transient reliability | NO permanent revoke | secrets retained |

---

## Revoke Matrix

| Action | Google provider call? | Credentials retained? | Resources? | Bindings? | Data? |
| --- | --- | --- | --- | --- | --- |
| Disable Connector | NO | YES | YES | YES | YES |
| Local Integration disable (status) | NO | YES (unless separate revoke) | YES | YES | YES |
| Revoke Google OAuth grant | YES | Cleared after success | YES | YES | YES |
| Reauthorize | YES (new grant) | New tokens | YES | YES | YES |

---

## Google Ads Auth Matrix

| Requirement | Source | Secret? | Tenant-specific? | OAuth? | Ads API? | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| OAuth access | User grant | YES | Integration | YES | YES | Credential |
| OAuth refresh | User grant | YES | Integration | YES | YES | Credential |
| Ads OAuth scope | Scope registry | N/A | Grant | YES | YES | Registry |
| Developer token | App config | YES | NO | NO | YES | Deployment |
| Customer ID | Discovery/resource | NO | YES | NO | YES | Prompt 15+ |
| login-customer-id | Hierarchy context | NO | YES | NO | Manager cases | Prompt 15/19 |

---

*End of Prompt 14 lifecycle document.*
