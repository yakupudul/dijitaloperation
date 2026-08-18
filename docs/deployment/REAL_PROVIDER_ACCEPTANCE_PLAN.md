# Real provider acceptance plan (future staging)

Not executed in repository-side infrastructure preparation. No live Google/Meta/OAuth/DataForSEO/AI calls in this phase.

AI keys remain optional to boot. Sales Intent paid calls remain **OFF** (`MOXDOP_SALES_INTENT_PAID_CALLS=false`). No scheduler job may run paid intent search.

## Setup order (after base staging is healthy)

1. Google application credentials
2. Google OAuth (`https://<APP_URL>/integrations/google/callback`)
3. Google resource discovery
4. Resource binding
5. Collection
6. Meta application credentials
7. Meta OAuth (`https://<APP_URL>/integrations/meta/callback`)
8. Meta resource discovery
9. Resource binding
10. Collection
11. AI providers (optional)
12. DataForSEO paid policy only if explicitly desired

## Google multi-asset scenario

Connect **one** Google integration. Discover multiple provider resources.

Bind:

**Brand A**

- Google Ads Asset A1
- Google Ads Asset A2
- GBP A1
- GBP A2
- GA4 A1
- GSC A1

**Brand B**

- Google Ads B1

Then verify **without opening each Brand daily**:

- cron / `moxdop:dispatch-due-automations` enqueues collection for every **active eligible** `CollectionSchedule` / binding
- each binding gets an independent collection run and freshness
- Customer/Brand/DigitalAsset ownership is correct
- **no cross-brand leakage**

Then manual **Collect live data** on **one** asset:

- only that asset/binding is requested
- same canonical collector / lifecycle path (no second pipeline)
- freshness updates
- historical Run rows remain

## Meta multi-asset scenario

One Meta integration → multiple ad accounts discovered.

- Same Brand: Meta Asset #1, Meta Asset #2
- Different Brand: Meta Asset #3

Bind separately. Central scheduler collects all active eligible bindings. No one-Meta-account-per-Brand assumption. Manual Collect remains asset-scoped.

## GBP multi-location scenario

One Google Business account → multiple locations.

The same Brand may own multiple GBP Digital Assets. Do **not** collapse them into a single location.

Known later limitation: some cross-asset analytics may still assume one GBP. That intelligence is **not** redesigned in this infrastructure phase. Collection and bindings must still treat locations independently.

## Shared collector boundary

| Path | Entry | Queue | Collector boundary |
| --- | --- | --- | --- |
| Automatic | `CollectionSchedule` per Digital Asset → `moxdop:dispatch-due-automations` → `CollectionScheduleAdapter` | Redis `collection` (`ExecuteDatasetRunJob`) | Dataset executors (GA4, Google Ads, Meta Ads, GSC, …) |
| Manual bound collect | Digital Asset **Collect live data** → `queueBoundCollect` | default Redis queue (`CollectLiveBoundDataJob`) | Bound collectors (same provider modules, one asset) |
| Manual integration collect | Integration Collect | Redis `collection` | Same `StartCollectionService` as scheduler |

Both automatic and integration Collect share `StartCollectionService` / collection engine. Bound collect is asset-scoped on-demand using bound collectors. Neither path may invent fixture/Demo metrics.

## Pass criteria (future)

- Independent runs per binding
- Independent freshness
- Correct Brand/DigitalAsset ownership
- No cross-brand leakage
- Manual refresh does not collect sibling assets
- Historical runs retained
- Paid Sales Intent still off unless explicitly enabled
