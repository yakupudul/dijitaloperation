# GA4 DATA CONTRACT V1

| Field | Value |
| --- | --- |
| Contract version | `1` |
| Status | **FROZEN FOR COLLECTION IMPLEMENTATION** |
| Date | 2026-08-13 |
| Based on freeze tag | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Canonical product branch | `main` |
| Audit branch | `feature/data-contract-ga4` |
| Scope | Frozen Google Analytics (GA4) operator workspace under `/app` Demo Mode IA |
| Runtime product code changed by this milestone | **NONE** |

Future semantic changes require **v2** or an explicitly documented contract amendment. Do not silently mutate meaning.

Primary official references used for provider field names (not blogs):

- [GA4 Data API Dimensions & Metrics (`api-schema`)](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema)
- [Data API `properties.runReport`](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport)
- [Data API `checkCompatibility`](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/checkCompatibility)
- [Admin API `Property` resource](https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties)
- Funnel capability: Data API **v1alpha** `runFunnelReport` (not used by frozen Demo journeys as-is)

Installed Google Analytics PHP client libraries: **none** as direct Composer packages. Existing code calls REST via `GoogleApiClient` / HTTP (`analyticsdata.googleapis.com/v1beta`, `analyticsadmin.googleapis.com/v1beta`).

---

## 1. Purpose

Define **exactly** what the frozen GA4 product needs from Google Analytics and from MoxDOP domains **before** any production collector, warehouse table, migration, or OAuth change.

This contract is the binding between:

```text
Frozen GA4 UI
  → Provider collection
  → Normalized storage (future)
  → Future Evidence / Findings
```

The future GA4 collector **must not decide** what data MoxDOP wants. This document already decided.

**Hard boundary of this milestone:** audit + documentation only. No collectors, migrations, live Customer API pulls, UI redesign, Evidence/Findings implementation, or provider writes.

---

## 2. Frozen UI Scope

### Verified primary IA

Source: `AnalyticsPage::$allowedTabs`, `resources/views/livewire/demo/analytics/overview.blade.php`, `docs/product/website/GA4.md`, freeze tests.

| Tab key | Operator label | Present in freeze |
| --- | --- | --- |
| `overview` | Overview | YES |
| `measurement` | Measurement | YES (subs: Business actions · Events · Streams · Data quality) |
| `acquisition` | Acquisition | YES |
| `behavior` | Behavior | YES |
| `journeys` | Journeys | YES |
| `operations` | Operations | YES (subs: Findings · Recommendations · Tasks · Outcomes) |

**Relationships** is **not** a primary sidebar tab. It is rendered **inside Overview** (`tabs/relationships.blade.php`). Legacy URL `tab=relationships` remaps to `overview`.

Legacy remaps (not primary IA): `landing_pages`/`engagement`/`devices` → `behavior`; `key_events`/`events` → `measurement`; `sources` → `acquisition`.

### Supporting surfaces audited

- Route: `demo.analytics` → `App\Livewire\Demo\Assets\AnalyticsPage`
- Fixtures: `App\Support\Demo\Ga4WorkspaceFixtures`
- Shared period: `InteractsWithDemoPeriod` + `DemoPeriod` + `period-bar`
- Drawers: attention, landing, finding, event, business action
- Existing runtime (not Demo UI): `Ga4BoundCollector`, `Ga4Discoverer`, `Ga4ConnectionProbeService`

### Explicit non-goals of the frozen product (for collection)

- No GA4 Admin/Data **writes**
- No user-level / client-id / PII streams
- No Measurement Score / Data Quality Score inventions
- No equating GA4 key events with MoxDOP Business Actions
- No silent merge of GA4 `sessionCampaignName` with Google Ads campaign entities

---

## 3. Source Classification

Use these exact classes on every requirement:

| Class | Meaning |
| --- | --- |
| `GA4_DATA_API` | Provider-measured report metrics/dimensions via Analytics Data API |
| `GA4_ADMIN_API` | Provider metadata / configuration read via Analytics Admin API |
| `MOXDOP_DERIVED` | Calculated in MoxDOP from stored base facts (ratios, shares, deltas) |
| `MOXDOP_MAPPING` | Operator/MoxDOP mapping of provider events → Business Actions |
| `CROSS_ASSET` | Sibling Digital Asset context (Website content roles, Ads/Meta links, Findings pointers) |
| `OPERATOR_MAINTAINED` | Explicit operator/agency configuration inside MoxDOP |
| `OPERATIONS_DOMAIN` | Findings / Recommendations / Work / Outcomes |
| `UNAVAILABLE` | Cannot be honestly produced from allowed sources |
| `DEMO_ONLY` | Exists only as deterministic Demo fixtures; not a production data promise |

A requirement may list **multiple** classes.

---

## 4. UI Requirement Matrix

Column legend matches §52 of the audit prompt. Exact provider API names are from official `api-schema` / Admin `Property` unless marked `REQUIRES PROVIDER VERIFICATION`.

### 4.1 Shell / identity / period

| Requirement ID | Workspace | UI component | Operator question | Semantic definition | Demo source | Source class | Provider API | Dimensions | Metrics | Mapping | Formula | Grain | Filters | Date | Comparison | Timezone | Dependencies | R/O/C | Provenance | Missing | Dataset candidate | Existing coverage | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_SHELL_IDENTITY | All | Header title / brand / Connected | Which property am I operating? | GA4 Digital Asset display + Brand link | `identity.*` | `GA4_ADMIN_API` + `CROSS_ASSET` + `OPERATOR_MAINTAINED` | Admin `properties.get` + Binding | — | — | Asset binding | — | Property | — | n/a | n/a | Property `timeZone` | Brand, Digital Asset | REQUIRED | Provider metadata + domain | Show Unavailable / Not connected | `ga4_property_metadata` | Discoverer lists properties; probe checks access; Demo fakes IDs | Measurement ID from Web stream |
| GA4_SHELL_RELATIONSHIP_LINE | All | “Measures · Website” | What does this property measure? | Semantic measures relationship to Website asset | `identity.relationship_line` | `CROSS_ASSET` | — | — | — | Asset graph | — | Brand estate | — | n/a | n/a | — | Website asset id | REQUIRED | Cross-asset | Hide link if unlinked | — | Demo only | No Data API |
| GA4_SHELL_FRESHNESS_CHIPS | All | Freshness chips (GA4/Website/Ads/Meta/Brand) | How fresh are related signals? | Last successful collection ages | `freshness[]` | `OPERATIONS_DOMAIN` + `CROSS_ASSET` | — | — | — | — | Age from Run timestamps | Run | — | n/a | n/a | Store TZ + display TZ | Collection Runs | CONDITIONAL | Operations / runs | “No collection yet” ≠ stale zero | — | Partial (Runs exist) | Not provider metrics |
| GA4_SHELL_PERIOD | overview/measurement/acquisition/behavior/journeys | Shared period bar | Which reporting window? | Inclusive calendar days in **property reporting timezone** | `DemoPeriod` | `OPERATOR_MAINTAINED` (selection) + `GA4_ADMIN_API` (TZ) | — | `date` for storage | — | — | Preset → [start,end] | Daily facts | Presets + custom | Inclusive start/end | Previous equal length | **Property `timeZone` — never UTC-by-default** | Metadata TZ | REQUIRED | Operator selection | Invalid range error | all daily datasets | DemoPeriod Europe/Berlin; live collector ComparisonPeriod currently UTC (**gap**) | Presets: last_7/14/28/30/90, this_month, last_month, custom |
| GA4_SHELL_COMPARE_TOGGLE | same | Compare · on | Show previous-period deltas? | Immediately preceding equal-length range | `previousBounds` | `MOXDOP_DERIVED` | — | — | — | — | See §16 | Same as current | — | Current + previous | On/off | Property TZ | Period | REQUIRED | Derived | If compare off, omit deltas | — | Demo | — |

### 4.2 Overview

| Requirement ID | Workspace | UI component | Operator question | Semantic | Demo | Source class | Provider | Dims | Metrics | Mapping | Formula | Grain | Filters | Date | Compare | TZ | Deps | R/O/C | Provenance | Missing | Dataset | Coverage | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_OVERVIEW_USERS | Overview | KPI “Users” | How many users in period? | Property-level distinct users in range | `glance.users` | `GA4_DATA_API` | Data `runReport` | none (or `date` for daily) | **`totalUsers`** (canonical V1) | — | Sum daily `totalUsers` **is not additive across days** — for range totals request range aggregate OR store carefully (see §7) | Property × range; daily series optional | — | Shared range | **Relative % delta** vs previous | Property TZ | — | REQUIRED | Provider measured | Missing ≠ 0 | `ga4_property_daily` + range-time range report | Collector summary uses `totalUsers` | **UI SEMANTIC REVIEW REQUIRED LATER** vs `activeUsers` |
| GA4_OVERVIEW_SESSIONS | Overview | KPI “Sessions” | How many sessions? | Count of `session_start` sessions | `glance.sessions` | `GA4_DATA_API` | `runReport` | `date` | `sessions` | — | Sum of daily sessions = range sessions | Property daily | — | Shared | Relative % | Property TZ | — | REQUIRED | Provider | Missing ≠ 0 | `ga4_property_daily` | Collector has sessions | — |
| GA4_OVERVIEW_BUSINESS_ACTIONS | Overview | KPI “Business actions” | How many mapped outcomes? | Count of events mapped to Business Actions with state Mapped | `glance.business_actions` = Lead Form + Phone only | `MOXDOP_MAPPING` + `GA4_DATA_API` + `MOXDOP_DERIVED` | `runReport` | `date`,`eventName` | `eventCount` | BA map | Sum `eventCount` for mapped event names only | Property daily events | Mapping filter | Shared | Relative % of mapped count | Property TZ | Mapping config | CONDITIONAL (requires ≥1 mapped action) | Mapped + measured | **Not mapped ≠ 0 actions** | `ga4_event_daily` | Not in collector | WhatsApp observed but excluded |
| GA4_OVERVIEW_MEASUREMENT_STATE | Overview | KPI “Measurement” | Is measurement trustworthy? | Qualitative rollup of BA mapping health | Partial · debt present | `MOXDOP_MAPPING` + `MOXDOP_DERIVED` | — | — | — | BA states | Rules over mapping states (not a GA4 metric) | Config | — | Soft (interruption may use dates) | None as % | — | Mapping + quality checks | REQUIRED | Derived / operator | “Unknown” if no config | — | Demo | No fake score |
| GA4_OVERVIEW_NEEDS_ATTENTION | Overview | Needs attention list + drawer | What needs agency review? | Finding-like attention cards | `needs_attention` | `OPERATIONS_DOMAIN` (+ Evidence deps) | — | — | — | Finding rules later | — | Finding | — | May cite windows | — | Property TZ in copy | Findings | CONDITIONAL | Operations | Empty list OK | — | Demo fixtures | Evidence deps in §14 |
| GA4_OVERVIEW_ACQUISITION_MIX | Overview | Acquisition mix bars | Channel mix of sessions? | Session default channel share | `acquisition_mix` | `GA4_DATA_API` + `MOXDOP_DERIVED` | `runReport` | `sessionDefaultChannelGroup` | `sessions` | — | share = channel_sessions / property_sessions | Channel × range (from daily) | — | Shared | Optional | Property TZ | Property sessions | REQUIRED | Provider + derived share | Missing channels omitted; not zero-filled inventively | `ga4_acquisition_channel_daily` | Collector top-25 range only | Session-scoped |
| GA4_OVERVIEW_SESSIONS_TREND | Overview | Sessions trend chart | Sessions & mapped actions over time? | Daily sessions + daily mapped BA counts | `performance_trend` | `GA4_DATA_API` + `MOXDOP_MAPPING` + `MOXDOP_DERIVED` | `runReport` | `date` | `sessions`; events via map | BA map | Chart series from daily facts | Daily | — | Shared | Label only | Property TZ | Events + map | REQUIRED | Provider + mapped | Gap days = missing points, not 0 unless measured 0 | `ga4_property_daily`, `ga4_event_daily` | No daily in collector | Sampling display ≠ storage |
| GA4_OVERVIEW_LANDING_PULSE | Overview | Landing pulse table | Which landings matter? | Top landing paths with sessions, engaged rate, mapped actions, Website role | `landing_pulse` | `GA4_DATA_API` + `MOXDOP_MAPPING` + `CROSS_ASSET` + `MOXDOP_DERIVED` | `runReport` | `landingPage` | `sessions`,`engagedSessions` | BA map + Website roles | engaged_rate = engagedSessions/sessions; actions from mapped events on landing | Landing × range | Presentation top-N | Shared | None on table | Property TZ | Website roles | REQUIRED | Mixed | Unmapped actions → Unavailable | `ga4_landing_page_daily` + event×landing | Collector landing top-25; no BA; no roles | Collect full; present top-N |
| GA4_OVERVIEW_BA_MATRIX | Overview | Business action matrix | Mapping health per BA? | BA → event → state | `business_actions` | `MOXDOP_MAPPING` + `GA4_DATA_API` | events | `eventName` | `eventCount` | BA taxonomy | — | Action × range | — | Shared | None | Property TZ | Map | REQUIRED | Mapped | Not mapped / Unavailable states | mapping + `ga4_event_daily` | None | Taxonomy from Demo: Lead Form, WhatsApp, Phone, Appointment |
| GA4_OVERVIEW_JOURNEY_SNAPSHOT | Overview | Journey snapshot (4 paths) | Notable paths? | Aggregated multi-step paths | `journeys` slice | `DEMO_ONLY` / see §12 | — | — | — | Funnel config | — | Path | — | Shared | None | — | — | OPTIONAL / currently DEMO | Demo | Empty if unsupported | — | Demo | **Not** honest path reconstruction from simple aggregates |
| GA4_OVERVIEW_RECENT_OUTCOMES | Overview | Recent outcomes | Did work change anything? | Observational outcome states | `recent_outcomes` | `OPERATIONS_DOMAIN` | — | — | — | — | — | Outcome | — | — | — | — | Tasks | CONDITIONAL | Operations | Empty OK | — | Demo | No causal claim |
| GA4_OVERVIEW_RELATIONSHIPS | Overview | Relationship summary | Measures / evidence sinks? | Asset graph + connection ids | `relationships` | `CROSS_ASSET` + `GA4_ADMIN_API` | Admin ids | — | — | Asset links | — | Brand | — | n/a | n/a | — | Estate | REQUIRED | Cross-asset | Unlinked state | metadata | Demo | Property/measurement IDs shown |
| GA4_OVERVIEW_OPPORTUNITY | Overview | Opportunity card | Where to act next? | Ops opportunity fixture | OpportunityFixtures | `OPERATIONS_DOMAIN` | — | — | — | — | — | — | — | — | — | — | — | OPTIONAL | Operations | Hide if none | — | Demo | — |

### 4.3 Measurement

| Requirement ID | Workspace | UI | Question | Semantic | Demo | Source class | Provider | Dims | Metrics | Mapping | Formula | Grain | R/O/C | Missing | Dataset | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_MEAS_BA_TABLE | Measurement · business_actions | BA→event table + drawer | Are outcomes mapped? | Operator mapping + event counts | matrix | `MOXDOP_MAPPING` + `GA4_DATA_API` | Data | `eventName` | `eventCount` | REQUIRED | — | Action × range | REQUIRED | Unavailable count when no event | `ga4_event_daily` + mapping store | “Operator-configured · not inferred” |
| GA4_MEAS_TRUST_CHIPS | Measurement | Trust chips | Health at a glance | Derived from BA states | trust_chips | `MOXDOP_DERIVED` | — | — | — | BA states | — | — | REQUIRED | — | — | Not provider |
| GA4_MEAS_EVENTS | Measurement · events | Events table + drawer | What events exist & map? | Catalog of relevant events + counts + map state | events[] | `GA4_DATA_API` + `MOXDOP_MAPPING` | Data | `eventName` | `eventCount` | Reverse map | — | Event × range | REQUIRED | Unavailable for never-seen | `ga4_event_daily` | Do not collect all GA4 events forever — interest set = mapped ∪ observed CTA ∪ funnel |
| GA4_MEAS_STREAMS | Measurement · streams | Data streams table | Is the web stream receiving? | Stream identity + measurement id + status | streams[] | `GA4_ADMIN_API` (+ freshness derived) | Admin dataStreams | — | — | — | Last-hit may be derived from data freshness | Stream | REQUIRED | No streams → Unavailable | `ga4_property_metadata` | Measurement ID non-secret |
| GA4_MEAS_QUALITY | Measurement · quality | Data quality checks | What measurement debt exists? | Checklist over mapping + hygiene | data_quality | `MOXDOP_DERIVED` + `MOXDOP_MAPPING` + `GA4_DATA_API` | mixed | — | — | — | Rule engine later | — | CONDITIONAL | States not scores | — | No fake DQ score |
| GA4_MEAS_INTERRUPTIONS | Measurement | Interruption alert | Was a key signal silent? | Multi-hour/day gap in mapped primary event while sessions continue | interruptions | `MOXDOP_DERIVED` + `GA4_DATA_API` | Data | `date` (+ finer if needed) | `eventCount`,`sessions` | Primary BA | Heuristic on daily facts | Daily (hourly REQUIRES VERIFICATION if needed) | CONDITIONAL | Candidate ≠ proven outage | `ga4_property_daily`,`ga4_event_daily` | Demo uses ~36h story from daily weights |
| GA4_MEAS_DUPLICATES | Measurement · quality | Duplicate candidates | Ads+GA4 double count risk? | Cross-asset advisory | duplicates | `CROSS_ASSET` + `MOXDOP_DERIVED` | — | — | — | — | Qualitative | — | OPTIONAL | Review state | — | Not a GA4 metric |
| GA4_MEAS_UTM_HYGIENE | Measurement · quality | UTM hygiene card | Campaign tagging debt? | Share of sessions with campaign `(not set)` / unavailable | utm_hygiene | `GA4_DATA_API` + `MOXDOP_DERIVED` | Data | `sessionCampaignName` | `sessions` | — | unavailable_pct = sessions_not_set / sessions; **pp delta** vs prior | Campaign × range | REQUIRED | Missing report ≠ 0% | `ga4_campaign_daily` | Trend 6%→18% is pp story |
| GA4_MEAS_REFERRALS | Measurement · quality | Referral review table | Self-referral risk? | Referral source/medium sessions + flags | referrals | `GA4_DATA_API` + `MOXDOP_DERIVED` + `OPERATOR_MAINTAINED` (exclusion list intent) | Data | `sessionSource`,`sessionMedium` | `sessions` | Optional site host list | Flag when source matches property host | Source/medium × range | CONDITIONAL | — | `ga4_source_medium_daily` | Admin exclusion write **out of scope** (read-only) |

### 4.4 Acquisition

| Requirement ID | Workspace | UI | Question | Semantic | Source class | Provider dims | Metrics | Scope | Grain | R/O/C | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_ACQ_CHANNELS | Acquisition | Channels table | Session channel performance? | **Session** default channel grouping | `GA4_DATA_API` + `MOXDOP_MAPPING` + `CROSS_ASSET` + `MOXDOP_DERIVED` | `sessionDefaultChannelGroup` | `sessions`; mapped BA via events | **Session acquisition — NOT first-user** | Channel daily | REQUIRED | Share derived; related asset links cross-asset |
| GA4_ACQ_SOURCE_MEDIUM | Acquisition | Source / medium table | Finer traffic breakdown? | Session source/medium | same | `sessionSourceMedium` (or `sessionSource`+`sessionMedium`) | `sessions` + mapped actions | Session | S/M daily | REQUIRED | Combined dimension matches UI “google / organic” |
| GA4_ACQ_CAMPAIGNS | Acquisition | Campaigns (measured) | Campaign measurement context? | **GA4** `sessionCampaignName` measurement — **not** Ads entity truth | `GA4_DATA_API` + `CROSS_ASSET` + `MOXDOP_MAPPING` | `sessionCampaignName` (+ display source) | `sessions` + mapped actions | Session | Campaign daily | REQUIRED | `(not set)` is first-class; Unavailable actions when unattributable |
| GA4_ACQ_USER_SCOPE | Acquisition | — | User-acquisition dimensions? | **Not used** by frozen UI | — | Do **not** collect `firstUser*` for V1 UI | — | — | — | NOT REQUIRED | Keep session vs user distinction explicit |

### 4.5 Behavior

| Requirement ID | Workspace | UI | Semantic | Source class | Provider | Formula | Grain | R/O/C | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_BEH_ENGAGEMENT_RATE | Behavior | Engagement rate card | Engaged sessions / sessions | `GA4_DATA_API` + prefer `MOXDOP_DERIVED` for reaggregation | `engagedSessions`,`sessions` (or provider `engagementRate` for single aggregate only) | engaged/sessions; display % | Property × range | REQUIRED | Never average % rows |
| GA4_BEH_AVG_ENGAGEMENT_TIME | Behavior | Avg engagement time | Average engagement duration display | `GA4_DATA_API` + `MOXDOP_DERIVED` | Store `userEngagementDuration` (+ users or sessions) | **Canonical V1:** `userEngagementDuration / activeUsers` **OR** confirm vs GA4 UI — **UI SEMANTIC REVIEW / REQUIRES PROVIDER VERIFICATION** for exact GA4 “Average engagement time” definition used in UI copy | Property × range | REQUIRED | Demo shows `1m 42s`; format mm:ss |
| GA4_BEH_VIEWS_PER_SESSION | Behavior | Views / session | Views per session | `GA4_DATA_API` or derived | Prefer provider `screenPageViewsPerSession` **or** derive `screenPageViews/sessions` | Same | Property | REQUIRED | VERIFIED metric name exists |
| GA4_BEH_APPOINTMENT_COMPLETION | Behavior | Appointment completion | BA completion rate | `MOXDOP_MAPPING` | — | Unavailable when Appointment not mapped | — | CONDITIONAL | Demo: Unavailable / Not mapped |
| GA4_BEH_LANDING_TABLE | Behavior | Landing table + drawer | Landing performance + Website attention | `GA4_DATA_API` + `MOXDOP_MAPPING` + `CROSS_ASSET` | `landingPage`,`sessions`,`engagedSessions` + mapped events | engaged_rate; BA counts | Landing daily | REQUIRED | Path without query string |
| GA4_BEH_DEVICES | Behavior | Devices bars | Device mix of sessions | `GA4_DATA_API` + derived share | `deviceCategory`,`sessions` | share_pct | Device daily | REQUIRED | Mobile/Desktop/Tablet |

### 4.6 Journeys

| Requirement ID | Decision class | Notes |
| --- | --- | --- |
| GA4_JOURNEY_AGG_PATHS | `DEMO_ONLY` / **UNSUPPORTED** as provider path reconstruction | Frozen cards show multi-step page paths with sessions & mapped actions. Aggregate Data API **does not** reconstruct user paths. Do not imply pathing from landing aggregates. |
| GA4_JOURNEY_CONFIGURED_FUNNELS | `OPERATOR_MAINTAINED` + optional `GA4_DATA_API` alpha funnel **or** event-stage derivation | Blade supports “Configured funnels” but Demo fixtures currently return **list paths only** (`funnels` empty). Production needs explicit funnel config. Mark: **OPERATOR/MOXDOP FUNNEL CONFIGURATION REQUIRED** |
| GA4_JOURNEY_RATES | `MOXDOP_DERIVED` when funnel configured | See §12 — no ratio averaging; zero denominator → Unavailable |

### 4.7 Operations

| Requirement ID | Source class | Provider data needed? | Notes |
| --- | --- | --- | --- |
| GA4_OPS_FINDINGS | `OPERATIONS_DOMAIN` | Indirect Evidence deps only | Table + drawer |
| GA4_OPS_RECOMMENDATIONS | `OPERATIONS_DOMAIN` | No | Linked to findings |
| GA4_OPS_TASKS | `OPERATIONS_DOMAIN` | No | Work items |
| GA4_OPS_OUTCOMES | `OPERATIONS_DOMAIN` | May cite GA4 facts observationally | No causal language |

---

## 5. Provider-Measured Requirements

### REQUIRED base metrics (Data API)

| Metric | Official name | Used by |
| --- | --- | --- |
| Sessions | `sessions` | Overview, trend, acquisition, behavior, devices, hygiene |
| Engaged sessions | `engagedSessions` | Landing engagement, property engagement rate inputs |
| Total users | `totalUsers` | Overview Users (canonical V1) |
| Event count | `eventCount` | Events, Business Actions, interruptions |
| Screen/page views | `screenPageViews` | Views/session derivation input (if not using provider ratio) |
| User engagement duration | `userEngagementDuration` | Avg engagement time input |

### REQUIRED dimensions (Data API)

| Dimension | Official name | Scope |
| --- | --- | --- |
| Date | `date` | Daily facts |
| Session default channel group | `sessionDefaultChannelGroup` | Session acquisition |
| Session source / medium | `sessionSourceMedium` | Session acquisition |
| Session campaign | `sessionCampaignName` | Session acquisition |
| Landing page | `landingPage` | Behavior (path; **no** query string required by freeze) |
| Event name | `eventName` | Measurement / BA |
| Device category | `deviceCategory` | Behavior devices |

### OPTIONAL / CONDITIONAL provider metrics

| Metric | Name | Status |
| --- | --- | --- |
| Engagement rate (provider) | `engagementRate` | OPTIONAL convenience for single-range property card; **canonical storage prefers counts** |
| Views per session (provider) | `screenPageViewsPerSession` | OPTIONAL vs derive |
| New users | `newUsers` | **NOT REQUIRED** by frozen UI (collector currently fetches — excess) |
| Key events aggregate | `keyEvents` | **NOT equated to Business Actions**; OPTIONAL measurement context only |
| Active users | `activeUsers` | OPTIONAL pending Users semantic review |

### NOT REQUIRED by GA4 Data Contract V1

- Monetary metrics / `purchaseRevenue` / etc. → **NOT REQUIRED BY GA4 DATA CONTRACT V1** (no frozen GA4 monetary surface)
- `firstUser*` acquisition dimensions
- `landingPagePlusQueryString` (unless Website join later demands it — not freeze-required)
- User-level / clientId / userId
- Realtime API
- Full unrestricted event catalog dump

---

## 6. Provider Metadata Requirements

| Field | Source | Official field | Required? | Why |
| --- | --- | --- | --- | --- |
| Property resource name / ID | Admin `properties.get` | `name` → `properties/{id}` | REQUIRED | Binding + UI |
| Display name | Admin | `displayName` | REQUIRED | Header |
| Reporting timezone | Admin | **`timeZone`** | REQUIRED | Canonical day boundaries |
| Currency code | Admin | `currencyCode` | OPTIONAL for V1 UI | No money UI; may store for future |
| Account parent | Admin | `account` / discovery | REQUIRED for discovery | Discoverer already |
| Web data stream display name | Admin dataStreams | stream display name | REQUIRED | Streams table |
| Measurement ID | Admin WebDataStream | `measurementId` (Web) | REQUIRED | Streams + relationship card |
| Stream ID | Admin | stream resource name/id | REQUIRED | Streams table |
| Stream type | Admin | web vs app | REQUIRED | UI “Web” |
| Key event configuration list | Admin (key events / conversion settings) | Admin key-events APIs | OPTIONAL context | Must stay distinct from Business Actions — **REQUIRES PROVIDER VERIFICATION** of exact Admin resource names at implementation time |

Discovery already uses `accountSummaries.list`. Property timezone requires **`properties.get`** (not returned by accountSummaries alone).

---

## 7. MoxDOP Derived Metrics

| Derived Metric ID | Display name | Formula | Inputs | Aggregation | Null rule | Zero-denominator | Comparison | Formatting | Provenance | Consumer UI |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4_DER_CHANNEL_SHARE | Channel share | `channel_sessions / property_sessions` | sessions | Compute after summing counts | If property_sessions missing → Unavailable | If property_sessions=0 → Unavailable (not 0%) | Optional pp or none | `0.0%` | Derived | Overview mix, Acquisition |
| GA4_DER_ENGAGEMENT_RATE | Engagement rate | `engagedSessions / sessions` | counts | Sum counts then divide | Missing inputs → Unavailable | sessions=0 → Unavailable | **Prefer percentage-point delta**; relative % also allowed if labeled | `0.0%` | Derived (canonical) | Behavior |
| GA4_DER_LANDING_ENGAGED_RATE | Landing engaged rate | same at landing grain | landing counts | Per row from counts | — | — | None in freeze tables | integer % in Demo; prefer 1 decimal in prod | Derived | Landing pulse/table |
| GA4_DER_VIEWS_PER_SESSION | Views / session | `screenPageViews / sessions` OR provider metric | counts | Sum then divide | — | sessions=0 → Unavailable | Relative optional | `0.0` | Derived/provider | Behavior |
| GA4_DER_AVG_ENGAGEMENT_TIME | Avg engagement time | **Pending semantic lock**; candidate `userEngagementDuration / activeUsers` | duration, users | Sum then divide | — | users=0 → Unavailable | Relative optional | `m:ss` | Derived | Behavior |
| GA4_DER_MAPPED_BA_COUNT | Business actions | `Σ eventCount(event ∈ mapped_set)` | events + map | Sum event counts | No mapping → **Unavailable** (not 0) | — | Relative % on counts | integer | Mapped+derived | Overview KPI, trends |
| GA4_DER_BA_RATE_SESSIONS | Business action rate (when shown) | `mapped_ba_count / sessions` | BA, sessions | Sum then divide | No mapping → Unavailable | sessions=0 → Unavailable | Prefer **pp** if shown as rate | `%` | Derived | Landing action_rate in fixtures (UI shows counts primarily) |
| GA4_DER_UTM_UNAVAILABLE_PCT | Campaign unavailable % | `sessions(campaign in {(not set), empty}) / sessions` | campaign daily | Sum then divide | — | sessions=0 → Unavailable | **Percentage-point** delta vs prior | `%` | Derived | UTM hygiene |
| GA4_DER_DEVICE_SHARE | Device share | device_sessions / property_sessions | sessions | Sum then divide | — | — | None | `%` | Derived | Devices |
| GA4_DER_PCT_DELTA | Relative period change | `(current - previous) / previous` | numeric | — | Any null → hide/Unavailable | previous=0 → **Unavailable** (never Infinity%/fake 0%) | — | `+0.0%` | Derived | Overview KPI secondaries |
| GA4_DER_PP_DELTA | Percentage-point change | `current_rate - previous_rate` | rates | — | Any null → Unavailable | — | — | `+0.0 pp` | Derived | Engagement rate, UTM % |

### Users / sessions non-additivity note

- **`sessions`**: daily sums for a property **are** valid for range totals.
- **`totalUsers` / `activeUsers`**: **not** safely summable across days. For property range KPI, either (a) store daily for trends of other metrics and request a **range aggregate** for Users, or (b) accept approximate unique methods — V1 requires **honest range query for Users totals**, not sum-of-daily uniques.

---

## 8. MoxDOP Business Action Mapping

### Intent (from frozen product + fixtures)

```text
GA4 events
  → MoxDOP mapping (operator-configured · not inferred)
  → Business Action types
  → Business Action counts / rates / health states
```

**Business Actions are not a native GA4 metric.**

### Frozen taxonomy (do not invent new types in V1)

| Business Action | Demo GA4 event | Role | Mapping state (Demo) | Counts toward Overview “Business actions”? |
| --- | --- | --- | --- | --- |
| Lead Form | `generate_lead` | Primary | Mapped · Healthy | YES |
| Phone | `phone_click` | Secondary | Mapped · Review | YES |
| WhatsApp | `whatsapp_click` | Secondary | **Not mapped** (event observed) | NO |
| Appointment | *(none discovered)* | — | **Not mapped / Unavailable** | NO |

### Mapping contract

| Topic | Contract |
| --- | --- |
| Provider fields required | `eventName`, `eventCount` at needed grains |
| Mapping source | Operator-maintained MoxDOP configuration (Demo today; Website/GA4 measurement settings intended) |
| Mapping scope | **Digital Asset / measurement-level** for the GA4 property (Brand-visible; not inferred from GA4 key events) |
| Canonical action identity | Stable MoxDOP action id/label (Lead Form, WhatsApp, Phone, Appointment) |
| Aggregation | Sum `eventCount` for mapped event name(s) in period/grain |
| Date support | YES — daily facts required |
| Acquisition support | YES — frozen Acquisition shows mapped actions by channel, source/medium, campaign |
| Landing-page support | YES — Behavior / Landing pulse |
| Journey support | YES when path/funnel configured; else Unavailable |
| Key events relation | Provider key-event flags may inform **measurement context** only — **never auto-create** Business Actions |
| Missing mapping semantics | State = `Not mapped` or `Unavailable`; **do not display 0 Business Actions** for that action; Overview total excludes unmapped |

### Business Action Rate

Frozen UI primarily shows **counts**. Where a rate appears (fixture `action_rate` on landings; Behavior “Appointment completion”):

- **Numerator:** mapped business action event counts (or specific action)
- **Denominator:** **`sessions`** at the same grain (frozen Demo landing rates are session-based; Overview does not show property BA rate)
- **Aggregation:** sum numerator and denominator separately, then divide
- **Zero denominator:** Unavailable
- **Missing mapping:** Unavailable (not 0%)
- **Formatting:** percentage with explicit precision

---

## 9. Acquisition Requirements

| Concept | Required? | Exact dimension | Scope |
| --- | --- | --- | --- |
| Default channel grouping | YES | `sessionDefaultChannelGroup` | **Session** |
| Source | YES (via combo) | `sessionSource` or as part of `sessionSourceMedium` | Session |
| Medium | YES (via combo) | `sessionMedium` | Session |
| Source / medium | YES | `sessionSourceMedium` | Session |
| Campaign | YES | `sessionCampaignName` | Session |
| Sessions | YES | metric `sessions` | — |
| Users by channel | NO for freeze tables | — | Do not require |
| Engaged sessions by channel | OPTIONAL | `engagedSessions` | Useful but not shown in Acquisition table |
| Business Actions by acquisition dims | YES | via `eventName` + acquisition dim + `eventCount` | Session-scoped dims |
| User acquisition (`firstUser*`) | **NO** | — | Must not silently mix |

**Channel values:** provider-defined enumeration (Direct, Organic Search, Paid Social, …). MoxDOP does **not** invent a parallel taxonomy in V1. Unknown/unassigned appear as provider values (e.g. empty/(not set)).

**Cross-network** appears in Demo mix — treat as provider channel value if returned; do not invent.

**Related asset column:** `CROSS_ASSET` navigation only (Google Ads / Meta / GSC) — not GA4 metrics.

---

## 10. Behavior Requirements

| Fact | Required | Notes |
| --- | --- | --- |
| Landing pages | YES | `landingPage`; sessions; engagedSessions; mapped BA; Website content role; Website attention strings |
| Page/screen dump of all pages | NO | Landings only in freeze |
| Engagement cards | YES | Rate, avg time, views/session; Appointment conditional |
| Devices | YES | `deviceCategory` × sessions |
| Query string on landing | NO for V1 freeze | Use `landingPage`; keep normalized path key for Website join |
| Hostname | NOT shown | Optional later for multi-domain; not freeze-required |

---

## 11. Measurement Requirements

| Item | Source |
| --- | --- |
| Property / stream identity | `GA4_ADMIN_API` |
| Event collection counts | `GA4_DATA_API` |
| Key events (provider) | OPTIONAL Admin/Data context — **≠ Business Actions** |
| Business Action mapping | `MOXDOP_MAPPING` / `OPERATOR_MAINTAINED` |
| Measurement completeness / trust chips | `MOXDOP_DERIVED` |
| UTM / referral hygiene | `GA4_DATA_API` + derived |
| Interruptions / duplicates | Derived / cross-asset |
| Website / Ads / Meta relation | `CROSS_ASSET` |

Do not turn qualitative configuration into fake GA4 metrics.

---

## 12. Journey / Funnel Requirements

### Decision table

| Frozen element | Classification |
| --- | --- |
| Aggregated multi-step page paths (Home→Implant→Contact, etc.) | **DEMO-ONLY** / **UNSUPPORTED** as honest provider path reconstruction from standard aggregates |
| Sessions + mapped actions on those cards | Partially **DERIVABLE** only under explicit funnel/path configuration — not automatic |
| Configured funnels UI (blade-ready) | **SUPPORTED WITH MOXDOP CONFIGURATION** (+ provider funnel report **or** stage event aggregates) |
| Stage rates | **DERIVABLE FROM COLLECTED AGGREGATES** once stages defined |
| Cross-asset notes on journeys (LCP Finding) | **CROSS-ASSET** |
| User-level pathing / PII | **UNSUPPORTED** / forbidden |

### Funnel configuration requirement

If production Journeys must show stages:

- Stage name, semantic, provider event/page dependency, mapping dependency, order, inclusion rules, date range, calculation source must be **operator/MoxDOP configured**.
- Marked: **OPERATOR/MOXDOP FUNNEL CONFIGURATION REQUIRED**
- Do **not** pretend GA4 provides these paths automatically.

### Journey rates (when configured)

- Numerator / denominator from stage counts at same period
- Aggregate counts then divide
- Missing stage → Unavailable
- Denominator 0 → Unavailable
- No averaging of step rates across funnels

---

## 13. Cross-Asset Requirements

| Relationship | Class | Persistent need (future) | Provider API for relationship presentation? |
| --- | --- | --- | --- |
| GA4 **measures** Website | `CROSS_ASSET` / MoxDOP domain relationship | Brand estate link: GA4 asset ↔ Website asset | **NO** |
| GA4 **provides Evidence to** Google Ads / Meta / Website Findings | `CROSS_ASSET` | Evidence consumer links | **NO** |
| Landing content roles | `CROSS_ASSET` / operator Website metadata | Join key: normalized path | **NO** |
| Website attention on landings | `OPERATIONS_DOMAIN` + Website | Finding ids / titles by path | **NO** |
| Paid workspace links from campaigns/channels | `CROSS_ASSET` | Heuristic by channel/campaign naming — keep GA4 campaign ≠ Ads campaign entity | **NO** |

### Cross-asset keys (specify only)

| Join | Normalized key |
| --- | --- |
| Website | `brand_id` + normalized `landing_path` (lowercase, strip trailing slash except `/`, no query) |
| Google Ads | date + brand; campaign compare only with explicit mapping — **do not auto-join** on GA4 campaign name alone |
| Meta Ads | same caution |
| Brand | `brand_id` on all facts |
| Digital Asset | `ga4_digital_asset_id`, `property_id` |

---

## 14. Operations-Domain Requirements

Operations UI itself does **not** require new GA4 API collection beyond Evidence dependencies for Finding rules (future).

| Element | Source |
| --- | --- |
| Findings / Recommendations / Tasks / Outcomes | `OPERATIONS_DOMAIN` |
| Needs attention | Operations (+ citations) |

### Future Evidence dependencies (document only — do not implement)

| Finding theme (Demo) | GA4 facts that must exist later |
| --- | --- |
| Lead Form interruption | Daily `generate_lead` (or mapped primary) `eventCount` + property `sessions` |
| WhatsApp unmapped | Observed `whatsapp_click` counts + mapping state |
| UTM hygiene | `sessionCampaignName` × `sessions` |
| Self-referral | `sessionSource`/`sessionMedium` × `sessions` |
| Phone review | `phone_click` counts + Website CTA inventory (`CROSS_ASSET`) |

---

## 15. Date / Timezone Contract

| Topic | Contract |
| --- | --- |
| Canonical reporting timezone | **GA4 Property `timeZone`** from Admin API |
| Shared Date Range presets | last_7, last_14, last_28, last_30, last_90, this_month, last_month, custom |
| Inclusive bounds | start and end inclusive calendar dates in property TZ |
| Date dimension stored | `date` (API `YYYYMMDD` → ISO `YYYY-MM-DD` in property TZ) |
| Minimum stored grain | **Daily** for all trending / recalculating facts |
| Brand TZ ≠ property TZ | **No silent conversion.** Use property TZ for all GA4 reporting periods. Document Brand TZ separately if needed for other domains. |
| Hard rule | **DO NOT ACCIDENTALLY USE UTC FOR GA4 REPORTING PERIODS.** |
| Known gap | `ComparisonPeriod::lastTwentyEightCompleteDays()` currently hard-codes `timezone: UTC` — **must be adapted later** to property TZ |

---

## 16. Previous-Period Comparison Contract

**Definition:** immediately preceding range with **equal day length** in the **property reporting timezone** (`DemoPeriod::previousBounds` / product rule).

| UI element | Comparison type |
| --- | --- |
| Overview Users / Sessions / Business actions | **Relative percentage delta** |
| Engagement Rate | Prefer **percentage-point delta** (document label); relative optional if explicitly labeled |
| UTM unavailable % | **Percentage-point** trend (Demo 6%→18%) |
| Acquisition / Behavior tables | **No row-level compare** in freeze |
| Measurement state / chips | No numeric compare |

### Edge cases (canonical)

| Case | Display |
| --- | --- |
| previous = 0, current > 0 | **Unavailable** / “—” for % (never Infinity%) |
| previous = 0, current = 0 | **Unavailable** or “—” (Demo currently shows `0.0%` — **tighten later**; contract forbids Infinity/NaN; prefer Unavailable) |
| missing current or previous | Hide delta / Unavailable |
| partial period / incomplete fresh end day | Prefer complete days for production collection; label incomplete windows |
| Never | `NaN`, `Infinity%`, fake reassuring `0%` when undefined |

---

## 17. Missing / Zero / Unavailable Semantics

**Global rule: Missing ≠ Zero.**

| Situation | Representation |
| --- | --- |
| No collected dataset | Unavailable / no data — **not** 0 sessions |
| No Business Action mapping | Not mapped / Unavailable — **not** 0 Business Actions |
| Event observed but unmapped | Show event count; BA state Not mapped; exclude from mapped totals |
| No journey/funnel configuration | Empty / Unavailable — **not** 0% completion |
| No provider permission | Error / Unavailable — **not** zero result |
| Measured zero sessions | Explicit measured `0` only when provider returned zero for a successful collection |
| Denominator zero for rates | Unavailable |

---

## 18. Provider Request Families

Do **not** create one API request per UI card. Families below are the minimum sensible set.

### GA4_RF_PROPERTY_METADATA

| Field | Value |
| --- | --- |
| Consumers | Shell, Measurement streams, Relationships |
| Endpoint | Admin `properties.get` + `properties.dataStreams.list` (and web stream get as needed) |
| Dimensions/Metrics | n/a |
| Grain | Property / stream |
| Required | REQUIRED |
| Compatibility | n/a |
| Dataset | `ga4_property_metadata` |
| Why | Identity, TZ, measurement id |

### GA4_RF_PROPERTY_DAILY

| Field | Value |
| --- | --- |
| Consumers | Overview KPIs (sessions), trend, engagement inputs, interruption co-signal |
| Endpoint | Data `runReport` |
| Dimensions | `date` |
| Metrics | `sessions`, `engagedSessions`, `screenPageViews`, `userEngagementDuration`, `totalUsers` (daily users for trend only; **range Users via RF_PROPERTY_RANGE**) |
| Grain | Property × day |
| Required | REQUIRED |
| Compatibility | VERIFIED COMPATIBLE (standard combo; still run `checkCompatibility` in impl) |
| Dataset | `ga4_property_daily` |

### GA4_RF_PROPERTY_RANGE_USERS

| Field | Value |
| --- | --- |
| Consumers | Overview Users KPI |
| Endpoint | `runReport` with dateRanges = selected + previous |
| Dimensions | none |
| Metrics | `totalUsers` (and optionally `activeUsers` if review demands) |
| Grain | Property × range |
| Required | REQUIRED |
| Why | Avoid summing unique users across days |

### GA4_RF_CHANNEL_DAILY

| Field | Value |
| --- | --- |
| Consumers | Overview mix, Acquisition channels |
| Dimensions | `date`, `sessionDefaultChannelGroup` |
| Metrics | `sessions`, `engagedSessions` |
| Grain | Channel × day |
| Required | REQUIRED |
| Compatibility | REQUIRES RUNTIME COMPATIBILITY CHECK |
| Dataset | `ga4_acquisition_channel_daily` |

### GA4_RF_SOURCE_MEDIUM_DAILY

| Dimensions | `date`, `sessionSourceMedium` |
| Metrics | `sessions` |
| Required | REQUIRED |
| Dataset | `ga4_source_medium_daily` |
| Cardinality | HIGH — paginate; do not store only top-10 |

### GA4_RF_CAMPAIGN_DAILY

| Dimensions | `date`, `sessionCampaignName` |
| Metrics | `sessions` |
| Required | REQUIRED |
| Dataset | `ga4_campaign_daily` |
| Also serves | UTM hygiene |

### GA4_RF_LANDING_DAILY

| Dimensions | `date`, `landingPage` |
| Metrics | `sessions`, `engagedSessions` |
| Required | REQUIRED |
| Dataset | `ga4_landing_page_daily` |
| Cardinality | HIGH |

### GA4_RF_EVENT_DAILY

| Dimensions | `date`, `eventName` |
| Metrics | `eventCount` |
| Filter | Interest set: mapped events ∪ observed CTA/funnel events (not entire property catalog indefinitely) |
| Required | REQUIRED |
| Dataset | `ga4_event_daily` |

### GA4_RF_EVENT_BY_CHANNEL_DAILY

| Dimensions | `date`, `sessionDefaultChannelGroup`, `eventName` |
| Metrics | `eventCount` |
| Filter | Mapped / attention events only |
| Required | CONDITIONAL (needed for Acquisition mapped actions column) |
| Compatibility | REQUIRES RUNTIME COMPATIBILITY CHECK |
| Why | Separating from sessions reports avoids distorting session counts with `eventName` |

### GA4_RF_EVENT_BY_SOURCE_MEDIUM_DAILY

Same pattern with `sessionSourceMedium` · CONDITIONAL · high cardinality.

### GA4_RF_EVENT_BY_CAMPAIGN_DAILY

Same pattern with `sessionCampaignName` · CONDITIONAL.

### GA4_RF_EVENT_BY_LANDING_DAILY

Same pattern with `landingPage` · CONDITIONAL · high cardinality · required for landing BA column.

### GA4_RF_DEVICE_DAILY

| Dimensions | `date`, `deviceCategory` |
| Metrics | `sessions` |
| Required | REQUIRED |
| Dataset | `ga4_device_daily` |

### GA4_RF_FUNNEL (future)

| Endpoint | v1alpha `runFunnelReport` **or** configured stage aggregates |
| Required | OPTIONAL / CONDITIONAL when operator funnels exist |
| Status | Not required to hydrate current Demo path cards honestly |

**Total provider request families (V1):** 12 named (10 Data + 1 Admin metadata + 1 future funnel).

---

## 19. Compatibility Matrix

| Family | Status |
| --- | --- |
| Property daily (date + session/engagement metrics) | VERIFIED COMPATIBLE per public schema norms; confirm with `checkCompatibility` at impl |
| Channel/source/campaign/landing + sessions | VERIFIED COMPATIBLE as session-scoped dims + session metrics |
| eventName + eventCount | VERIFIED COMPATIBLE |
| eventName + sessionDefaultChannelGroup + eventCount | REQUIRES RUNTIME COMPATIBILITY CHECK |
| eventName + landingPage + eventCount | REQUIRES RUNTIME COMPATIBILITY CHECK |
| Mixing `firstUser*` with session metrics for freeze UI | **Forbidden** for V1 |
| Funnel report | Alpha capability; separate from v1beta runReport |

Implementation **must** call `properties.checkCompatibility` before locking request shapes in code.

---

## 20. Candidate Normalized Datasets

*(Candidates only — **no tables created** in this milestone.)*

| Dataset | Grain | Keys | Base facts | Consumers | Cardinality | Retention |
| --- | --- | --- | --- | --- | --- | --- |
| `ga4_property_metadata` | property | property_id | TZ, currency, names, streams, measurement ids | Shell, Measurement | Tiny | Current snapshot + history optional |
| `ga4_property_daily` | property×date | property_id, date | sessions, engagedSessions, screenPageViews, userEngagementDuration | Overview, Behavior, Ops evidence | Low | ≥180d |
| `ga4_acquisition_channel_daily` | channel×date | …, channel | sessions, engagedSessions | Overview, Acquisition | Low | ≥180d |
| `ga4_source_medium_daily` | s/m×date | …, source_medium | sessions | Acquisition, referrals | High | ≥180d; pagination |
| `ga4_campaign_daily` | campaign×date | …, campaign | sessions | Acquisition, UTM | Medium-High | ≥180d |
| `ga4_landing_page_daily` | landing×date | …, landing_path | sessions, engagedSessions | Behavior, Overview | High | ≥180d |
| `ga4_event_daily` | event×date | …, event_name | eventCount | Measurement, BA totals | Low-Medium (filtered) | ≥180d |
| `ga4_event_channel_daily` | event×channel×date | … | eventCount | Acquisition BA | Medium | ≥180d |
| `ga4_event_source_medium_daily` | event×s/m×date | … | eventCount | Acquisition BA | High | CONDITIONAL store |
| `ga4_event_campaign_daily` | event×campaign×date | … | eventCount | Acquisition BA | Medium-High | CONDITIONAL |
| `ga4_event_landing_daily` | event×landing×date | … | eventCount | Behavior BA | High | ≥180d |
| `ga4_device_daily` | device×date | … | sessions | Behavior | Low | ≥180d |
| `ga4_business_action_mapping` | mapping rows | asset, action_id | event_name, role, state | Measurement | Tiny | Config |
| `ga4_funnel_definition` | config | funnel_id | stages | Journeys | Tiny | Config — required for non-demo journeys |

**BASE FACTS vs presentation:** persist counts (sessions, engagedSessions, eventCount, durations). Prefer compute rates/shares/deltas at query time.

---

## 21. Historical Backfill Requirements

| Tier | Value |
| --- | --- |
| MINIMUM REQUIRED HISTORY | **180 complete days** in property TZ — supports `last_90` **plus** equal-length previous-period comparison |
| RECOMMENDED INITIAL BACKFILL | **180 days** |
| Demo fixture depth | 90 days ending anchor (insufficient alone for last_90 compare in production) |
| YoY / seasonal | **Not required** by frozen UI |
| DECISION REQUIRED | Whether to backfill **>180 days** for analyst convenience — options: (A) 180 only (meets freeze), (B) 365 for headroom (cost/quota), (C) full retention available from GA4 (quota risk). **Default recommendation: A unless product amends.** |

---

## 22. Refresh / Freshness Requirements

| Dataset | Cadence | Freshness expectation | Late-data recheck |
| --- | --- | --- | --- |
| Property / stream metadata | Daily or on-demand / on bind | Hours–day | On connection change |
| Daily fact families | **Daily** after property-TZ day close | GA4 processing lag — treat last 2–3 days as subject to revision | Recollect trailing 3 complete days each run |
| Business Action mapping | On demand / on save | Immediate | n/a |
| Operations entities | On domain events | Immediate | n/a |

Do not implement scheduling in this milestone.

---

## 23. Cardinality / Pagination Risks

| Dimension | Risk | Rule |
| --- | --- | --- |
| `sessionSourceMedium` | High | Collect with pagination; UI may show top-N **without** limiting storage to top-N |
| `landingPage` | High | Same |
| `sessionCampaignName` | Medium-High | Same; retain `(not set)` |
| `eventName` (unfiltered) | Extreme | **Filter interest set** |
| Channels / devices | Low | Full collect |

**Hard rule:** UI top-10/25 ≠ collection top-10/25. Existing `Ga4BoundCollector::TOP_ROW_LIMIT = 25` is **presentation-shaped** and **insufficient** as a storage strategy for Contract V1.

---

## 24. Existing Implementation Reuse Matrix

| Component | Current behavior | Contract coverage | Disposition |
| --- | --- | --- | --- |
| `Ga4Discoverer` | Admin accountSummaries → properties | Partial metadata | **KEEP** |
| `Ga4ConnectionProbeService` | Probe property access Evidence | Access check | **KEEP** / ADAPT LATER for asset model |
| `GoogleApiClient` | OAuth HTTP | Transport | **KEEP** |
| `Ga4BoundCollector` | Range summary + landing top25 + channel top25 → Evidence | Partial overlap | **ADAPT LATER** (not delete yet) |
| `ComparisonPeriod` | last 28 complete days in **UTC** | Period helper | **ADAPT LATER** (TZ bug vs contract) |
| Evidence types `ga4_*` | Diagnosis payloads on Website-scoped binding | Ops/Evidence | **KEEP** pattern; expand types later |
| `Ga4WorkspaceFixtures` | Full Demo UI | Specimens | **KEEP** for Demo; not production warehouse |
| `AnalyticsPage` UI | Frozen IA | Consumers | **KEEP** (no redesign) |
| Admin `properties.get` for TZ | Not implemented | Gap | **ADD LATER** |
| Business Action mapping store | Demo only | Gap | **ADD LATER** |

---

## 25. Current Collector Gap Analysis

`Ga4BoundCollector` today:

| Collected field | Classification vs Contract V1 |
| --- | --- |
| `totalUsers` | REQUIRED BY CONTRACT |
| `sessions` | REQUIRED BY CONTRACT |
| `engagedSessions` | REQUIRED BY CONTRACT |
| `engagementRate` | USEFUL BUT optional if deriving |
| `screenPageViews` | REQUIRED (as base or via views/session) |
| `newUsers` | USEFUL BUT NOT CURRENTLY REQUIRED by freeze UI |
| `keyEvents` | USEFUL BUT NOT BA; do not treat as Business Actions — OK as context |
| Landing `landingPage` + sessions/users/engagementRate/views | PARTIAL — missing engagedSessions base, BA, daily grain, full cardinality |
| `sessionDefaultChannelGroup` + sessions/users/engagedSessions | PARTIAL — missing daily, BA, full cardinality |
| Daily grain | **MISSING** |
| Source/medium, campaign, devices, events | **MISSING** |
| Property timezone metadata | **MISSING** |
| Business Action mapping | **MISSING** (explicitly does not invent — correct stance) |
| Top-25 limit | **SEMANTICALLY WRONG** as sole storage approach |
| UTC comparison window | **SEMANTICALLY WRONG** vs property TZ contract |

---

## 26. Unsupported / Unavailable Frozen Concepts

| Requirement | Why unavailable | What would be needed | UI may remain? |
| --- | --- | --- | --- |
| Multi-step aggregated journey paths as live truth | No honest path reconstruction from v1beta aggregates | Operator funnel config and/or alpha funnel API + acceptance of funnel semantics (not free-path) | YES — Unavailable / empty / Demo |
| Appointment completion | No mapped event | Mapping + event | YES — Unavailable |
| WhatsApp as Business Action count | Not mapped | Operator mapping | YES — Not mapped |
| GA4 key event = Business Action | Product forbids auto-equation | Explicit mapping | N/A |
| Currency/money KPIs | Not in freeze UI | — | N/A |
| User-level journeys | Privacy + product | Forbidden | N/A |

---

## 27. Decisions Required Before Collection Implementation

1. **Users semantic lock:** keep Overview label “Users” bound to `totalUsers` vs switch canonical metric to `activeUsers` (**UI SEMANTIC REVIEW REQUIRED LATER** — collection can ship `totalUsers` as V1 with optional `activeUsers`).
2. **Avg engagement time exact divisor:** `activeUsers` vs `sessions` vs other — **REQUIRES PROVIDER VERIFICATION** against GA4 product definition used for operator expectation.
3. **Backfill beyond 180 days:** A/B/C in §21 — default **A (180)**.
4. **Journeys production posture:** keep Demo-only until funnel configuration productized, vs implement Admin/operator funnel MVP.
5. **Whether to persist high-cardinality `event × source/medium` daily** for all interest events or compute on-demand for Acquisition table only (cost tradeoff) — both can satisfy UI if latency/quota allow; prefer store channel+landing+campaign event facts first.
6. **Adapt `ComparisonPeriod` to property TZ** before any production GA4 collector expansion (blocking correctness).

If implementation proceeds without (1)(2)(6), status of collector work should remain blocked on correctness grounds.

---

## 28. Definition of Done

| Question | Answer |
| --- | --- |
| Can every frozen GA4 UI element be traced to an explicit data source? | **YES** |
| Are provider metrics separated from derived metrics? | **YES** |
| Are Business Actions explicitly mapped? | **YES** |
| Are Journey/Funnel semantics explicit? | **YES** |
| Is timezone explicit? | **YES** (property `timeZone`) |
| Is comparison behavior explicit? | **YES** |
| Is missing distinct from zero? | **YES** |
| Are API request families explicit? | **YES** |
| Are normalized dataset candidates explicit? | **YES** |
| Can a future collector be implemented without deciding what data to collect on its own? | **YES** |

### Data minimization pass (completed)

Removed from V1 requirements: monetary metrics, first-user acquisition, unrestricted event dump, landing query strings, user-level data, YoY backfill, collecting `newUsers`/`keyEvents` as freeze necessities.

### Query consolidation pass (completed)

Grouped into reusable daily facts + separate event×dimension families (to protect session metric integrity) + dedicated range Users query + Admin metadata.

---

## Appendix A — Provenance legend (quick)

Provider measured · Provider metadata · Derived · Mapped · Cross-asset · Operator maintained · Operations-domain · Unavailable · Demo-only

## Appendix B — Privacy

**USER-LEVEL DATA NOT REQUIRED BY GA4 DATA CONTRACT V1.**  
**PII NOT REQUIRED.** Aggregate operational intelligence only.

## Appendix C — Audit provenance (code)

| Artifact | Path |
| --- | --- |
| Page | `app/Livewire/Demo/Assets/AnalyticsPage.php` |
| Fixtures | `app/Support/Demo/Ga4WorkspaceFixtures.php` |
| Views | `resources/views/livewire/demo/analytics/**` |
| Period | `app/Support/Demo/DemoPeriod.php` |
| Product doc | `docs/product/website/GA4.md` |
| Collector | `app-modules/website/src/Collection/Ga4BoundCollector.php` |
| Discoverer | `app/Services/Integrations/Google/Discovery/Ga4Discoverer.php` |
| Tests | `tests/Feature/Ga4OperatingWorkspaceTest.php` |
