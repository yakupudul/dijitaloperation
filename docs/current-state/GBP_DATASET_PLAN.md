# Google Business Profile data plane plan

Status: implementation target for the existing MOXDOP Data Pool. This is not a second analytics architecture.

## Source boundary

MOXDOP must keep provider facts, derived analysis, and external local-SEO data separate.

- `google_provider`: data read from Google Business Profile APIs.
- `moxdop_derived`: calculations produced from stored provider facts.
- `external_local_seo`: rank-grid / competitor / geo-visibility data from non-GBP providers.

Do not label external rank-grid or competitor data as Google Business Profile data.

## Dataset catalog

### 1. `gbp_location_snapshot`
Provider: Business Information API.
Grain: one location snapshot per external resource + collected date/time.
Purpose: canonical profile identity and operational profile fields.
Core fields: account/location resource names, location id, place id when available, title, store code, primary/additional categories, storefront address, service area, primary/additional phones, website URI, regular/special/more hours, profile description, labels, lat/lng, open status, metadata and raw provider fingerprint.
Retention note: profile content must follow Google Business Profile API content-storage policy; do not treat this table as an unlimited historical archive.

### 2. `gbp_performance_daily`
Provider: Business Performance API.
Grain: external resource + date + daily metric.
Purpose: first-party local visibility and interaction time series.
Metrics: Search desktop/mobile impressions, Maps desktop/mobile impressions, website clicks, call clicks, direction requests, business conversations, bookings, food orders/menu clicks when Google returns them.

### 3. `gbp_search_keyword_monthly`
Provider: Business Performance API search-keyword endpoint.
Grain: external resource + year-month + normalized search keyword.
Purpose: local discovery demand.
Fields: keyword, impressions, threshold/availability metadata when applicable, provider pagination provenance.

### 4. `gbp_review_snapshot`
Provider: Google My Business v4 reviews.
Grain: external resource + review id, refreshed snapshot.
Purpose: reputation operations.
Fields: reviewer display name/photo URI where returned, star rating, comment, create/update timestamps, owner reply and timestamp, reply moderation state/policy violation when returned, review media items, review reply URL when returned.
Retention note: review content must obey Google Business Profile API content-storage policy; persist only the minimum operational snapshot needed and expire provider content on policy schedule.

### 5. `gbp_media_snapshot`
Provider: Google My Business v4 media.
Grain: external resource + media item id.
Purpose: media completeness/current state.
Fields: media format/category, source/create/update metadata and Google-hosted URLs where returned.
Do not create a photo-view KPI from removed/deprecated media insights.

### 6. `gbp_local_post_snapshot`
Provider: Google My Business v4 local posts.
Grain: external resource + local post id.
Purpose: content operations/current post state.
Fields: topic type, language, summary, CTA, event, offer, media, create/update/schedule/recurrence/state fields when returned.
Do not present removed Local Post insights as a current performance metric.

### 7. `gbp_attribute_snapshot`
Provider: Business Information API.
Grain: external resource + attribute id.
Purpose: active attributes and profile completeness.
Fields: attribute id, value type, values/repeated values, display name when available, active state/source metadata.
Available-but-unused attributes should be derived by comparing the location values with the category/region attribute catalog, not fabricated.

### 8. `gbp_service_snapshot`
Provider: Business Information API location service items / categories.
Grain: external resource + service item identifier.
Purpose: offered services and profile/service alignment.
Fields: service type/id, display name/description, price/free-form price when returned, category relationship.

### 9. `gbp_place_action_link_snapshot`
Provider: Place Actions API.
Grain: external resource + place-action-link resource id.
Purpose: booking/order/shop/action destination health.
Fields: place action type, URI, provider type, preferred flag and create/update metadata when returned.

### 10. `gbp_verification_snapshot`
Provider: Verifications API / Voice of Merchant state.
Grain: external resource current verification snapshot.
Purpose: ownership/verification operational health.
Fields: voice-of-merchant state, verification requirement/state, available methods and verification resource metadata where returned.

### 11. `gbp_google_update_snapshot`
Provider: Business Information API `locations.getGoogleUpdated`.
Grain: external resource current Google-updated proposal snapshot.
Purpose: expose Google-suggested changes without silently accepting/reverting them.
Fields: diffable Google-updated location payload plus changed field mask/provenance where returned.

## Derived MOXDOP datasets / read models

These are not direct Google facts and should normally be computed/read-model output rather than masquerading as provider datasets:

- profile completeness score
- visibility totals and Search/Maps/mobile/desktop mixes
- period-over-period / YoY deltas
- interaction and conversion-rate proxies
- review response rate / velocity / sentiment / topic clusters
- category/service/attribute opportunities
- Website ↔ GBP NAP/entity consistency
- GBP ↔ Search Console ↔ GA4 local landing-page analysis
- Findings → Recommendations → manual Tasks → Outcomes

## Explicitly external / later

Do not implement these as GBP provider datasets:

- geo rank grids / 3x3-9x9 map ranking
- keyword-level Maps rank position
- share of local voice
- competitor discovery/rating/category/service snapshots unless sourced from a separate Places/local-SEO provider

## Collection order for the first vertical slice

1. Location snapshot
2. Performance daily
3. Search keywords monthly
4. Reviews
5. Attributes/services
6. Media/posts
7. Place-action links
8. Verification / Google-updated state

Every collected record must carry the canonical MOXDOP identity/provenance fields required by the Data Pool (`customer_id`, `brand_id`, `digital_asset_id`, `integration_id`, `external_resource_id`, dataset id, collected/run provenance as defined by the existing writer contract).