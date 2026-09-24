# PRODUCT_CAPABILITY_LEDGER

## 2026-10-06 — Faz 11: Sade menünün tamamlanması + güvenlik

**State:** CODED + PHPUnit (`tests/Feature/Work/AlertsPageTest`, `tests/Feature/Portfolio/BrandFilesTabTest`, `tests/Feature/Operations/KvkkAndBackupTest`, `tests/Feature/Advisor/AdvisorFaz6Test`; menu and brand tabs locked in `PanelDesignFreezeTest`). Full Feature + Unit suites compared with the baseline. The migration runs on PostgreSQL 16, and a real pg_dump backup passed the new read-back check there. **No live UAT:** 2FA enforcement has not been switched on in staging, and alert snoozing has not been used on real alerts.

- Uyarılar (İşler menüsü, `/alerts`): every open asset alert in the portfolio, most severe first. Severity counts, filters by brand and severity, snoozed and closed-in-30-days views, and a link to each asset.
  - An alert can be snoozed for 1, 7 or 30 days. Snoozed alerts are hidden on Bugün and the dashboard until the date.
  - An alert that closes and is detected again starts unsnoozed. Closing stays automatic.
- İş listesi › "Önerilen (Danışman + SEO)": open SEO Görevleri and advisor items from every channel in one priority order, at most 10 per brand. This is the roadmap's single work list next to the operator's own tasks.
- Marka › Dosyalar sekmesi: files attached to the brand. The "Dosya yükle" button opens Dosyalar with the brand scope.
  - Uploads made from a brand, customer or asset link are now attached to that record. Before this, uploads never set `brand_id`.
  - Otomatik kur links to Açık Web Keşfi, filtered by the brand.
- Güvenlik:
  - Sistem Sağlığı lists active admins without two-factor authentication.
  - `MOXDOP_REQUIRE_ADMIN_2FA=true` limits such admins to the Profile page until they enable 2FA. It is off by default.
  - Every nightly backup is read back before it counts as a success: the whole gzip stream, the SQLite header, or the pg_dump / mysqldump completion footer. A file that fails the check is deleted and the run is recorded as failed, which sends a push notification.
- Not done:
  - The work list's suggested view is read-only. Done / skip still happen on SEO Görevleri and Danışman.
  - Alerts cannot be closed by hand.
  - Customer-level files have no tab of their own; the customer page link opens Dosyalar filtered.
  - There is still no restore command.

## 2026-10-05 — Faz 10: Sade menü + gözden kaçanlar

**State:** CODED + PHPUnit (`tests/Feature/Reports/ChartAnnotationsTest`, `tests/Feature/Portfolio/CustomerHealthScoreTest`, `tests/Feature/Intel/AiVisibilityTest`, `tests/Feature/Operations/KvkkAndBackupTest`; menu locks in `PanelDesignFreezeTest`, `GlobalAgencyOperatingLayerTest`). Full Feature + Unit suites compared with the baseline; the new migrations run on PostgreSQL 16. **No live UAT:** no real backup was restored, no AI visibility probe ran against a real model, health scores were not checked against the owner's view of the portfolio.

- Grafik notları (Raporlar menüsü, `/reports/annotations`): dated events per brand (campaign, site change, other) or agency-wide (Google algorithm update, regulation / platform rule) with note and source; they appear as markers on monthly report charts and are listed under them.
- Müşteri sağlığı puanı (0–100, daily `moxdop:customers:health` 07:10): penalties for conversion / traffic drop (last 28 days vs previous 28), no contact for 30 days, renewals due, unpaid collections, critical / high alerts (thresholds in `moxdop-assistant.health`). Badge on the customer list; Bugün › "kime ne yazmalı" puts the risk band first.
- AI görünürlüğü (Pazar menüsü, `/market/ai-visibility`): per brand up to N questions a customer would ask; on click each is sent to the configured `intel.ai_visibility_probe` route (queued), the answer is checked for the brand's name / site and for competitors; history per question. Never automatic.
- KVKK (Ayarlar › KVKK, admin): per active customer the data processing agreement date, note and whether health data is processed; optional WhatsApp message text retention (≥ 30 days; `moxdop:whatsapp:retention` 03:50 blanks older texts, conversation and times stay).
- Sistem yedeği (`moxdop:backup` 03:30): compressed pg_dump / mysqldump / SQLite copy in `storage/app/backups`, last 14 kept, optional copy to a filesystem disk (`MOXDOP_BACKUP_REMOTE_DISK`), every run recorded; failure → phone notification; Sistem Sağlığı shows the last successful backup (stale after 26 h).
- Sade menü (roadmap target): Bugün · Portföy (Müşteriler, Markalar, Varlıklar) · İşler (İş listesi, Danışman, SEO Görevleri, Yenilemeler) · Pazar (Sorgular, Hizmetler, Rakipler, Harita sıralaması, Backlink, Rakip izleme, AI görünürlüğü) · Satış (Lead kutusu, Potansiyel müşteriler, Niyet Radarı, WhatsApp) · Raporlar (Aylık rapor, Grafik notları, Üretim Arşivi, Uyum, Aktivite) · Sistem (Entegrasyonlar, WordPress siteleri, Ayarlar). Fırsatlar, Bulgular, Öneriler, Açık Web Keşfi, Dosyalar left the sidebar (Ayarlar › Operasyon links them); Sorgu kümeleri is a button on Sorgular; Arka plan işleri stays under Ayarlar. All routes unchanged.
- Not done: no restore command or restore test (restore is manual `gunzip | psql`); the remote copy needs a configured disk; KVKK page is a register only (no document upload, no consent texts); separate "Uyarılar" page from the roadmap is not built (alerts stay on Bugün and asset pages); AI visibility uses one model route (no per-engine comparison of ChatGPT / Gemini / Perplexity).

## 2026-10-05 — Faz 9: Aylık rapor v2 + WordPress eklentisi v2

**State:** CODED + PHPUnit (`tests/Feature/Reports/MonthlyReportV2Test`, `tests/Feature/Integrations/WordPressManagementTest`). Full Feature + Unit suites compared with the baseline; both migrations run on PostgreSQL 16 and the report builder (incl. camelCase GA4 columns) was exercised on PostgreSQL. **No live UAT:** no real brand month was reviewed against Looker, no AI commentary on real numbers, no real WordPress site with plugin 1.3.0 (login link, update) was tried.

- Aylık rapor (İşler menüsü, `/reports/monthly`): per brand and month — Search Console (clicks, impressions, CTR), GA4 (sessions, users, engaged sessions), Google Ads (cost, clicks, impressions, conversions, CPA), Meta (spend, impressions, clicks, CPC), Business Profile (views, calls, directions, website clicks, messages) — each vs the previous month and the same month last year, with a daily SVG chart (this month vs last); brand conversions from the conversion dictionary; local visibility (map grid SoLV, Google rating / new reviews); work done in the month with measured before/after; next items; rule-based highlights. Central rows win over legacy per-asset copies; a channel without data is shown as missing. Numbers are frozen per report (`monthly_reports`) and can be refreshed.
- AI commentary only on click (queued, `reports.monthly_commentary` route, archived in Üretim Arşivi), editable (summary, wins, watch, next month) plus the operator's note. Publish → signed client link (60 days, published reports only, `noindex`), print / save as PDF from the browser; operator preview.
- WordPress Connector 1.3.0 (ADR-068): `GET /health` (WordPress/PHP versions, pending core / plugin / theme updates, Site Health counts), `POST /login-link` (single-use 60-second link for the user the site admin picked; off by default), `POST /updates` (one WordPress-offered update per request; off by default). MoxDOP: daily health read (`moxdop:wordpress:health` 06:20), Entegrasyonlar › WordPress siteleri (versions, pending updates, Site Health, update list), admin-only audited "WP paneline gir", admin-approved queued updates recorded in `external_write_actions` (`update_apply`, not undoable), health re-read after each update.
- Not done: the old Client Value Story PDF / scheduled delivery still exist separately (report v2 is not emailed automatically); report v2 PDF is browser print (no DomPDF artifact); no Looker parity check per brand; WordPress updates have no automatic backup or rollback; plugin auto-update of the connector itself is not implemented (download 1.3.0 from Site bağlayıcıları).

## 2026-10-04 — Faz 8: Rakip/yorum istihbaratı + ajans satışı

**State:** CODED + PHPUnit (`tests/Feature/Intel/MapGridTest`, `KmlExperimentTest`, `BacklinkEngineTest`, `ReviewAndCompetitorWatchTest`, `ProspectAuditTest`, `tests/Feature/Sales/LeadInboxTest`). Full Feature + Unit suites compared with the baseline; the 7 migrations run on PostgreSQL 16 and the new readers (grid metrics, spend, experiment, review comparison/themes, backlink queries, competitor watch, costs) were exercised on PostgreSQL. **No live UAT:** no real DataForSEO maps/reviews/backlinks response, Nominatim answer, competitor site or website form post was observed; DataForSEO prices in config are estimates until the first real invoices (real cost per task is recorded).

- DataForSEO standard queue (`dataforseo_tasks`, `moxdop:intel:collect` every 5 min): paid tasks are posted in batches with their cost; results are read for free and handed to the feature. One per-brand monthly USD cap (`brand_intel_settings.monthly_usd`) covers grid + reviews + backlinks; Maliyetler shows "DataForSEO · pazar istihbaratı".
- Harita sıralaması (Pazar menüsü, `/market/map-rankings`): N×N grid (3/5/7/9, km spacing) around the Business Profile pin or a set centre, top 20 per point, our rank by place id / CID / site host / phone; ARP, ATRP, SoLV (top 3), change vs the previous scan, heat grid + OpenStreetMap map, businesses most often in the top 3. Up to 5 keywords per brand, scheduled every N days (`moxdop:intel:grid` 04:30) when switched on.
- KML deneyi: service areas geocoded from OpenStreetMap Nominatim (background, 1/s); KML with the profile pin + one pin per area, informative description (address, phone, site; sector compliance check), pin cap; download only. "Haritayı yayınladım" records the date; grid ATRP / SoLV 42 days before vs after per keyword.
- Backlink fırsatları (`/market/backlinks`): summaries for the brand and approved competitors, referring domains (new 45 days, lost 90 days), opportunities linking to ≥ 2 competitors but not to us (rank ≥ 50, spam ≤ 30), Turkish directory / citation list by sector (free), outreach status new → contacted → waiting → live / lost / rejected with page URL, contact and note; weekly check that the promised link is on the page. Monthly refresh when switched on (`moxdop:intel:backlinks` 04:50).
- Rakip izleme (`/market/competitor-watch`): Google reviews of the brand and the top grid competitors (+ manual CID) — rating and 90-day change, count, reviews in 30 / 90 days, 90-day average, owner response rate, recurring words / phrases in negative reviews and recent examples; reviewer names are not stored (`moxdop:intel:reviews` 05:05, every 14 days when on). Weekly public snapshot of approved competitors' home page and sitemap: new / removed pages, changed title / H1 / description (`moxdop:intel:competitors` Mon 05:25, free). Meta Ad Library links per brand / competitor (page id or name search).
- Dış denetim (potansiyel müşteri › Dış denetim): 14 public website checks with a score, optional one Google Maps search (rank, rating, top 3; agency cap 5 USD/month), printable report page.
- Lead kutusu (Satış menüsü, `/leads`): the agency's own site form posts to `/api/leads/{token}` (hashed, rotatable token, throttled, honeypot); same phone within 24 h merged; push notification; one-click conversion to a prospect (follow-up tomorrow); manual entry.
- Not done: map grid points use DataForSEO's coordinate search (no own proxy / device emulation); KML experiment effect is unmeasured; review themes are word counts (no AI summary); competitor watch reads only the home page and sitemap (no content diff); Meta Ad Library has no data import; lead inbox has no email notification or auto-reply; prospect audit has no PDF artifact (browser print).

## 2026-10-03 — Faz 7: Beyin (Yöntem Kütüphanesi, doğrulama, sonuca dayalı öncelik, tutarlılık, Ads analitiği, sektör örüntüleri)

**State:** CODED + PHPUnit (`tests/Feature/Brain/MethodLibraryAndVerificationTest`, `CrossConsistencyRulesTest`, `SectorPatternsTest`, `tests/Feature/Advisor/GoogleAdsAdvisorRuleEngineTest`, `GoogleAdsAdvisorRunTest`). Full Feature + Unit suites compared with the baseline; the migration runs on PostgreSQL 16 and the new readers (sector patterns, sector rule stats, Quality Score recorder/history, cross consistency) were exercised on PostgreSQL. No live UAT: no real plan, Quality Score change or sector data was observed.

- Yöntem Kütüphanesi (Ayarlar › Yöntem Kütüphanesi, `/settings/methods`): every numeric threshold and word list of the advisor, SEO tasks, alerts and demand configs is shown with its file default and can be overridden by an admin (`method_settings`, next plan); rules can be switched off per scope (advisor / SEO); each rule shows done / measured / improved / came back.
- Verification: "Yapıldı" immediately queues a rules-only plan (no AI) for that asset. An item no longer detected becomes "Doğrulandı"; still detected within 7 days → "Hâlâ görünüyor"; after 7 days → reopened as "Geri geldi" (`reopened_count`). "30 gün ertele" hides an item/task; it comes back only if the problem is still there when the date passes. Data is collected daily, so the first check may still show the old state.
- Outcome-based priority: outcomes are measured at 28 and 56 days; once a rule has ≥ 5 measured outcomes its priority is multiplied by 0.8–1.2 by the share that improved — using the brand's own sector when that sector has enough outcomes (ADR-066), else agency-wide.
- Cross-asset consistency (Kanallar arası danışman): Business Profile website missing / pointing to another host, profile phone not on the home/contact page (NAP), Google Ads landing hosts and Meta destinations outside the brand's site (WhatsApp / Messenger / Instagram / Maps allowed, editable). Replaces the old Filament-only `Analyze*ConsistencyJob` path noted in ADR-065 (those jobs stay unused).
- Google Ads analytics: `ngram-waste` (2–3 word phrases recurring across cheap non-converting terms, phrase-match paste list, outcome measured like negatives), daily Quality Score history (`google_ads_quality_score_history`, `moxdop:google-ads:record-quality-scores` 05:40, 400 days) + `quality-score-drop` (≥ 2 points vs ≥ 28 days ago, components that worsened), `performance-anomaly` (per campaign, last 7 vs previous 28 days: CPC up ≥ 30 %, CTR down ≥ 30 %, conversion rate down ≥ 40 %, minimum clicks), `landing-keyword-mismatch` (no keyword word in the crawled landing title / H1 / description / URL, Turkish suffixes tolerated).
- Sector patterns (Ayarlar › Sektör örüntüleri, ADR-066): for sectors with ≥ 2 active brands — recurring unbranded demand queries, repeating advisor problems, rule results in the sector vs agency-wide, and a brand's gaps (queries ≥ 2 other brands track). Aggregates only; never in client reports.
- Not done: Quality Score drop needs 28+ days of recorded history before it can fire; anomaly detection is campaign-level only (no ad group / device / hour); n-gram lists are paste-only (the ADR-064 writer still accepts only `negative-keywords`); sector is the brand's primary `sector` field only; landing ↔ keyword fit uses crawled head text, not body content.

## 2026-10-02 — Faz 6: Asistan (Bugün, hatırlatıcı, telefon bildirimi, takvim, yenilemeler, uptime, WhatsApp ↔ müşteri)

**State:** CODED + PHPUnit (`tests/Feature/Assistant/UptimeAndPushTest`, `RenewalsTest`, `TodayRemindersCalendarTest`, `WhatsAppContactLinkTest`). Full Feature + Unit suites compared with the baseline; the migration runs on PostgreSQL 16 and the new readers were exercised on PostgreSQL. No live UAT: no real ntfy / Telegram message, RDAP answer, site outage or Google Calendar subscription was observed.

- Telefon bildirimleri (Ayarlar › Telefon bildirimleri, admin): ntfy topic (+ optional token) and/or Telegram bot + chat id (secrets encrypted), minimum severity, test button, send log (`push_notifications`, 90 days). Pushed: site down / back up, newly opened high/critical asset alerts, renewals at 30/14/7/1 days, due reminders (always). The owner's own channel — not a write to a client account.
- Uptime: every 5 minutes one queued check per operational website (safe public fetcher); 2 consecutive failures → `site_down` critical alert + push; recovery resolves + push with outage length. `uptime_checks` kept 30 days; `uptime_states` holds the current state.
- Yenilemeler (`/renewals`, İşler menüsü): domain and SSL rows are created for every active website; domain expiry + registrar from public RDAP (weekly), SSL expiry from the collected certificate (free auto-renew issuers marked); hosting / other added by hand; a manual date is never overwritten. Cost, customer charge, collection state (faturalanmadı / faturalandı / ödendi / ücret alınmıyor), "Yenilendi" (+1 year). Daily `moxdop:renewals:daily` (06:05) + website alerts `renewal_due_*` (≤30 days; auto-renewing ≤7).
- Hatırlatıcılar: one-off / weekly / monthly / yearly, optional customer; pushed when due (every minute, once); repeating ones move forward when done.
- Google Takvim: read-only iCalendar feed per user (`/calendar/{token}.ics`, secret token, regenerable) with reminders, renewals and assigned task due dates. No write to Google (no ADR needed).
- Bugün panel (top of the home screen): sites down, today's reminders + quick add, "Kime ne yazmalı" (WhatsApp conversations whose last message is incoming, prospect follow-ups due, customers with critical/high alerts or 30+ days of WhatsApp silence), renewals within 30 days, calendar link.
- WhatsApp ↔ müşteri/aday: conversations are linked by phone (customer primary phone, customer contacts, prospect phone; last 10 digits) on creation and hourly; operator links are kept. Inbox shows the link, links by hand, creates a prospect from an unknown contact (follow-up tomorrow) and saves `next_follow_up_on` / `next_step`.
- Not done: messages are still never sent from MoxDOP (copy only); the reply AI does not yet receive the linked customer's context; no customer health score; no invoice / payment integration (collection state is manual); tasks still have no reminder time (only due date); uptime checks the home page only (no keyword / SSL handshake check).

## 2026-10-01 — Faz 5: sektör paketleri, sağlık kuralları, uyum denetçisi, Üretim Arşivi

**State:** CODED + PHPUnit (`tests/Feature/Archive/ProductionArchiveTest`, `tests/Feature/Compliance/ComplianceAuditTest`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 and the audit / compliance page exercised on PostgreSQL. No live UAT. **The health rules are a draft starter set, not legal text** — the RG 12.11.2025 / 33075 change still needs a lawyer's review; rules are edited on screen.

- Üretim Arşivi (`ai_productions`, gold, never deleted; `/archive`, İşler menüsü): advisor Google Ads / Meta / Business Profile drafts, AI SEO briefs, WhatsApp reply suggestions and brand setup proposals are archived when they land on their work item (model hooks; drafters unchanged). Same content is stored once per item; regenerating adds a version. Versions are marked Yeni / Kullandım / Yayınlandı / Kullanılmadı and rated 👍/👎; filter by type, status, brand, work item. Requesting a draft first restores a fresh (≤ 14 days) archived draft instead of a new AI call; "Yeniden hazırla" on a ready draft still calls AI.
- Sector packs (`config/moxdop-sector-packs.php`, `SectorPack` classes): a pack applies to brands whose sectors (`Brand::sectorCodes()`) match; can be switched off. Health pack (healthcare, dental, medical_aesthetics) rules: superlatives, result guarantees, inducements (discount / campaign / free check-up / gift), patient testimonials, before/after, comparison, Meta ad sets targeting under 18, and an off-by-default required-phrase rule (institution / licence). Matching is Turkish-folded and suffix-tolerant; symbol patterns (%100) match raw text. Ayarlar › Sektör paketleri edits phrases, advice, severity, on/off and adds rules; edits are kept (`origin=operator`).
- Uyum denetçisi: daily `moxdop:compliance:scan` (06:45, active customers, stored data only) checks advisor AI drafts, AI SEO briefs, live Meta ad title/body and ad set targeting, stored website pages (visible text, ≤ 150 latest pages per site) and Business Profile profile/posts/services. Findings (`compliance_findings`) keep the phrase and an excerpt (GBP content is purged after 30 days), resolve when the content no longer matches, stay dismissed with a note. Uyum page (İşler menüsü) with "Şimdi tara". AI drafts in Danışman and AI SEO briefs show a live "Uyum: N sorun" badge.
- Fix: page text extraction joins text nodes with spaces (competitor page word counts in Faz 2b were slightly low).
- Not done: Google Ads ad text (RSA headlines/descriptions) is not collected, so live Google Ads text is not audited; Meta `object_story_spec` texts and images are not checked; Perfex "yasaklı reklam ifadeleri" list is not in the repo (only the draft list above); no other sector pack yet; archive has no link to `ai_usage_records` cost per version.

## 2026-09-30 — Faz 4: entegrasyonlar (hatalar/güvenlik, kendi kendini onaran akış, tek görünüm, maliyet, eklenti sürümü)

**State:** CODED + PHPUnit (`tests/Feature/Integrations/IntegrationSelfHealingTest`, `SystemHealthAndCostsTest`). Full Feature + Unit suites compared with the baseline; new read queries exercised on PostgreSQL 16. No live UAT (no real OAuth reconnect or token expiry observed).

- E1: Meta `reauth_required` / `permission_required` and dead-token credential states now open the `credential_reconnect_required` alert (before, only Google states did). File log channels (`single`, `daily`) mask sensitive context keys and bearer / OAuth / Meta tokens in messages (`App\Logging\RedactSecretsTap`). `/ops/health-snapshot` is admin-only. Successful OAuth writes `INTEGRATION_RECONNECTED` to the security audit log.
- E2: `credential_expiring` warning 7 days (`MOXDOP_OPS_CREDENTIAL_EXPIRY_WARNING_DAYS`) before a Google refresh token (testing-mode apps) or a Meta long-lived token expires. A successful Google/Meta OAuth makes the accounts stopped for "reconnect" due immediately. Daily `moxdop:resources:retry-stopped` (05:10) retries accounts stopped by repeated failures (after 20 h) and resumes "reconnect" stops whose integration and account are usable again; contract errors, cancellations and portfolio gates are left alone. Queue heartbeat probe job on `default` and `collection` every 5 minutes; DB collection workers write a heartbeat once a minute. `deploy.sh` runs `smoke.sh` and `schedule:list` after `up` (warnings, no rollback).
- E3: Ayarlar › Sistem Sağlığı (`/settings/system-health`, linked from Integrations and Ayarlar › Operasyon): scheduler, workers, open system alerts, each integration's status and authorization expiry, every account's collection state, data-through date and freshness (stopped and stale first), WordPress plugin versions; admins can retry stopped collections. The Integrations hub WordPress card reports paired / outdated / silent sites instead of a fixed "setup required".
- E4: Ayarlar › Maliyetler (`/settings/costs`, admin): six months of recorded AI spend per provider and DataForSEO spend (area SERP checks, demand enrichment, prospect radar) with the AI monthly budget. WordPress connector plugin older than `moxdop-wordpress.connector_version` shows on the website integration page and raises a low `wordpress_plugin_outdated` website alert.
- Not done: arbitrary date-range backfill from the operator UI (existing initial backfills and GA4 restatement remain); `MOXDOP_OPS_EXPECTED_SUPERVISORS` must still be set on the server for the worker-missing alert (suggested `queue:default,queue:collection,db-collector`); connect/bind flows are still per provider (no shared connector interface); no integration-level view/manage permission split (admin vs others only).

## 2026-09-29 — Faz 3: ölçüm temeli (dönüşüm sözlüğü, takip sağlığı, markalı ayrım, Sayfa Karnesi)

**State:** CODED + PHPUnit (`tests/Feature/Measurement/BrandConversionDictionaryTest`, `TrackingHealthCheckerTest`, `PageScorecardTest`, `tests/Feature/Demand/BrandedSplitReaderTest`, `Ga4ProductionCollectorTest::landing_page_by_channel_is_stored_per_page_and_channel`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 and the new read queries were exercised on PostgreSQL. No live UAT; provider data is seeded in tests.

- Brand conversion dictionary (`brand_conversion_sources`): daily `moxdop:measurement:refresh` (06:15, active customers) discovers the brand's GA4 key events (configured + reported), Google Ads conversion actions (not removed), Meta action types that are conversions (one entity level per brand) and Business Profile actions (calls, messages, bookings, directions, website clicks). Each gets a suggested type (form, phone call, WhatsApp/message, appointment, booking, purchase, qualified lead, other) and a "counts" default that avoids double counting: GA4 counts the website; Ads website-tag and GA4-import actions, Meta pixel events and Meta aggregates (`lead`, `purchase`, `omni_*`, `*_total`) are listed but not counted; Ads call/lead-form actions, Meta on-Meta leads/messages and Business Profile calls/messages/bookings count. Operator changes are kept (`origin=operator`). Brand › İşletme › Dönüşümler shows the 30-day total vs the previous 30 days, per type, per signal, with the homepage tags.
- Tracking health (daily alert scan, website assets): stored homepage HTML tag detection (GTM, GA4 `G-`, Google Ads `AW-`, Meta pixel) → "Ana sayfada ölçüm etiketi yok" (no GTM and no gtag) and "GA4 kimliği bağlı mülkle eşleşmiyor" (gtag without GTM, none of the bound property's measurement IDs); on the brand's first website: "GA4 veri almıyor" (collection ran in the last 2 days, no sessions for 3+ days, prior ≥ 20/day), "Web sitesi dönüşümleri durdu" (counted GA4 key events 0 for 3 days with sessions, prior ≥ 1/day), "Dönüşüm ölçülmüyor" (≥ 300 sessions / 30 days, nothing counted). Thresholds in `config/moxdop-alerts.php` `tracking`. GTM API is not used.
- Branded vs non-branded (Brand › Talep): six months of Search Console clicks and Google Ads cost/conversions split by the branded-query test (`BrandedQueryMatcher`: full brand name or domain root; shared with the demand builder). Search Console hides rare queries, so shares are of reported queries.
- GA4 landing page × channel: new central family `GA4_RF_LANDING_CHANNEL_DAILY` → `ga4_landing_channel_daily` (sessions, engaged sessions, active users, optional engagement rate / key events), 1-day slices; fills on the next GA4 collection.
- Sayfa Karnesi (website › Sayfa Karnesi tab): one row per page for the last 28 days — Google clicks (vs previous 28), GA4 sessions and key events, main channel and channel mix, Google Ads landing clicks/cost/conversions (tracking parameters stripped, other hosts ignored), indexing verdict, lab LCP, open SEO tasks; flags "Google dizininde değil", "Google tıkları düşüyor", "Ziyaret var, dönüşüm yok", "Reklam tıklıyor, dönüşmüyor", "Yavaş açılıyor". Missing sources show "—", never zero. Top 50 by traffic + search.
- Not done: the monthly report still has no conversions section (dictionary totals are ready to feed it); `google_ads_conversion_business_mappings` (Ads measurement panel stages) is not yet merged into the dictionary; Meta pixel IDs are detected but not compared with Meta's reported pixel; tag detection reads only the stored homepage (tags injected by GTM are not visible).

## 2026-09-28 — Faz 2b: talep hattı (sorgu → hizmet/bölge → bölge SERP → rakip sayfa → SEO görevi)

**State:** CODED + PHPUnit (`tests/Unit/SeoTasks/SeoTextMatchesPhraseTest`, `tests/Feature/Demand/BrandDemandBuilderTest`, `AreaSerpCheckerTest`, `CompetitorPageComparatorTest`). Full Feature + Unit suites compared with the baseline; new migrations run on PostgreSQL 16 locally. No live UAT; DataForSEO SERP location/organic responses are faked in tests.

- Matching: one Turkish fold (`SeoText::fold`; `LocationOptions::fold` delegates) and suffix-tolerant phrase matching (`SeoText::matchesPhrase`: implantı, fiyatları, estetiği; words < 4 letters exact) used by the service keyword matcher and the SEO task query matcher.
- Brand demand table (`brand_demand_queries`, gold, never deleted): weekly `moxdop:demand:build` (Mon 05:30, active customers) merges the brand's own Search Console queries, Google Ads search terms (cost/clicks/conversions) and Business Profile keywords (90 days / 3 months); assigns the most specific matching service, the service area named in the query (district before city), out-of-area and branded flags (full brand name / domain root), and a value score (`config/moxdop-demand.php`). Operator assignments are kept; unseen queries keep their row with zero window metrics. Brand › İşletme › Talep shows per-service demand and lets the owner assign unmatched queries.
- Area SERP (paid, opt-in per brand, admin): weekly `moxdop:demand:serp` (Mon 05:45) checks each service's top 3 non-branded, in-area queries at the brand's service-area locations (DataForSEO free TR location directory → `brand_service_areas.dataforseo_location_code`, fallback website market / Türkiye); same keyword + location reused for 28 days across brands; the brand's monthly USD cap (default 2) is never exceeded. Domains in the top 10 for ≥ 2 keywords become pending competitors (`area_serp` source). Brand › Talep shows our rank per keyword and area plus the top 3 domains; "Şimdi kontrol et" runs in the background.
- Competitor comparison (free): weekly `moxdop:demand:compare` (Mon 06:00) fetches our service page (ServicePageAssignment, else the ranking URL) and up to 4 top-5 outranking pages (approved competitors first, rejected ones never), measures words, H2, FAQ, schema types, price and place mentions, stores gaps in `demand_service_comparisons`; SEO Görevleri adds one `competitor-gap` task per service (high when not in the top 10).
- Not done: Google Ads keyword (criterion) texts are not imported (search terms are); GBP keywords are not yet in the automatic query-library import (they are in the brand demand table); the legacy Search Demand pages (clusters, enrichment, ownership, competitive intelligence) are untouched and should be retired or folded into Talep in the menu phase; SERP device is desktop only.

## 2026-09-27 (c) — Faz 2: portföy (aktif/pasif, Keşfet ve Grupla, tek rakip listesi)

**State:** CODED + PHPUnit (`tests/Feature/PassiveCustomerGateTest` switch, `tests/Feature/Portfolio/DiscoverAndGroupTest`, `tests/Feature/Portfolio/BrandCompetitorsTest`). Full Feature + Unit suites compared with the baseline. No live UAT.

- Customer list: active/passive switch per row (archived stays a badge). Passive stops all automatic flows (Faz 0 gate); reactivation makes accounts paused with `customer_passive` due immediately.
- "Keşfet ve Grupla" (`/customers/discover`, admin only, button on the customer list): unbound, bindable Google/Meta accounts grouped by domain (Search Console, GA4 cached web stream, Business Profile website) and by name (Google Ads, Meta; name score ≥ 0.8), manager accounts excluded. Groups matching an existing website asset bind to that brand. One click creates customer (or picks an existing one) + brand and applies the accounts through the "Otomatik kur" applier (website asset, GA4/SC on the website, new Ads/Meta/GBP assets); a site crawl is queued so "Otomatik kur" can propose services. No provider calls while grouping.
- One competitor list per brand: Brand › İşletme › Rakipler shows the competitor library (`search_demand_competitors`) with approve / "Rakip değil"; one-click suggestions from the brand card text, business-context known competitors and DataForSEO competitor domains; "Arama sonuçlarından bul" imports stored SERP results (no new provider call). Rejected competitors are never suggested again. Setup checklist gains an optional "Rakipler" item (≥ 3 approved).
- Faz 1 follow-ups: legacy WordPress application-password probe removed; `sample-module` removed via composer (old registry rows stay hidden); cross-asset consistency checks noted for Faz 7.

## 2026-09-27 (b) — Faz 1: temizlik + veri saklama

**State:** CODED + PHPUnit (`tests/Feature/Retention/DataRetentionTest`, `RecurringAutomationEngineProductionTest` retired kind, `MoxDopUiFoundationTest` admin resources; removed features' tests deleted). Full Feature + Unit suites compared with the baseline. No live UAT. About 67k lines removed.

- Removed (no operator consumer): agency-brain stack (IntelligenceEvaluation, SectorLearning, BrandExperiences, BusinessOutcomes, Assistant, IntelligenceMemory, IntelligenceRetrieval); team workflows (Client Requests, Approvals, QA, Playbooks, Recurring Reviews — Work/Tasks are task-only now); Instagram page (`/assets/instagram` redirects to the asset list; old rows still readable); dead AI (DiscoveryInference, IntentRadarService/IntentClassification, WebsiteFindingInsightAgent, GBP/GA4/GSC guidance keys); zero-reference demo fixtures; unrouted Domain/Hosting components.
- Filament `/admin` is technical tooling only (ADR-065): Runs, Modules, dashboard widgets, login/profile/MFA. Module AI guidance (Website/Google Ads/Meta Ads `src/Ai`, guidance jobs, skills) removed; click-only AI stays in Advisor drafts, SEO Görevleri, brand setup, WhatsApp, prospects.
- Monthly report no longer has a business-outcome section (the producer was removed); it still renders.
- Guarded drop migrations (`2026_09_27_100100_drop_team_workflow_tables`, `2026_09_27_120000_drop_agency_brain_tables`) drop a table only if it is empty; tables with rows (e.g. seeded playbooks) stay for review.
- Recurring occurrences of a retired schedule kind finish as Skipped (`KIND_RETIRED`) instead of failing.
- Retention (`moxdop:data:retention`, daily 04:10, `config/moxdop-retention.php`): raw payloads / HTML copies after 90 days (each page's latest HTML kept), telemetry windows (30–180 days, unprocessed WhatsApp receipts kept), daily performance older than 25 months rolled into `performance_monthly_rollups` (sums; ratios impression/session-weighted) then deleted. Gold daily tables (GSC query tables, Ads search terms/keywords) are never rolled up. DataForSEO keyword snapshots and GA4 event tables keep collecting (gold / Faz 3 inputs).
- Not done / follow-ups: cross-asset consistency jobs (7 `Analyze*ConsistencyJob`) have no trigger since Filament ViewDigitalAsset was removed; old WordPress application-password connection is Filament-only and orphaned; `sample-module` needs `composer update` to remove; Opportunities/Recommendations/Findings pages fold into the one work list in the menu phase.

## 2026-09-27 — Faz 0: AI tıkla, pasif müşteri kapısı, GBP saklama, yetki, 2FA

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/SeoPlanRunTest`, `tests/Feature/PassiveCustomerGateTest`, `tests/Feature/OperatorTwoFactorLoginTest`, `tests/Feature/OperatorAssetDataSourcesGuardsTest`). Full Feature + Unit suites compared with the baseline. No live UAT.

- SEO plan AI is opt-in: scheduled, bulk, brand-setup and "Planı yenile" runs are rules-only (`llm_summary.skipped_reason = not_requested`); the new "✨ Briefleri AI ile hazırla" button (`SeoTasksPanel::refreshPlanWithAi`, trigger `manual_ai`) runs site understanding + brief enrichment. A rules-only run never overwrites an earlier AI brief/title/checklist (evidence and score still refresh).
- WhatsApp automatic suggestions default off in the settings form (dispatcher already defaulted off); suggestions on click.
- Passive gate: `DigitalAsset::operational()` / `isOperational()` = asset active and customer active. Applied to scheduled SEO plans, advisor plans, daily alert scan, WordPress reconcile, account collection (`ResourceAutomationService::portfolioGate`: `customer_passive` when every bound asset is passive, `unbound` when no binding and no query-library sector) and automatic query import (passive only). Manual clicks on a passive customer's pages are not blocked.
- Scheduled SEO queue rotates least-recently-planned first (per-tick limit no longer always takes the first ids).
- GBP retention scheduled daily 04:40 (`moxdop:gbp:purge-expired`): reviews/media/posts/profile snapshots older than 30 days are deleted; `gbp_performance_daily` and `gbp_search_keywords_monthly` are no longer purged (owner: keyword/query data kept).
- Admin-only: AI route save, resource automation save/run/resume/close/recheck, Data Sources bind/unbind (controls hidden for team members).
- 2FA (authenticator app, TOTP) for `/login` and Filament `/admin` with one secret (`users.app_authentication_secret` encrypted, hashed recovery codes; Filament `AppAuthentication`, no new dependency). Setup/turn off/regenerate codes on Profil; login challenge `/two-factor-challenge` (5-minute pending login, code reuse rejected, throttled). Optional per user, not enforced.
- Not done: passive gate for report deliveries and recurring automations (`moxdop:dispatch-due-automations`), enforcing 2FA for admins.

## 2026-09-26 — Dijital varlık sayfaları Faz D: eksik analizler + uyarılar

**State:** CODED + PHPUnit (`tests/Feature/Alerts/AssetAlertScannerTest`, `tests/Feature/GoogleAds/GoogleAdsCampaignAnalyticsTest`, `tests/Feature/MetaAds/MetaAdsCampaignExplorerTest`, `tests/Feature/Website/WebsiteHealthScoreTest`). Full Feature + Unit suites: no new failures vs baseline. No live UAT; everything reads collected tables (no provider calls on render).

- Alerts (`asset_alerts`, `moxdop:alerts:scan` daily 06:30, `config/moxdop-alerts.php`, `MOXDOP_ALERTS_ENABLED`): Google Ads/Meta spend spike (≥2× 7-day avg) and delivery stop, Google Ads conversions stopped (3 days spend + 0 conv after ≥1/day), Search Console clicks −40% week over week, stale bound data (>72h), unanswered ≤2★ reviews (7 days). Upsert by key, auto-resolve. Shown in the asset frame, dashboard card and weekly digest. No push/SMS.
- Google Ads: campaign Δ% vs previous period (cost/clicks/conv/CPA), lost IS budget/rank (impression-weighted), sortable; monthly pacing (MTD, projected month-end, ±10% band, shared budgets counted once); CSV for campaigns and search terms. Conversions by action per campaign skipped: collected grain is account-level.
- Meta: campaign → ad set → ad drill-down with breadcrumb (URL state), impressions/CPM/results/cost per result by objective, sorting; creative fatigue (first vs last 7 active days: CTR −30% with rising or ≥1.8 daily frequency); CSV of the current list.
- Website: Site Sağlığı score 0–100 (weighted by share of pages with crawl issues per severity; formula in `WebsiteHealthScoreService`), trend of last 6 crawls, issue groups with affected URLs and "new since last crawl", 4xx/5xx, redirect chains, broken internal links, orphan pages, duplicate titles/descriptions (not scored); CSV.

## 2026-09-25 (d) — Dijital varlık sayfaları Faz C: ortak çerçeve, tek bağlama yolu, GA4/GSC modeli

**State:** CODED + PHPUnit (`tests/Feature/Assets/AssetContextTest`: frame on all seven asset page types + Data Sources + edit; GA4/GSC as website sources in the estate matrix and the legacy GA4 page hint; `CanonicalPortfolioRuntimeTest` creatable types). Full Feature + Unit suites compared with the baseline. No live UAT.

- Shared frame (`<x-operator.asset-context>`) above every asset page, Data Sources and the edit form: Müşteri › Marka › Varlık breadcrumb, switcher to the brand's other assets (with freshness dots) + "Varlık ekle", bound accounts, freshness/last data, "Veri Kaynakları" and "Düzenle". Duplicate sources/edit/brand buttons removed from channel headers.
- One binding path: Data Sources, Integrations pages and Brand setup already used `Confirm*ResourceBindingService`; the Filament website GA4/GSC action (direct row create) now uses it too.
- GA4 / Search Console are Website sources: not offered when creating assets; the estate matrix counts a website binding and links to the website tab; existing standalone GA4/GSC pages show a "web sitesinde aç" hint when the website has the same source bound.
- Intentionally kept: account-centric binding on Integrations pages (same service); Filament `/admin` performance/intelligence relation managers (technical panel, not operator product; removal would be a separate cleanup); per-channel period controls (GBP uses 28/90/180 days).

## 2026-09-25 (c) — Dijital varlık sayfaları Faz B: temizlik

**State:** CODED + PHPUnit (website/Google Ads/Meta/Instagram page tests, redirects for retired Meta pages, weighted impression share, KPI deltas, Turkish labels). Full Feature + Unit suites compared with the baseline. No live UAT.

- Website page 12 → 9 tabs (Genel Bakış, SEO Görevleri, Search Console, Google Analytics, Sayfalar & İçerik, Site Sağlığı, Standartlar, Altyapı & WordPress, Veri Kaynakları); old tab URLs mapped; overview lists up to 10 findings/recommendations; no raw JSON / English fallbacks; labels in `lang/*/operator_website.php`.
- Google Ads: Optimization tab folded into Danışman (Google recommendations below the advisor panel); overview shows each count once ("Dikkat gerektirenler"); KPI deltas vs previous period with Turkish text and dates; statuses Aktif/Duraklatıldı/Kaldırıldı; PMax/Shopping/Video headers Turkish; Scenario Planner placeholder removed; impression share impressions-weighted; dead tab views and component state removed.
- Meta: 9 legacy pages (campaigns/adsets/ads/creatives/breakdowns/insights + details) are redirects to the workspace tabs (route names kept); their Livewire classes/views deleted; dead filter/drawer state removed; YoY comparison not offered (reads compare with previous period); Bütçe column; whole-number results; jargon (destination_type, canonical lead action, raw freshness codes) replaced.
- Instagram: single honest page — "analitik bağlı değil" + collected profile fields when present; not offered when creating new assets.
- Shared: Data Sources/runtime wording without provider/binding/collection; discover buttons Admin-only; "Kamu Keşif" → "Açık Web Keşfi"; connector page links use the shared asset route map; asset list filter labels localized; unused GBP map script removed.
- Still open (Faz C/D): shared asset frame, one binding flow, GA4/GSC model, Filament duplicate performance views, measurement TR/EN twin views, `GoogleAdsSearchRecoveryCollectionService` (no callers).

## 2026-09-25 (b) — Dijital varlık sayfaları Faz A: kırık ve yanlış çalışan yerler

**State:** CODED + PHPUnit (`tests/Feature/Assets/*`: GBP page from collected gbp_* rows incl. partial run, retired tabs; asset list real status + edit form + Data Sources render; Google Ads landing tab + no provider call on Search render; Meta no auto-pick + recorded recommendations + run analysis). Full Feature + Unit suites compared with the baseline. Live UAT not run.

- Veri Kaynakları (`/assets/{id}/sources`) no longer 500s (mixed one-line / block `@php` in the Blade); breadcrumb links back to the asset; statuses in Turkish.
- Operator asset edit page `/assets/{id}/edit` (name, status, website fields, SEO market as DataForSEO codes); brand/type fixed. "Varlığı düzenle" menus now open it (was the create form). Create continues to Data Sources.
- Business Profile page reads `gbp_location_snapshots` + performance/keywords/reviews/media/posts/attributes/services by the bound resource; partial runs count. Tabs: Özet · Performans & Aramalar (28/90/180 days vs previous) · Yorumlar (distribution, reply rate, unanswered, latest) · Profil (completeness checklist) · Danışman. Visibility/Competitors/Operations placeholders removed (old links redirect); dead demo tab views deleted.
- Asset list: connection, freshness (fresh ≤72h / stale / not collected / not connected / no data source), last update and open work (advisor + SEO tasks) from bindings and runs; "Veri sorunları" filter works.
- Google Ads: "Açılış Sayfaları" tab reachable (panel existed but was mapped to Overview); Search tab no longer calls the Google Ads API while rendering (`MOXDOP_GADS_SEARCH_LIVE_FALLBACK`, default off); connector Accounts tab no longer also renders the activity monitor.
- Meta: `/assets/meta` without an id goes to the Meta-filtered asset list (no auto-picked account); İçgörüler tab lists recorded findings, advisor recommendations, tasks and measured outcomes; header "Analizi çalıştır".
- Not in this slice (Faz B–D): dead demo code cleanup, tab consolidation, English/jargon cleanup, shared asset frame, GA4/GSC model decision, new analytics (pacing, drill-down, alerts).

## 2026-09-25 — Faz 7: Admin onaylı harici yazma (ADR-064)

**State:** CODED + PHPUnit (`tests/Feature/ExternalWrites/ExternalWritesTest`: paste parsing, Admin-only UI + 403 for team members, Google Ads mutate sequence limited to shared list services, undo removes exactly the added criteria, kill switch, WordPress signed draft create/trash, old plugin refused, plugin source never publishes). Full Feature + Unit suites: no new failures. Live UAT not run — needs a real Google Ads account with an approved developer token (≥ Basic access) and the connector plugin updated to 1.2.0 on each site.

- Danışman → Google Ads "Negatif anahtar kelime listesi": Admin sees "Google Ads'e ekle…", edits the list, confirms; terms go to "MoxDOP negatifleri" shared list (existing ones skipped), list attached to enabled Search campaigns; item closes; status line + "Geri al".
- SEO Görevleri → brief: Admin "WordPress'e taslak gönder" → title + H2 skeleton + queries/links as comments, `post` (guide/faq) or `page` (service/location), never published; "Taslağı aç" link + "Geri al" (trash, only while still a draft).
- Connector plugin 1.2.0 (`connectors/wordpress/moxdop-connector`): draft endpoints; `status.capabilities = ['drafts']`; site owner can disable.
- Known limits: undo does not detach the shared list from campaigns or delete the (empty) list; WordPress draft is a skeleton, the writer fills the text.

## 2026-09-24 (d) — Advisor Faz 6: tek iş listesi, kanallar arası, ölçülen etki

**State:** CODED + PHPUnit (`tests/Feature/Advisor/AdvisorFaz6Test`: merged ranking + per-brand cap, dashboard card, brand block, cross-channel rules, SEO outcome measurement window + GSC clicks, report section text, unbound negative list, digest off/forced). Full Feature + Unit suites: no new failures. Live UAT not run.

- Dashboard: "Bu haftanın en önemli 5 işi" (SEO Görevleri + all advisor channels, max 2 per brand).
- Brand overview: "Danışman" block — one line per channel (Web / SEO, Google Ads, Meta Ads, İşletme Profili, Kanallar arası) with open/urgent counts and last run, then the brand's top 5.
- Cross-channel (`cross_channel`, on the website asset, weekly with the advisor): Google Ads search terms with ≥2 conversions and no site page / not in organic top 10; brand search paid while organic avg position ≤1.5 (a 2-week test suggestion); Business Profile searches (≥40 impressions) with no site page.
- `moxdop:advisor:measure` (Mon 07:30): done items 28 days later — negative lists (spend on listed terms before/after) and SEO tasks with a target page (GSC clicks before/after, 3-day lag); others recorded as done without a metric.
- Client report (value story, PDF, share view, composer): "Yapılanlar ve gözlenen etkisi"; wording is observation only, attribution/causation flags stay false.
- `moxdop:advisor:digest` (Mon 08:00): internal email of the top jobs to active admins; off unless `ADVISOR_DIGEST_ENABLED=true`.
- Not built: down-weighting rule types without measured effect (needs history).

## 2026-09-24 (c) — Advisor Faz 5: İşletme Profili danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/GbpAdvisorRunTest`: all rules end-to-end, brand-search exclusion, keyword↔service matching, silence without binding/media, draft validation, profile page with advisor tab for a bound profile). Full Feature + Unit suites: no new failures; 2 previously failing GBP workspace tests now pass. Live UAT not run.

- Menu page renamed "Danışman" (Google Ads, Meta Ads, İşletme Profili); profile page gets a "Danışman" tab.
- Fixed: bound Business Profile page crashed (`GbpLocationBoundCollector::EVIDENCE_TYPE` was undefined).
- Rules (config `moxdop-advisor.gbp`): profile shown closed (critical); profile gaps (description missing/<250 chars, no extra categories, no hours, no phone, website missing/broken, empty service list, missing cover/logo, Google-updated info, unset attributes); search keywords whose service is not on the profile or not a brand service (3 months, ≥30 impressions, brand searches excluded); priority brand services missing from the profile; 28-day interaction drop ≥30%; rating trend (last 90 days vs prior year, −0.3); photo freshness only when the profile is used; UTM on the website link (paste-ready URL).
- AI: "Taslak hazırla" on profile gaps → 2 descriptions ≤750 chars + service descriptions; no links/phones.
- Known gaps: structured services carry only ids; review replies out of scope; ratings on automated runs come from `gbp_reviews` only.

## 2026-09-24 (b) — Advisor Faz 4: Meta Ads danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/MetaAdsAdvisorRunTest`: collector result resolution, all rules end-to-end, self-closing, panel channel filter, draft queue/limits; Meta frozen tab list updated). Full Feature + Unit suites: no new failures. Live UAT not run.

- Same `/ads-advisor` page (channel switch: Tüm kanallar / Google Ads / Meta Ads) and a "Danışman" tab on the Meta account; weekly run covers both channels.
- Rules (config `moxdop-advisor.meta_ads`): creative fatigue (daily avg frequency ≥1.8 and link CTR −25% week over week), audience saturation (campaign daily avg frequency ≥2.5), low-result ad sets likely stuck in learning (<15 results/week, needed daily budget), conversion campaigns without results, pixel/conversion source health (unavailable, silent >3 days, ad set optimizing to unknown pixel, archived custom conversion), expensive placement/device/hour (account breakdown, click based), landing page crawl issues, cost-per-result jump after a change event.
- AI: "Taslak hazırla" on creative-fatigue → 3 concepts, primary texts ≤250, headlines ≤40, descriptions ≤30; copy-paste only.
- Known gaps: learning stage, weekly reach/frequency, audience size, per-campaign breakdowns and results by placement are not collected.

## 2026-09-24 — Advisor Faz 3: Google Ads danışmanı

**State:** CODED + PHPUnit (`tests/Feature/Advisor/*`: rule engine, end-to-end run/resolve/panel, quality-score collection guard, ad-copy limits). Full Feature suite: no new failures. Live UAT not run; quality score appears only after the next Google Ads collection.

- `/ads-advisor` (menu "Reklam Danışmanı") + Google Ads account tab "Danışman"; weekly `moxdop:advisor:plan --scheduled` (Mon 07:00), manual "Tüm hesapları incele" / per-account "İncele".
- Rules (config `moxdop-advisor.google_ads`): negative keyword list (existing negatives, brand and service terms excluded; single-word phrase negatives; paste-ready), converting terms not yet keywords, budget-limited profitable / zero-conversion campaigns, measurement (no primary, primary no signal, low-intent primary, lead MANY_PER_CLICK, auto-tagging off, Ads vs GA4 google/cpc), landing pages (website crawl status/redirect/noindex + Ads speed/mobile scores), weak RSA ad strength, account asset library gaps, filtered Google recommendations (budget/broad/bidding nudges excluded), low quality score, CPA jump after a change event.
- AI: "Taslak hazırla" on weak-ad-strength items → one budget-guarded call, headlines ≤30 / descriptions ≤90 enforced; copy-paste only.
- Known gaps: asset coverage is account-level (no per-campaign link); search-term match type and RSA text are not collected; outcome measurement 28 days after "Yapıldı" stores the baseline only (reporting in Faz 6).

## 2026-09-23 (i) — SEO Görevleri: page redesign and visible plan rebuild

**State:** CODED + PHPUnit (`SeoPlanRunTest` global panel assertions). Full Feature suite: no new failures. Live UAT not run.

- /seo-tasks opens with a "Haftalık plan" bar: schedule, last build, live "N site için plan kuruluyor" (5 s polling while plans run), primary "Tüm planları yenile" and a 3-step "Planı yenile ne yapar?" explainer.
- Site table lists every active website (customer filter applied) with plan state (Kuyrukta / Kuruluyor / Başarısız + error / last build), content progress, critical fixes, a per-row "Yenile" (`refreshSite`) and row click = site filter.
- Task list: type tabs with counts and a one-line meaning, filters in one toolbar (+ "Filtreleri temizle"), ranked cards with a type colour accent, clamped reason, explicit "Brief ve adımlar" toggle. Setup cards grouped under "Önce bunları yanıtla".

## 2026-09-23 (h) — SEO Görevleri: false positives from the first Faz 2 review

**State:** CODED + PHPUnit (`SeoTaskRuleEngineTest` regression test). Full Feature suite: no new failures. Live UAT not run.

- Pages whose canonical points to another URL are no longer "indexable": parameter URLs (`?pg_client=`, `?elementor_snippet=`…) canonicalized to their clean page no longer inflate canonical/H1/meta/thin counts. `canonical-conflict` only fires for clean (no query string) URLs.
- `thin-content` ignores 0-word pages (text not extracted); when ≥95% of ≥20 pages are thin, the task becomes a low-severity "verify measurement first" card, not a template task.
- Site understanding no longer adopts article titles ("… Nedir?", "… Nasıl …", guide) as inferred services.

## 2026-09-23 (g) — Advisor Faz 2: web depth rules

**State:** CODED + PHPUnit (`SeoTaskRuleEngineTest` depth tests, `SeoDepthInputCollectorTest`, `SeoUrlInspectionQueueTest`, panel render test). Full Feature suite: no new failures. Live UAT not run.

- Collector reads already collected, previously unused data: `gsc_page_daily` (28/28-day and 90-day windows, history length), latest `gsc_url_inspection_snapshot`, latest `gsc_sitemap_snapshot`, latest-crawl `website_link_edge` graph, `website_performance_measurement`, the brand's single connected `gbp_location_snapshots`; stored HTML adds the first paragraph after H1 and `tel:` numbers.
- 11 rules (see `docs/product/SEO_TASKS.md` → Faz 2), all silent without their data; pruning is one decision card per site.
- `SeoUrlInspectionQueue`: each plan run sends important pages without a fresh (<14 days) inspection to GSC URL inspection (≤20, one run per site per ISO week, system trigger). First time URL inspection is used in production flow.
- GPTBot added to AI crawler checks; AI visibility quota 3 → 4.
- Known gaps: PageSpeed still measures one URL per site (service pages unmeasured); pruning cannot see publish dates, so a brand-new page can appear (the card says to keep it); Person/author detection only covers pages whose HTML was read (≤150).

## 2026-09-23 (f) — Matching expressions, GBP keyword import, brand/customer screens

**State:** CODED + PHPUnit (`tests/Feature/Portfolio/BrandWorkspaceTest.php`, `tests/Feature/SearchDemand/GbpLibraryImportTest.php`, `BrandSetupAssistantTest`). Full Feature suite: no new failures versus the previous head (147 pre-existing failures unchanged). Live UAT not run.

- "Otomatik kur" proposes location-free, service-specific matching expressions and appends them to the catalog service (`ServiceKeywordService::append`, generic words rejected centrally), so later imports auto-assign.
- Query import source `google_business_profile` reads already-collected `gbp_search_keywords_monthly` (month-based range).
- Brand page rebuilt (`App\Livewire\Operator\Portfolio\BrandShow`, `BrandWorkspaceReadService`): 5 tabs, setup checklist, real connection state and last sync; legacy `Demo\Portfolio\BrandShow` (session-shaped, fixture sections) removed. Reports moved into `InteractsWithBrandReports`.
- `OperatorPortfolioPresenter` derives connection state and `connected_assets` from active bindings (was hardcoded "not configured").
- Customer page: 3 tabs, brands with setup state, contacts inline; contact role now survives edit.
- Brand create takes an optional website and opens "Otomatik kur" with the proposal started. The `/setup` portfolio wizard (placeholder account steps) was removed with owner approval (route, component, view, session helpers, lang keys, wizard-only tests); `/setup` now returns 404.
- Playwright specs `tests/e2e/02, 04, 05, 09, 10` and report scripts updated to the new screens (stable hooks: tablist `Marka`, `[data-asset-row]`, customer `⋯` menu). Not executed here: the harness is pinned to `/workspace` and branch `cursor/production-readiness-audit-ea01`.

## 2026-09-23 (e) — Location-free services/keywords, out-of-area demand

**State:** CODED + PHPUnit (`BrandSetupAssistantTest`, `SeoTaskRuleEngineTest`). Triggered by the first live "Otomatik kur" run (busranurozger.com, İstanbul brand): aliases such as "jinekomasti ankara" and Ankara queries.

- `LocationOptions::describe/withinAreas/classify`: location readings (country/city/district) judged against the brand's service areas.
- "Otomatik kur": service names and aliases are stripped of locations; each service gets location-free, non-branded Search Console keywords; on approval they are stored in the query library (sector → service → query, source `search_console`) and inherited into the brand's query portfolio. The page reports out-of-area locations (or asks for service areas when none exist).
- SEO Görevleri: out-of-area queries are excluded from content buckets; one `out-of-area-demand` question card per site ("Hizmet verdiği yerlere ekle" / "Hizmet vermiyorum").
- Known gap: district names are matched from the national list; a district that is also an ordinary word can be misread.

## 2026-09-23 (d) — Advisor Faz 1: AI cost control, free providers, brand "Otomatik kur"

**State:** CODED + PHPUnit (`tests/Feature/AiControl/AiCostControlTest.php`, `tests/Feature/BrandSetup/BrandSetupAssistantTest.php`). Live UAT with real Groq/OpenRouter keys, real Google discovery (GA4 data streams) and a real brand approval not run. Roadmap: `docs/product/ADVISOR_ROADMAP.md`.

- Groq and OpenRouter API-key providers under Integrations → AI providers (connection check; OpenRouter check endpoint unverified live).
- Every `laravel/ai` call is recorded in `ai_usage_records` (tokens, estimated USD via `config/moxdop-ai-pricing.php`). Monthly budget (default 25 USD, editable on AI Control Plane): when exhausted only known-zero-price models run; plans continue with rule results.
- AI routes carry a client-data flag; free/third-party tiers are rejected on client-data routes. Default steps: analysis Sonnet 5, classification Haiku 4.5, public data Groq, Gemini as fallback.
- Brand "Otomatik kur" (`/brands/{brand}/setup`): queued proposal (website asset, GSC by domain, GA4 by data-stream URL, GBP by website/name, Ads/Meta by name, services via one AI call on route `brand_setup.assistant`), single admin approval applies selected items through existing binding/catalog/offering services. No external writes.
- Known gaps: pre-existing failures in `OperatorAssetDataSourcesGuardsTest` (blade syntax in `asset-data-sources.blade.php`) and `ModuleBoundaryArchitectureTest` allowlist are unrelated and unchanged.

## 2026-09-23 (c) — SEO Görevleri: first real-data review fixes and page redesign

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 15 tests / 143 assertions). Triggered by the first staging run: "missing title" listed 2,226 URLs on moximu.com (robots.txt, sitemaps, feeds) and 22 per-service question cards flooded the list.

- Non-HTML URLs are excluded from the inventory; head/H1 facts are judged only when observed (missing ≠ not collected). Fix lists are ordered by search traffic and template-level issues are flagged.
- Service ↔ page matching rescored (title/H1, URL, GSC share, article penalty, margin rule); only starred services are asked, in one grouped card per site; no "create service page" while candidates wait.
- Brand services that do not fit a website switch that website to site understanding (reason `brand_services_not_on_site`).
- `/seo-tasks` redesigned: KPI strip, per-site overview, setup cards, Turkish labels, impact/effort, brief copy, Turkish pagination.
- Existing staging rows from the first run become `stale` automatically on the next plan run (task keys changed).

## 2026-09-23 (b) — SEO Görevleri: stored HTML rules and site understanding

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 12 tests / 121 assertions). Live UAT with real stored HTML, real Anthropic calls and real no-service Brands not run.

- Stored HTML is read through the Website module (`StoredPageReader::html()`, new; `read()` unchanged) via the allowlisted adapter `App\Services\SeoTasks\SeoStoredHtmlReader`. New rules: duplicate H1, missing image alt on service pages, Organization schema without `sameAs`. HTML-based H1 check replaces the profile heuristic when HTML exists.
- Brands without services: `SeoSiteUnderstanding` infers services from stored pages + GSC + GA4 (AI route `seo_tasks.site_understanding`, 28-day reuse, rule-based page-topic fallback). Inferred services are plan-local; operator adopts them from the website SEO tab ("Markaya ekle").
- Anthropic key: existing `/integrations/anthropic` page is the entry point; no new integration field was needed. LLM calls now use a 120 s timeout instead of the 20 s provider default.

## 2026-09-23 — SEO Görevleri (staging branch)

**State:** CODED + PHPUnit (`tests/Feature/SeoTasks/*`, 9 tests / 90 assertions). Live operator UAT with real GSC/WordPress data, real Anthropic call and the weekly scheduler on staging are **not** run. Spec: `docs/product/SEO_TASKS.md`.

- New tables `seo_plans`, `seo_tasks`, `service_page_assignments`; `brand_offerings.is_priority` (backfilled from `priority_rank`).
- Engine `App\Services\SeoTasks\*` (collector → rules → optional single LLM call → diff writer), job `RunSeoPlanJob`, command `moxdop:seo:plan`, weekly schedule `seo-tasks-weekly-plan`, AI route `seo_tasks.content_planner` (Anthropic primary, OpenAI fallback).
- Operator UI: `/seo-tasks` (menu item), website asset tab `SEO Görevleri`, brand offering ★ toggle.
- Guarantee: at least 4 `create` tasks per site per run whenever the brand has offerings (GSC-backed first, library/fallback briefs otherwise, marked as such).
- Not touched: Task / Finding / Recommendation tables, clustering, collection, WordPress plugin.
- Known gaps: duplicate H1 and image alt rules need HTML reads (not produced); `sameAs` content not verified; queue/Horizon behaviour of the 600 s job only exercised with the sync driver.

## Account admission starvation and GA4 empty landing values — 2026-09-12

Operator supplied post-deploy GA4/Ads HTML: automatic job #86 is active, but Ads shows 56
eligible accounts, two with historical facts and zero active Ads transfers. GA4 exposes a
PERSISTENCE rejection for an empty `landingPage`. HTML alone does not reveal all scheduler,
queue or account rows, so live root-cause closure is not claimed.

Fixed source-level blockers: account admission now mirrors the existing dedicated Google Ads
worker and shared non-Ads worker, with two active/planning accounts per lane (four total by
default). Each lane selects its own due candidates, so a GA4/GSC backlog cannot exhaust Ads
admission or hide Ads behind the per-tick limit. Active/planning IDs are deduplicated. Provider
worker concurrency, quotas, pauses, authentication checks and MCC exclusion remain authoritative.

GA4 landingPage empty strings remain exact provider values, distinct from `(not set)` and `/`.
The effective storage overlay explicitly allows empty text only for the landingPage dimension
in landing-page daily/event-landing daily datasets. Null/missing scope keys remain invalid.
Malformed dimension positions/types fail normalization, with no checkpoint advance.

The existing automation settings/status panel is now visible on Ads, GA4 and GSC connector
pages. Staging deployment invokes due admissions and selectively rearms enabled automations
stopped by this exact historical landing-key rejection. Scheduled ticks do not blanket-reset
attention states; paused/revoked accounts are excluded from the repair.

Verification: regression tests cover both directions of worker-lane isolation, exact failure
recovery without unpausing accounts, empty landing preservation and strict missing keys.
PHP/Pint remain unavailable; targeted PHPUnit/Pint commands could not execute. `git diff --check`
and `bash -n deploy/staging/deploy.sh` pass. Live staging/provider/UAT acceptance remains pending.

## Collection visibility and interrupted workers — 2026-09-12

Staging branch `chatgpt/search-demand-foundation`: the header lists global active jobs with
site/account, manual/automatic origin, queue/retry/stalled state, completed dataset count and
last dataset activity. It no longer presents an equal-weight aggregate as a crawl percentage.
The detail panel links to existing background operations. Successful website console counters
are explicitly dataset completion, not URL coverage. Fresh running work takes precedence over
old queued dependents when classifying stalled work.

Scheduled collection recovery now reclaims running datasets only after both activity and the
execution lease expire (at least 30 minutes). Checkpoints and completed datasets are retained;
three resumes at an unchanged checkpoint are allowed, then the dataset fails visibly. Failed
prerequisites settle their queued dependents; terminal children reconcile unfinished parents.
An executor returning after its lease was replaced cannot advance that run's checkpoint/state.
Delayed continuations persist `retry_at`, so database workers and early queue deliveries respect
provider backoff. Redispatch alone no longer records fictitious dataset progress.

WordPress reconciliation reuses a recent completed full/manual inventory with all five WP
datasets complete, without advancing pending content-event cursors. Access-role-only events
do not trigger another full inventory. Daily/three-day CMS inventory and bounded event refresh
remain; general public crawl and PageSpeed still require explicit collection. Cross-run HTTP
conditional revalidation and adaptive URL scheduling are not implemented by this change.

Verification: focused PHPUnit regressions added for recovery bounds, live leases, dependencies,
orphan parents, delayed continuation, header visibility/stall classification, inventory reuse
and access-only events. `git diff --check` passes. PHPUnit could not execute (`php` unavailable);
Pint could not execute (no vendor installation). Live provider, worker and operator UAT remain
unverified. No schema or connector package change is required.

## Automatic collection recovery — 2026-09-11

Source fixes on `chatgpt/search-demand-foundation`: paired WordPress connections initialize
inventory scheduling without an incoming heartbeat (including existing installations on the next
tick). Signed status refresh updates the installed version without inventing event receipts.
Full inventories work with V1; changed-object scopes still require 1.1.0. Pause/cursors persist.

New discovered accounts become due immediately under the existing two-slot bound. Old unused
initial delays are recovered, while pauses and error backoff remain. Terminal parents no longer
consume slots through stale resource rows; Google Ads repairs their unfinished datasets. A new
planner clears its prior run reference so the scheduler cannot mistake a previous failure for
the new attempt. Missing run references use bounded retry. Staging planning jobs target the
existing Redis/Horizon worker; non-durable queue configuration fails explicitly. Meta/GBP resume
after the required real binding appears; unbound collection is still unsupported and no binding
is invented. This is not a claim that all discovered Meta accounts can collect without mapping.

Verification: regression tests added for capacity, initial delays, paused accounts, binding recovery,
missing/previous runs, sink rejection, WordPress bootstrap and preserved cursors. `git diff --check`
passes. PHP, Composer dependencies and Pint are unavailable here; PHPUnit/Pint could not execute.
Live scheduler/worker/provider UAT and deployment remain unverified. No migration or plugin ZIP
update is required for these server-side fixes.

> Manual query clusters, 2026-09-09: source-reviewed staging implementation only. No tests, migrations, queue, build or UAT executed.

> **Canonical product capability truth table for MoxDOP.**  
> Public Discovery Stage 1 update: 2026-09-07, `chatgpt/search-demand-foundation`. 70 targeted tests / 365 assertions passed; Pint, frontend build and Blade compilation passed. Real staging PostgreSQL, worker operation and operator UAT remain unclaimed. Contract: `docs/product/DISCOVERY_INTELLIGENCE.md` (ADR-061).
> Previous Website standards update: 2026-09-06, staging work branch `chatgpt/search-demand-foundation`; main is not evaluated by this update. No PR/merge is part of this task.
> Website verification: 63 targeted tests / 851 assertions, 39 PHP syntax checks, Pint, frontend build, Blade compilation and authenticated route checks passed. Full-suite, live-model and staging PostgreSQL/operator UAT remain unclaimed; details: `docs/product/website/WEBSITE_STANDARDS_ASSESSMENT.md`.
> Do **not** treat “IMPLEMENTED V1” in older docs as Definition-of-Done **DONE**.  
> Persistent product direction: `PROJECT_MEMORY.md`.  
> Async operator standard: `OPERATOR_ASYNC_EXECUTION.md`.

## How to read this ledger

| Column | Meaning |
| --- | --- |
| **Code** | Meaningful product code present; explicit `(branch)` rows apply only to the named work branch, never implicitly to main |
| **Automated Tests** | PHPUnit coverage for the recorded capability slice on its named branch |
| **Real UAT** | Real provider / operator UAT explicitly claimed in canonical docs |
| **Operator UX** | Filament / operator surface usable for the slice |
| **Background-ready** | Long-running operator flows actually queue and return control (not merely Job classes existing) |
| **State** | Ledger state — see `PROJECT_MEMORY.md` Definition of Done |
| **Known blocker / debt** | Explicit gaps |
| **Canonical notes** | Scope boundaries and pointers |

**States used:** `PLANNED` · `IMPLEMENTING` · `CODE COMPLETE` · `TESTED` · `UAT REQUIRED` · `UAT PASS` · `PARTIAL` · `BLOCKED` · `DONE`

**Inspection rules used for this snapshot:**

- Unmerged PR code is **not** main.
- Job classes implementing `ShouldQueue` without operator `dispatch` ≠ background-ready.
- Filament actions that call `(new SomeJob(...))->handle(...)` or inline services are **synchronous**.
- “IMPLEMENTED V1” in product docs = version label / scoped slice, not automatic **DONE**.

---

## Capability ledger

| Capability | Code | Automated Tests | Real UAT | Operator UX | Background-ready | State | Known blocker / debt | Canonical notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Manual service query clusters (four-step operator roadmap) | YES (staging branch) | NO — operator instruction | NO | Bilingual tree/table, filters, bulk selection/move, central rename, child CRUD/merge, receipts/undo, CSV, Website target plans | YES — 250-row queued operations + durable progress/Activity; confirmation snapshot is one synchronous SQL statement | **CODE COMPLETE / UAT REQUIRED** | No runtime, migration, queue, load or visual validation; 100k throughput unmeasured. Existing import caps unchanged. No automatic export/receipt retention. Legacy Brand AI clusters preserved separately; downstream bridging is later scope. | Global service is root; one child level on existing query-service associations; no AI or DataForSEO call. Snapshot/revision guards protect subsequent changes. Source and scope: docs/product/SEARCH_DEMAND_INTELLIGENCE.md, manual-clusters section. |
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Operator `/customers` `/brands`; Filament `/admin` technical CRUD |
| Digital Assets | YES | YES | NO | YES | PARTIAL | TESTED | Long actions migrated to queue; short cross-asset checks still sync | Operator `/assets`; types include website, google_ads, gbp, meta_ads, instagram |
| MoxDOP Intelligence Core | YES (branch) | NO | NO | N/A | N/A | **CODE COMPLETE** | Tests and live UAT intentionally not run. Formula-to-Evidence consumers and additional provider adapters remain later milestones. | ADR-046/047; versioned registry + capability/metric contracts + Page/Search Term/Entity/Business Action identities and provenance aliases. Provider fact tables stay canonical; existing Formula/Evidence/Finding pipeline is reused. |
| Website Intelligence Projection | YES (branch) | NO | NO | PARTIAL | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Pages & Content, Technical Health, Infrastructure & WordPress and Data Sources consume projection profiles; Search Console and Google Analytics specialist drill-ins remain directly available. Remaining cross-source Website tabs do not yet consume their target projections. Live projection backfill and provider/collection UAT are required. DataForSEO, GBP and AI Search adapters remain later slices. | Rebuildable 90-complete-day Page/Search Term/Entity/Outcome profiles over Website public facts, authenticated WordPress, bound GSC and bound GA4. GSC page/query period grains and GA4 landing/event period grains are explicit contracts. External source keys longer than the storage boundary use a deterministic SHA-256 key while raw identity stays in canonical facts/aliases; failed source cards expose only safe run/error-class references. Queued and synchronous rebuilds share an asset-scoped lock to prevent concurrent identity writes. Source-keyed typed states retain period, coverage, value state and provenance. Collection completion queues a rebuild; `intelligence:website-projection:rebuild` supports backfill. No generic metric warehouse, magic score, AI Finding or provider write. |
| Website Pages & Content workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run per operator request. Requires deployed projection backfill, two post-deploy public HTML observations for semantic comparison, and operator review with real Website/WordPress/GSC/GA4 coverage. Remaining Website tabs are separate phases. | Compact projection-backed Page inventory with reconciled public/CMS/platform scopes, saved operator views, deterministic pagination Page families, source-aware missing states, meaningful-vs-raw HTML change separation, mobile cards and a right-side detail drawer. Recent stored HTML versions remain authenticated, checksum-verified and non-executable plain text. Missing remains distinct from zero; the screen presents facts, not Findings. |
| Website Technical Health workspace | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires real public crawl, targeted verification, TLS and optional PageSpeed operator review. Deterministic observations are not yet promoted to Findings in this slice. | Projection-backed HTTP reachability, redirects, crawl observation severity/counts, document-head and schema facts, TLS certificate state, PageSpeed lab LCP coverage, responsive page filters/details and source-record provenance. `Verify fix` queues the selected URL plus at most 99 same-issue URLs in the same pagination family; each URL is independently fetched, versioned and only clears when the current observation disappears. It never manually resolves observations or expands into a full crawl. No opaque health score; missing is not zero; interpretation remains in Improvements. |
| Website Infrastructure & WordPress workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires projection rebuild and operator review with a real paired WordPress site. Site Health and update facts remain observations, not Findings. | Entity-projection-backed WordPress/PHP/runtime facts, safe settings, active theme, plugin/theme inventory and updates, taxonomy/feature/SEO-provider summaries, connector state and separate external TLS/Website configuration. Credentials are never exposed; missing remains distinct from zero. |
| Website Data Sources workspace | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires operator review against real Public Website, WordPress, PageSpeed, GSC and GA4 source states. DataForSEO, GBP and AI Search cards are intentionally deferred until source adapters exist. | Projection coverage and canonical connection health are presented separately for Public Website, WordPress Connector, PageSpeed, confirmed GSC and confirmed GA4. Shows source readiness, collected-data state, watermark, honest coverage counts, Website profile contribution and links to canonical management/collection screens without duplicating raw dataset tables. |
| Canonical operator URL architecture (ADR-044) | YES | YES | NO | YES | N/A | **TESTED** | Formal live host UAT not claimed on this PR | Operator product at `/` `/login` `/customers` `/brands` `/assets` `/integrations` `/tasks`; Filament `/admin` only; legacy `/app` `/system` → 410 |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth requires external Google Cloud console; resource refresh sync | Agency Google Integration; ADR-039/040; Prompt 13+14 |
| Frozen Google Integration UI (backend state) | YES | YES | NO | YES | N/A | **TESTED** | Discovery/bind UX still PARTIAL (Prompts 15–16); connector pages still Demo | `GoogleIntegrationReadModel` + `GoogleConnectorRegistry`; docs: `GOOGLE_INTEGRATION_ARCHITECTURE.md` |
| Google OAuth & credential lifecycle | YES | YES | NO | YES | YES | **TESTED** | External Google Cloud verification/approval MANUAL; no live OAuth in CI | `GoogleOAuthService` + `GoogleCredentialBroker` + attempt store; docs: `GOOGLE_OAUTH_CREDENTIAL_LIFECYCLE.md` |
| Google resource discovery (GA4/GSC/Ads/GBP) | YES | YES | NO | YES | PARTIAL | **TESTED** | GBP/Ads external API access MANUAL; discovery sync on operator action; no auto bind | `DiscoverGoogleResourcesService` + four discoverers; operator Data Sources refresh is Admin-gated before any provider call. Docs: `GOOGLE_RESOURCE_DISCOVERY.md` |
| Google resource selection & asset binding | YES | YES | NO | YES | N/A | **TESTED** | Human confirmation required; no collection side effect; Filament `/admin` + operator Data Sources share Confirm* guards; replacement preserves binding identity | `ConfirmGoogleResourceBindingService` (+ Meta equivalent); docs: `GOOGLE_RESOURCE_SELECTION_BINDING.md` |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request; frozen bind workflow Prompt 16 | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | YES | TESTED | Async via Activity Center / database queue; real Ads UAT not re-run here | Operator Collect Now / Collect live data for GA4/GSC/Google Ads uses Collection Engine (`ExecuteCollectionLifecycleService::runNow` → `CollectionRun` / warehouse), not BoundCollector Evidence summaries. GBP remains BoundCollectorRegistry. Queued `CollectLiveBoundDataJob` still wraps the operator trigger. |
| Google Ads Intelligence | YES | YES | NO | YES | YES | TESTED | Collect + AI guidance queued; Expert Workspace not redesigned | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | YES | TESTED | Refresh data + diagnosis queued | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | YES | TESTED | SEO refresh queued when provider work needed; fresh cache stays sync | Workspace V2A, SEO Light, AI guidance. Period presets: Demo catalog/fixtures stay on `DemoPeriod::ANCHOR_DATE`; real operator/provider reads use wall-clock / `OperatorPeriod` (not `APP_ENV`). |
| Public Website Discovery — stored Stage 1 | YES (branch) | YES — 70 targeted tests / 365 assertions including regression checks | NO | YES — TR/EN review and Integration handoff | YES — existing collection + resume + missed-event recovery | **TESTED / UAT REQUIRED** | Real PostgreSQL, queue and representative Website/operator UAT remain required. Deterministic extraction can miss unstructured services. SERP, reviews, social content and continuous monitoring remain later stages. | Reads current verified stored HTML; refreshes gaps via existing public collector. Original source dates, coverage/gap examples, identity-safe dedupe, edit/map/ignore, canonical service/area/competitor receipts and social profile handoff. No basic AI/paid calls; preserves legacy decisions. ADR-061 / `docs/product/DISCOVERY_INTELLIGENCE.md`. |
| WordPress Connector V1 | YES (branch) | YES (pending gate) | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Live disposable WordPress install, pairing, signed status/snapshot, five connector datasets, versioned public HTML collection and connector↔public parity UAT are still required. Production deploy is not claimed. | Installable read-only plugin; one-time pairing; encrypted credentials; HMAC request/response; site/content/media/taxonomy/extensions/SEO snapshots. Public Discovery remains active on paired WordPress Websites. Final visitor HTML is stored separately per URL as content-addressed compressed artifacts with current/previous hashes and explicit change state. Website integration separates discovered URLs from HTML coverage and keeps interpretation in Website analysis. |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
| Global Service Catalog + Brand Service Areas | YES (branch) | PARTIAL — Discovery transfer/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full catalog and Brand-edit workflow tests were not run in this slice. City/district values are operator-entered in this slice; a maintained geography reference import remains later. | Stable agency-wide Service IDs + aliases link to existing Brand Offering IDs. Brand form captures services, explicit priority and multiple country/city/district areas without creating service × area jobs. Structured Brand Context receives compatibility projections. |
| Search Query Library | YES (branch) | NO | NO | YES | NO | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Import runs synchronously and is bounded by upload/row limits. Brand Query Portfolio, SERP validation and URL ownership are later phases. | Manual, pasted, CSV/TSV/TXT/XLSX queries with TR-safe normalization, service association, market/language, approved semantic fields, branded exclusion state, source provenance and optional Ads/GSC/DataForSEO metrics. Missing metrics stay missing. |
| AI Search Demand Librarian | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration plus queue worker and operator review. It has no provider-spend, Finding, Task, CMS or external-write capability. | Search Intelligence Analyst + generation/classification Skills; structured query, alias, family, intent, problem, stage, location, branded-suspicion and future cluster candidates. Persistent confidence/abstention/provenance; exact agent/Skill/route/input fingerprint reuse; bulk edit/approve/reject gate. |
| Brand Query Portfolio | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Global-promotion submissions need a later library-review workflow; no provider call is triggered. | Relational global-query inheritance from active Brand services, Brand-only queries, explicit Brand overrides/exclusion, dynamic multi-area `{location}` rendering, canonical `IntelligenceSearchTermIdentity` resolution and per-Website activation. No copied global query text or persistent Service × Area expansion. |
| AI Search Demand Clustering | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires configured AI Integration, queue worker and human review. No SERP evidence is consumed in this phase, so approval remains `ai_prediction`; locked clusters reject mutation. | Three-layer demand-family/SERP-intent/content-target clusters, representative query, content-type suggestion, confidence/rationale/uncertainty, incremental new-query runs, reviewable move/merge/split proposals, manual controls and immutable version snapshots. Exact Agent/Skill/route/input reuse; no automatic Finding, Task, content or external write. |
| Search Demand Query–URL Visibility Map | YES (branch) | NO | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Reads only website-active portfolio items. GSC/GA4 binding and requested-period coverage may be unavailable; provider row limits apply. Missing means unknown, never zero. | Period/comparison GSC query–URL performance joined to canonical Website Page profiles, observed HTTP/robots/HTML state and page-grain GA4 landing behavior. Query/cluster/service/area/observed filters, cluster summary, query detail and URL detail; no new metrics warehouse or query-level GA4 attribution. |
| Search Demand DataForSEO / SERP Enrichment | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires active DataForSEO credentials, configured Website SEO market/language, queue worker and explicit paid consent. Live endpoint shape, reported cost and provider UAT remain unverified. An unresolved paid attempt fails closed as `CHARGE_UNKNOWN` and is never auto-retried. | Provider-neutral adapter; service/cluster-scoped max-20 batch; desktop/mobile first 10/20 organic results, SERP features and observed Brand rank; separately labelled provider-estimated volume/CPC/competition/monthly trend; exact fingerprint freshness reuse and paid locks. Optional Keyword Ideas remain human-reviewed portfolio candidates. SERP-overlap cluster status is a persisted recommendation applied only by operator approval. No auto Brand call, URL ownership, competitor library, Finding, Task or external write. |
| Search Demand URL Ownership / Page Relevance | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a rebuilt Website Page Projection, observed page language/HTTP/head facts for the fail-closed technical gate, configured AI Integration for semantic review and a queue worker. Real GSC/SERP/operator UAT is required. | One versioned human URL-owner decision per Website + content-target cluster; bounded Page candidates from existing projection, separate GSC/SERP/current-owner/term-match provenance, deterministic technical gate and two-period wrong-URL/cannibalization candidacy. Page Relevance AI can only propose an eligible URL or abstain. Approval rechecks live eligibility; lock is human-controlled. No automatic redirect, delete, merge, page creation, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitor Library / Discovery | YES (branch) | PARTIAL — reviewed Discovery handoff/preservation covered | NO | YES | N/A | **CODE COMPLETE / UAT REQUIRED** | Full stored SERP import and Library workflows were not exercised in this slice. Real stored SERP/domain observations and operator review require UAT. Import is synchronous and bounded to 100 distinct domains; it performs no provider request. | Brand-scoped normalized competitor domains with pending/approved/rejected lifecycle; independent commercial/SERP/content roles and business/directory/platform/authority kind; append-only source provenance, observed URLs/queries and service/area/cluster relations. Manual approved entry plus individual/bulk candidate review. Stored DataForSEO facts can establish SERP candidacy only, never automatic commercial competition. No crawl, AI analysis, Finding, Recommendation, Task or external write. |
| Search Demand Competitor Page Collection | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a queue worker, public DNS/HTTP access and approved competitors linked to a content-target cluster. Live-site response behavior and operator UAT are unverified. | Deterministic URL-hash dedupe; max 3 URLs per competitor / 20 per run; exact-URL-only SSRF-safe Public Discovery fetch with no link following; normalized text, title/meta/H1–H6, schema, bounded links and service/location expression observations. Raw/content fingerprints append history and reuse unchanged content without duplicate parsing/storage. No AI, Finding, Recommendation, Task, provider spend or external write. |
| Search Demand Competitive Intelligence | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner with stored checksum-valid Website HTML, successful Phase 10 observations, configured AI Integration and queue worker. Real operator/model UAT remains unverified. | Dedicated Competitive Intelligence Analyst + Skill + route; exact-fingerprint reuse; bounded stored-evidence comparison over max 8 competitor pages; proposed competitor kind/roles, page intent, topics/questions, structure, local trust, missing user needs, do-not-copy cautions and differentiation. Canonical Activity plus separate run/page proposal records and human accept/reject. Review never mutates competitor truth or URL ownership; no browsing, Finding, Recommendation, Task, publication or external write. |
| Search Demand Finding / Recommendation Planning | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a verified URL owner and readable own-page HTML, enabled standards, configured AI Integration and queue worker. Phase 11 context is optional (ADR-060); see tested Website standards rows below. Migration, queue/model behavior and operator promotion require staging UAT. | Deterministic technical and evidence-bounded Website Improvement AI semantic proposals; exact Agent/Skill/route/input fingerprint reuse; action taxonomy, content brief, evidence IDs, confidence, rationale, verification and abstention. Explicit operator acceptance publishes canonical Evidence → Finding evaluation → existing Finding → existing Recommendation. Rejection changes only review state; insufficient evidence cannot be promoted; Task remains a separate manual action. No browsing, content publication, Website mutation or external write. |
| Website Standards Library (phases 14–16, staging) | YES (branch) | YES (targeted) | NO | YES | N/A | **TESTED / UAT REQUIRED** | Admin catalogue controls and all Skill definitions tested locally; manual visual/operator acceptance is pending. | 26 versioned definitions, preserving 17 diagnosis IDs; groups, applicability, evidence, source, action, verification; admin toggles and up to 30 expert criteria. Existing Library records and collection/binding flow retained. |
| Independent Website Standards Assessment (staging) | YES (branch) | YES (targeted) | NO | YES | YES | **TESTED / UAT REQUIRED** | SQLite tests and route renders; live PostgreSQL/storage/worker/operator UAT pending. Limits: 500 profiles, 3,000 active queries, 100 clusters, 20 candidates/cluster. No full-site completeness claim beyond those limits. | Queue-based stored-data evaluation with zero AI/provider calls; applicability/unknown/advisory/verified outcomes, explicit technical priorities and grouped affected URLs; source/freshness reuse and changed HTML invalidation; current-evidence human promotion to canonical Website Findings/Recommendations. Existing service/query/owner coverage preserves blocked candidates and human locks. |
| Shared Website Content / Competitor Criteria (staging) | YES (branch) | YES (targeted, fake model) | NO | YES | YES | **TESTED / UAT REQUIRED** | Live model advice quality and real competitor comparison require UAT. Own page must have readable HTML within 30 days. No automatic new-page/merge decision, external publication or Task creation; global technical proposals excluded from cluster-specific Phase 13. | Selected-page standards review works without competitors; approved comparable rival context is optional. Exact criterion IDs/own-rival excerpts, non-actionable and fabricated-output rejection, source changes invalidate approval, unchanged semantic content reuse; scoped Library links preserve selection. |
| Search Demand Change / Outcome Tracking | YES (branch) | NO | NO | YES | YES | **CODE COMPLETE / UAT REQUIRED** | Tests intentionally not run. Requires a completed Task from an approved Phase 12 proposal, stored pre-change HTML, a queue worker, configured AI Integration, post-change targeted crawl and real GSC/GA4/SERP coverage for full comparison. Migration, storage checksum, queue/model and operator acceptance require staging UAT. | Applied-change provenance with affected URLs/clusters and old/new HTML fingerprints; bounded exact-URL + page-family Public Crawl; deterministic technical recheck; review-only stored-evidence semantic AI; explicit GSC/GA4 periods and stored SERP comparison. Human acceptance writes the existing Task Outcome and optionally appends resolved/reconfirmed Finding evaluation. No Result entity, causal claim, automatic DataForSEO spend, publication, redirect/delete or external write. |
| DataForSEO | YES | YES | NO | YES | YES | TESTED | Paid refresh queued when not fresh; cost/freshness guards remain | Central Integration + Website SEO collectors |
| AI Control Plane | PARTIAL | YES | NO | YES | PARTIAL | PARTIAL | Capability Router / Playbooks / RAG still PLANNED; long AI guidance queued | AI Router + Agent Profiles + Skill Library V1 present |
| Website Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; no tools/MCP/Capability Router | Website SEO Analyst + Brand Discovery Analyst |
| Google Ads Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; real Ads UAT not claimed PASS | Second operational Agent after Website |
| Recommendation | YES | YES | NO | YES | N/A | TESTED | AI drafts only; humans create Recommendations | Finding → Recommendation gate |
| Tasks | YES | YES | NO | YES | N/A | TESTED | Snapshot immutability (ADR-029) | Manual Recommendation → Task |
| Outcome Loop | YES | YES | NO | YES | N/A | TESTED | Metric Outcomes / Learning Candidates not in V1 | Task outcome signals + Finding re-eval; no Result entity |
| Business Outcome aggregates (Prompt 57) | YES | YES | NO | YES | N/A | **TESTED** | Client Value Story / Report Snapshots not yet; CRM out of scope | Definition + Observation + Revision + Manual/CSV; Brand Value outcomes cards use Read Service |
| Client Value Story (Prompt 58) | YES | YES | NO | YES | N/A | **TESTED** | Report Snapshots / PDF / share not yet; Demo catalog story fixtures retained | Deterministic read projection over Findings/Opportunities/Work/Outcomes; no attribution/AI |
| Meta central Integration | YES | YES | YES | YES | NO | UAT PASS | Resource refresh still sync | Agency Meta Integration; product docs claim real UAT PASS |
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Canonical `DiscoverMetaResourcesService` (selected Business context). Operator Data Sources Meta refresh uses `refreshInventory`, not broad `me/adaccounts` enumeration. Admin-gated. |
| Meta binding | YES | YES | YES | YES | N/A | UAT PASS | Collect live data hidden without collector | Meta Ads Digital Asset ↔ AssetBinding |
| Meta Ads Intelligence | YES | YES | YES | YES (interim specialist UX) | YES | **UAT PASS / ACCEPTED — NOT DONE** | Collect + AI guidance **queued** (async foundation). Professional Meta Expert Workspace **NOT IMPLEMENTED**. Real async Meta collect UAT tracked on Async Operations PR. | Read-only Intelligence engine on main after PR #119. Ads Manager spot-check PASS retained. |
| Professional Operator Workspace (Meta Ads) | NO | NO | NO | NO | N/A | PLANNED / BLUEPRINTED | Blueprint only — no final dashboard/charts/filters built; depends on Operational Data Foundation after async | Canonical blueprints: `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` + `docs/product/META_ADS_EXPERT_WORKSPACE.md` |
| Async execution | YES | YES | YES (Cloud Meta async smoke) | YES | YES | **TESTED / ACCEPTED** | Cancellation future; cross-asset still sync; persistent public host **deferred** (templates only) | Async implementation accepted on #121 (queue + Activity + Cloud Meta smoke). Persistent deployment ≠ required for this acceptance. |
| Historical performance memory | PARTIAL | PARTIAL | NO | PARTIAL | NO | PARTIAL | No dedicated historical warehouse / backfill / incremental store | Run/Evidence history exists; Historical Performance Store **PLANNED** |
| Operational Taxonomy | NO | NO | NO | NO | N/A | PLANNED | Do not invent taxonomy module yet | Direction in `PROJECT_MEMORY.md` |
| Marketing Initiative | NO | NO | NO | NO | N/A | PLANNED | No model/service on main | Brand-level commercial effort grouping — future |
| Benchmark Cohorts | NO | NO | NO | NO | N/A | PLANNED | No cohort objects on main | Compatible taxonomy dimensions required first |
| Cross-Asset Analyst | PARTIAL | YES | NO | YES | NO | PARTIAL | Deterministic packs TESTED; Analyst persona PLANNED; jobs invoked sync | Consistency packs in Core; Digital Operations Analyst future |
| Agency Learning | NO | NO | NO | NO | N/A | PLANNED | No Learning Candidate pipeline | Human-reviewed Agency Knowledge only — future |
| Platform Engineer | NO | NO | NO | NO | N/A | PLANNED | Research reference only (e.g. OpenHands) | Not a customer-analysis runtime |
| Google Business Profile | PARTIAL | YES | NO | PARTIAL | NO | PARTIAL | No local rank grid / competitor data; reply drafting not in scope; live UAT not run | Location page reads collected gbp_* tables: profile + completeness, performance with comparison, search keywords, reviews, photos/posts; Advisor tab (2026-09-25 Faz A) |
| Finding lifecycle / fingerprint | YES | YES | NO | YES | N/A | TESTED | Unique `(digital_asset_id, fingerprint)` | Persistent Findings; ADR-034 |
| Evidence / Run model | YES | YES | NO | YES | N/A | TESTED | Foundational model; not a historical warehouse | Evidence bound to Run; no separate Result entity |
| Shared collection engine (control plane) | YES | YES | NO | NO | YES | **TESTED** | Redis/Horizon required for production collection queue | Prompt 9: `CollectionRun`→`ResourceRun`→`DatasetRun` + planner + Horizon. Docs: `docs/implementation/COLLECTION_ENGINE_ARCHITECTURE.md`. Operator Collect Now for GA4/GSC/Google Ads/Meta Ads starts this engine (not specialist Evidence collectors). GA4/GSC/Ads/Meta/Website/DFS DatasetExecutors exist on the current release stack; that does **not** make those collectors REAL/DONE without their own UAT gates. |
| Data pool / warehouse foundation | YES | YES | NO | NO | N/A | **TESTED** | Provider population not REAL; BigQuery not implemented; SQLite proves writer semantics, PostgreSQL proves partitions | Prompt 10: raw object storage + typed PostgreSQL facts + materialization. Docs: `docs/implementation/DATA_POOL_ARCHITECTURE.md` + `MOXDOP_DATA_POOL_STORAGE_V1`. |
| Persistent collection monitoring | YES | YES | NO | YES | YES | **TESTED** | Provider collectors still fake/unimplemented; Reverb optional; polling is mandatory fallback | Prompt 11: Integrations hub `MonitoringPanel` + `CollectionRunMonitorQuery`. Docs: `docs/implementation/COLLECTION_MONITORING_UX.md`. Does **not** make provider collectors REAL. |
| GA4 production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs GA4 UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period GA4`). Unique users stay non-additive. Property retention may truncate 16m. Canonical `/assets/analytics/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month backfill token, optional `newUsers`/`conversions`/`keyEvents`/`totalRevenue` on `ga4_property_daily`, YoY period compare, pool-backed Analytics screen |
| GSC production collection (contract-driven) | YES | YES | PARTIAL (staging) | YES | YES | **PARTIAL** | Live closed-period ±1% vs Search Console UI is EXTERNAL UAT REQUIRED (`moxdop:reconcile-provider-period SEARCH_CONSOLE`). Search Appearance still deferred. Canonical `/assets/search-console/{id}` is numeric-only; DemoCatalog string ids 404 and never yield fixture KPIs. | Track A: 16-month recommended backfill, pool-backed Search Console screen, previous+YoY compare, missing≠zero |
| Google Ads production collection (contract-driven) | YES | YES | PARTIAL (staging) | NO | YES | **PARTIAL** | Daily facts are successful zero-row on the bound staging account; GBP not in this slice; keyword grain now includes `ad_group_id` but staging 735 remains collapsed historical inventory (`IMPLEMENTED_UNPROVEN` until staging recollection with exact-resource `current_run_grain_proven`). Cursor Cloud cannot reach staging OAuth; operator path is `moxdop:google-ads:recollect-entity-snapshot` (`docs/operations/GOOGLE_ADS_KEYWORD_GRAIN_RECOLLECTION.md`) | Prompt 19; sibling-asset eligibility + v25 GAQL + keyword ad-group grain + pre-fact-commit checksum retry + brand-scoped Google backfill/incremental due-query (`core_asset_binding_ids`); non-keyword snapshots proven on PR #200 |
| Meta production collection (contract-driven) | YES | YES | NO | YES | YES | **PARTIAL** | Unmerged stacked child of PR #200. Live Marketing API / warehouse Collection Engine UAT **not** reachable from this Cursor Cloud agent (`META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` unset; `APP_URL=http://127.0.0.1:8000`; no staging SSH/OAuth DB). Legacy Intelligence Ads Manager UAT (`act_744654160596455`) does **not** prove this warehouse path. Google Ads keyword grain remains `IMPLEMENTED_UNPROVEN` on #200. GBP isolated. No Meta specialist UX. | Prompt 24 `MetaAdsDatasetExecutor` + Prompt 25 initial backfill + Prompt 27 incremental on the shared engine. COLLECTION_READY families: `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`. Deferred (not expanded): `RF_META_ASYNC_INSIGHTS` (async is transport inside daily/breakdown). Incremental due selection uses exact preflight `core_asset_binding_ids`; `DATA CURRENT` only when every eligible Meta dataset in that binding scope is current. Google bindings never enter Meta runs. |
| Website production crawl collection | YES | YES | NO | YES | YES | **PARTIAL / UAT REQUIRED** | Live Website crawl and authenticated WordPress Connector Collection Engine UAT remain external gates. Public collection truthfulness, per-URL idempotency, HTML artifact recovery and change detection require live UAT. Connector is not a substitute for external crawl; no production deployment is claimed. | Shared engine public families remain `WEB_RF_HTTP_HTML_DIAGNOSIS`, `WEB_RF_PUBLIC_CRAWL`, `WEB_RF_DNS_TLS`, `WEB_RF_PAGESPEED`. The resumable crawl seeds from sitemap, existing URL inventory and published connector permalinks, follows same-site links and is bounded at 5,000 pages / 2 GB. `website_html_snapshot` retains every observation while unchanged bodies reuse a content-addressed private artifact. A paired WordPress Website additionally plans `WEB_RF_WP_REST`; non-WordPress sites stay public-only. Integration shows required progress and discovered-vs-captured HTML coverage; Website analysis owns interpretations. Not DONE. |
| DataForSEO production enrichment (engine-driven) | YES | YES | NO | NO | YES | **PARTIAL** | Unmerged stacked child of PR #203. Live Marketing/Labs UAT **not** reachable (`DATAFORSEO` credentials unset in this agent; no staging SSH). Paid POST is never auto-retried / never routinely scheduled. Fail-closed `paid_attempt_started` is checkpointed before the charged POST; an unresolved attempt fail-closes that DatasetRun (`CHARGE_UNKNOWN`) even if the fingerprint recomputes. Paid request fingerprint remains the lock/cost provenance key **and** the `HIT_FRESH` pool key (asset + dataset + fingerprint, including `target` / `location_code` / `language_code`); a market change is a cache miss and POSTs again. Warehouse write idempotency is unique per DatasetRun + batch so a sibling asset or force-refresh cannot reuse another run’s committed receipt. A different fingerprint is allowed only on a new DatasetRun. Legacy SEO Evidence collectors unchanged. Domain intersection, relevant pages, and SERP organic stay DEFERRED. | Shared engine `DataForSeoDatasetExecutor` for COLLECTION_READY: `DFS-FREE-USER`, `DFS-FREE-MARKETS`, `DFS-RK-LIVE`, `DFS-KFS-LIVE`, `DFS-COMP-DOMAIN-LIVE`. Agency Integration credentials; facts are Website-asset scoped. Paid families require `paid_enrichment_consented`; competitors also require `public_discovery`. Missing search_volume/etv recorded as missing, never a measured zero. Not DONE. |
| Agency brain / operational synthesis (Phase C.1) | YES | YES | NO | N/A | N/A | **PARTIAL** | Live provider/WordPress UAT remains external and is not claimed. No BrainV2 / FindingV2 / Result entity / auto-Task / Agency Learning. Document Head only evaluates collected public dimensions on a proven homepage. WordPress maintenance and parity rules only evaluate completed connector/public DatasetRuns; update availability never implies a vulnerability. Meta/Google coverage safeguards remain unchanged. | `EvaluateFindingsForAssetJob` runs canonical `FindingEvaluationService` then `CollectedFactsAnalysisService`. Website combines `DocumentHeadEvaluator` with `WordPressCollectedFactsEvaluator` for core/plugin/theme update state, REST/cron state and connector↔published title/description/canonical parity. Google Ads and Meta adapters remain unchanged. Recommendations are deterministic; Task creation remains manual. Not DONE. |
| Operational / settings completeness (Phase D) | YES | YES | NO | YES | N/A | **PARTIAL** | Live SMTP delivery UAT and browser/mobile push remain external/deferred. No SaaS whitelabel, no second credential screen, no SettingsV2. | Canonical operator `/settings` + `/profile` + `/integrations`. Admin/Team Member lifecycle with deactivate-not-delete. Agency timezone/locale drive operator rendering via `OperatorClock` (storage clock stays `APP_TIMEZONE`); dashboard greeting/date use `OperatorClock::now(auth()->user())`. Encrypted write-only operator SMTP overlay with env fallback and test-mail action. `SendReportDeliveryJob` reloads the persisted overlay and purges the mailer at the queued send boundary so long-lived workers honor settings changes/clears. In-app notification preferences only; push not implemented. |
| End-to-end operator UX / QA (Phase E) | YES | YES | NO | YES | YES | **PARTIAL** | Staging/browser operator UAT not reachable from this Cursor Cloud agent (`APP_URL=http://127.0.0.1:8000`; empty `GOOGLE_CLIENT_ID` / DataForSEO / Meta secrets; no staging SSH). Live provider collect remains the isolated #200/#203/#204 gate. Collection Engine still rejects PHPUnit `sync` queue; production Website refresh surfaces that as unavailable rather than a fake success. No UI redesign, no push/PWA, no GBP collector reopen. | Canonical root journey `Login → Customer → Brand → Asset → Data Sources bind/collect → Activity → Evidence/Finding → Recommendation → manual Task → Outcome`. Data Sources Collect Now for GA4/GSC/Ads/Meta creates `CollectionRun` (no specialist Evidence summaries); GBP stays on BoundCollectorRegistry. Production period reads use `OperatorPeriod` / `OperatorReportingPeriod` (custom dates override DemoPeriod math). Capture note/opportunity are truthful unavailable. Google Ads/Meta `runAnalysis` queues finding evaluation. Findings/Recommendations `?asset=` isolation. Activity Center lists `CollectionRun` + async `Run`. PHPUnit: `tests/Feature/PhaseE/*`. Not DONE. |
| Production readiness / release (Phase F) | YES | YES | NO | YES | YES | **PARTIAL** | **RELEASE-CANDIDATE CODE READY WITH EXTERNAL GATES** on the dedicated RC integration branch that actually contains #202 + #199 + #200-downstream (#203→#204→#206→#207→#208→#209). PR #209 alone is **not** that cumulative ancestry. Remaining external gates: Reviewer APPROVED, SSH/deploy of this RC SHA, Google Ads keyword recollection, GBP official API, Meta/Website/DataForSEO shared-engine live UAT, live SMTP, browser push/PWA (not implemented). Do not treat Demo fixtures as production truth. GitHub `verify`/`postgres` ran on the Collect Now SHA; `gate` failed because the RC PR body quoted the Autopilot product-PR HTML marker (substring match). This RC PR is not Autopilot and must not contain that marker. | Hardening only: controller-backed retired `/app` `/system` 410 routes so `route:cache` stays deploy-safe; `moxdop:production-check` HTTPS + OAuth-callback + redacted failures; canonical docs/ADR-044 match root operator + Filament `/admin`. Operator Data Sources bind through Confirm*; Google/Meta resource refresh on that page is Admin-only and Meta uses selected-Business `refreshInventory`. PHPUnit: `tests/Feature/PhaseF/*`. Not DONE. |

---

## Critical clarifications

### Meta Ads Intelligence — UAT PASS / ACCEPTED, not DONE

PR [#119](https://github.com/yakupudul/dijitaloperation/pull/119) (*Meta Ads Intelligence + Analyst V1*) merges the **read-only Meta Ads Intelligence engine** onto main.

Accurate multidimensional state:

> **UAT PASS / ACCEPTED — NOT DONE**

Accepted operator UAT (Ads Manager manual spot-check **PASS**):

| Field | Value |
| --- | --- |
| Meta Ad Account | Obezite ve Estetik (`act_744654160596455`) |
| Campaign | `09 \| Diaspora TR \| Form - Mox` |
| Period | `2026-07-14` → `2026-08-10` |
| Result | DOP metrics matched Meta Ads Manager |

Also accepted on this slice: hierarchy collection, provider-ID joins, missing≠zero, click/result metric semantics, synthetic UAT isolation, read-only Meta client (GET only).

**Explicitly still NOT DONE / not claimable as finished Meta product:**

- **Background-ready: YES** for collect + AI guidance (queued) — Activity Center persists progress. Real async Meta collect UAT is on the Async Operations PR.
- **Professional Operator Workspace: BLUEPRINTED / PLANNED, NOT IMPLEMENTED** — current Overview/Performance is an interim UAT surface; target IA is `docs/product/META_ADS_EXPERT_WORKSPACE.md` (+ global `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Historical arbitrary querying / performance warehouse: **NO**

Do **not** describe this merge as “Meta Ads complete”, “Meta module finished”, or “Meta workspace done”.

Main also continues to include Meta central Integration + discovery + binding (connection layer), with prior product-doc real UAT PASS for that scoped slice.

### Meta production collection (contract-driven) — PARTIAL, not DONE

Contract-driven Meta Ads collection already uses the shared Collection Engine and Data Pool (`MetaAdsDatasetExecutor`, `MetaInitialBackfillOrchestrator`, `MetaIncrementalCollectionOrchestrator`). This stacked child closes the contract-to-runtime loop for COLLECTION_READY `META_ADS` families (catalog + executor kind + PHYSICAL_TABLE natural keys + freshness/backfill policy), bounded 180d historical slices with exact asset/resource provenance, DatasetWritePipeline grain/idempotency on the nine Meta physical tables, checkpoint resume for entity snapshot `step_index` and insights `work_index`, and incremental `DATA CURRENT` only when every eligible Meta dataset in the exact preflight binding scope is current.

**COLLECTION_READY families:** `RF_META_AD_ACCOUNT_META`, `RF_META_ENTITY_SNAPSHOT`, `RF_META_INSIGHTS_SYNC`, `RF_META_INSIGHTS_DAILY`, `RF_META_TYPED_ACTIONS`, `RF_META_INSIGHTS_BREAKDOWN`.

**Deferred (not expanded):** `RF_META_ASYNC_INSIGHTS` — async Insights is transport inside daily/breakdown, not a separate collector family.

**UAT REQUIRED / PARTIAL:** this Cursor Cloud agent cannot reach the already UAT-proven Meta Integration OAuth path. Exact missing capability: no `META_APP_ID` / `META_APP_SECRET` / `META_ACCESS_TOKEN` in process or `.env`, `APP_URL` is `http://127.0.0.1:8000`, no `.env.staging` / production secrets, no staging SSH or operator SQLite with a live Marketing API token. Do not treat PR #119 Ads Manager Intelligence UAT (`act_744654160596455`) as proof of this shared-engine warehouse path.

**Not claimed:** live Marketing API warehouse CollectionRun, professional Meta Expert Workspace, Google Ads keyword-grain staging proof (still `IMPLEMENTED_UNPROVEN` on PR #200), or GBP.

Do **not** describe this slice as “Meta collection DONE”.

### Agency brain / operational synthesis (Phase C.1) — PARTIAL, not DONE

Phase C.1 wires already-supported deterministic analyzers to collected Data Pool facts instead of Demo fixtures or live provider HTTP:

- Website/SEO: `website_metadata_snapshot` → existing Document Head rules (only collected dimensions) on the proven homepage URL (`primary_url` slash variants or the HTTP snapshot redirect target of that request). Homepage metadata, redirect HTTP, and schema rows must belong to a completed DatasetRun for this asset; running/failed crawls skip as `unproven_website_homepage_snapshot`. Multi-page crawls with a shared checkpoint `observed_at` never fall back to a higher-ID sibling such as `/contact`.
- Google Ads: bound `google_ads_campaign_daily` → existing campaign spend-with-zero-conversions rule, only from a non-partial 28-day window whose coverage dates are attributed to completed DatasetRuns (warehouse facts from those runs plus completed zero-row dates); failed paged-run slices in merged materialization metadata cannot prove the window
- Meta Ads: bound `meta_campaign_daily` + `meta_campaign_snapshot` → existing inactive-campaign-with-spend rule; daily window uses the same completed-run coverage attribution. Snapshot status is taken only from the materialization's latest successful `meta_campaign_snapshot` DatasetRun (stale entities from older completed refreshes, and running/failed/partial entity snapshots, are never current / `response_ok=true`)

Canonical production job `EvaluateFindingsForAssetJob` now runs collected-facts adapters after `FindingEvaluationService`. `FindingEvaluationService` emits `FindingEvaluationCompleted` so Outcome V1 can observe later canonical GSC/GA4 evaluations (ported from superseded PR #205; Google Ads account `conversions-decline` Evidence definition was not copied because #206 already has a campaign-grain Ads vertical). A thrown rule after eligibility emits `evaluationSuccessful: false` for that module so a crashed follow-up cannot classify `IMPROVEMENT_OBSERVED` from a stale Finding. Manual Recommendation → Task remains human. AI does not create Findings or Tasks. Live provider UAT from #200/#203/#204 is a separate external gate.

Do **not** describe this slice as “Agency brain DONE” or as proof of live Google/Meta/Website collection UAT.

### Operational / settings completeness (Phase D) — PARTIAL, not DONE

Phase D reuses the existing operator Settings/Team/Profile/Integrations surfaces. It does **not** introduce SettingsV2, UserV2, NotificationV2, SaaS whitelabel, or a second credential store.

Shipped in this slice:

- Admin-only team create/role/deactivate (no destructive delete; last admin protected)
- Operator forgot-password / reset on `/forgot-password` (inactive/unknown emails share the same success copy and receive no mail; successful reset rotates remember token and does not reactivate). Reset mail locale is set on the Notification at dispatch and in explicit `__()` calls; `MailMessage::locale()` is not used.
- Agency timezone/locale/default analytical range affect operator date rendering (`OperatorClock`) and session period defaults, including dashboard greeting/date via `OperatorClock::now(auth()->user())`. Invalid stored timezone/locale values fall back to catalog defaults. Laravel `APP_TIMEZONE` remains the storage clock (password-reset tokens / Eloquent datetimes / queue+artisan are not rewritten per operator).
- Operator SMTP overlay: encrypted write-only password, env fallback without copying env secrets into the DB, test-mail action; invalid host/port/encryption is rejected without poisoning runtime mail config; test-mail failures log exception class only; queued report send reloads the overlay and purges the resolved mailer so Horizon/worker processes do not keep a stale transport after Settings change or clear
- In-app notification preferences for existing events; browser/mobile push is **not** implemented

**Not claimed:** live SMTP provider UAT, web-push/PWA, SaaS tenant branding, or Filament as the canonical operator settings product (`/admin` remains technical).

Do **not** describe this slice as “Phase D DONE”.

### End-to-end operator UX / QA (Phase E) — PARTIAL, not DONE

Phase E is QA + narrow remediation of the canonical root operator journey. It does **not** reopen provider collection, Agency Brain analytics, Settings architecture, GBP collectors, push/PWA, or whitelabel.

Shipped in this slice:

- Production date presets/custom ranges use agency `OperatorClock` “today” (`OperatorPeriod`) and treat filled from/to as a custom range (`OperatorReportingPeriod`) so workspace period controls actually change warehouse reads
- Website overview KPIs stay `—` when the requested period does not overlap collected days (`period_has_data`); collected values outside the range are not reused as stale current KPIs. KPI summaries and `gsc_daily` still use overlap / per-day slice semantics. GSC queries/pages and GA4 landing/acquisition **undated aggregates** require an exact `requested_period` match; dated detail rows may be sliced when the Evidence period overlaps. A wider aggregate is not shown under a narrower 7-day/custom selection and is never prorated. Missing/uncollected detail datasets stay empty arrays, never numeric zero.
- Capture `note` / `opportunity` are unavailable (no DemoState persistence); `client_request` and `task` still persist
- Google Ads `createRecommendation` / `markClusterReviewed` no longer flash a fake success
- Google Ads and Meta Ads `runAnalysis` queue `FINDING_EVALUATION` instead of AI guidance
- Findings and Recommendations indexes honor `?asset=`
- Activity Center lists Collection Engine runs plus async `Run` rows (`metadata.async`)
- Website `refreshData` starts production collection when possible and surfaces Collection Engine `sync`/Redis unavailability instead of a fake refresh
- Deterministic PHPUnit journey: Customer → Brand → Website asset → GA4 bind → `Http::fake` collect → Evidence + Document Head Finding → grounded Recommendation → manual Task → later `improvement_observed`
- Demo catalog IDs remain 404 on operator routes; Atlas copy is not rendered on production website/GA4 surfaces

**Not claimed:** staging browser smoke, live provider collect UAT, Collection Engine on PHPUnit `sync` queue, or Phase E DONE.

Do **not** describe this slice as “Phase E DONE”.

### Production readiness / release (Phase F) — PARTIAL, not DONE

Phase F is release convergence/hardening. The dedicated RC integration branch is the first tested head that actually contains **#202 + #199 + #200-downstream**. PR #209’s own ancestry does **not** include #202 or #199. This slice does **not** reopen collection, Agency Brain, Settings, GBP, push/PWA, or whitelabel.

Shipped in this slice:

- Retired `/app/*` and `/system/*` 410 responses are controller-backed so staging `route:cache` remains a real deploy step
- `moxdop:production-check` verifies HTTPS/`APP_FORCE_HTTPS`/secure cookies on staging/production, canonical Google/Meta callback paths, and does not print `APP_KEY` or exception secrets
- Canonical docs (MASTER_SPEC §6/§12, ADR-044, AGENTS.md, PROJECT_MEMORY, deploy/rollback/backup/smoke) match the root operator + Filament `/admin` contract
- Focused PHPUnit: `tests/Feature/PhaseF/PhaseFReleaseReadinessTest.php`

**External gates (not claimed here):** live staging deploy from this agent (public HTTPS login is reachable at `app.moximu.com` but there is no SSH, no host `.env` access, and no ability to `git checkout` the RC SHA); Google Ads keyword exact-resource recollection; GBP official API; Meta/Website/DataForSEO shared-engine live UAT; live SMTP delivery; browser/mobile push (not implemented); Prompt 68 host backup restore drill; repository Reviewer APPROVED + stacked merge.

Do **not** describe this slice as “Phase F DONE” or as production-deployed.

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async foundation (material)

Long operator actions (bound collect, Website diagnosis, public discovery, SEO refresh when not fresh, Website/Google/Meta AI guidance) queue via `AsyncOperationService` onto Laravel **database** queue (Redis/Horizon on staging/production). Canonical execution record remains **Run** (`queued|running|completed|partial|failed`; `cancelled` reserved). Operator Activity Center is the root Livewire surface `/activity` (`operator.activity`) with phase progress, duplicate guards, stale detection, retry for safe failures, and in-app database notifications. Filament `RunResource` at `/admin/runs` remains technical/admin tooling only (ADR-044). **Cancellation** is intentionally **not** shipped (fragile with current job architecture). Cross-asset consistency packs and integration resource refresh remain synchronous by design for now.

### Async is not fully universal

On main after Async Operations merge:

- Migrated Digital Asset long actions **dispatch** queue jobs (not `(new Job)->handle()`)
- Cross-asset consistency checks may still call `->handle()` in-request (short/safe)
- Integration resource discovery refresh may still be sync
- Cancellation of in-flight provider work is **future**

Track readiness per capability in this ledger’s **Background-ready** column — do not mark Historical Store / Expert Workspace DONE because async landed.

### “IMPLEMENTED V1” ≠ DONE

Many product docs and `docs/PROJECT_STATUS.md` use **IMPLEMENTED V1** / **COMPLETED** for scoped milestones. This ledger intentionally separates:

- version / milestone labels
- Definition-of-Done **DONE**

Most coded capabilities on main are **TESTED** or **UAT PASS** (Meta connection slice) or **PARTIAL**, not **DONE**, especially while async debt remains for long-running flows.

---

## Module inventory (main)

| Module | On main | Notes |
| --- | --- | --- |
| `website` | YES | Collection, intelligence, Discovery, analysts, skills |
| `google-ads` | YES | Collector, Findings, Analyst, skills, workspace |
| `google-business-profile` | YES | First-module collector; Reputation not present |
| `meta-ads` | YES | Insights collector + Intelligence + Analyst on main after #119; async collect/AI via Core queue jobs |
| `sample-module` | YES (fixture) | Not an operator product capability |

Core owns Customer/Brand/DigitalAsset, Integrations, Run/Evidence/Finding/Recommendation/Task, and cross-asset packs.

---

## Maintenance rule

When a PR changes capability behavior or readiness:

1. Update **this ledger in the same PR**
2. Update `PROJECT_MEMORY.md` if the change is a material product / architecture decision
3. Do not mark DONE without reconciling code, tests, real UAT, operator UX, async requirement, blockers, and docs

---

## Snapshot provenance

| Field | Value |
| --- | --- |
| Base | `origin/main` (updated at PR #119 acceptance) |
| PR #119 | Ads Manager operator spot-check **PASS** — merge acceptance for Intelligence engine |
| Accepted UAT | `act_744654160596455` / `09 \| Diaspora TR \| Form - Mox` / `2026-07-14`→`2026-08-10` |
| Method | Code / test / Filament invocation / operator Ads Manager comparison |
| Guessing | Forbidden — unknown real UAT recorded as **NO** unless docs claim PASS |


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


## Brand edit hotfix — 2026-09-08

Source review found that `Brand.offerings` is both a legacy text attribute and a HasMany relationship. `fillCommercialContext()` called collection methods on the shadowing text/null attribute during Brand edit mount. It now explicitly reads the loaded relationship through `getRelation('offerings')` for selected services and priorities. Legacy text and all database identities remain unchanged.

Direct staging hotfix; no clone, tests, build or server execution, per operator instruction. This resolves the identified code defect; the reported production HTTP 500 has not been correlated with server logs or verified after deployment.


## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.


## Brand detail mount hotfix — 2026-09-08

The routed Operator BrandShow adapter still called map() on Brand's legacy offerings text attribute when a BrandIntelligenceContext existed. The prior edit-form fix did not cover this separate mount path. Read the explicitly eager-loaded offerings relation with getRelation('offerings') when constructing the business-context service list. Existing filtering, order, labels and stored data are unchanged.

Source-reviewed direct staging patch; no clone, tests, build or server execution, per operator instruction. This fixes an identified fatal code path; the reported HTTP 500 remains unverified against server logs and post-deployment runtime.


## Brand service bulk selection — 2026-09-08

The shared Brand create/edit form adds Select all shown and Deselect all shown. Actions use the same server-derived serviceOptions as rendering, respecting selected sectors, active status, search text and selected-only filtering. Selection is deduplicated and preserves other selections and existing priorities. Deselection removes priorities only for deselected services. These actions change form state; persistence still requires Save. Empty/inapplicable buttons are disabled; TR/EN labels and visible count are provided.

Direct staging change, source-reviewed only. No cloning, tests, build or runtime/UAT verification, per operator instruction.


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

## Automatic resource collection → Query Library — staging source, 2026-09-09

| Capability | Code | Operator UX | Async | Tests / UAT | Remaining scope / limits |
| --- | --- | --- | --- | --- | --- |
| Discovered-account cadence and smart continuation | Added on staging work branch | Google/Meta integration account controls; daily/3-day/pause/update now, status and actionable errors | Scheduler + existing central/bound collectors; two account slots | NOT RUN by operator instruction; live runtime unverified | Google Ads/GSC/GA4 resource-first; Meta/GBP require real existing binding; no Website/paid-provider auto enablement |
| Completed-data automatic Ads/GSC query imports | Added on staging work branch | Persistent sector/service mapping, progress, observations, resume/close, Activity | Four admitted account imports, 100-row steps, indexed dataset receipts | NOT RUN; no 100k benchmark | Initial successful stored history included; counters describe source rows; no provider-metric aggregation |
| Preserve manual decisions and recheck unmatched queries | Added on staging work branch | Sticky query deletion/rename aliases, removable service chips and automatic-match blocks, confirmed recheck | Recheck queued; small manual edits synchronous | NOT RUN; migration/UAT required | Existing service/child placements preserved; changed account mapping does not retroactively reclassify existing queries |

See PROJECT_MEMORY automatic account section for full contract, worker/scheduler prerequisites and explicit unimplemented resource-first Meta/GBP scope. No main or server deployment is claimed.


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

## Website integration collection controls — 2026-09-10

Implemented in staging source; no clone, tests, formatter, build, browser UAT or deployment run,
as explicitly requested by the operator. This is not a verified runtime/DONE claim.

- Website integration offers General (public HTML/TLS + paired WordPress), Public, WordPress full
  inventory, and PageSpeed scopes. PageSpeed is explicit in this screen; other existing callers'
  family defaults are unchanged. Missing CMS/PageSpeed connections are checked on the server.
- Connector 1.1.0+ delivery state exposes daily/three-day full inventory cadence and pause/resume.
  Event-driven refresh remains in bounded batches between inventories. Pause affects future
  admissions, not an active run or receipt/audit recording; manual collection remains available.
- Display last receipt, automatic reconciliation/full inventory, central pending event count,
  stale receipt warning and retry errors. Pending count is stored unprocessed events, not the
  sender's last-reported outbox size. Automatic inventory timestamps exclude manual collections.
- Source statuses use latest dataset attempts per source, independent of the most recent overall
  run. Latest-run counters remain explicitly latest-run counters. Queries no longer hydrate the
  entire collection history each poll; idle screen refreshes every 30 seconds.
- Collection console/history show scope and automatic trigger. PageSpeed-only runs have a valid
  progress denominator. Admission through the Website orchestrator rejects another active run
  under a short per-asset cache lock; reconciliation ticks also use a shared lock.
- Fix full-inventory reconciliation advancing past the first 50 events: only the processed event
  batch advances the cursor after success, retaining later URL refresh work. No incoming history
  is deleted. Failed/partial work retains its cursor, retrying later. At most two automatic
  reconciliation runs remain active; busy/unsupported candidates are deferred.
- A missing recent heartbeat no longer silently suppresses periodic recovery inventory after the
  first supported delivery. Disconnected/unpaired sources are excluded; old plugin versions wait.
- New additive preference columns/indexes require the normal staging migration. Shared cache,
  scheduler and collection workers are required. Keep Connector 1.1.0+ installed and WP-Cron or
  host cron running. General public crawl/PageSpeed are not automatically run daily. No remote
  CMS mutation, paid enrichment or AI request is introduced.

Remaining acceptance: migrate staging, exercise scope selection and pause/resume, confirm delayed
event batches/cursor recovery and source timestamps against a real paired site. No such acceptance
has been performed in this change.

## Standards workspace revision — 2026-09-10

Operator requested standards after the Connector and Website integration updates.

- Deterministic catalogue now has 52 visible definitions (37 general, 15 WordPress); 7 expert
  criteria remain archived. Eight additions cover HTML canonical-target noindex, hreflang
  self/return links, WP HTTPS home setting, plain permalinks, cache declaration, outbox setup
  declaration and post-gap inventory recovery. Two existing length heuristics remain disabled
  by default. Existing enabled/disabled overlays are retained; no catalogue seed is required.
- Website / Google Ads / Meta Ads navigation, populated category counts, platform/status/search
  filters and 20-row pagination replace the unbounded card list. Ads categories remain explicitly
  empty. Active administrators can change enabled state and low/medium/high severity, and restore
  a definition's shipped defaults. Existing settings JSON stores only validated severity overrides;
  arbitrary executable checks cannot be submitted. Historical runs remain unchanged.
- Current completed raw TLS and robots.txt collection rows feed site-level assessment, preferring
  newer evidence. The TLS check is explicitly certificate expiry only, not trust-chain/host
  verification. HTTP-to-HTTPS and sitemap still require their existing diagnosis evidence; missing
  observations stay unknown. No fresh network request or paid data is started by assessment.
- Hreflang inspection reports truncation and resolves against the HTML base URL. Return-link
  checks require fresh full target HTML and observed successful HTTP; external/uncollected targets
  are unknown. This is HTML-only coverage, not sitemap or HTTP-header hreflang validation.
  Canonical noindex covers HTML robots/googlebot directives, not X-Robots-Tag or actual indexing.
- WP inventory freshness is four days, supporting the three-day inventory option; update-cache
  freshness remains two days and delivery review remains 30 minutes. Invalid/future timestamps
  and absent extension inventory cannot become passes. WP freshness state participates in cached
  result identity. Missing stored HTML contributes to incomplete coverage.
- Cache declaration is a review signal, not measured speed or cache-hit proof. Outbox setup checks
  the connector's declared installation marker, not a database write probe. Gap recovery cannot
  reconstruct lost audit events. No automatic site mutations or plugin installs are implemented.
- Assessment rows open 25-row result details with state filters, URLs, reasons and observed values,
  including unknown/pass/not-applicable outcomes. Site checks are stored explicitly on new runs;
  old reports without these details request reassessment. Proposed actions for site checks use
  site-level wording. Saved severity applies to new proposals, with verified target blockers still
  forced high. Existing human approval/Findings/Recommendations pipeline remains in place.
- Existing 500-profile, 5 MB HTML and scoped evidence bounds remain. No all-page scale claim.
  Broader sitemap graph/orphan analysis, full PHP support/vulnerability feeds, GSC URL Inspection,
  browser rendering, remote speed repairs and Ads standards are outside this change.

Verification: direct GitHub source inspection only. No clone, tests, formatter, build, browser
acceptance or server execution performed, per operator instruction. No new migration required
beyond the already committed integration migrations. Live rendering, stored-fact compatibility,
queue execution and real operator acceptance remain unverified.


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


## 2026-09-14 — WhatsApp signup table mapping hotfix

Operator reported HTTP 500 on /whatsapp after the Embedded Signup release. Source inspection
found WhatsAppSignupAttempt lacked an explicit table name: Eloquent infers
whats_app_signup_attempts, while the migration creates whatsapp_signup_attempts.
Set the model table explicitly, consistent with the existing WhatsApp conversation/message/receipt
models. This corrects the same query path in inbox rendering, setup guards and background signup
processing. No schema changes or data deletion. Source reviewed; runtime environment and server
logs are unavailable. No tests, formatter, live rendering or deployment performed. Operator
deployment/confirmation of page recovery remains required; Meta connection readiness is unchanged.


## 2026-09-14 — Isolate Embedded Signup from FedCM login

Operator supplied a failing Facebook URL with scope=openid, response_type=token and
dialog_source=fedcm, followed by a second signup popup. That URL is a separate SDK login
step; the application requests config_id with response_type=code for Embedded Signup.
The connection page now explicitly sets FB.init fedCM:false, status:false and xfbml:false
to opt out of FedCM and automatic status/widget processing. Login remains a synchronous
operator click with the configured business flow and Coexistence featureType.

Meta's FedCM documentation search excerpts expose the fedCM boolean/object option:
https://developers.facebook.com/documentation/facebook-login/web/fedcm
Full documentation and SDK source could not be retrieved in this workspace. The effect of
the opt-out on the current served SDK is not runtime-verified; the operator must confirm
the unexpected OpenID popup disappears after deployment. This is a targeted mitigation,
not a claim that WhatsApp onboarding or the existing-number connection is complete.
Previously reported advanced-access error #2655111 is a separate Meta approval blocker.
No extra permission, account deletion, number registration or backend mutation was added.
No tests, formatter, browser UAT or deployment performed, per operator workflow.


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
