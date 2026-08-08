# Google Integration Setup

> Authenticate once at agency level, bind many resources to Digital Assets.  
> Dayanak: ADR-039

## Purpose

MoxDOP connects **one** Moximu Google account via Settings → Integrations → Google, then discovers:

* Search Console properties
* GA4 properties
* Google Ads accounts
* Google Business Profile locations (optional / setup-gated)

No customer-level OAuth. No metric collection in this milestone.

## Google Cloud project

1. Create/select a Google Cloud project.
2. Configure **OAuth consent screen** (Internal if Workspace-only; External + test users for personal Google).
3. Create **OAuth client ID** type **Web application**.
4. Authorized redirect URI (must match env exactly):

```text
{APP_URL}/integrations/google/callback
```

Example local:

```text
http://127.0.0.1:8000/integrations/google/callback
```

5. Enable APIs:

| API | Required for |
|-----|--------------|
| Search Console API | Search Console discovery |
| Google Analytics Admin API | GA4 property discovery |
| Google Ads API | Ads account discovery |
| My Business Account Management API | GBP accounts (optional) |
| My Business Business Information API | GBP locations (optional) |

## Environment variables

Put values only in `.env` (placeholders exist in `.env.example`):

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/integrations/google/callback"
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_API_VERSION=v25
GOOGLE_INCLUDE_GBP_SCOPE=false
GOOGLE_GBP_DISCOVERY_ENABLED=false
```

* Never commit real secrets.
* Client secret is agency/system config — never stored per Digital Asset.
* If client ID/secret missing, UI shows **Not configured / Setup required** (no exception crash).
* `GOOGLE_ADS_API_VERSION` defaults to **v25** (current Google Ads API major as of this preflight). All active Ads REST calls — central Integration discovery and legacy CoreConnection probe/collect — read this single config value. Prefer the latest supported major from [Google Ads API deprecation & sunset](https://developers.google.com/google-ads/api/docs/sunset-dates).

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
4. Set `GOOGLE_ADS_DEVELOPER_TOKEN`.

If missing: Search Console + GA4 can still work; Ads capability shows **Setup required**.

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
2. Open Google Integration.
3. Confirm app configuration status.
4. **Authorize** (offline access / consent).
5. **Test connection**.
6. **Refresh resources**.
7. Review Resources grouped/filtered by capability.
8. Open a Digital Asset → Provider resources → bind compatible resources.

## Security notes

* Tokens encrypted in `core_integration_credentials`
* Write-only; never shown in UI
* OAuth `state` validated
* Callback strips code from browser flow via server redirect
* Admin-only authorize / disconnect
* External access remains READ-ONLY in product behavior

## Testing without live Google

CI uses HTTP fakes/mocks. Real Google credentials are not required for PHPUnit.
