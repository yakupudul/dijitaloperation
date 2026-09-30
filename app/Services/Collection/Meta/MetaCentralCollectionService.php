<?php

namespace App\Services\Collection\Meta;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Events\Collection\CollectionRunStarted;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Collection\CollectionDatasetCatalog;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Time\SafeTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * MoxDOP v2 (Faz 1): collects a discovered Meta ad account from the provider resource itself — bound or not — like
 * the Google Ads / GA4 / Search Console central collectors. Rows carry no Digital Asset; screens read them through
 * the account's binding (MetaScreen::q). The Meta usage governor (rate-limit cooldown) guards every call.
 *
 * Dated families: first run = the family's history window; later runs = from the family's coverage end (at most
 * the last 7 days, Meta restates late attribution) through yesterday in the account's time zone.
 */
final class MetaCentralCollectionService
{
    /** Meta restates the most recent days (late attribution). */
    public const int RESTATEMENT_DAYS = 7;

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly StartCollectionService $starter,
    ) {}

    /** @param list<int|string> $externalResourceIds */
    public function startSmartUpdate(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        return app(ResourceAutomationService::class)->withResourceLocks(
            $externalResourceIds, fn (): CollectionRun => $this->start($integration, $externalResourceIds, $requestedBy)
        );
    }

    /**
     * Families collected for an account: dataset id => [family id, history days (0 = current snapshot)].
     *
     * @return array<string, array{family: string, days: int}>
     */
    public static function plannedDatasets(): array
    {
        $out = [];
        foreach ([MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META, MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT] as $family) {
            foreach (MetaAdsRequestFamilyCatalog::definition($family)['dataset_ids'] as $dataset) {
                $out[$dataset] = ['family' => $family, 'days' => 0];
            }
        }
        foreach ((array) config('moxdop-meta-ads-central.families', []) as $family => $definition) {
            $history = (string) ($definition['history'] ?? 'current');
            $out[(string) $definition['dataset']] = ['family' => (string) $family, 'days' => $history === 'current' ? 0 : (int) $history];
        }

        return array_filter($out, static fn (array $row, string $dataset): bool => CollectionDatasetCatalog::keeps('META_ADS', $dataset), ARRAY_FILTER_USE_BOTH);
    }

    /** @param list<int|string> $externalResourceIds */
    private function start(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy): CollectionRun
    {
        if ($integration->provider !== ProviderRegistry::META || ! $integration->isActive()) {
            throw new InvalidArgumentException('Meta integration is not active.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $externalResourceIds))));
        $resources = CoreExternalResource::query()->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)->whereIn('id', $ids ?: [0])->orderBy('id')->get();
        if ($ids === [] || $resources->count() !== count($ids)) {
            throw new InvalidArgumentException('One or more Meta ad accounts are unavailable or outside this integration.');
        }

        $plans = $resources->map(fn (CoreExternalResource $resource): array => $this->plan($resource))->all();
        $allInitial = collect($plans)->every(fn (array $plan): bool => $plan['mode'] === 'initial');
        $this->registry->load();
        $version = $this->registry->version();

        $run = DB::transaction(function () use ($integration, $plans, $requestedBy, $version, $allInitial): CollectionRun {
            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $requestedBy?->id,
                'trigger_type' => $allInitial ? CollectionTriggerType::InitialBackfill : CollectionTriggerType::Incremental,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $this->registry->registryId(),
                'contract_registry_version' => $version,
                'contract_registry_checksum' => $this->registry->checksum(),
                'idempotency_key' => 'meta-central:'.Str::uuid(),
                'last_activity_at' => now(),
                'resources_total' => count($plans),
                'datasets_total' => collect($plans)->sum(fn (array $plan): int => count($plan['datasets'])),
                'request_context' => [
                    'force_refresh' => ! $allInitial,
                    'date_range' => null,
                    'provider_sources' => ['META_ADS'],
                    'context' => ['collection_scope' => 'provider_resource_first', 'meta_integration_id' => $integration->id,
                        'collection_intent' => 'meta_central_smart', 'asset_binding_required' => false],
                ],
                'plan_snapshot' => ['resources' => [], 'datasets' => [], 'dispositions' => [],
                    'contract_registry_version' => $version, 'planner_version' => 'meta-central-resource-first-v1'],
                'metadata' => ['collection_intent' => 'meta_central_smart', 'collection_intent_label' => 'Meta Central Smart Update',
                    'collection_scope' => 'provider_resource_first'],
            ]);

            $snapshot = [];
            foreach ($plans as $plan) {
                /** @var CoreExternalResource $resource */
                $resource = $plan['resource'];
                $resourceRun = CollectionResourceRun::query()->create([
                    'collection_run_id' => $run->id, 'provider_or_source' => 'META_ADS', 'resource_kind' => 'provider_resource',
                    'external_resource_id' => $resource->id, 'digital_asset_id' => null, 'core_asset_binding_id' => null,
                    'status' => CollectionRunStatus::Queued, 'last_activity_at' => now(), 'datasets_total' => count($plan['datasets']),
                    'metadata' => ['capability' => 'meta_ads', 'collection_scope' => 'provider_resource_first',
                        'collection_mode' => $plan['mode'], 'account_timezone' => $plan['timezone']],
                ]);
                foreach ($plan['datasets'] as $dataset => $row) {
                    CollectionDatasetRun::query()->create([
                        'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'checkpoint' => [],
                        'provider_or_source' => 'META_ADS', 'dataset_contract_id' => $dataset, 'request_family_id' => $row['family'],
                        'requirement_level' => RequirementLevel::Required, 'contract_registry_version' => $version,
                        'status' => CollectionRunStatus::Queued, 'max_attempts' => (int) config('moxdop-collection.default_max_attempts', 3),
                        'progress_mode' => ProgressMode::Indeterminate, 'last_activity_at' => now(),
                        'metadata' => ['date_range' => $row['range'], 'collection_scope' => 'provider_resource_first',
                            'collection_mode' => $plan['mode'], 'account_timezone' => $plan['timezone']],
                    ]);
                    $snapshot[] = ['provider_or_source' => 'META_ADS', 'dataset_contract_id' => $dataset, 'request_family_id' => $row['family'],
                        'external_resource_id' => $resource->id, 'digital_asset_id' => null, 'date_range' => $row['range']];
                }
            }
            $run->forceFill(['plan_snapshot' => array_merge($run->plan_snapshot ?? [], ['datasets' => $snapshot])])->save();

            return $run;
        });

        CollectionRunStarted::dispatch($run);
        $this->starter->dispatchEligibleRootJobs($run);

        return $run->fresh() ?? $run;
    }

    /** @return array{resource: CoreExternalResource, mode: string, timezone: string, datasets: array<string, array{family: string, range: ?array{start: string, end: string}}>} */
    private function plan(CoreExternalResource $resource): array
    {
        $timezone = SafeTimezone::normalize((string) (data_get($resource->metadata, 'timezone_name') ?: 'UTC'), 'UTC');
        $end = CarbonImmutable::now($timezone)->subDay()->startOfDay();
        $automation = app(ResourceAutomationService::class);
        $datasets = [];
        $initial = true;
        foreach (self::plannedDatasets() as $dataset => $row) {
            $range = null;
            if ($row['days'] > 0) {
                $covered = $automation->coverageEnd((int) $resource->id, 'META_ADS', $row['family'], $dataset);
                $initial = $initial && $covered === null;
                $start = $covered === null
                    ? $end->subDays($row['days'] - 1)
                    : CarbonImmutable::parse(min($end->subDays(self::RESTATEMENT_DAYS - 1)->toDateString(), CarbonImmutable::parse($covered)->addDay()->toDateString()), $timezone);
                $range = ['start' => $start->toDateString(), 'end' => $end->toDateString()];
            }
            $datasets[$dataset] = ['family' => $row['family'], 'range' => $range];
        }

        return ['resource' => $resource, 'mode' => $initial ? 'initial' : 'update', 'timezone' => $timezone, 'datasets' => $datasets];
    }
}
