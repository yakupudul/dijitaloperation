# PROJECT_MEMORY

## 2026-10-05 — Faz 10 decisions (sade menü + gözden kaçanlar)

- Faz 10 was not in the original roadmap table; it was defined from the roadmap's remaining items (sade menü target, chart notes, customer health, AI visibility, KVKK, backup).
- The sidebar follows the "Sade menü (hedef)" list in `docs/product/MOXDOP_STRATEGY_ROADMAP.md`; new screens go into an existing group instead of new groups, and screens removed from the menu keep their routes and get a link from their parent screen. `PanelDesignFreezeTest` locks the order.
- Customer health is a rule score from stored data only (no AI); thresholds live in `moxdop-assistant.health`.
- AI visibility checks run only on click through the `intel.ai_visibility_probe` route; only the question text is sent to the model.
- Database backups are owned by the app (`moxdop:backup`); credentials only via environment variables; a missing or stale backup is a Sistem Sağlığı issue. WhatsApp text retention is opt-in and never shorter than 30 days.

## 2026-10-05 — Faz 9 decisions (rapor v2 + eklenti v2)

- Monthly client numbers come from `MonthlyReportBuilder` (stored data only, brand scope, central rows win over legacy per-asset copies, missing ≠ zero); a report freezes its payload in `monthly_reports`. New channel KPIs are added there, not in blades.
- Report commentary is AI only on click and always editable before publishing; clients get a signed, time-limited link to published reports only.
- WordPress Connector v2 features that act on a client site (login link, updates) are off in the plugin until the site admin enables them, admin-only in MoxDOP, recorded (security audit / external_write_actions), one item at a time; updates are not undoable (ADR-068). Server-side feature gate: `moxdop-wordpress.management_min_plugin_version`.

## 2026-10-04 — Faz 8 decisions (pazar istihbaratı + ajans satışı)

- Every paid DataForSEO call of a market feature goes through `DataForSeoTaskQueue` (queued `post` or `recordLive`), so it lands in `dataforseo_tasks` with its cost and counts toward the brand's monthly cap; new queued purposes register a handler in `moxdop-intel.tasks.handlers`. New endpoints must be added to `DataForSeoEndpointAllowlist` (task_get reads are pattern-matched).
- The brand's own business in Maps results is recognised only through `BrandGbpIdentity` (settings place id / CID → GBP snapshot → site host → phone).
- Competitor reviews and site snapshots are agency-internal (ADR-067); reviewer names are never stored; nothing is written to Google / Meta / competitor sites. Public fetches use the Website module's safe fetcher via `PublicPageReader`.
- The KML map-pin idea stays an experiment until grid scans show a before / after difference; do not roll it out across the portfolio.
- `/api/leads/{token}` is only for the agency's own website form; client forms are not connected there.

## 2026-10-03 — Faz 7 decisions (Beyin)

- Thresholds and word lists stay in config files as defaults; owner edits live in `method_settings` via `MethodLibrary` (applied at boot). New rule thresholds should be plain int/float/string-list config leaves so they appear in Yöntem Kütüphanesi automatically; new rule ids go into `MethodLibraryPage::ADVISOR_RULES`.
- Plan writers own verification: done + still detected → `still_detected` (grace `brain.verify_grace_days`) → reopened as `recurred`; skipped with `snoozed_until` comes back after the date. "Yapıldı" queues a rules-only plan (`trigger=verify`); never AI.
- Rule priority weight = measured outcome success (28/56 days) per rule, sector-first when the sector has ≥ `brain.min_measured` outcomes (ADR-066). Cross-brand data only as aggregates over ≥ 2 active brands, agency-internal.
- Cross-asset consistency lives in the cross-channel advisor rules (not the old `Analyze*ConsistencyJob`s).
- Keyword Quality Score has no history in the snapshot; `google_ads_quality_score_history` is the only source for QS trends.

## 2026-10-02 — Faz 6 decisions

- Phone notifications go only to the owner's own ntfy / Telegram (`PushNotifier`, dedupe window, send log); new notifiable events use `PushNotifier::send` with a stable dedupe key.
- Google Calendar is served as a read-only ICS feed with a secret per-user token; writing to Google Calendar would need its own ADR (ADR-064 is unchanged).
- Alerts owned by other monitors (uptime `site_down`, renewals) are re-detected inside `AssetAlertScanner` so the daily scan does not resolve them.
- Renewal dates: manual > RDAP / certificate; RDAP and uptime are public reads, no provider accounts involved.

## 2026-10-01 — Faz 5 decisions

- AI outputs are archived through model hooks in `ProductionArchive::boot()`; a new AI feature that stores its output must be added there (kind + subject) so nothing is lost when regenerating.
- Sector knowledge goes into `SectorPack` classes (listed in `config/moxdop-sector-packs.php`), not into ad-hoc checks; pack rules are copied into `compliance_rules` and the owner's edits win.
- Health compliance rules are a draft until legal review of RG 12.11.2025 / 33075; the UI says so. The auditor reads stored data only and never writes to providers.

## 2026-09-30 — Faz 4 decisions

- Stopped collections heal themselves: OAuth success resumes "reconnect" stops; a daily retry covers repeated failures. Contract errors (`request_requires_fix`) are never retried automatically — they need a code fix.
- Ayarlar › Sistem Sağlığı is the one operator view of machine health (heartbeats, alerts, authorization expiry, per-account freshness, plugin versions); new health signals go there, not into Filament.
- Costs shown are the app's own records (AI usage, DataForSEO runs); any new paid call must record its cost so it appears on Maliyetler.
- Logs pass through `RedactSecretsTap`; new log channels must add the tap.

## 2026-09-29 — Faz 3 decisions

- `brand_conversion_sources` is the one per-brand answer to "what is a conversion"; totals, alerts and future reports read it. Defaults avoid double counting (GA4 = website; Ads/Meta only for what happens off the site); operator choices win.
- Tracking checks read stored data only (homepage HTML snapshot, GA4 rows); "no data" is only claimed when collection demonstrably ran. The GTM API is not used.
- Branded detection has one implementation (`BrandedQueryMatcher`); do not add another.
- Raw SQL on GA4 tables must quote camelCase columns (`DB::getQueryGrammar()->wrap()`); Postgres folds unquoted identifiers.

## 2026-09-28 — Faz 2b decisions

- The brand demand table (`brand_demand_queries`) is the per-brand, automatic view of demand; the global query library and manual portfolio remain for catalog work. Weekly order: demand build 05:30 → area SERP 05:45 → comparison 06:00 → SEO plan 06:30.
- Paid SERP checks are per-brand opt-in with a monthly USD cap and 28-day cross-brand reuse; everything else in the pipeline is free (stored data, public fetch, rules). AI stays on click.
- Use `SeoText::fold` / `SeoText::matchesPhrase` for all Turkish text matching; do not add new fold copies.

## 2026-09-27 (c) — Faz 2 decisions

- Bulk portfolio creation reuses the "Otomatik kur" applier (one binding path); grouping is deterministic and never calls providers.
- The competitor library (`search_demand_competitors`) is the only competitor record per brand; the brand text field, business context and DataForSEO domains only feed suggestions.

## 2026-09-27 (b) — Faz 1 decisions

- Removed layers must not be reintroduced without a consumer (screen, rule or click AI). Filament `/admin` = technical tooling only (ADR-065); new operator features go to the operator product.
- Table removals use guarded forward migrations (drop only when empty); never edit old migrations. Persisted enum cases of removed features stay as `@deprecated` so old rows hydrate.
- Retention is one job (`DataRetentionService`); gold data list lives in `config/moxdop-retention.php` and the GBP content purge. New raw/telemetry tables must be added to that config.

## 2026-09-27 — Faz 0 decisions

- AI never runs from a schedule or bulk action: SEO plans pass `useAi` only from the explicit AI button; new AI features must follow the same opt-in pattern. AI output on a work item is not overwritten by rules-only runs.
- "Passive" = customer status inactive/archived or asset not active; `DigitalAsset::operational()` is the one gate for automatic flows. Unbound provider accounts are collected only when they feed the query library (sector set).
- GBP: provider content follows the 30-day retention; the business's performance and search-keyword metrics are kept (gold data rule).
- Two-factor uses Filament's `AppAuthentication` for both logins (one secret); do not add a second TOTP implementation.
- Map stacking (My Maps/KML pins) is recorded as a Faz 8 measured experiment only (weak evidence, spam risk); the maps grid tracker is its prerequisite.

## 2026-09-26 (b) — Strategy & roadmap agreed with the owner

- Canonical roadmap: `docs/product/MOXDOP_STRATEGY_ROADMAP.md` (principles: algorithms run / AI on click, gold data kept forever, passive customer stops all flow, AI outputs archived, agency-internal cross-brand brain, health-sector compliance). Phases 0–9; read it before planning new work.

## 2026-09-26 — Digital asset pages Faz D (analytics + alerts)

- Alerts are a separate lifecycle from advisor items: time-sensitive, detected daily from collected data, auto-resolved; thresholds in `config/moxdop-alerts.php`. Advisor items stay the weekly "what to improve" list.
- Asset pages export CSV via Livewire streamDownload (UTF-8 BOM, `;`), no export routes.
- Website health score counts crawl rules only; duplicates/orphans/broken links are listed but not scored so the trend stays comparable.

## 2026-09-25 (d) — Digital asset pages Faz C (frame + models)

- Every asset page starts with the shared asset frame (breadcrumb, sibling switcher, status strip, Data Sources/Edit). New asset pages must include `<x-operator.asset-context :asset-id="$assetId" />` and must not add their own sources/edit buttons.
- GA4 and Search Console belong to the Website asset (Data Sources binding); standalone ga4/gsc assets are legacy — not creatable, still viewable.
- All bindings go through `Confirm*ResourceBindingService` (operator Data Sources, Integrations, Brand setup, Filament).

## 2026-09-25 (c) — Digital asset pages Faz B (cleanup)

- Website page is 9 tabs; Google Ads "Optimization" lives under Danışman; Meta legacy sub-pages are redirects only; Instagram is a single honest page and cannot be created until it has a data source.
- Rule kept: one place per number (no repeated counters on overview), operator text Turkish with no provider/binding/dataset jargon (technical details only in collapsed blocks).

## 2026-09-25 (b) — Digital asset pages: audit + Faz A fixes

- Audit (all asset pages) found: Data Sources 500, GBP page never reading collected data, fake list status, hidden Google Ads landing tab, provider call during Search render, Meta auto-picking an account, "edit asset" opening the create form. Fixed in Faz A.
- Decisions: asset pages never call providers while rendering (Google Ads live Search fallback is opt-in via env); a missing asset id never auto-selects an account; asset editing lives in the operator product (`/assets/{id}/edit`), brand and type fixed after creation; GBP tabs without a data source (local rank grid, competitors) are not shown.
- Planned next (not done): Faz B cleanup (dead demo views/code, duplicate tabs, English/jargon), Faz C shared asset frame (breadcrumb, switcher, status strip, one period bar, "Kaynak & Ayarlar" tab, one binding flow, GA4/GSC as website sources), Faz D missing analytics (Ads pacing/comparison columns/impression share, Meta drill-down/budget/fatigue, website audit score, alerts, CSV).

## 2026-09-25 — Faz 7: two narrow external writes (ADR-064)

Owner decision ("Faz 7 yap"; both writes, shared list, Admin only): ADR-018 gets exactly two exceptions.
(1) Google Ads: an advisor negative list is added to the account's "MoxDOP negatifleri" shared negative
keyword list, attached to enabled Search campaigns — only sharedSets / sharedCriteria / campaignSharedSets
mutates are allow-listed in `GoogleApiClient::mutateAds`. (2) WordPress: an SEO content brief becomes a
draft through MoxDOP Connector ≥1.2.0 (`POST /moxdop/v1/drafts`, forced `draft`; `DELETE` trashes only
MoxDOP-created drafts). Every write: Admin click + confirm, queued job, `external_write_actions` audit row
(request + provider ids), one-click undo, kill switch `EXTERNAL_WRITES_ENABLED` (+ per channel), site-side
opt-out `moxdop_connector_allow_drafts`. Nothing else may write externally.

## 2026-09-24 (d) — Faz 6: one work list, cross-channel advice, measured outcomes

Decisions: SEO Görevleri and advisor items stay separate engines but share one ranked list
(`AdvisorWorkQueue`, same 100–1300 priority scale, max 2 per brand in the agency-wide top 5) shown on the
dashboard and the brand overview ("Danışman" block: one status line per channel + top items).
Cross-channel advice is its own advisor channel (`cross_channel`) on the website asset. "Yapıldı" work is
measured once after 28 days against the metric it came from (negative lists: spend on the listed terms;
SEO tasks with a target page: Search Console clicks) and reported as observed change, never causation;
the client report gets a "Yapılanlar ve gözlenen etkisi" section. The weekly internal digest email exists
but is off unless `ADVISOR_DIGEST_ENABLED=true`. "Less-effective rule types shown less" is not built:
it needs more measured history first.

## 2026-09-24 (c) — Faz 5: Business Profile advisor; one "Danışman" page

Decision: Google Business Profile joins the advisor channels (channel id `google_business_profile`, legacy
asset type `gbp` aliased). The menu page is renamed "Danışman" (route unchanged, `/ads-advisor`). Review
replies stay out of scope; photo freshness is only suggested when the profile earns ≥20 interactions in
28 days; posting is never suggested for its own sake. Monthly search keywords are matched to brand
services to find services missing from the profile (and searches the brand does not offer). AI only drafts
the profile description and service texts on click (route `gbp.profile_draft`); links/phones rejected.

## 2026-09-24 (b) — Faz 4: Meta Ads advisor on the same advisor lifecycle

Decision: channels plug into `AdvisorChannels` (interface `AdvisorChannel`: collect stored data, pure rules,
draft rules, asset URL); runner, writer, panel and /ads-advisor are shared. Meta "results" are resolved per
campaign from ad-set optimization goal (else objective) via `moxdop-advisor.meta_ads.result_actions` — the
first listed action type with data. Learning phase and weekly frequency are not collected, so learning is a
labelled proxy (weekly results vs ~50) and frequency is the impression-weighted daily average. AI only
drafts new creative ideas/copy on click (route `meta_ads.creative_draft`); nothing is written to Meta.

## 2026-09-24 — Faz 3: channel advisor (Google Ads) on generic advisor tables

Decision: channel advisors (Google Ads now; Meta Ads and Business Profile in Faz 4–5) share one lifecycle in
`advisor_plans` / `advisor_items` (channel column) instead of per-channel tables, so Faz 6 can show one
screen. Items are rule-produced from already collected normalized Google Ads tables (no provider calls),
grouped (one negative list, not one item per term) and capped per account (`max_open`, criticals exempt).
An item no longer produced closes itself ("Kendiliğinden kapandı"); done/skipped are kept with a baseline
for later outcome measurement. AI only drafts RSA copy on an explicit operator click (route
`google_ads.ad_copy_draft`, budget-guarded); nothing is written to Google Ads (ADR-018). Keyword quality
score is now collected (keyword_view `quality_info`); keyword-daily-derived snapshots only fill gaps.

## 2026-09-23 (f) — Faz 2: depth rules live inside SEO Görevleri

Decision: Faz 2 web/SEO/GEO work extends the existing SEO Görevleri engine (one list, same quotas) instead
of a new surface. Every rule must name its data source and stay silent without it. Search Console URL
inspection is now driven by the plan (important pages, weekly, within quota) — the first production use of
that collected-but-idle capability. Pruning is a single per-site decision card, not one task per page.

## 2026-09-23 (e) — Simple customer/brand screens; setup through "Otomatik kur"

Owner direction: customer and brand screens must be simple, complete and without redundancy. Decisions:
the brand page answers four questions (who is it, what is connected, what needs doing, what did we
report) in five tabs; connection state is derived only from confirmed account bindings; new brands go
customer → brand (name + website) → "Otomatik kur" approval instead of the multi-step setup wizard.
Matching expressions ("eşleştirme ifadeleri") are the single mechanism that assigns imported queries to
services, so the setup assistant proposes them; GBP search keywords are an import source like Ads/GSC.

## 2026-09-23 (d) — Services and keywords never carry a location

Owner decision: service names and their keywords must be location-free so they are reusable for brands
in other places; brand-specific strategy uses the brand's "hizmet verdiği yerler". Search demand for places
outside those areas is not a content target; it becomes one operator decision per site (add the area or not).
Brand setup keywords feed the existing sector → service → query library instead of a parallel list.

## 2026-09-23 (c) — Advisor roadmap approved; Faz 1

Owner approved `docs/product/ADVISOR_ROADMAP.md` in full: algorithm first, AI acts only through
operator click approval, no busywork task factory, low tool spend. Decisions: phases 1→6 in order;
Faz 7 (external writes: WP drafts, Ads negatives) out of scope for now; model defaults Sonnet 5
(analysis), Haiku 4.5 (classification), free models (Groq/OpenRouter) only for public data, Gemini
fallback; monthly AI budget 25 USD; brand setup uses a one-click approval screen. Client data never
goes to free tiers. GBP review replies are not the operator's responsibility.

## 2026-09-23 (b) — SEO Görevleri: no mandatory service definition

Owner direction: a Brand without services must still get content suggestions; the system should
understand the business from the website, GSC and GA4 data it already collects. Decision: infer
services per plan (AI first, rule-based page topics as fallback), keep them plan-local and never
write them to the Brand automatically; the operator adopts them explicitly. Stored HTML snapshots
(already collected) are the source for H1/alt/JSON-LD checks — no new crawling or HTTP requests.

## 2026-09-23 — SEO Görevleri: rule-first weekly content plan

Owner decision: "SEO Görevleri" is one list in the main menu for all brands, with the same list
filtered per site on the website asset page; main focus is telling the operator which content to
write for which brand, at least 4 content suggestions per site per week. Written directly to
`chatgpt/search-demand-foundation`, no main/PR.

- Separate tables (`seo_plans`, `seo_tasks`, `service_page_assignments`); existing Task/Finding/
  Recommendation and clustering flows are untouched. The star (`brand_offerings.is_priority`) is
  the single "priority service" signal; the brand-form priority order keeps it in sync.
- Deterministic rules own selection, scoring, quotas and task keys. The LLM (one structured call,
  Anthropic first) only rewrites titles/reasons/checklists and fills content briefs; invalid or
  missing output never blocks a plan. Thresholds live in `config/moxdop-seo-tasks.php`.
- Operator answers to service-page questions are final and re-used by every later run.
- Weekly scheduler only targets Search-Console-bound websites; bulk refresh covers all active sites.
- Verification: PHPUnit only (sync queue, faked agent). Real GSC/WordPress/Anthropic/Horizon
  behaviour is pending staging UAT (see ledger 2026-09-23).

## 2026-09-13 — Free public-source Intent Radar (staging)

Owner authorization: implement the agreed fastest no-paid-API/no-AI source-monitoring slice;
write directly to chatgpt/search-demand-foundation, no main/PR/clone/test/server deployment.
The current free path supersedes the paid-only UI described in the historical Batch B below.

- Existing SalesSearchProfile/Signal/RadarRun and sales activity history are reused. Profiles
  may bind the global ServiceCatalogItem and its live names/matching keywords, with optional
  additional/excluded terms and a location expression. Built-in aliases cover website, SEO,
  Google Ads and social advertising. Rule scores are heuristic, never purchase probabilities.
- FreeIntentRadar + RunFreeIntentRadar use the existing default worker; scheduler every five
  minutes admits one agency-wide job. Default per-profile cadence is hourly or daily. No
  DataForSEO, AI, Google scraping, browser service, new dependency or outbound message.
- Migration seeds four public category URLs: WM Aracı (job requests, Ads, SEO) and
  R10 software/web job requests, not sample leads. Operators can add same-origin RSS/Atom or public HTML lists, capped at 20 sources.
  This is bounded source monitoring, not automatic whole-web/source discovery.
- Shared source/page cache prevents per-service repeated fetches. A job reads at most two
  due lists and two due detail pages; robots is cached separately for one hour. Lists yield
  at most 100 candidates, matching examines the latest 500 candidates. Remaining due work
  gets another scheduled pass. HTML discovery requires a demand-bearing link title.
- PublicHttpFetcher/PublicUrlSafety are reused. Robots disallow fails closed; unavailable
  robots, source errors and unrecognized content are shown rather than reported as no demand.
  Same-host page identity preserves URL path/query. No login/cookie/CAPTCHA bypass.
- Detail extraction uses primary structured article/discussion data or known first-post
  markup; it does not classify forum replies/navigation as the original request. Missing
  content/date remains review-only. Known publication older than 30 days is excluded.
  Unknown market remains review-only. Cached details refresh daily, bounded by the queue.
- Profile+URL fingerprint is stable across edited text. Rediscovery preserves review,
  dismissal and prospect conversion. Historical paid/AI signals remain visible and labelled.
  Prospect handoff is the existing explicit conversion/research workflow.
- Source failures back off 1h to 24h. Interrupted queued/running sales runs are failed by
  the watchdog after 20 minutes; the next due profile is eligible again. Active owner
  and application access are rechecked before automatic work.
- Tests, PHP lint, formatter/build and live operator UAT were NOT run at owner request.
  Source URLs were inspected through public web research, not fetched successfully from
  this execution environment. Real staging robots/HTML/queue/parser behavior remains UAT.
  Especially missing publication markup, body-only requests, large/paginated archives and
  rapid deletions between list reads limit recall. This slice does not fix broader brand
  Public Discovery or add paid/semantic search.


## 2026-09-12 — Automatic account admission must match worker isolation

Post-deploy operator evidence still showed many never-collected Ads accounts while automatic
GA4/GSC work was active. Dedicated Ads execution did not help when a shared two-account
admission cap blocked planning first. Admission now has two bounded lanes matching the existing
staging workers: Ads and non-Ads, two accounts each by default. This is a four-account admission
limit, not an increase to actual worker concurrency. Connector pages expose existing automatic
settings/status directly so absent facts are not confused with disabled or waiting automation.
GA4 empty landingPage values must be preserved as values; do not fabricate a URL or merge with
`(not set)`. The effective storage contract documents this narrow allowance. Deployment can
rearm only the matching historical failure on enabled accounts. See the current ledger for
unexecuted tests and pending live verification; no PR/main workflow.

## 2026-09-12 — Honest collection status and checkpoint recovery

The global header must identify jobs and their states rather than imply that heterogeneous
provider datasets measure website URL coverage. Queued and backoff work are not “running”.
Interrupted execution recovery uses the existing collection state machine and durable checkpoint;
completed work is not replayed by the watchdog. Recovery is bounded at an unchanged checkpoint,
and API continuation delays must be durable for both Redis and the database worker.
Recent complete manual WordPress inventories satisfy automatic inventory freshness, while event
watermarks remain independent so changed-page verification cannot be skipped. Periodic CMS full
inventories remain the recovery mechanism; this does not introduce continuous full public crawls.
Published workflow remains the staging work branch plus an exact commit deploy command. Runtime
verification limits are recorded in the 2026-09-12 capability ledger entry.

## 2026-09-11 — Automatic collection recovery

Operator supplied deployment baseline `19f6163f2213595f07b05a04f7fa5c566956a832` and confirmed
direct publication to `chatgpt/search-demand-foundation`, then an exact-SHA deploy command.
Continue from the latest branch, preserving subsequent WhatsApp changes; no PR/main workflow.
WordPress inventory now bootstraps from pairing or the scheduler without requiring push events.
New account collection is immediately due subject to the existing concurrency bound. Recovery
handles stale child states under terminal runs and prevents previous-attempt reconciliation from
overwriting a newly queued planner. Meta still requires an explicit real asset binding, with
automatic resumption when that binding becomes available. See the 2026-09-11 ledger entry for
verification limits; live runtime is not verified and PHP/Pint were unavailable locally.

> **Canonical persistent product / architecture memory for MoxDOP.**  
> Historical baseline: `origin/main` @ `171e5e7` (2026-08-11). Latest scoped change: Public Discovery Stage 1 on `chatgpt/search-demand-foundation` (2026-09-07); main was not re-evaluated or modified.
> Does **not** override `docs/MASTER_SPEC.md`. See **Source priority** below.  
> Implementation truth (coded / tested / UAT / UX / async) lives in `PRODUCT_CAPABILITY_LEDGER.md`.  
> Operator long-running execution standard: `OPERATOR_ASYNC_EXECUTION.md`.

---

## Product identity

**MoxDOP** (DOP — Dijital Operasyon Platformu) is an **internal digital operations platform for Moximu**.

It is **not**:

- SaaS
- a customer / client portal
- a subscription / billing product
- a marketplace / plugin ZIP store
- a multi-tenant Workspace product

Operators are agency owners and agency staff only. Customers do **not** log in.

Canonical operational hierarchy:

```text
Customer
→ Brand
→ Digital Asset
→ Integration / External Resource / Binding
→ Run
→ Evidence
→ Finding
→ Recommendation
→ Task
→ Outcome
```

Notes:

- **AI remains advisory and evidence-grounded.** AI does not invent Findings, silently override deterministic Recommendations, or auto-open Tasks.
- **External provider integrations remain READ-ONLY.** No external write actions.
- There is **no separate Result entity**. Outcomes are observed via later Evidence / Finding lifecycle and Task outcome signals.
- Canonical operator product: root routes (`/`, `/login`, `/customers`, `/brands`, `/assets`, `/integrations`, `/activity`, `/findings`, `/recommendations`, `/tasks`, `/settings`, `/profile`, …). TailAdmin Livewire. One application.
- Single Filament technical/admin panel: id `app`, path `/admin` (ADR-044; supersedes ADR-026 path `/app`). `web` guard; `spatie/laravel-permission`.
- Legacy `/app/*` and `/system/*` prefixes are retired (HTTP 410). No parallel operator product.
- Operator Data Sources bind through ConfirmGoogle/ConfirmMeta guards. Google/Meta resource refresh on that page is Admin-only (`Roles::ADMIN`) before any provider call or inventory persistence; Meta refresh uses selected-Business `DiscoverMetaResourcesService::refreshInventory` (not broad `me/adaccounts`). Website period reads compose PeriodAware pool overlays with evidence `period_has_data` filtering.
- Staging/production: HTTPS + PostgreSQL + Redis/Horizon. `moxdop:production-check` is the production-readiness gate. The dedicated RC integration branch is the first head that contains **#202 + #199 + #200-downstream**; PR #209 alone is not that ancestry.
- Modules live under `app-modules/` + `internachi/modular` (minimal registry: id + enabled/disabled).

---

## Brand / account model

One Brand **MAY** have:

- multiple Meta Ads accounts
- multiple Google Ads accounts
- multiple Digital Assets of the same provider type

**Canonical model:**

```text
ONE provider advertising account
=
ONE corresponding Ads Digital Asset
+
its provider binding
```

Do **not** force all Brand ad accounts into one Digital Asset.

Meta Business Manager / Google Manager (MCC) accounts may appear as **provider scope / container context**, but are **not** automatically equivalent to Brand.

---

## Central integration model

The agency authenticates providers **centrally**.

### Meta

```text
one central Meta Integration / agency credential
→ discover accessible Businesses / Ad Accounts
→ operator selects relevant account(s)
→ bind selected accounts to Brand Digital Assets
```

- No Meta App per customer.
- No access token per Ad Account as the primary auth model.

### Google

Follows the corresponding **central agency-auth** model (one agency Google Integration → discover resources → bind to Digital Assets).

Google **Collect Data** is Integration-scoped at the operator entry, but planning/execution is **Brand-scoped**: one `CollectionRun` per eligible Brand, same-brand GSC/GA4/Ads siblings in that run, no silent drop of sibling Brands, no cross-brand or cross-customer mixing inside a run. Incremental refresh due selection uses that Brand’s exact preflight binding IDs across Digital Assets (not only the website/GSC anchor). Meta same-customer multi-brand backfill remains a separate contract (one run may span Brands for the same Customer).

Operator **Collect Now** / **Collect live data** for GA4, Search Console, Google Ads, and Meta Ads must start the shared Collection Engine (`ExecuteCollectionLifecycleService::runNow` → `CollectionRun` / warehouse). It must not write specialist Evidence summaries through BoundCollectorRegistry. GBP remains on the legacy bound Evidence collector. DataForSEO `HIT_FRESH` is scoped to the paid request fingerprint (including market `location_code` / `language_code`); a market change is a cache miss.

The dedicated RC integration PR is **not** a DOP Autopilot product PR. Do not put the Autopilot product-PR HTML marker in that PR body, even as a negation: the Gate treats a substring match as Autopilot and then fails when task metadata is absent. Autopilot squash-merge to `main` remains forbidden for this RC.

Site-scoped legacy connection paths may still exist for some Website connectors; the **direction of travel** is central Integration + External Resource + AssetBinding.

**Track A (issue #211):** GSC/GA4 analytical reads use the canonical PostgreSQL Data Pool (`gsc_*` / `ga4_*`), not a second metrics store. Initial backfill target is `provider_16m_available` (486 days). Evidence remains run provenance. Closed-period provider totals are compared via `moxdop:reconcile-provider-period` (live ±1% is external UAT). `core_connections` is not retired while probe/WordPress/PageSpeed paths still depend on it.

---

## Current product philosophy

```text
Provider / raw data
→ normalized operational data / Evidence
→ deterministic Findings
→ bounded Agent + Skills
→ AI interpretation
→ human Recommendation
→ human Task
→ later read-only refresh
→ Outcome
```

Hard distinctions:

| Platform / provider signal | Must not be treated as |
| --- | --- |
| Platform result | Verified business outcome |
| Meta lead | Qualified lead |
| Messaging result | Qualified customer |
| Purchase value | Verified profit (unless supported by business / CRM Evidence) |

Platform metrics are useful operational Evidence. They are **not** automatic truth about business success.

---

## Operational Taxonomy — planned foundation

**Status: PLANNED — do not implement in this memory milestone.**

Marketing entities will eventually be classified across **independent dimensions**, not one simple category string.

Example dimensions:

- Service / Offer
- Market / Geography
- Audience Segment
- Funnel Stage
- Business Goal
- Language
- Acquisition Type

Future classification should support:

- canonical terms
- aliases
- manual assignment
- AI / rule suggestions
- human approval
- provenance
- confidence
- valid-from / valid-to where needed

---

## Marketing Initiative — planned

**Status: PLANNED — do not implement yet.**

Brand-level grouping of provider entities that represent the **same commercial effort**.

Example:

```text
Mommy Makeover | Germany | Turkish Diaspora | Lead Gen
```

could later contain:

- Meta Campaign A
- Meta Campaign B
- Google Campaign X
- relevant landing-page context

Initiatives are a future organizational layer above raw provider campaign objects.

---

## Benchmark Cohort — planned

**Status: PLANNED — do not implement yet.**

Future cross-Brand comparisons should use **approved compatible taxonomy dimensions**.

Do **not** compare semantically incompatible platform metrics merely because labels look similar.

Example: Meta CTR and Google Search CTR are **not** automatically equivalent benchmark metrics.

---

## Operational Data Foundation — next foundation direction

**Status: DOCUMENTED DIRECTION ONLY — do not implement in this milestone.**

Planned building blocks:

- Provider Entity Catalog
- Historical Performance Store
- Historical backfill
- Incremental sync
- Operational Taxonomy
- Classification assignments
- Marketing Initiative foundations
- Benchmark Cohort foundations

Desired future behavior:

```text
Brand connects provider account
→ available provider history backfilled in resumable chunks
→ normalized daily facts retained
→ incremental updates continue
→ campaigns / entities are classifiable
→ historical filtering / comparison becomes possible
→ Evidence / Findings / Outcome / learning can use the history
```

Constraints:

- Historical store is **NOT RAG**.
- Do **not** use giant Evidence JSON dumps as the primary historical warehouse.
- Prefer normalized daily / entity facts with provenance.

---

## Agency Learning — future

**Status: PLANNED — no automatic self-modifying truth.**

Controlled future learning flow:

```text
Historical Evidence
+ Recommendation
+ Task
+ later Evidence
+ Outcome
→ Learning Candidate
→ human review
→ approved Agency Knowledge
```

No automatic Skill / Agent mutation from Outcomes without human approval.

---

## Outside-in Discovery status

**Latest Public Discovery slice applies to staging work branch `chatgpt/search-demand-foundation`, not main.**

Stage 1 now turns already-stored public Website HTML into reviewable information with actual canonical destinations. It uses the existing Integration collection engine for absent/stale/problem HTML and resumes the same operation after collection. The deterministic pass makes no AI or paid-provider calls. Historical AI/competitor candidates are preserved; their presence does not imply fresh external research.

Core choices (ADR-061):

- Original source time and exact URL identity matter. Missing, stale, unreadable, error-template and bounded/uninspected states remain explicit. Seven-day freshness, 500 pages / 32 MiB per pass and 5 MiB per object bound the analysis; existing collection limits are unchanged.
- Menus alone are not services; physical addresses are not automatically service areas. Same-value candidates combine provenance without resetting human decisions.
- Human approval links services to the existing Service Catalog / Brand Offering, explicitly structured areas to Brand Service Areas, and historical competitors to the Competitor Library. Existing priority, manual classification, relationships and exclusions win. Scalar replacement requires an explicit choice and matching current value.
- Approved social profiles appear in Integrations as candidates for the current authorization/resource/binding flow. Discovery never creates or binds an asset automatically.
- Receipts distinguish applied, integration-ready, observation-only and kept conflict. Historical approvals without a receipt require explicit transfer; there is no silent migration.
- There is no Agent-Reach runtime or new plugin framework. SERP/web research, social content, reviews, mentions and recurring discovery remain later stages.

The prior main implementation described bounded public crawling and optional provider competitor candidates. Do not claim that main has the new staging workflow. Do not describe either version as full digital-web intelligence. Canonical contract and verification limits: `docs/product/DISCOVERY_INTELLIGENCE.md`, `PRODUCT_CAPABILITY_LEDGER.md`.


## Operator workspace model — planned foundation

**Status: DOCUMENTED DIRECTION ONLY — not implemented; no UI built from this yet.**

`docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` defines one shared operator workspace shape across channel/module workspaces (Meta Ads, Google Ads, Website, GBP): **GLANCE → EXPLORE → DECIDE → DEEP DATA**, progressive disclosure, semantic-color-only design, no decorative charts, and the **Missing ≠ zero** rule (absent/uncollected data must never render as `0`).

It also codifies, as a UI-layer requirement, the existing platform-attribution-vs-verified-business-outcome distinction, and requires operator-facing workspaces to avoid internal jargon (Run/Evidence/ExternalResource/CoreAssetBinding) in favor of operator language — extending the pattern already used in `docs/product/integrations/WORKSPACE.md`.

Meta-specific application: `docs/product/META_ADS_EXPERT_WORKSPACE.md` (status: **BLUEPRINT / NOT IMPLEMENTED**; explicitly out of scope for PR #119).

Two decisions worth remembering from that blueprint:

- **Result Mix over forced Primary Result at account level.** When an account's campaigns have heterogeneous objectives, Overview should show a labeled breakdown across result types ("Result Mix") instead of collapsing to the current "Deferred" placeholder. Campaign/ad set/ad-level primary-result resolution is unchanged.
- **Delivered-in-selected-period is the default campaign filter**, not "Active now" — a campaign qualifies by `spend > 0 OR impressions > 0` in the selected period, sorted by material spend. Active/Paused/Archived/All remain explicit alternate filters.

A professional operator workspace (real performance-over-time, reliable multi-period comparison, fatigue-adjacent signals) is **blocked** on the Historical Performance Store / Operational Data Foundation and on `OPERATOR_ASYNC_EXECUTION.md` adoption — it cannot be honestly built on single-Run Evidence snapshots or blocking sync collection alone.

## Meta / Google intelligence (main vs unmerged)

### Google Ads Intelligence

Present on **canonical main** (module collectors, Findings, Google Ads Analyst + Skills, workspace UX).  
State details: see `PRODUCT_CAPABILITY_LEDGER.md`. Product doc often labels this “IMPLEMENTED V1” — that means a technical version slice, **not** automatically Definition-of-Done **DONE**.

### Meta Ads Intelligence

**PR #119** (`Meta Ads Intelligence + Analyst V1`) is the read-only Meta Ads Intelligence engine (collectors, Evidence, Findings, Analyst/Skills, interim specialist workspace).

Operator Ads Manager spot-check: **PASS**  
Account `act_744654160596455` · Campaign `09 | Diaspora TR | Form - Mox` · Period `2026-07-14`→`2026-08-10`.

Canonical ledger state: **UAT PASS / ACCEPTED — NOT DONE**.

Still explicit:

- **Background-ready: YES** for Collect live data + Generate AI guidance (database queue + Activity Center). Professional workspace still **NOT IMPLEMENTED**. Async Meta operator UAT is validated on the Async Operations PR (read-only).
- **Professional Meta Expert Workspace: BLUEPRINTED / NOT IMPLEMENTED** (`docs/product/META_ADS_EXPERT_WORKSPACE.md` + `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Do not call Meta Ads “complete”, “finished”, or “workspace done”

Main also has Meta **central Integration + resource discovery + binding** (connection layer).

Details: `PRODUCT_CAPABILITY_LEDGER.md`.

---

## Environments (material)

| Environment | Role |
| --- | --- |
| Cursor Cloud / local agent | **Development / automated test** only |
| PHPUnit | Isolated testing (`sqlite :memory:`) |
| Disposable browser-UAT SQLite | Synthetic browser checks only |
| **persistent UAT** | Future browser host when operator provisions infrastructure — **PREPARED / DEFERRED** (`docs/operations/PERSISTENT_UAT.md`) |
| Production | Future; **not** claimed by Async / UAT template work |

Persistent UAT decisions (when eventually used):

- Uses **MySQL 8** (not Cloud SQLite)
- Web = **Nginx + PHP-FPM**; plus separate persistent **queue worker** and **scheduler**
- One stable **`APP_KEY`** across deploys so encrypted provider credentials survive
- Provider credentials and real bindings must survive deploys; never regenerate `APP_KEY` casually
- Target hostname concept: `https://uat.dop.moximu.com` (operator DNS/host required)

**Async implementation acceptance** (queue + Activity + Cloud Meta smoke) is independent of **persistent deployment acceptance**. Operator decision (2026-08-12): do **not** provision VPS until Meta Expert Workspace UI is useful; Cursor Cloud remains development/test.

---

## Definition of Done

A feature is **NOT** considered **DONE** merely because code exists.

**DONE** requires the relevant dimensions to pass:

1. Code implemented
2. Automated tests
3. Real / provider UAT where applicable
4. Operator UX usable
5. Async / background-safe where long-running
6. Security / provenance checked
7. Known blockers resolved
8. Canonical documentation updated

Use explicit states such as:

| State | Meaning |
| --- | --- |
| `PLANNED` | Direction accepted; no meaningful product code |
| `IMPLEMENTING` | Active work; not ready to treat as main capability |
| `CODE COMPLETE` | Code on target branch; tests/UAT/UX may lag |
| `TESTED` | Automated tests cover the capability on main |
| `UAT REQUIRED` | Needs real provider / operator verification |
| `UAT PASS` | Real/provider UAT recorded as pass for the scoped slice |
| `PARTIAL` | Meaningful subset only; gaps are explicit |
| `BLOCKED` | Cannot proceed without resolving a named blocker |
| `DONE` | Meets Definition of Done for the scoped slice |

**Avoid** using “Implemented V1” as a synonym for **DONE**.

Technical version labels (for example Agent 1.0.0, “Intelligence V1”) remain valid as **version identifiers**, not completion claims.

Reconcile claims against `PRODUCT_CAPABILITY_LEDGER.md` before asserting completeness.

---

## External repository references

Reviewed external repos are **references only**. Never automatically vendor / copy them into this repository.

| Repository | Role |
| --- | --- |
| [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) | Methodology / Skills reference |
| [joshbuchea/HEAD](https://github.com/joshbuchea/HEAD) | Technical SEO taxonomy reference |
| [AgriciDaniel/claude-seo](https://github.com/AgriciDaniel/claude-seo) | SEO methodology + Recommendation framing reference |
| [every-app/open-seo](https://github.com/every-app/open-seo) | Selective implementation / workflow reference |
| [zubair-trabzada/geo-seo-claude](https://github.com/zubair-trabzada/geo-seo-claude) | Future GEO methodology reference |
| [garmeeh/next-seo](https://github.com/garmeeh/next-seo) | Structured-data taxonomy / reference |
| [pipeboard-co/meta-ads-mcp](https://github.com/pipeboard-co/meta-ads-mcp) | Meta taxonomy / reference only — **no** runtime / write adoption |
| [georgekhananaev/google-reviews-scraper-pro](https://github.com/georgekhananaev/google-reviews-scraper-pro) | Review intelligence concepts only — scraper runtime **rejected** |
| [Panniantong/Agent-Reach](https://github.com/Panniantong/Agent-Reach) | Capability / Adapter architecture reference — runtime **not** adopted |
| [OpenHands/OpenHands](https://github.com/OpenHands/OpenHands) | Future Platform Engineer research reference — **not** customer-analysis runtime |

Canonical adoption registry: `docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`.

---

## Website source boundary (accepted 2026-08-29)

- WordPress Connector = CMS inside truth. Public Discovery = externally published HTTP/HTML truth.
- A paired WordPress Website keeps Public Discovery and adds the authenticated connector family; it never replaces public verification.
- Non-WordPress Websites use public collection families.
- Connector is asset-scoped, read-only, least-data and signed. No WordPress writes, users/passwords/comments or media binaries.
- Integration screens show collection truth only. Deterministic Findings, Recommendations and manual Task handoff belong to the Website Digital Asset analysis workspace.
- Final visitor HTML is stored separately as versioned `website_html_snapshot` observations. SHA-256 change state links each observation to a content-addressed private compressed artifact; unchanged HTML does not duplicate the body. WordPress `post_content` remains distinct CMS truth.
- Public collection seeds from sitemap, existing URL inventory and published connector permalinks, then follows real same-site links within the explicit 5,000-page / 2 GB per-run and 10 MB per-response bounds. Discovered URL count is never presented as captured-HTML coverage.
- Update availability is an observed maintenance state, not a CVE/vulnerability claim.
- Code/test completion does not prove live WordPress UAT or production deployment.

Canonical decision: ADR-045. Detailed contract: `docs/product/website/WORDPRESS.md`.

---


## Intelligence Core boundary (accepted 2026-08-31)

- Intelligence Core provider-neutral identity/provenance/metric/capability layeridir; provider fact tablolarının yerine geçen ikinci bir warehouse değildir.
- Canonical dimensions: Page/URL, Search Term, Entity, Business Action, Time/Context ve Source/Provenance.
- URL join key scheme, `www`, path case ve trailing slash bilgisini korur. Redirect/canonical/CMS/rule/operator kanıtı olmadan Page identities birleştirilmez.
- Search term canonical text diacritics korur; folded text yalnız clustering candidate üretir. Source semantics alias üzerinde ayrı kalır.
- Missing ≠ zero; estimated ≠ measured; platform signal ≠ verified business outcome. Magic score ve ad-hoc formula yoktur.
- DataForSEO, GBP veya gelecekteki AI search kaynağı capability adapter ekler; mevcut source tables veya projection tüketicileri yeniden tasarlanmaz.
- Rebuildable Page/Search Term/Entity/Outcome profilleri Website Projection tarafından source-keyed read model olarak uygulanır. Bu katman provider fact tablolarını kopyalayan generic warehouse değildir ve source facts silinirse tek başına canonical truth sayılmaz.
- Mevcut Formula/Evidence/Finding/Recommendation/manual Task hattı tek otoritedir. AI Finding/Task oluşturmaz; external write yapmaz.

Canonical decision: ADR-046. Machine-readable contract: `resources/intelligence/MOXDOP_INTELLIGENCE_CORE_V1.json`.

---

## Website Intelligence Projection boundary (accepted 2026-08-31)

- Projection’ın canonical girdileri mevcut Website public/HTML, authenticated WordPress, bound GSC ve bound GA4 fact tablolarıdır. Kaynak fact tabloları authoritative kalır.
- Projection dört kimlik profili üretir: Page, Search Term, Entity ve Outcome. Her profil tek satırda source-keyed typed state, period, coverage, value state ve provenance taşır; generic EAV metric warehouse değildir.
- Varsayılan analitik pencere son tamamlanmış 90 UTC gündür. GSC/GA4 kaynakları kendi coverage ve watermark bilgisini ayrıca taşır; missing veya provider-omitted değerler sıfıra çevrilmez.
- WordPress CMS içeriği ile public visitor HTML aynı Page identity üzerinde ayrı `wordpress` ve `website` source state olarak kalır. Birinin alanı diğerinin yerine kullanılmaz.
- GSC query↔page ilişkileri provider limitleri belirtilerek korunur. GA4 Key Event, explicitly mapped Business Action altında provider-attributed signal olarak kalır; operator-verified outcome’a otomatik yükseltilmez.
- Collection tamamlandığında ilgili Website projection rebuild işi kuyruğa alınır. Projection kısmi kaynak hatasında mevcut başarılı source state’i korur; tam rebuild yok olan profilleri temizleyebilir.
- DataForSEO, GBP, Ads ve gelecekteki AI Search yeni source adapter ekler. Mevcut profile tüketicileri ve provider tabloları yeniden tasarlanmaz.
- Bu milestone backend projection/read service’tir. Operator Website sekmeleri, formula→Evidence tüketimi, test ve live UAT ayrı aşamalardır; DONE değildir.

Canonical decision: ADR-047. Implementation truth: `PRODUCT_CAPABILITY_LEDGER.md`.

---

## Search Demand foundation boundary (accepted 2026-09-02)

- MoxDOP's minimum commercial context is Customer → Brand → operator-selected Services + explicit country/city/district Service Areas.
- Global Service Catalog is reusable agency vocabulary. Existing Brand Offering remains the Brand-scoped identity and links to the catalog; neither replaces the other.
- Search Query Library is agency-wide operator knowledge with source records. It is not provider Evidence, a second Intelligence warehouse or a ranking claim.
- Brand-scoped provider queries continue to converge through `IntelligenceSearchTermIdentity`. A later Brand Query Portfolio will resolve approved Library items into that existing identity layer.
- Do not create a permanent Service × Area Cartesian product. Keep service and area relations separate; render provider request variants only when required.
- AI is reserved for bounded language classification and clustering candidates. Operator review and later SERP validation are required before URL ownership decisions. AI never invents metrics, Findings or Tasks.
- Query source observations retain provenance and missing values. Google Ads, GSC and DataForSEO observations do not overwrite one another.
- Search Demand Librarian execution is queued and persists proposals separately from Library truth. Exact reuse requires the same input, Agent, Skill definition and AI route/model fingerprint.
- AI-generated service aliases and query semantics are applied only after explicit operator approval. Rejected and abstained candidates remain auditable; abstention is never converted into a synthetic classification.
- Brand Query Portfolio references global Library identities instead of copying query text. Brand-only queries and Brand overrides are separate operator facts; global promotion is a submitted proposal, never an automatic write.
- Global, Brand and Website query scope are distinct. Portfolio application resolves through canonical Brand-scoped `IntelligenceSearchTermIdentity`; website activation is an explicit relation.
- Multi-region query variants are rendered from Brand Service Areas at use time. The default `all_brand_areas` scope and optional selected-area relations must not become a persistent Service × Area Cartesian table.
- Brand query clustering stores demand family, predicted SERP intent and content target as separate Brand-scoped layers. AI runs are queued proposals; human approval is the only apply path.
- Clusters are lockable and versioned. Incremental clustering only receives currently unclustered active portfolio queries; move, merge and split operations preserve stable item IDs and append snapshots.
- Without observed SERP evidence, a cluster remains `ai_prediction`. Semantic confidence must not be presented as SERP validation, ranking evidence or URL ownership.
- The Query–URL Visibility Map reads website-active portfolio queries and existing GSC, GA4 and Website Projection facts. It does not persist copied performance values or introduce another warehouse.
- GSC query–URL metrics are first-party measured at query/page grain. GA4 landing metrics remain page grain and are never represented as query attribution. Requested-period absence is `unobserved`/unknown, not zero.
- Search Demand SERP enrichment is a separate manual, queued and paid-consent-gated workflow behind a provider-neutral adapter. It is never invoked by Brand creation, import, page render or routine scheduling.
- Exact query/market/language/device/depth fingerprints reuse fresh SERP and keyword-metric observations. Every paid POST receives a durable pre-call marker, one queue attempt and fail-closed `CHARGE_UNKNOWN` handling when commit cannot be proven.
- DataForSEO search volume, CPC, competition and monthly trend are provider estimates and remain distinct from measured GSC/GA4 facts. Missing estimates remain unknown. Configured pre-call USD values are estimates; provider-reported cost is separate provenance.
- Optional DataForSEO query expansion creates review candidates only. Operator approval may add and activate a Brand Portfolio query; no automatic cluster membership, global promotion or Finding/Task follows.
- Observed exact-URL SERP overlap creates a threshold-provenanced cluster validation recommendation. Only human approval applies `serp_validated`, `serp_conflict` or `review_required`; that Phase 7 action never changes the separate Phase 8 URL ownership decision.
- URL ownership is a versioned human decision at Website + content-target-cluster grain. Candidate generation reads existing Website Page Projection, GSC query–page facts and stored SERP Brand URLs; it does not create a second metrics warehouse.
- The URL technical gate is fail-closed: only a same-Website public page with observed 2xx HTTP, no observed `noindex`, no canonical to another URL, matching observed language and an allowed content URL type may be proposed or verified. Missing gate evidence remains `unknown`.
- Two-period GSC leader changes and split visibility produce wrong-URL/cannibalization review candidates only. Page Relevance AI receives a bounded evidence pack, proposes at most one eligible page or abstains, and cannot change ownership.
- Human approval rechecks the live gate, records decision-time evidence and may lock ownership. Redirect, deletion, merge, new page/content, Finding, Recommendation, Task, provider spend and external write never follow automatically.
- Competitor Library identity is Brand + normalized domain. `www` is folded into the same domain while other subdomains stay distinct; candidate status is separate from role and entity-kind classification.
- Stored DataForSEO SERP/domain observations may create a bounded SERP competitor candidate with source/query/URL/time provenance. They never establish commercial competition automatically and the Phase 9 import never calls the provider.
- Commercial, SERP and content roles are independent. Business, directory, platform and authority-site kind is a separate operator classification; unknown remains allowed.
- Approved competitor links to services, Brand Service Areas, content-target clusters, appeared-on queries and observed URLs without creating a Service × Area Cartesian scope. Manual addition is an explicit human approval; pending candidates support individual/bulk review.
- Competitor page fetch/crawl is Phase 10 and Competitive Intelligence AI is Phase 11. Phase 9 creates no crawl, AI inference, Finding, Recommendation, Task, provider spend or external write.
- Competitor page collection is a queued, cluster-scoped Phase 10 operation over approved Competitor Library URLs. Selection is deterministic and bounded to 3 URLs per competitor / 20 per run; extracted links are observations and are never followed, so this is not a whole-site crawl.
- Phase 10 reuses Public Discovery's SSRF-safe bounded HTTP fetcher. It stores normalized text, title/meta/H1/headings, schema summary, bounded internal/external links and deterministic service/location expression matches with observation history.
- Raw HTML and normalized-content fingerprints detect repeats. Exact raw repeats skip parsing; unchanged normalized content appends a lightweight observation that references the prior content record instead of duplicating it. Phase 10 has no AI, Finding, Recommendation, Task, provider spend or external write.
- Competitive Intelligence Phase 11 requires a human-verified URL owner with checksum-verified stored Website HTML plus successful Phase 10 observations from approved, cluster-linked competitors. It never browses or fetches pages itself.
- The dedicated Competitive Intelligence Analyst receives bounded excerpts (Brand 16k chars, competitor 12k chars, max 8 newest unique competitor URLs), treats page/query content as untrusted data and persists exact Agent/Skill/route/input provenance.
- Phase 11 describes gaps as unanswered user needs/questions rather than word-count comparisons. Proposed competitor kind/roles, page intent, topics, structure, local trust, unnecessary/do-not-copy content and Brand differentiation remain separate review-only analysis records.
- Accepting or rejecting a Competitive Intelligence analysis changes only its review state. Competitor truth, URL ownership, Findings, Recommendations, Tasks, pages and external systems are unchanged; Phase 12 owns Finding/Recommendation creation.
- Search Demand Phase 12 selected-page AI receives current verified Brand-page evidence and Website standards. Human-approved comparable Phase 11 analyses are optional supporting context (ADR-060); pending/rejected/abstained analyses are excluded. Site-wide technical title/head/link checks now belong to the independent Website standards assessment.
- Every Phase 12 semantic proposal carries Agent/Skill/route provenance, exact analysis/observation/competitor references, evidence confidence, rationale, verification steps, one bounded action type and a non-publishable content brief. `insufficient_evidence` and abstention remain non-promotable states.
- Human acceptance is the only Phase 12 promotion path. It publishes canonical derived Evidence, attaches it to a Finding evaluation, writes/reconfirms the existing canonical Finding and creates a Finding-sourced Recommendation through the existing writer. It never creates a second Finding/Recommendation model.
- Recommendation → Task remains a separate explicit operator action. Phase 12 never creates a Task, changes URL ownership, publishes content, mutates a Website or writes externally. Change/result measurement remains Phase 13.
- Search Demand Phase 13 starts only from a completed Task linked to a human-approved Phase 12 proposal. The applied-change record stores affected URLs/clusters, application and review dates, and pre/post HTML fingerprints; it is provenance, not a separate Result entity.
- Targeted verification uses the shared read-only Website Public Crawl for exact affected URLs plus at most 99 matching page-family URLs. It never starts DataForSEO or performs a Website/CMS write.
- Deterministic technical rechecks, stored GSC/GA4 periods, stored pre/post SERP snapshots and a bounded Website Change Verification AI proposal remain separate components. Missing observations stay insufficient, and metric movement never becomes causal attribution.
- Phase 13 AI output is review-only. Human acceptance is required before the existing Task `outcome_*` fields change or a resolved/reconfirmed FindingEvaluation is appended. Task remains the only current Outcome truth; no Result/Outcome table exists.

Canonical decisions: ADR-048, ADR-049, ADR-050, ADR-051, ADR-052, ADR-053, ADR-054, ADR-055, ADR-056, ADR-057, ADR-058 and ADR-059. Canonical product contract: `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`.

---

## Website standards and improvement boundary (accepted 2026-09-06)

- Scope is the staging work branch `chatgpt/search-demand-foundation`, not main. The operator authorized implementation and direct branch saving, with no PR and no server execution.
- Integrations remain the collection/binding source. Services, areas, query/cluster and competitor Library records and human URL locks are retained.
- The Website module owns a 26-entry versioned standard catalogue, preserving all 17 diagnosis IDs. Admin controls activate/deactivate criteria and add expert review criteria with evidence/applicability/source/action/verification metadata.
- Website → Standards & Improvements evaluates stored Website profiles and HTML asynchronously with zero AI/provider calls. Missing/old evidence is unknown; optional/heuristic checks are distinguished from verified failures. There is no aggregate SEO/GEO score.
- Reuse the existing Run/improvement-proposal/human approval path. Standalone runs have nullable cluster/owner/competitive IDs; accepted technical proposals use Website source/asset subjects in the canonical Finding/Recommendation pipeline. Task creation remains manual.
- Technical groups prioritize verified-target accessibility/indexability blockers, other technical defects and advisory review, then affected URL count. Service/query/cluster coverage is separately ordered by repair need and explicit service priority.
- A blocked relevant page is a repair/review candidate, not proof that another page is needed. Existing verified owners remain locked until a human changes them; coverage matching cannot write ownership.
- Selected-page AI reviews stored content against criteria without requiring competitors. Comparable approved competitor analysis can enrich the same criterion. Exact own/rival excerpts and criterion IDs are checked; non-actionable, unsupported or abstained results cannot be promoted.
- Old or changed criterion/page/cluster/owner/competitor context cannot be approved as current. HTML observation IDs/timestamps alone do not force a second semantic call when content and other inputs are identical.
- Limits: 500 profiles, 3,000 active queries, 100 clusters, 20 candidates per cluster, 30 custom criteria, 5 MB per stored HTML and 16,000 own-page excerpt characters. Limits remain visible; unsupported whole-site conclusions are prohibited.
- Site-wide technical recommendations are rechecked with the standards assessment; Phase 13 remains cluster-scoped and does not accept standalone technical proposals. There is no automatic closure of Findings or automatic new-page/merge instruction from one page excerpt.
- All Search Demand Skill context keys are explicitly catalogued as workflow inputs; this does not create canonical Evidence or grant collection/writing capabilities.
- Validation and deployment truth are recorded in the Capability Ledger; local automated success does not establish operator/model/PostgreSQL UAT.

## Source priority

Preserve MASTER_SPEC supremacy while integrating project memory:

1. `docs/MASTER_SPEC.md` — product truth (highest)
2. Latest accepted ADRs (`docs/foundation/DECISION_LOG.md`)
3. `PROJECT_MEMORY.md` — persistent product / architecture memory (this file; does not override MASTER_SPEC)
4. Relevant `docs/product/*` / module blueprints
5. `PRODUCT_CAPABILITY_LEDGER.md` — **implementation truth** (coded / tested / UAT / UX / async)
6. `docs/IMPLEMENTATION_ROADMAP.md`
7. `docs/PROJECT_STATUS.md`
8. `AGENTS.md` / supporting references (`docs/foundation/*`, `docs/module-sdk/*`, research)

`docs/current-state/*` remains **historical** snapshot material. On conflict with the sources above, current-state loses.

When behavior or capability state changes, update `PRODUCT_CAPABILITY_LEDGER.md` in the **same PR**.  
When material product / architecture decisions change, update `PROJECT_MEMORY.md` in the **same PR**.

---

## Related canonical docs

| Doc | Role |
| --- | --- |
| `docs/MASTER_SPEC.md` | Product constitution |
| `PRODUCT_CAPABILITY_LEDGER.md` | Capability truth table |
| `OPERATOR_ASYNC_EXECUTION.md` | Operator async execution standard |
| `docs/PROJECT_STATUS.md` | Human/agent progress tracker |
| `docs/product/*` | Domain blueprints |
| `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` | Global operator workspace model (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/product/META_ADS_EXPERT_WORKSPACE.md` | Meta-specific workspace blueprint (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/foundation/DECISION_LOG.md` | ADRs |

## Global services management — 2026-09-07

Operator-authorized scope: Library navigation contains only Services, Search Queries, Query Clusters and Competitors. Other routes remain available to existing deep links. Services is the agency-wide editing surface: paginated searchable table, sector filter, edit drawer, sector CRUD and reversible deletion.

Global catalogue names are authoritative. A rename synchronizes linked Brand Offering primary names in one transaction, preserving offering IDs, priorities and goal/query relationships. A conflicting name aborts the entire edit. Brand-local rename of linked services directs the operator to Library. New Brand Offerings resolve the global catalogue, and the additive migration links legacy unlinked offerings without fuzzy matching or silently merging conflicts.

Service deletion is soft deletion: current catalogue/name/brand-offering reads hide the deleted identity, while historical foreign keys and observations remain. Restore brings the same identity and its links back. Current Brand Intelligence products/services and priority projections refresh on rename, deletion and restoration. Category keys remain stable on rename; deleting a category clears the service category, never deletes its services.

Validation: operator explicitly requested direct GitHub edits, no cloning and no tests. No test, build, browser or staging database verification is claimed. Deployment and operator acceptance remain required.


## Library imports and central locations — 2026-09-08

Scope: operator-authorized direct edits on staging work branch `chatgpt/search-demand-foundation`. Library navigation remains Services, Queries, Query Clusters, Competitors.

- Services supports bulk lines (up to 2,000), optional `service | phrase, phrase` syntax, and editable matching expressions. Expressions have service-local uniqueness; shared expressions can match multiple services. Existing global identity aliases remain separate.
- Queries supports paste, XLSX/CSV/TSV/TXT and selected stored Google Ads / Search Console resources over an explicit date range. Only existing canonical provider fact tables are read. No provider collection/API/spend follows import, and provider metrics are not copied or aggregated as query performance.
- A valid sector is mandatory. Optional service selections must be active and belong to that sector. Whole-phrase matching uses selected service expressions. Unmatched queries remain available through the sector-aware Unassigned filter. Operators can bulk assign up to 500 selected queries and create a sector/service inline.
- The agency Library identity for new writes is the location-free canonical text, independent of account, sector and market. Existing exact canonical items are reused. Multiple sectors attach to one item rather than creating another identity; the legacy scalar sector remains the primary compatibility value. Provider Intelligence identities and provider facts are unchanged. Existing historical query variants with locations are not destructively merged or rewritten by this migration.
- Country/province/district names are stripped at whole-expression boundaries, longest first; Turkish/ASCII spelling is folded only for location and phrase recognition. Original query spelling and removed expressions are visible in source records. Ambiguous names such as Of/Kale are also stripped under the operator's literal rule; the import panel says so. Location-only rows fail explicitly. Source repetition does not add identical source rows.
- The central bundled catalog has 249 ISO country/territory labels, 81 Turkish provinces and 973 districts with parent IDs and available dataset details. Original MIT files, licenses and pinned provenance live under resources/data/locations. No runtime downloads, new provider, new dependencies or Locations navigation item. CountryOptions and CityOptions delegate to the central catalog; Brand, customer HQ, prospect, public-discovery and search-profile location inputs use it. Non-Turkey free-form existing city data remains possible; no additional foreign subdivision catalog is introduced. Provider-specific geotarget IDs retain their own provider contract.
- Imports and bulk assignment use durable SearchQueryLibraryImport records and 100-row queued chunks, with bounded inputs, progress, first 20 row errors and terminal cleanup of temporary source files. Activity links to the relevant Library screen. Unknown failure never reports completion; reimport is safe. XLSX expansion is bounded to 32 MiB, 256 columns and 10,000 query rows; XML external loading and DTD/entity declarations are not accepted.
- Migration adds matching expressions, query-sector relations and import payload storage; backfills sector relations while preserving IDs and historical records.

Verification truth: source was reviewed without cloning, installing, running tests, Pint, build, browser acceptance or staging execution, as explicitly requested. Code is saved for deployment; migration, workers, runtime and operator acceptance are unverified. Not a DONE/UAT claim.


## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.


## Query Library daily management — 2026-09-08

Operator-authorized scope: inline query-name editing, download of filtered/all queries, immediate removal and related list usability, on `chatgpt/search-demand-foundation`.

- Hover/focus pencil opens the row editor; mobile keeps it visible. Enter saves, Escape cancels, inline errors retain input. Rename applies the same location stripping and canonical normalization as import, checks existing/deleted identities and rejects stale concurrent edits. ID and service/sector links persist. Original observations remain; a manual source record records previous/new input, actor and stripped locations. Linked Brand portfolio entries without text overrides refresh their Intelligence identity through the existing resolver; provider fact identities are not rewritten.
- Soft removal uses an additive deleted_at column. Removed items leave normal Library reads; Deleted supports individual/bulk restore, and the last individual removal has Undo. Imports refuse to recreate/resurrect removed identities. Existing Brand portfolio references explicitly retain their Library relation (withTrashed); removal from Library does not silently remove previously applied Brand queries.
- The default unfiltered list includes all nondeleted statuses. Status, sector, service, source, unassigned and literal folded search use one model query scope shared with authenticated CSV export. Export downloads all matching records, independent of pagination or row selection, in stable selected ordering. It is a direct streamed download with 500-row eager-loaded batches, UTF-8 BOM, semicolon delimiter and formula-cell neutralization; no Livewire base64/full-memory download. A concurrent writer can change records during a long export: no point-in-time snapshot guarantee.
- Service filter, clear-filters action, status labels, newest/A–Z/Z–A sorting, 25/50/100 page size and page correction after removal are exposed. Selection actions preserve the current filter scope and have a 500-selected-row mutation limit. Selected rows can be activated, excluded, removed or restored. Existing assignment/import jobs remain queued. New bounded status CRUD and rename are synchronous; export is a streamed read, not provider or AI work.

Verification: source inspection only. Per operator instruction no clone, tests, dependency install, formatter, build, browser or server execution. Migration, runtime, CSV opening and UI acceptance are unverified; no DONE claim.


## Query exclusion list — 2026-09-08

Operator approved the proposed exclusion-list workflow, then requested continuation. Scope remains direct staging branch `chatgpt/search-demand-foundation`; no main, PR or server deployment.

- Sorgular embeds a collapsible Exclusion list with bulk expression entry (1–2,000 lines per save), normalized deduplication, search, edit, enable/disable and deletion. Matching is whole folded word/phrase, case/Turkish-ASCII tolerant, never substring or fuzzy typo guessing. Deleting a rule never restores previously removed queries.
- Existing cleanup is a durable queued preview over all nondeleted Library queries through the start-time maximum ID, independent of current list filters. It checks canonical text and retained original source texts, showing the actual matching text/expression. All matches are paginated and initially selected; operators may uncheck individual rows or select/deselect the full preview before approval.
- Only the creator can approve their ready run. Run-row locking serializes approval and preview selection changes. Current rule fingerprint must match the preview; changed rules require a new preview. Apply processes only approved selected rows, rechecks item version, matching text and exceptions, and soft-removes with the existing model. Changed/protected/deleted rows are skipped, never silently replaced by unseen candidates. No Brand portfolio or provider fact deletion follows Library removal.
- Scan/apply execute in 100-item queued chunks with durable cursor/results/counts, overlap protection, terminal failure history and no automatic restart after partial failure. History resumes from the Sorgular panel after navigation/reload. It is a dedicated Library maintenance history, not a new provider collection or Finding/Run engine.
- New writes through SearchQueryLibraryService consult active exclusions before location stripping, preserving the existing store return contract through a specific validation exception. The main paste/Excel/CSV/stored Ads/GSC LibraryImportWorkflow catches it as a separately counted excluded row, storing every raw row and matched rule snapshot for paginated inspection. These are neither accepted queries nor generic errors/duplicates. Older callers of the shared writer also respect screening and receive the explicit validation reason.
- Individual and bulk restore expose an enabled-by-default protection checkbox. Protection stores a canonical query exception used by both preview/apply and future imports. Exceptions can be removed from their own list; removing protection does not immediately delete the query. Import raw text is checked first; only exemption identity resolution uses the location-free canonical text.
- Rule changes during processing are checked between rows; execution is not an all-or-nothing global transaction. Completed removal receipts persist and removed queries remain recoverable. Preview membership is a snapshot; new/changed data can be covered by a new scan.

Verification truth: code inspected only; operator forbade cloning and tests. No tests, formatter, build, browser or staging DB/queue execution ran. New migration, worker runtime and human acceptance remain unverified. No DONE/UAT claim.

## Manual service query clusters — 2026-09-09

Explicit operator decision supersedes the Library menu's AI-first clustering flow: a global service is the main cluster, and operators create one level of child page groups without creating another service. This scope is the authorized four-step manual roadmap on staging; no provider or AI calls are part of clustering.

- The canonical query-service pivot now carries a nullable child-cluster reference, review timestamp and monotonically increasing placement revision. No query text or service relation is copied. Null means main cluster. Existing assignments initially appear as unreviewed/New. Imports that reuse a service association leave its placement/review state intact; newly attached service associations enter the main cluster.
- The root Library route opens the bilingual manual workspace. The existing Brand AI cluster records and downstream SERP/ownership/competitor consumers remain intact; their previous workspace is retained at /library/search-demand-clusters/legacy. Global manual IDs are never passed to Brand cluster consumers. Bridging manual groups into future competitor/HTML work is a later explicitly scoped step, not implemented here.
- The workspace provides sector and service/child search, a collapsible service tree, direct/total counts, paginated active queries, contains/does-not-contain phrases (any/all), dates, New and include-children filters, page/all-filtered selection, inline central-query editing and move/new-destination dialogs. The filter set is shared with snapshot exports. A child may be created or renamed with same-service name uniqueness and an optimistic revision guard.
- Bulk moves, keep-in-main, child merge/removal, undo and CSV exports use durable operations. One INSERT SELECT captures selected membership IDs, text and placement versions in the confirmation request; no full ID array is hydrated in Livewire. Mutation and export work then runs in 250-row queued steps with receipts and counters, plus Activity and paginated history. The SQL snapshot itself is synchronous; no load benchmark or 100k latency claim has been made.
- Operations serialize per service; a failed operation can resume from pending receipts or explicitly close while preserving completed changes. Request UUIDs prevent duplicate submissions. An all-filtered selection is independent of pagination. A changed confirmation count aborts and asks for a fresh selection. Subsequent imports do not enter an already captured operation.
- Undo checks membership identity and placement revision; newer changes, removed assignments and unavailable destinations are skipped. It does not undo the central query text. Retired children retain targets and names in receipts; their target URL snapshots remain visible in history. Undo can reactivate a removed child unless its name was reused. Merge/removal includes inactive/deleted-query associations so restoring a query cannot strand it in a removed child.
- Website-specific target URLs are explicit page plans, separate from technically verified legacy ownership. They are editable per service + child-or-root + Website, restricted to that Website's configured exact host, and never trigger fetches or provider writes. Clearing a URL retains a revision tombstone; targets are not silently copied during merge.
- CSV files are prepared on private local storage in bounded chunks, with UTF-8 BOM, semicolon delimiters, formula neutralization and authenticated download. Filtered export covers all matching active queries; structure export covers the selected service and children, ignores query filters and includes empty children. Export query rows retain confirmation-time text/group names. CSV order is stable membership ID order. Temporary/export files and operation receipts currently have no automatic retention cleanup.

Verification: source review only. Operator explicitly forbids cloning, tests, dependency installation, formatting/build and PR/main actions. No PHP/Blade compilation, migrations, queue execution, browser UAT or server deployment ran. Code is reviewable on staging, not a DONE or verified 100k throughput claim. Existing import-batch caps remain unchanged; this workspace handles the accumulated library.


## Automatic account collection and query imports — 2026-09-09

Operator explicitly approved the reviewed automatic collection/import design on `chatgpt/search-demand-foundation`. No main, PR, merge, cloning, dependency installation, tests, build, or direct server deployment is authorized in this task.

- New resource-level settings govern discovered Google Ads client accounts, GSC properties and GA4 properties using their existing central smart collectors. Default collection is daily with deterministic account staggering across the day; operators can pause, choose every three days, or request the next scheduler tick. Manager/MCC containers are displayed but never collected as client accounts. Discovery here enumerates already stored external resources; it does not rerun provider discovery automatically.
- Meta Ads and GBP reuse their existing confirmed-binding collectors, scoped to the exact external resource/binding. Unbound resources explicitly show that binding is required. This feature does not create fake Brands, Assets or bindings, and does not add a resource-first Meta/GBP collector. Website crawl/WordPress schedules and paid DataForSEO refreshes are not enabled by this change.
- Laravel scheduler runs `moxdop:resources:automate` every minute, performing bounded database planning. Provider work remains in queued jobs and canonical CollectionRun/DatasetRun workers. At most two automatic account collections/planners and four active automatic query batches are admitted by default; pre-existing active collections consume available account slots. Existing worker/provider quota limits remain authoritative. Manual central smart starts and automatic starts share resource locks and reject active resource runs. Existing GA4 daily restatement schedule is replaced by this cadence to avoid a second automatic driver; its manual command remains available.
- Existing initial policies remain authoritative: GSC 486 days, GA4 configured initial window, Google Ads activity-aware historical periods. Successful dataset-family coverage is used for catch-up after long gaps; recent restatement windows remain provider-specific. GA4/GSC repair plans retain saved checkpoints. GSC optional search-type probes use the incremental window after initial discovery. Recent resource-run object hydration is bounded; coverage lookup still uses successful historical dataset records.
- Collection and query-import state are separate. Only completed query dataset runs whose exact resource is completed/partial are eligible for Library ingestion; unfinished datasets never advance the Library receipt. Dataset receipts, rather than a largest dataset ID, allow an older slow dataset to complete later without being lost. First mapping covers successfully collected existing data. Fact rows are read by `(external_resource_id, last_dataset_run_id, id)` in 100-row chunks with supporting indexes and a captured upper ID. No 10k paste/file cap applies to accumulated automatic imports.
- Matching dictionaries are reused within each bounded worker chunk. A persisted rule fingerprint avoids repeating unchanged service matching for already observed queries; manual unblock invalidates that fingerprint.
- Account mappings require a valid sector and optionally selected active services in that sector. Empty service selection uses all active services of the sector. Matching uses the shared whole-expression service dictionary; multiple matches attach multiple services, unmatched queries stay sector-scoped. Source-specific metrics remain in provider facts and are not summed/copied as Library performance.
- Every automatic batch snapshots sector, eligible service IDs and mapping revision. Cadence-only settings changes do not change mapping revision. Settings changes do not rewrite previously observed queries or an in-flight batch; interrupted old-scope imports can resume with valid original scope or explicitly close while retaining completed changes. A confirmed queued recheck reevaluates this account's existing active unassigned queries in the selected sector; it never reallocates existing service/cluster memberships or changes other sectors.
- Existing location stripping, active exclusion rules, exact canonical deduplication and soft-delete protection apply. Per-account original query observations retain first/last reporting dates, decision and canonical query ID. Repeated provider rows do not duplicate canonical queries or source observations. Raw rows excluded by current rules remain inspectable, and changing a rule does not silently delete existing queries without the existing preview/approval workflow.
- A new alias map remembers manually renamed query identities, including bounded migration of historical rename receipts, so old provider spellings resolve to the renamed query rather than recreating it. Manual query deletion remains sticky. Service chips now allow explicit assignment removal with a persistent query/service block; all shared keyword matching respects that block. Explicit manual assignment or Allow automatic matching clears the relevant block. Existing child-cluster placement/revisions are not rewritten by imports.
- Bilingual collapsible account controls appear in Google/Meta Integrations and Queries. All discovered Ads/GSC resources are also available in the manual import selector even before facts exist. Controls include cadence, enable/disable, sector/services, next run, last collection/import success, last successful batch data date, recent counts, account observation history, recheck, resume and close-interrupted. Times on this control are explicitly UTC; historical provider facts retain their own reporting clocks. Account lists paginate 20, observations 25, recent import history 10; full LibraryImport and CollectionRun history remains in Activity.
- Transient collection attempts use existing provider retries plus bounded account retry (30 minutes, then 3 hours; repeated failures require attention). Revoked access/cancellation do not cause retry storms. Completed-data imports can resume without provider recollection. Required-attention failures reuse OperationalAlert lifecycle and configured in-app recipients; successful routine refreshes do not send new notifications. Default recipient/preference policies remain unchanged.
- Source-row counters are not unique-query totals: a query can appear on multiple reporting dates. Concurrent restatement may replace a fact's last dataset owner; that row is accounted for by the later completed dataset, not imported from an unfinished one. Canonical source provenance is retained; no full fact-table snapshot or atomic cross-provider view is claimed.

Verification truth: source inspection only. No tests, lint/Pint, PHP/Blade compilation, migration execution, queue execution, browser UAT, 100k benchmark, or server deployment ran, as explicitly instructed. Deployment must apply the additive migration and keep the existing scheduler and queue/collection workers active. Snapshot/observation/receipt retention uses existing storage policy; no automated purge is introduced. This is code prepared for staging, not a DONE/live-UAT claim.


## Connector activity and standards extension — 2026-09-09

Operator-approved sequence: connector → integration ingestion/UX → deterministic standards.
The existing pairing and read endpoints remain compatible; downloadable connector version is 1.1.0.
No clone, dependency install, tests, formatter, build, browser or host execution was performed.
Source implementation is not deployment, runtime acceptance, or DONE.

Implemented:
- WordPress local non-autoloaded outbox table, 50-event signed POST batches through WP-Cron every
  five minutes, signed per-ID acknowledgements, replay protection, retry/backoff and 10,000-event cap.
  Save hooks never perform HTTP. Events in one request coalesce by type/object; separate editor
  requests remain separate audit records. Missing queue writes/overflow report a persistent gap.
- Content publish/update/status/delete, allowlisted SEO/business metadata, selected settings,
  theme/plugin maintenance and role-change events. Acting WP user ID/display name are included;
  no passwords, form entries, arbitrary metadata values, visitor clicks or binary media.
  Public content types are inventoried; internal submission/order stores are excluded.
- Safe cached/runtime health, delayed cron count, module presence, debug/registration policies,
  cache flags, adapter versions and allowlisted branch fields extend existing CMS metadata.
- Additive receiving tables; connection/installation-bound HMAC, timestamp/nonce verification,
  credential recheck under connection lock, deduplication and transactional acknowledgement.
- Website Integration Activity tab: period/type/actor filters, 25-row pagination, delivery age,
  pending count and coverage-gap warning. Global Activity merges the same stored events.
- Scheduler admits at most two event reconciliation collections, at most 50 events/changed object
  IDs per incremental scope, and defers while the asset has an active collection. Successful
  completion advances the receipt watermark; failed/partial collections do not. The existing
  CMS snapshot writer retains untouched objects on incremental refresh and removes only scoped
  missing objects. The connector must echo the exact object scope before writes are accepted.
- Daily CMS inventory reconciliation and global-settings changes use full CMS inventory; ordinary
  content changes use scoped content/media/SEO reads. Site/extensions/taxonomies are still full
  lightweight snapshots. Relevant known public URLs use existing bounded targeted crawl (max 100).
  No daily all-page browser run, paid provider or AI call is introduced.
- Standards categories: Website, Google Ads, Meta Ads. Ads categories are placeholders with explicit
  empty-state copy; their criteria are not shipped. Website definitions distinguish general vs
  WordPress inheritance. Existing expert criteria are retained but inactive; their add form is
  removed. Length heuristics default inactive. Historical evidence is preserved.
- 25 additional deterministic/advisory checks cover duplicate/multiple metadata, H1/language,
  internal target status, canonical/hreflang target status, content fingerprint matches, image
  attributes, mixed resources, WordPress debug/registration/visibility/cron/update/health policies
  and delivery freshness. Existing 500-profile assessment bound remains visible. Missing target
  HTTP, stale evidence, unsupported connector fields and truncated scans do not become passes.
  Inventory, HTML and cached Site Health observations remain distinct.

Not implemented in this delivery:
- Remote SEOPress/LiteSpeed write controls, installations, automatic plugin/theme/core updates,
  image optimization or rollback. management_enabled=false; no advertised executable action.
- Complete malware scans, backup/SMTP provider adapters, visitors/session recording, historical
  activity reconstruction, all-page browser checks or automatic whole-site standards after each event.
- Full planned SEO catalogue (e.g. complete sitemap membership, reciprocal hreflang, orphan/depth
  graph, GSC URL Inspection/cluster comparison) is not yet implemented by the new checks.
- Continuous CMS delta cursor independent of events; daily inventory is the recovery mechanism.
  Low traffic or disabled WP-Cron needs host scheduling. A coverage gap cannot reconstruct history.

Deployment: normal staging script installs additive Laravel tables and restarts workers.
Then download connector 1.1.0 from the existing connector screen and replace the installed plugin.
Existing pairing remains; WordPress init creates the local outbox. First successful heartbeat
enables reconciliation. Scheduler and collection workers must run. Verify live pairing, event
redelivery, scoped deletion, filtered history, and standards before operational acceptance.

## 2026-09-10 — Website collection integration controls

Operator requested the integration collection update after Connector 1.1.0. The Website screen
now selects collection scope explicitly; General excludes optional PageSpeed, which is separate.
Automatic WordPress event refresh continues between configurable daily/three-day inventory runs,
with pause/resume that preserves event intake and active work. Source status is independent of the
last overall run; last automatic inventory is distinguished from manual collection. Reconciliation
only acknowledges its actual 50-event batch even during a full inventory, preserving later URL
verification. Shared admission locks reduce overlapping snapshot writers. Additive settings migration
and existing scheduler/workers required. No clone/tests/build/formatter/deploy or live acceptance
performed by operator instruction; runtime/UAT remains unverified. Details in the capability ledger
and docs/product/website/WORDPRESS.md.

## 2026-09-10 — Standards management and evidence integration

After Website collection controls, operator requested standards implementation. Website standards
now expose a paginated category workspace and administrator enabled/severity/default controls.
Catalogue has 52 visible deterministic/advisory checks, including 8 additions; expert criteria stay
archived and Ads categories remain empty. New assessments expose per-standard result details,
including missing evidence, and use completed raw TLS/robots collection observations. WP inventory
freshness allows the configured three-day cadence; freshness affects result reuse. Definitions
and observations distinguish expiry, cache/setup declarations and HTML-only language/indexing
checks from actual security/indexing/performance guarantees. Existing proposal approval remains
human controlled. No clone/tests/build/formatter/deployment run; runtime/UAT unverified. Full
contract and remaining scope: docs/product/website/WEBSITE_STANDARDS_ASSESSMENT.md and ledger.


## 2026-09-10 — WhatsApp reply assistant (staging only)

Owner-authorized simple Sales menu `/whatsapp`: conversations, received/sent message history,
Turkish AI reply/wait/clarify and copy. Direct Meta adapter with signature-verified durable receipts,
fixed WABA/phone binding, encrypted central credentials/message bodies, paginated inbox and own
persistent async statuses. Existing Laravel AI provider credentials and a dedicated sales.whatsapp_reply
route are reused. New message/settings changes invalidate old drafts; context is capped and labelled.
Active Admin-only access. No WhatsApp send endpoint, task creation or external mutation.
Additive migration + existing minute scheduler/default Redis Horizon required. Initial API setup,
webhook subscription and Coexistence eligibility remain external. History/echo support only covers
received provider events; no guarantee of complete phone history. Media not interpreted; delivery,
edits/deletions, global Activity and full Agent/Skill execution ledger not implemented in this slice.
Contract: docs/product/WHATSAPP_ASSISTANT.md. Source reviewed only; no tests/build/formatter, dependency
installation, runtime/migration execution, live Meta/AI UAT or host deployment. Not DONE/live accepted.
All changes are on chatgpt/search-demand-foundation; no main edits and no PR.


## WhatsApp credential form correction — 2026-09-10

Failed settings saves previously cleared all three password inputs via finally/dehydrate and showed
an unnamed first-missing error. Password inputs now stay browser-local (wire:ignore, DOM refs), are
submitted only as action arguments, and clear only on explicit successful save. Validation failures
retain unsaved inputs in the current open form, not in server snapshots or persistent browser storage.
Field-specific messages list every missing credential; server-derived presence flags distinguish
stored credentials from blank edits. Reads use a fresh provider-credential relation query. Existing
stored values remain write-only and blank submissions preserve them. Stale save errors reset before
new save attempts. No credential/account data migration or provider mutation is performed.
Source-reviewed fix only: no tests, build, formatter or live deployment run. Supersedes earlier
notes about clearing fields after failed saves. Number mismatch remains a separate configuration issue.



## WhatsApp setup and action feedback correction — 2026-09-10

Initial WABA/phone binding corrections are now allowed only with no conversations and no
non-completed receipts. Completed ignored receipts are retained. IDs compare as trimmed strings;
existing history continues to block rebinding with field-specific explanations. Receipt signature
validation/persistence and settings changes serialize on the integration row. No data is deleted.
The form distinguishes stored IDs, stored secret presence and unsaved secret edits, offers visibility
for newly typed secrets, and shows saving/success/failure feedback beside the actions. Failed saves
preserve input; controls are disabled during save. API checks use persisted queued/error/result
states, timestamps, duplicate-click suppression and automatic result refresh. Request IDs prevent
an older result overwriting settings saved during the HTTP call. Two-minute queue delays show a
retry/help message. Separate WhatsApp App Secret guidance leaves Meta Ads credentials untouched.
Added PHPUnit coverage for initial correction, receipt/history guards, blank credential preservation
and stale API results. Execution was attempted but unavailable: this workspace has no PHP executable
or installed vendor/Pint. No PHP/Blade compilation, full browser UAT, Meta verification or server deploy
was performed. The actual form submit handler passed isolated JavaScript checks for success,
validation rejection and network failure; this is not browser UAT. Source reviewed; runtime
acceptance remains pending, not DONE.



## 2026-09-13 — Integration and Intent Radar operator review corrections

Owner-authorized direct staging revision; no main changes, PR, clone, tests or local build.
The owner additionally authorized SSH inspection/deployment for this revision. The SSH attempt
failed at DNS resolution before authentication; no server access or deployment was performed.

- Search Appearance filtered queries use automatic aggregation, removing the observed BY_PROPERTY
  invalid request. Normalizer keeps provider response aggregation provenance. Deployment explicitly
  re-admits automation accounts stopped by this known error; original runs/checkpoints remain.
- Invalid-request/persistence failures stop account-level automatic retry storms. Known earlier GA4
  landing-page recovery also recognizes this stopped state. Other errors are not silently repaired.
- Expired queue dispatch claims can republish due retrying datasets as well as queued datasets,
  respecting retry deadlines, execution leases, dependencies, terminal/cancelling parents.
- DB worker ordering uses COALESCE(activity, created_at), preventing PostgreSQL NULL-last ordering
  from indefinitely favouring previously started work over never-started datasets. Initial account
  admission precedes repeat account refreshes. Existing concurrency limits and history scopes remain.
- GSC/GA4/Ads share a read-only state presenter: queued, running, retry wait and progress delayed
  are distinct. Thirty-minute delay is an observation, not proof of a failed worker. Future retry
  deadlines do not become stalls. Success percentages count successful datasets only.
- Main account surfaces use the existing paginated automation table with locked source type,
  stored date bounds, status and collection detail drawer. Bulk operations have their own tab;
  live collection/error history is in Activity. Query mapping stays in the Queries import workspace.
  Coverage bounds do not prove uninterrupted coverage. Details distinguish earlier attempts.
- Account summaries load latest attempt/latest success per resource/provider/asset instead of
  hydrating all history. Monitor polling is reduced to 15 seconds. Operational table times use
  Europe/Istanbul; provider reporting dates keep their original meaning.
- Radar defaults to explicitly chosen catalog services, with search to add a sold service.
  Existing profiles persist; customer services are not inferred as agency offerings. Setup is
  collapsible. Missing body is labelled rather than repeating the title as an apparent excerpt.
- Public-source classification reasons are language-independent keys; existing TR/EN reason text
  is localized on read without deleting history. Primary-post parsing supports nested schema
  objects, schema type arrays, postcontent and scoped publication/author markup; no reply-body
  fallback, login bypass, paid API, AI call or invented publication date is introduced.

Validation: source review only, no PHP/Blade compilation, test suite, browser UAT or live provider
verification. Real forum accessibility/markup and server queue recovery remain deployment UAT.
The broader recent-data-first / bounded historical backfill redesign is not part of this correction;
initial 486-day GSC/GA4 scopes and existing Ads history policy still apply. This is not a DONE claim.


## 2026-09-13 — Runtime evidence: provider admission starvation

Owner supplied bf9c171 deployment output and a second status sample 16 minutes later.
All three Supervisor processes were RUNNING. GSC attempts increased 104→160 and 91→154
while completed datasets stayed at 16 each. This proves execution attempts continued,
not by itself that pages/checkpoints advanced. Ads attempts stayed at 3185 and 832;
retry deadlines/errors were absent, so quota or another root cause is not yet established.
The null collection queue sink is intentional DB-worker architecture, not a missing queue.

Confirmed code/runtime mismatch: two GSC active accounts consumed the entire non-Ads
admission lane. Ninety GA4 and 27 Meta accounts waited. Admissions now allocate the existing
two-account limit per resource type, preserving actual worker count and per-account locks.
Existing Meta/GBP binding/readiness checks still apply; this does not bypass account access.
GSC/GA4 turns are capped at five API pages and resume saved checkpoints to share the worker
more frequently. Full history scope is preserved; this is not the separate recent-first redesign.
ProgressReporter now reflects persisted chunk progress on parent activity timestamps without
counting the dataset complete. CLI status derives effective state from datasets, reports rows,
API pages and retry deadline; --details prints capped sanitized error/progress lines. Deployment
includes Ads detail output so its remaining blocker can be diagnosed from actual retry reasons.

No tests, build, PHP/Blade compilation, provider UAT or SSH execution performed in this revision.
The previous deployment was successful per the owner log; this revision still requires deployment
and proof of GA4/Meta admission and advancing stored rows/pages. No overall resolved/DONE claim.



## 2026-09-14 — WhatsApp Embedded Signup and connection diagnostics

Owner approved implementing the WhatsApp status review's next actions. On the existing
chatgpt/search-demand-foundation branch: App ID/configuration setup (initial Configuration ID
1757572378897162), dedicated Facebook signup page with Coexistence selection, encrypted expiring
Admin/session-bound attempts, async token/app/scope/WABA/phone verification, explicit number choice
when Meta omits it, preserved history/rebinding guards and WABA subscription with separate retry.
User-authorized scoped setup mutation is POST WABA/subscribed_apps; no message send, automatic
migration, number registration or automatic history request. Meta Ads integration stays separate.
Provider diagnostic message/HTTP/code/subcode/trace are redacted and visible. Subscription,
callback verification, actual message persistence and history/echo observations remain separate.
Existing WhatsApp scheduler handles queue recovery/expiry; browser not needed after code handoff.
Product contract: docs/product/WHATSAPP_ASSISTANT.md. Additive migration only. No tests, build,
PHP/Blade compilation, live Meta UAT or server deployment executed. PHP/vendor are unavailable;
Pint unavailable. Source-reviewed implementation; actual app login/permissions/webhook fields and
real inbound/echo/history/AI behavior await operator acceptance. Not DONE or live-verified.


## 2026-09-14 — GBP discovered-resource automation

Owner reports Google GBP access approved and 65 discovered locations. Supplied connector HTML
shows available locations with not_collected and only Manage binding. Discovery is not collection
proof. Source confirms ResourceAutomationService required GBP binding, then routed it through
CollectionLifecycle rather than the existing GBP Run/typed-table collector.

GBP now uses the existing resource automation scheduler and durable ResourceCollectionJob, with
one of ten GBP datasets per worker turn. Run metadata checkpoints completed dataset outcomes;
resource_automations.gbp_run_id references the existing GBP Run, separately from CollectionRun IDs.
This intentionally retains GBP's existing Run collector; it does NOT claim a completed migration
to the shared Collection Engine/warehouse control plane. No new scheduler, credentials or provider
store. Nullable asset ownership in existing GBP tables and runs allows unbound collection with
non-null external_resource_id provenance. Existing exact active binding is validated and retained;
no automatic customer/brand/asset creation. Old binding-only attention states are re-admitted.
Explicitly paused automations remain paused. New locations retain existing default daily cadence.

The dedicated root GBP connector embeds existing paginated TR/EN automation controls: pause, daily/
three-day interval, run now, error status, last full success, next due time, processed dataset count
and recent GBP Run outcomes/row counts/errors. Discovery count is labelled locations. No claim that
all APIs are authorized just because location discovery succeeds. Initial windows reuse 180 days
of performance and 12 complete keyword months (configurable); each refresh currently rereads these
windows. Missing/partial endpoints do not become current. 429/5xx and local pacing failures retry the affected dataset up to three attempts with increasing delay and jitter. Other partial cycles retry at normal cadence,
manual retry is available. Per-step wall-time budget and request pacing bound work; pagination caps
are partial, not complete. Within-dataset page continuation and delta-only refresh remain future
improvements. Interrupted work reuses saved completed dataset outcomes and idempotent typed writes.
No provider writes, AI analysis, competitor/rank-grid calls, or new paid providers.

Verification: source/diff review only. Per owner no tests, build or live provider calls. PHP and
vendor/Pint are unavailable. Not deployed or runtime proven; actual persisted rows, API-specific
403/429 errors, queue/scheduler operation and browser rendering require operator deployment/UAT.
Official quota guidance: https://developers.google.com/my-business/content/limits
