# Google Integration Setup

> Configure provider credentials once in MoxDOP Admin; authorize accounts separately.  
> Dayanak: ADR-039, ADR-040

## Purpose

MoxDOP connects **one** Moximu Google account via Settings → Integrations → Google, then discovers:

* Search Console properties
* GA4 properties
* Google Ads accounts
* Google Business Profile locations (optional / setup-gated)

No customer-level OAuth. After binding External Resources to Digital Assets, use **Collect live data** on the asset workspace for Binding-based Google collection (Search Console / GA4 / Ads / GBP).

## Two credential categories (canonical)

### A. Provider / application credentials (Admin-managed)

* OAuth Client ID
* OAuth Client Secret
* Google Ads Developer Token

Configured in **Settings → Integrations → Google → Configure**.  
Encrypted in `core_integration_credentials` with `credential_type = provider`.  
**Survive Disconnect.** Prefer Admin Panel configuration for normal operation.

### B. Authorization tokens (OAuth-generated)

* access token
* refresh token
* expiry / granted scopes

Obtained only via **Authorize Google**.  
Encrypted with `credential_type = authorization`.  
**Never** shown or editable in UI. Cleared by **Disconnect Google account**.

## Google Cloud project

1. Create/select a Google Cloud project.
2. Configure **OAuth consent screen** (Internal if Workspace-only; External + test users for personal Google).
3. Create **OAuth client ID** type **Web application**.
4. Authorized redirect URI — copy the **OAuth Redirect URI** shown on Settings → Integrations → Google (copyable).  
   It is derived automatically from `APP_URL` + the named callback route (no manual typing).

Examples:

```text
APP_URL=http://127.0.0.1:8000
→ http://127.0.0.1:8000/integrations/google/callback

APP_URL=https://dop.moximu.com
→ https://dop.moximu.com/integrations/google/callback
```

5. Enable APIs:

| API | Required for |
|-----|--------------|
| Search Console API | Search Console discovery |
| Google Analytics Admin API | GA4 property discovery |
| Google Ads API | Ads account discovery |
| My Business Account Management API | GBP accounts (optional) |
| My Business Business Information API | GBP locations (optional) |

## Application credentials (preferred: Admin UI)

1. Admin → Settings → Integrations → Google.
2. Click **Configure**.
3. Enter **OAuth Client ID**, **OAuth Client Secret**, and **Google Ads Developer Token**.
4. Save.

After save:

* Client ID may be shown (useful for verification).
* Client Secret and Developer Token show as **Configured** only (write-only; blank edit preserves stored values).
* Use explicit Clear toggles to delete a stored secret deliberately.

## Environment fallback (optional)

Deployment teams may still set:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
# Optional override only. Normal installs derive callback from APP_URL automatically.
# GOOGLE_REDIRECT_URI=
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_API_VERSION=v25
GOOGLE_INCLUDE_GBP_SCOPE=false
GOOGLE_GBP_DISCOVERY_ENABLED=false
```

**Resolution precedence** (per Client ID / Secret / Ads token field):

1. Integration encrypted **provider** credential (database) — via **Configure**
2. Environment / `config/moxdop.php` fallback
3. Missing → Setup required / Incomplete

UI shows **Configured by environment** when a value comes from env and not from the database.  
Env secrets are **not** copied into the database automatically.

`GOOGLE_ADS_API_VERSION` remains deployment/system config (default **v25**); it is not a secret input in Admin.

**OAuth Redirect URI** is not a secret and is not Admin-typed. Canonical resolver: `APP_URL` + `integrations.google.callback` path. Optional `GOOGLE_REDIRECT_URI` only for unusual deployments. Authorize + token exchange + Admin display always use the same resolver.

**Google has one configuration path:** Settings → Integrations → Google → **Configure**. Do not use generic Integration Edit KeyValue/JSON for Google secrets.

## OAuth scopes requested

Default:

* `https://www.googleapis.com/auth/webmasters.readonly`
* `https://www.googleapis.com/auth/analytics.readonly`
* `https://www.googleapis.com/auth/adwords`  
  (Google Ads has **no** separate readonly scope; DOP still performs no Ads mutations.)

Optional GBP (off by default):

* `https://www.googleapis.com/auth/business.manage`  
  Only when `GOOGLE_INCLUDE_GBP_SCOPE=true`.

## Google Ads developer token

Required for Ads discovery (`customers:listAccessibleCustomers`).

1. Sign in to a Google Ads **manager** account.
2. Open **API Center**.
3. Apply for / copy **developer token**.
4. Paste into Admin **Configure** (preferred) or set `GOOGLE_ADS_DEVELOPER_TOKEN` as fallback.

If missing: Search Console + GA4 can still work; Ads capability shows setup/missing developer token.

## Google Business Profile prerequisites

GBP APIs often remain at **quota 0** until Google approves Business Profile API access.

Until then:

* leave `GOOGLE_INCLUDE_GBP_SCOPE=false`
* leave `GOOGLE_GBP_DISCOVERY_ENABLED=false`
* GBP capability shows setup/unavailable
* does **not** block Search Console / GA4 / Ads

When approved:

1. Enable the GBP APIs listed above.
2. Set both GBP env flags to `true`.
3. Re-authorize Google (to grant `business.manage`).
4. Refresh resources.

## In-app steps

1. Admin → Settings → Integrations → Add integration → **Google** (if not present).
2. Open Google Integration (the workspace).
3. Copy **OAuth Redirect URI** → Google Cloud → OAuth Web Client → Authorized redirect URIs.
4. **Configure** → Client ID / Client Secret / Ads developer token → Save.
5. Confirm Application configuration = **Complete**.
6. **Authorize Google** (offline access / consent).
7. **Test connection**.
8. **Refresh resources**.
9. Review Resources grouped/filtered by capability.
10. Open a Digital Asset → Provider resources → bind compatible resources.

No JSON. No generic KeyValue credential entry. No second Google settings path.

## Disconnect vs remove provider configuration

| Action | Clears authorization tokens | Clears Client ID/Secret / Ads token | Keeps Integration + resources identity |
|--------|-----------------------------|-------------------------------------|----------------------------------------|
| Disconnect Google account | Yes | No | Yes (resources marked unavailable) |
| Remove provider configuration | No | Yes (DB provider row only) | Yes |

## Security notes

* Provider secrets and OAuth tokens use Laravel encrypted cast (`encrypted:array`) and remain `Hidden`
* Distinct rows: `provider` vs `authorization` under `(integration_id, credential_type)` unique
* Write-only secrets; never shown in tables/logs/HTML after save
* OAuth `state` validated
* Callback strips code from browser flow via server redirect
* Admin-only configure / authorize / disconnect / remove provider configuration
* External access remains READ-ONLY in product behavior
* `APP_KEY` stays environment-managed (never Admin UI)

## Testing without live Google

CI uses HTTP fakes/mocks. Real Google credentials are not required for PHPUnit.
