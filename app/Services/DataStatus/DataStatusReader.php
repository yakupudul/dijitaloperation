<?php

namespace App\Services\DataStatus;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Support\Integrations\AssetBindingCompatibility;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one reader behind every "is this data source connected and current?" signal: asset pages, the Veri durumu
 * strip, the website no-data banner and the Portföy sağlığı grid all ask this class, so they cannot disagree.
 *
 * Per (asset, source) it reads only the real system: the active binding (CoreAssetBinding), the integration's
 * authorization state (CoreIntegration / CoreExternalResource), the central collection state (ResourceAutomation +
 * collection_resource_runs) and the newest reporting_date actually present in the Data Pool fact table of that source.
 * "Last data date" always comes from the facts, never from Evidence or from a run's requested date range.
 *
 * A batch costs one query per table touched (bindings, automations, runs, one fact table per source), and results
 * are kept for the current request.
 */
#[Scoped]
class DataStatusReader
{
    /** Activity tier value that turns a bound source into the `paused` (Pasif) state. */
    public const string ACTIVITY_INACTIVE = 'inactive';

    /**
     * Days a source's newest reporting day may trail today before it counts as late, on top of the account's
     * collection interval: providers publish with a delay (Search Console ~2-3 days, Business Profile ~5).
     *
     * @var array<string, int>
     */
    public const array EXPECTED_LAG_DAYS = [
        'search_console' => 3, 'ga4' => 2, 'google_ads' => 2, 'meta_ads' => 2, 'google_business_profile' => 6,
    ];

    /**
     * Data Pool fact tables whose newest reporting_date is the source's last data day (account/property grain).
     *
     * @var array<string, list<string>>
     */
    public const array FACT_TABLES = [
        'search_console' => ['gsc_property_daily'],
        'ga4' => ['ga4_property_daily'],
        'google_ads' => ['google_ads_account_daily', 'google_ads_campaign_daily'],
        'meta_ads' => ['meta_account_daily', 'meta_campaign_daily'],
        'google_business_profile' => ['gbp_performance_daily'],
    ];

    /**
     * Asset alert kinds that only restate data freshness (AssetAlertScanner). Where the data status is shown, these
     * are left out so the page carries one freshness signal, not two that can disagree.
     *
     * @var list<string>
     */
    public const array FRESHNESS_ALERT_KINDS = ['stale_data', 'ga4_stale', 'gsc_stale'];

    private const array ACTIVE_RUN_STATUSES = ['queued', 'running', 'retrying', 'cancellation_requested'];

    private const array RECONNECT_AUTH_STATES = ['refresh_required', 'reauth_required', 'revoked', 'error'];

    private const array RECONNECT_CREDENTIAL_STATES = ['expired', 'revoked', 'invalid', 'wrong_app'];

    /** @var array<int, list<DataStatus>> */
    private array $memo = [];

    private ?int $memoRequest = null;

    /** @var array<string, bool> */
    private static array $tables = [];

    /**
     * Every source the asset type can bind, in a fixed order, each with its status.
     *
     * @return list<DataStatus>
     */
    public function forAsset(DigitalAsset $asset): array
    {
        return $this->forAssets(collect([$asset]))[(int) $asset->id] ?? [];
    }

    public function forAssetSource(DigitalAsset $asset, string $capability): DataStatus
    {
        foreach ($this->forAsset($asset) as $status) {
            if ($status->capability === $capability) {
                return $status;
            }
        }

        return $this->forAssets(collect([$asset]), [$capability], force: true)[(int) $asset->id][0]
            ?? new DataStatus((int) $asset->id, $capability, DataStatus::NOT_BOUND, action: DataStatus::ACTION_BIND);
    }

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @param  list<string>|null  $onlyCapabilities  restrict to these sources (default: the asset type's sources)
     * @return array<int, list<DataStatus>> keyed by asset id
     */
    public function forAssets(Collection $assets, ?array $onlyCapabilities = null, bool $force = false): array
    {
        $this->guardRequest();
        $wanted = [];
        foreach ($assets as $asset) {
            $capabilities = $onlyCapabilities ?? AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type);
            $capabilities = array_values(array_intersect($capabilities, array_keys(self::FACT_TABLES)));
            if ($force || $onlyCapabilities !== null || ! isset($this->memo[(int) $asset->id])) {
                $wanted[(int) $asset->id] = [$asset, $capabilities];
            }
        }
        if ($wanted !== []) {
            $computed = $this->compute($wanted);
            if ($onlyCapabilities !== null) {
                $out = [];
                foreach ($assets as $asset) {
                    $out[(int) $asset->id] = $computed[(int) $asset->id] ?? [];
                }

                return $out;
            }
            $this->memo = $computed + $this->memo;
        }

        $out = [];
        foreach ($assets as $asset) {
            $out[(int) $asset->id] = $this->memo[(int) $asset->id] ?? [];
        }

        return $out;
    }

    /**
     * Activity tier of a bound account: `self::ACTIVITY_INACTIVE` makes the source `paused` (Pasif) — the account
     * had no activity, so no new data is expected and it must not be reported as late.
     *
     * The account activity-tier reader (built alongside the collection planner) is wired in here and only here;
     * until it exists no account is known to be inactive, so this returns null and `paused` is never produced.
     */
    public function activityFor(DigitalAsset $asset, string $capability, ?int $externalResourceId): ?string
    {
        return null;
    }

    /** Drops the per-request results (after a refresh was started, or between jobs). */
    public function flush(): void
    {
        $this->memo = [];
    }

    private function guardRequest(): void
    {
        $request = app()->bound('request') ? spl_object_id(app('request')) : null;
        if ($request !== $this->memoRequest) {
            $this->memo = [];
            $this->memoRequest = $request;
        }
    }

    /**
     * @param  array<int, array{0: DigitalAsset, 1: list<string>}>  $wanted
     * @return array<int, list<DataStatus>>
     */
    private function compute(array $wanted): array
    {
        $allCapabilities = array_values(array_unique(array_merge(...array_values(array_map(fn (array $w): array => $w[1], $wanted)))));
        $bindings = $allCapabilities === [] ? collect() : CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->whereIn('digital_asset_id', array_keys($wanted))
            ->whereIn('capability', $allCapabilities)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CoreAssetBinding $b): string => $b->digital_asset_id.'|'.$b->capability)
            ->keyBy(fn (CoreAssetBinding $b): string => $b->digital_asset_id.'|'.$b->capability);

        $resourceIds = $bindings->pluck('external_resource_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $automations = $resourceIds === [] ? collect() : ResourceAutomation::query()->whereIn('external_resource_id', $resourceIds)->get()->keyBy('external_resource_id');
        [$lastSuccess, $active] = $this->runs($resourceIds);
        $factDates = $this->factDates($bindings, array_keys($wanted));
        $today = CarbonImmutable::today();

        $out = [];
        foreach ($wanted as $assetId => [$asset, $capabilities]) {
            $out[$assetId] = [];
            foreach ($capabilities as $capability) {
                $binding = $bindings->get($assetId.'|'.$capability);
                $out[$assetId][] = $binding === null
                    ? new DataStatus($assetId, $capability, DataStatus::NOT_BOUND, action: DataStatus::ACTION_BIND,
                        actionUrl: route('operator.asset.sources', ['assetId' => $assetId]))
                    : $this->status($asset, $capability, $binding, $automations->get($binding->external_resource_id),
                        $lastSuccess, $active, $factDates, $today);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, CarbonImmutable>  $lastSuccess
     * @param  array<int, array{total: int, done: int}>  $active
     * @param  array<string, array<string, string>>  $factDates
     */
    private function status(DigitalAsset $asset, string $capability, CoreAssetBinding $binding, ?ResourceAutomation $automation,
        array $lastSuccess, array $active, array $factDates, CarbonImmutable $today): DataStatus
    {
        $assetId = (int) $asset->id;
        $resource = $binding->externalResource;
        $resourceId = $binding->external_resource_id !== null ? (int) $binding->external_resource_id : null;

        $dates = array_filter([
            $resourceId !== null ? ($factDates[$capability]['r'.$resourceId] ?? null) : null,
            $factDates[$capability]['a'.$assetId] ?? null,
        ]);
        $lastData = $dates !== [] ? CarbonImmutable::parse(substr((string) max($dates), 0, 10)) : null;
        $success = collect([$automation?->last_collection_success_at, $resourceId !== null ? ($lastSuccess[$resourceId] ?? null) : null])
            ->filter()->map(fn ($at): CarbonImmutable => CarbonImmutable::parse((string) $at))->sortDesc()->first();
        $run = $resourceId !== null ? ($active[$resourceId] ?? null) : null;
        $collecting = $run !== null || $automation?->collection_status === 'collecting';
        $progress = $run !== null && $run['total'] > 0 ? max(1, min(99, (int) round($run['done'] / $run['total'] * 100))) : null;
        $ageDays = $lastData !== null ? (int) $lastData->diffInDays($today) : null;

        $common = [
            'assetId' => $assetId, 'capability' => $capability, 'bindingId' => (int) $binding->id, 'externalResourceId' => $resourceId,
            'resourceName' => $resource !== null ? (string) ($resource->display_name ?: $resource->external_id) : null,
            'provider' => $resource?->provider !== null ? (string) $resource->provider : null,
            'lastDataDate' => $lastData, 'lastSuccessAt' => $success, 'ageDays' => $ageDays,
            'collecting' => $collecting, 'progressPct' => $progress,
        ];

        $access = $this->accessProblem($resource, $automation);
        if ($access !== null) {
            return new DataStatus(...$common, state: DataStatus::ACCESS_PROBLEM, reason: $access,
                action: DataStatus::ACTION_RECONNECT, actionUrl: $this->reconnectUrl($resource, $access));
        }

        if ($this->activityFor($asset, $capability, $resourceId) === self::ACTIVITY_INACTIVE) {
            return new DataStatus(...$common, state: DataStatus::PAUSED);
        }

        $reason = $this->collectionReason($automation);
        if ($lastData === null) {
            return $success !== null && ! $collecting
                ? new DataStatus(...$common, state: DataStatus::STALE, reason: $reason ?? 'no_rows')
                : new DataStatus(...$common, state: DataStatus::FIRST_LOAD, reason: $reason);
        }

        $allowed = (self::EXPECTED_LAG_DAYS[$capability] ?? 2) + max(1, (int) ($automation?->interval_days ?? 1));
        if ($ageDays > $allowed) {
            return new DataStatus(...$common, state: DataStatus::STALE, reason: $reason);
        }

        return new DataStatus(...$common, state: DataStatus::FRESH);
    }

    /** Why the source cannot be read at all until someone re-authorizes, or null. */
    private function accessProblem(?CoreExternalResource $resource, ?ResourceAutomation $automation): ?string
    {
        $integration = $resource?->integration;
        if ($integration instanceof CoreIntegration) {
            if ($integration->status === CoreIntegration::STATUS_DISABLED) {
                return 'integration_disabled';
            }
            $auth = (string) data_get($integration->config, 'auth_status', '');
            $credential = (string) data_get($integration->config, 'credential_status', '');
            if ($auth === 'revoked' || $credential === 'revoked') {
                return 'revoked';
            }
            if (in_array($auth, self::RECONNECT_AUTH_STATES, true) || in_array($credential, self::RECONNECT_CREDENTIAL_STATES, true)) {
                return 'reconnect';
            }
        }
        if ($resource !== null && $resource->status === CoreExternalResource::STATUS_UNAVAILABLE) {
            return 'resource_unavailable';
        }
        if ($automation !== null && $automation->collection_error === 'reconnect') {
            return 'reconnect';
        }

        return null;
    }

    private function reconnectUrl(?CoreExternalResource $resource, string $reason): ?string
    {
        if ($resource === null) {
            return route('operator.integrations');
        }
        $meta = $resource->provider === 'meta';
        if ($reason === 'resource_unavailable' || $resource->integration_id === null) {
            return route($meta ? 'operator.integrations.meta' : 'operator.integrations.google', ['tab' => 'resources']);
        }

        return route($meta ? 'integrations.meta.authorize' : 'integrations.google.authorize', ['integration' => (int) $resource->integration_id]);
    }

    /** The last known collection problem of the account (shown next to Gecikmiş / İlk veri yükleniyor). */
    private function collectionReason(?ResourceAutomation $automation): ?string
    {
        if ($automation === null) {
            return null;
        }
        if (! $automation->collection_enabled) {
            return 'collection_disabled';
        }
        $error = (string) $automation->collection_error;

        return in_array($error, ['collection_failed', 'request_requires_fix', 'cancelled', 'unbound'], true) ? $error : null;
    }

    /**
     * Newest successful collection per resource, and the running collection (dataset progress) per resource.
     *
     * @param  list<int>  $resourceIds
     * @return array{0: array<int, CarbonImmutable>, 1: array<int, array{total: int, done: int}>}
     */
    private function runs(array $resourceIds): array
    {
        if ($resourceIds === [] || ! self::hasTable('collection_resource_runs')) {
            return [[], []];
        }
        $success = DB::table('collection_resource_runs')
            ->whereIn('external_resource_id', $resourceIds)
            ->whereIn('status', ['completed', 'partial'])
            ->whereNotNull('finished_at')
            ->groupBy('external_resource_id')
            ->selectRaw('external_resource_id, max(finished_at) as finished_at')
            ->pluck('finished_at', 'external_resource_id')
            ->mapWithKeys(fn ($at, $id): array => [(int) $id => CarbonImmutable::parse((string) $at)])
            ->all();
        $active = [];
        DB::table('collection_resource_runs')
            ->whereIn('external_resource_id', $resourceIds)
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->get(['external_resource_id', 'datasets_total', 'datasets_completed', 'datasets_failed'])
            ->each(function (object $row) use (&$active): void {
                $id = (int) $row->external_resource_id;
                $active[$id] ??= ['total' => 0, 'done' => 0];
                $active[$id]['total'] += (int) $row->datasets_total;
                $active[$id]['done'] += (int) $row->datasets_completed + (int) $row->datasets_failed;
            });

        return [$success, $active];
    }

    /**
     * Newest reporting_date per source, keyed "r{resourceId}" (resource-first central facts) and "a{assetId}"
     * (older asset-scoped facts without a resource id). One grouped query per fact table; works on the
     * PostgreSQL compact views because only logical columns are read.
     *
     * @param  Collection<string, CoreAssetBinding>  $bindings
     * @param  list<int>  $assetIds
     * @return array<string, array<string, string>>
     */
    private function factDates(Collection $bindings, array $assetIds): array
    {
        $out = [];
        foreach ($bindings->groupBy('capability') as $capability => $group) {
            $resourceIds = $group->pluck('external_resource_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $groupAssets = array_values(array_intersect($assetIds, $group->pluck('digital_asset_id')->map(fn ($id): int => (int) $id)->all()));
            foreach (self::FACT_TABLES[$capability] ?? [] as $table) {
                if (! self::hasTable($table)) {
                    continue;
                }
                DB::table($table)
                    ->where(function ($scope) use ($resourceIds, $groupAssets): void {
                        $scope->whereIn('external_resource_id', $resourceIds === [] ? [0] : $resourceIds)
                            ->orWhere(fn ($legacy) => $legacy->whereNull('external_resource_id')->whereIn('digital_asset_id', $groupAssets === [] ? [0] : $groupAssets));
                    })
                    ->groupBy('external_resource_id', 'digital_asset_id')
                    ->selectRaw('external_resource_id, digital_asset_id, max(reporting_date) as last_date')
                    ->get()
                    ->each(function (object $row) use (&$out, $capability): void {
                        $key = $row->external_resource_id !== null ? 'r'.(int) $row->external_resource_id : 'a'.(int) $row->digital_asset_id;
                        $date = substr((string) $row->last_date, 0, 10);
                        if ($date !== '' && $date > ($out[$capability][$key] ?? '')) {
                            $out[$capability][$key] = $date;
                        }
                    });
            }
        }

        return $out;
    }

    private static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }
}
