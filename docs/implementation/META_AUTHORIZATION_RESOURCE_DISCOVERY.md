# META AUTHORIZATION & RESOURCE DISCOVERY

Prompt 22 — production Meta authorization, token lifecycle, Business discovery,
Business discovery-context selection, and Ad Account inventory.

Verification date: **2026-08-13**. Graph API version: **v26.0**.

Official references:

- [Facebook Login for Business](https://developers.facebook.com/docs/facebook-login/facebook-login-for-business/)
- [Marketing API Authentication](https://developers.facebook.com/docs/marketing-api/get-started/authentication/)
- [Manually Build a Login Flow](https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow)
- [Long-Lived Access Tokens](https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived)
- [Access Token Debugger / debug_token](https://developers.facebook.com/docs/facebook-login/guides/access-tokens#debug)
- Business Management: `me/businesses`, `{business-id}/owned_ad_accounts`, `{business-id}/client_ad_accounts`

## 1. Purpose

Make the Prompt 21 Meta Integration architecture production-ready for:

Connect Meta → secure token persistence/validation → Business discovery →
human Business selection (discovery context) → owned + client Ad Account
inventory → handoff to Prompt 23 binding.

## 2. Relationship to Prompt 21 Architecture

Reuses: `CoreIntegration` (`meta`), `CoreIntegrationCredential`,
`META_BUSINESS` / `META_AD_ACCOUNT`, `MetaAdAccountId`, `MetaConnectorRegistry`,
frozen `/app/integrations` read model.

Does not invent a second Meta integration architecture.

## 3. Meta Application Configuration

Deployment-level (`config/moxdop.php` / env):

| Key | Purpose |
| --- | --- |
| `META_APP_ID` | App ID |
| `META_APP_SECRET` | App Secret (never tenant rows / UI) |
| `META_LOGIN_CONFIGURATION_ID` | Facebook Login for Business config_id |
| `META_REDIRECT_URI` | Optional override of APP_URL callback |
| `META_API_VERSION` | Default `v26.0` |
| `META_OAUTH_STATE_TTL_MINUTES` | Authorization attempt TTL |
| `META_USE_APPSECRET_PROOF` | Server-side Graph proof (default true) |

Health: `MetaConfigurationHealth` (no secrets).

## 4. Production Authorization Flow

**Canonical:** Facebook Login for Business with `config_id` + `response_type=code`.

Dialog: `https://www.facebook.com/{version}/dialog/oauth`

Local/dev fallback (no config_id): `scope=ads_read,business_management` only.

Never requests `ads_management` or unrelated Page/IG/WhatsApp/leads permissions.

Manual pasted token remains Filament KEEP_INTERNAL — not frozen product path.

## 5. Authorization Attempt / OAuth State

Model: `MetaOAuthAuthorizationAttempt`

- cryptographically random state; store `state_hash` only
- binds Integration + Admin user + requested permissions + return route
- expiry + one-time consume
- open-redirect protection (allow-listed routes only)

## 6. Permission Registry

`MetaPermissionRegistry`: `ads_read`, `business_management`.

## 7. Requested vs Granted Permissions

Persisted separately on Integration config. Coverage:
`MetaPermissionCoverageService`. Unknown grant set ≠ missing.

## 8. Token Type / Lifecycle

Persisted `token_type`:

- `user_access_token`
- `long_lived_user_access_token` (via `fb_exchange_token`)
- `business_integration_system_user_access_token` (no expiry reported)

System User is a Login-for-Business configuration outcome, not a second
unexplained product path.

## 9. Why Meta Is Not Modeled Like Google Refresh Tokens

Meta does not issue OAuth refresh_tokens for this flow. Short-lived codes
exchange to access tokens; short-lived user tokens may exchange to long-lived
user tokens. No Google-style refresh_token field is required or invented.

## 10. Credential Storage

`CoreIntegrationCredential` TYPE_PROVIDER encrypted `access_token` (+ metadata).
App Secret never copied into tenant payload.

## 11. Token Validation / Debugging

`MetaCredentialValidator` → GET `/debug_token` with app access token.
Persisted/cached on Integration config. **Not** called on page render.

## 12. Token Expiry / Reauthorization

Provider `expires_at` / `data_access_expires_at` persisted when returned.
Expired/revoked/wrong_app → `reauth_required`. Inventory preserved.

## 13. Disconnect / Permission Revocation

Frozen Disconnect = local credential clear + best-effort `DELETE /me/permissions`.
Never claims provider-revoked on failure. Inventory preserved.

Deauthorization / data-deletion callbacks: documented as external Meta App
Dashboard readiness (UNKNOWN without dashboard access).

## 14. External Meta App Readiness

See checklist below. Code readiness ≠ App Review / Advanced Access /
Business Verification.

## 15. Business Discovery

`MetaBusinessDiscoverer` → `GET me/businesses` (paginated) →
`ReconcileExternalResourcesService` → `META_BUSINESS`.

## 16. Business Selection Context

`CoreIntegrationDiscoveryContext` purpose=`discovery_context`.
Human-controlled. **Not** `CoreAssetBinding`. **Not** DigitalAsset.

## 17. Ad Account Discovery

`MetaAdAccountDiscoverer` for each selected Business:

- `{id}/owned_ad_accounts`
- `{id}/client_ad_accounts`

## 18. Owned vs Client Ad Accounts

Same canonical `act_*` identity; `metadata.access_contexts` preserves edge +
business provenance.

## 19. `act_` Identity Normalization

`MetaAdAccountId` — stored form always `act_{digits}`; digits/`act_` equal.

## 20. Business → Ad Account Provider Hierarchy

`parent_external_id` + `access_contexts` metadata. Not Binding / Asset Relationship.

## 21. Discovery Completeness

Attempt rows: `MetaIntegrationDiscoveryAttempt` (phase businesses|ad_accounts,
edge, complete_inventory, status).

## 22. Resource Reconciliation

Complete Business inventory may mark missing Businesses unavailable.
Ad Account edges: access_lost per business+edge when that edge completed;
never hard-delete; never global unavailability when another context remains.

## 23. Persistent ExternalResource Inventory

Page render reads DB only.

## 24. Frozen Integration UX

`/app/integrations` + `/app/integrations/meta`:

Connect Meta · Discover Businesses · Select context · Discover Ad Accounts ·
Refresh Resources · Disconnect.

No new top-level nav. Bind/Collect disabled (Prompts 23–25).

## 25. Security / Tenant Isolation

Admin-only authorize/discover/select. Callback resolves Integration from attempt.
Appsecret_proof optional on Graph client. No secrets in UI/queue/logs.

## 26. Retry / Failure Handling

Auth/permission/rate-limit/5xx classified via `MetaException`. No busy `sleep()`.
Failed/partial refresh preserves inventory.

## 27. Legacy Authorization Convergence

Manual token + env `META_ACCESS_TOKEN` remain resolver-compatible.
New writes: OAuth → TYPE_PROVIDER. Filament paste KEEP_INTERNAL.

## 28. Tests

`tests/Feature/MetaAuthorizationDiscoveryTest.php` (+ Prompt 21 regression).

## 29. Reality Matrix

See `MILESTONE_5_PANEL_FREEZE.md` Prompt 22 rows.

## 30. Prompt 23 Handoff

Persistent META_AD_ACCOUNT inventory ready for human selection → DigitalAsset
+ `CoreAssetBinding`. No Binding created here.

## 31. Definition of Done

See Prompt 22 §263.

---

## Authorization Matrix

| Capability | Permission | Official name | Access level | App Review | Business Verification | Requested initially | Write implied | MoxDOP op |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Business inventory | business_management | business_management | Advanced for external clients | Often yes | May be required externally | YES | NO | Discover Businesses |
| Ad Account inventory / future Insights | ads_read | ads_read | Advanced for external clients | Often yes | May be required externally | YES | NO | Discover Ad Accounts / Prompt 24 |
| Write ads | ads_management | ads_management | — | — | — | **NO** | YES | Forbidden |

## Token Matrix

| Value | Purpose | Owner | App/tenant | Encrypted | Persisted | Expiry | Exchange | Validation | UI | Queue | Logs |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| App ID | OAuth client | Deployment | App | No | Env/config | N/A | N/A | N/A | configured? | No | No |
| App Secret | OAuth + debug | Deployment | App | Env | Env/config | N/A | N/A | app token | No | No | No |
| Login config_id | FLFB dialog | Deployment | App | No | Env/config | N/A | N/A | N/A | No | No | No |
| OAuth state | CSRF | Attempt row hash | Tenant attempt | Hash only | Attempt | TTL | N/A | N/A | No | No | No |
| Authorization code | One-time | Transient | Tenant | N/A | No | Seconds | → access token | N/A | No | No | No |
| User / long-lived / BISUAT access token | Graph auth | Integration credential | Tenant | YES | YES | Provider | fb_exchange_token | debug_token | Status only | No | No |
| App access token (id\|secret) | debug_token | Deployment | App | In-memory | No | N/A | N/A | N/A | No | No | No |

## Token State Matrix

| Scenario | Integration | Permission | Discovery allowed | Operator action | Inventory |
| --- | --- | --- | --- | --- | --- |
| No app config | not_configured | — | No | Configure | Preserved |
| Not authorized | authorization_required | — | No | Connect Meta | Preserved |
| Valid token | connected | granted | Yes if covered | Discover | Preserved |
| Valid / missing permission | permission_required | missing listed | No for missing ops | Reauthorize | Preserved (not zero) |
| Expired/revoked | reauth_required | — | No | Reauthorize | Preserved |
| Validation unavailable | prior status kept | — | Prior | Retry later | Preserved |
| Reauthorized | connected | updated | Yes | Discover | Preserved |

## Business / Selection / Ad Account / act_ / Discovery / Reconciliation matrices

Documented in code + tests; summary:

- META_BUSINESS: container, selectable for discovery, not bindable, not DigitalAsset, not collection root
- META_AD_ACCOUNT: discovery candidate, Prompt 23 bindable, Prompt 24 root, not auto DigitalAsset
- act_ / digits → one canonical `act_{digits}`
- COMPLETE edge may mark access_lost for that business+edge; PARTIAL/FAILED never negatively reconcile that edge
- Same account multiple Businesses → one ExternalResource

## External Meta readiness checklist

| Item | Status |
| --- | --- |
| Meta App + FLFB product | MANUAL / UNKNOWN (dashboard) |
| Login configuration (config_id) | Env `META_LOGIN_CONFIGURATION_ID` |
| Valid OAuth redirect URI | APP_URL callback / override |
| App mode / publishing | MANUAL |
| ads_read + business_management Advanced Access | MANUAL |
| App Review | MANUAL |
| Business Verification | MANUAL |
| Marketing API access | MANUAL |
| Privacy policy / data deletion callback | MANUAL — mark production blocker if required by app type |
| Deauthorization callback | MANUAL |

## Collection handoff

| Concept | P21 | P22 | P23 | P24 | P25 |
| --- | --- | --- | --- | --- | --- |
| Authorization | Ownership | REAL | — | — | — |
| Business/Ad Account inventory | Architecture | REAL | — | — | — |
| Binding | Foundation | No create | REAL | uses | uses |
| Collector / backfill | NOT YET | NOT YET | NOT YET | REAL | REAL |
