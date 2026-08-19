# Google Ads keyword-grain recollection (staging)

Status: **operator runbook**. This is not a product feature and does not change Google V1 scope.

The corrected keyword snapshot grain is `customer_id × ad_group_id × criterion_id`. It is implemented in the normalizer, storage unique key, and PHPUnit. Staging still holds CollectionRun **#2** dataset **39**’s **735-row last-write-wins inventory**. That historical evidence is preserved and is **not** current-grain proof. Matrix/ledger status stays `IMPLEMENTED_UNPROVEN` until this runbook is executed on staging through the collection engine.

## Why Cursor Cloud cannot do this

CollectionRun #2 (`d24a53b2-caf5-49ad-978f-a4f9629fa91d`, binding `3`, Ads Digital Asset `2`, ExternalResource `173`) ran on the **staging VPS**:

- `APP_ENV=staging`
- `APP_URL=https://app.moximu.com`
- PostgreSQL `moxdop_staging`
- Redis + Horizon `supervisor-collection` (`queue=collection`)
- encrypted Google OAuth refresh token in `core_integration_credentials`
- Google Ads developer token on that host

Cursor Cloud agents boot from `.cursor/environment.json`: SQLite `database/database.sqlite`, empty `GOOGLE_*`, no SSH to the VPS, no staging DB URL. They must **not** invent OAuth tokens, curl Google Ads, or hand-edit `collection_dataset_runs` to `completed`.

Earlier runtime access was possible because those agents/operators were **on the staging host** (or an environment with that PostgreSQL + encrypted credentials). This Cloud pod is not that host.

Missing external capability for a Cloud agent:

1. Deploy/SSH to the staging VPS at the PR head SHA (or a deploy of that SHA)
2. Staging `.env` with existing encrypted Google Ads authorization (already bound; do not paste secrets into chat)
3. Horizon consuming `collection`

## Preconditions

On the staging host, as the app user, in the deployed application directory:

```bash
git rev-parse HEAD
php artisan about --only=environment
php artisan tinker --execute 'echo App\Models\CoreAssetBinding::query()->where("capability","google_ads")->where("status","active")->orderBy("id")->get(["id","digital_asset_id","external_resource_id"])->toJson();'
```

Confirm:

- deployed SHA includes the `ad_group_id` keyword grain (this PR / later)
- `APP_ENV=staging`
- the bound Ads resource is still `core_asset_binding_id=3` unless the operator explicitly rebound it — **never auto-pick the first Ads account**
- Horizon `supervisor-collection` is running
- do not pass tokens on the command line

## Command (repo-supported)

`php artisan moxdop:google-ads:recollect-entity-snapshot`

This starts a **Manual** CollectionRun with `forceRefresh=true` and `requestFamilyIds=['GADS_RF_ENTITY_SNAPSHOT']` through `StartCollectionService`. Incremental Collect Data **cannot** replace this: incremental planning ignores `forceRefresh` and will skip a snapshot that already looks DATA CURRENT (including the old 735-row inventory).

Required:

```bash
php artisan moxdop:google-ads:recollect-entity-snapshot --binding-id=3 --wait --json
```

Useful variants:

```bash
# Plan only
php artisan moxdop:google-ads:recollect-entity-snapshot --binding-id=3 --dry-run --json

# Start and return immediately; then report after Horizon finishes
php artisan moxdop:google-ads:recollect-entity-snapshot --binding-id=3 --json
php artisan moxdop:google-ads:recollect-entity-snapshot --binding-id=3 --report-run-uuid=<uuid> --json
```

`--binding-id` is mandatory. The command refuses production, refuses Cursor Cloud/local unless `--allow-non-staging`, never prints OAuth/developer tokens, and never auto-selects a binding.

`--wait` polls until `GADS_RF_ENTITY_SNAPSHOT` is terminal (default 1800s). Horizon must process `ExecuteDatasetRunJob` on `collection`. Do not mark rows complete by hand.

## First recollection (current grain)

1. Deploy/checkout the SHA that contains the `customer_id × ad_group_id × criterion_id` unique key.
2. Run `--binding-id=<ads binding> --wait --json`.
3. Capture: `deployed_sha`, `collection_run_id` / `uuid`, `dataset_run_id` / `uuid`, `dataset_run_status`, `attempt_count`, `rows_received`, `rows_written`, `write_batches_keyword_snapshot`, `materialization`, `grain_before`, `grain_after`.
4. Treat `grain_after` as current-code proof only if:
   - dataset status is `completed`
   - `attempt_count >= 1`
   - `grain_after.grain_matches_current_schema` is true (`COUNT(*)` = `COUNT(DISTINCT customer|ad_group|criterion)` and no empty `ad_group_id`)
   - `rows_last_written_by_dataset_run` is the current-code inventory
5. If `criterion_ids_in_multiple_ad_groups > 0`, the hashed samples must show `ad_group_count` matching surviving composite rows. If the provider returns no repeated criterion IDs, say so — that is unobserved, not disproven.
6. Preserve CollectionRun #2 dataset 39 / 735 rows as **old-grain history**. Do not delete those collection rows. Warehouse leftovers with a different `last_dataset_run_id` are historical, not current-grain proof.

Do **not** change `GOOGLE_INGESTION_COVERAGE_MATRIX.md` or `PRODUCT_CAPABILITY_LEDGER.md` to `PROVEN_STAGING` unless this first recollection actually completed on current code.

## Second recollection (idempotent)

Run the **same command** again with the same `--binding-id` and `--wait`.

Prove:

- a new CollectionRun / DatasetRun completed
- `grain_after.row_count` and `distinct_composite_count` did not multiply versus the first current-code inventory
- `rows_missing_ad_group_id = 0`
- write-batches committed; no hand-edited statuses

## Honesty rules

- Do not curl Google Ads with a one-off token.
- Do not bypass OAuth/binding.
- Do not `UPDATE collection_dataset_runs SET status='completed'`.
- Do not treat resume of CollectionRun #2 as current-grain proof.
- Do not mark GBP proven. GBP remains `BLOCKED_EXTERNAL` / `MISSING`.
