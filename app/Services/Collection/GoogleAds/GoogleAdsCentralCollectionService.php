<?php

namespace App\Services\Collection\GoogleAds;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\ProgressMode;
use App\Events\Collection\CollectionRunStarted;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Collection\CollectionQueueGate;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resource-first Google Ads ingestion.
 *
 * A discovered Google Ads customer can be collected before a DigitalAsset exists.
 * Provider facts stay keyed by CoreExternalResource; a later DigitalAsset binding can
 * reuse the already materialized facts without a second provider backfill.
 */
final class GoogleAdsCentralCollectionService
{
    public const int INITIAL_DAYS = 180;
    public const int RESTATEMENT_DAYS = 30;
    public const int CHANGE_EVENT_SAFE_DAYS = 29;

    private const array ACTIVE_STATUSES = [
        'queued', 'running', 'retrying', 'cancellation_requested',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly CollectionQueueGate $queueGate,
        private readonly StartCollectionService $starter,
    ) {}

    /**
     * @param list<int|string> $externalResourceIds
     */
    public function startSmartUpdate(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        $this->queueGate->assertReady();
        $resources = $this->resolveResources($integration, $externalResourceIds);
        if ($resources->isEmpty()) {
            throw new InvalidArgumentException('Veri çekmek için en az bir kullanılabilir Google Ads müşteri hesabı seçin.');
        }

        $plans = [];
        foreach ($resources as $resource) {
            $plans[(int) $resource->id] = $this->smartPlan($resource);
        }

        $intents = collect($plans)->pluck('intent')->unique()->values();
        $intent = $intents->count() === 1 ? (string) $intents->first() : 'google_ads_central_smart';
        $label = match ($intent) {
            'google_ads_central_initial' => 'Google Ads ilk veri aktarımı',
            'google_ads_central_update' => 'Google Ads veri güncellemesi',
            'google_ads_central_repair' => 'Google Ads eksik veri onarımı',
            'google_ads_central_resume' => 'Google Ads aktarımına devam',
            default => 'Google Ads akıllı veri toplama',
        };

        return $this->startPlan($integration, $resources, $plans, $intent, $label, $requestedBy);
    }

    /** @return Collection<int, CoreExternalResource> */
    private function resolveResources(CoreIntegration $integration, array $ids): Collection
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE || ! $integration->isActive()) {
            throw new InvalidArgumentException('Google entegrasyonu aktif değil.');
        }

        $normalized = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($normalized === []) {
            return collect();
        }

        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', GoogleResourceType::GOOGLE_ADS_CUSTOMER)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereIn('id', $normalized)
            ->get();

        if ($resources->count() !== count($normalized)) {
            throw new InvalidArgumentException('Seçilen Google Ads hesaplarından biri bu Google entegrasyonuna ait değil veya artık kullanılamıyor.');
        }

        foreach ($resources as $resource) {
            $meta = is_array($resource->metadata) ? $resource->metadata : [];
            if ((bool) ($meta['is_manager'] ?? false) || array_key_exists('selectable', $meta) && ! (bool) $meta['selectable']) {
                throw new InvalidArgumentException(($resource->display_name ?: 'Google Ads hesabı').' bir yönetici/MCC hesabıdır; performans toplama kökü olarak seçilemez.');
            }
        }

        return $resources;
    }

    /** @return array{intent:string,families:list<array{family:string,date_range:?array{start:string,end:string}}>} */
    private function smartPlan(CoreExternalResource $resource): array
    {
        $history = CollectionResourceRun::query()
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->where('external_resource_id', $resource->id)
            ->whereNull('digital_asset_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->with('datasetRuns')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $active = $history->first(fn (CollectionResourceRun $run): bool => in_array($run->status->value, self::ACTIVE_STATUSES, true));
        if ($active instanceof CollectionResourceRun) {
            throw new InvalidArgumentException(($resource->display_name ?: 'Google Ads hesabı').' için veri toplama zaten devam ediyor.');
        }

        $latest = $history->first();
        $completed = $history->first(fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed);
        $latestNeedsRepair = $latest instanceof CollectionResourceRun
            && in_array($latest->status, [CollectionRunStatus::Partial, CollectionRunStatus::Failed, CollectionRunStatus::Cancelled], true)
            && (! $completed instanceof CollectionResourceRun || $latest->id > $completed->id);

        if ($latestNeedsRepair) {
            $families = $latest->datasetRuns
                ->filter(fn (CollectionDatasetRun $dataset): bool => ! in_array($dataset->status, [
                    CollectionRunStatus::Completed,
                    CollectionRunStatus::Skipped,
                    CollectionRunStatus::NotEligible,
                ], true))
                ->map(function (CollectionDatasetRun $dataset) use ($resource): array {
                    $family = (string) $dataset->request_family_id;
                    $range = data_get($dataset->metadata, 'date_range');

                    return [
                        'family' => $family,
                        'date_range' => $this->repairDateRange($resource, $family, $range),
                    ];
                })
                ->filter(fn (array $entry): bool => in_array($entry['family'], GoogleAdsCentralRequestFamilyCatalog::supportedFamilies(), true))
                ->values()
                ->all();

            if ($families !== []) {
                return [
                    'intent' => $latest->status === CollectionRunStatus::Cancelled
                        ? 'google_ads_central_resume'
                        : 'google_ads_central_repair',
                    'families' => $families,
                ];
            }
        }

        if (! $completed instanceof CollectionResourceRun) {
            return [
                'intent' => 'google_ads_central_initial',
                'families' => $this->initialFamilies($resource),
            ];
        }

        return [
            'intent' => 'google_ads_central_update',
            'families' => $this->updateFamilies($resource, $completed),
        ];
    }

    /** @return list<array{family:string,date_range:?array{start:string,end:string}}> */
    private function initialFamilies(CoreExternalResource $resource): array
    {
        $timezone = $this->timezone($resource);
        $end = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        $out = [];

        foreach (GoogleAdsCentralRequestFamilyCatalog::supportedFamilies() as $family) {
            $days = GoogleAdsCentralRequestFamilyCatalog::initialDays($family);
            $out[] = [
                'family' => $family,
                'date_range' => $days !== null
                    ? ['start' => $end->subDays($days - 1)->toDateString(), 'end' => $end->toDateString()]
                    : null,
            ];
        }

        return $out;
    }

    /** @return list<array{family:string,date_range:?array{start:string,end:string}}> */
    private function updateFamilies(CoreExternalResource $resource, CollectionResourceRun $completed): array
    {
        $timezone = $this->timezone($resource);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $closedEnd = $today->subDay();
        $out = [];

        foreach (GoogleAdsCentralRequestFamilyCatalog::supportedFamilies() as $family) {
            if (! GoogleAdsCentralRequestFamilyCatalog::isDated($family)) {
                $out[] = ['family' => $family, 'date_range' => null];
                continue;
            }

            $window = GoogleAdsCentralRequestFamilyCatalog::isChangeEvent($family)
                ? self::CHANGE_EVENT_SAFE_DAYS
                : self::RESTATEMENT_DAYS;
            $previous = $completed->datasetRuns->firstWhere('request_family_id', $family);
            $coverageEnd = data_get($previous?->metadata, 'date_range.end');

            try {
                $anchor = is_string($coverageEnd) && $coverageEnd !== ''
                    ? CarbonImmutable::createFromFormat('Y-m-d', $coverageEnd, $timezone)->startOfDay()
                    : $closedEnd;
            } catch (\Throwable) {
                $anchor = $closedEnd;
            }

            if ($anchor->greaterThan($closedEnd)) {
                $anchor = $closedEnd;
            }

            $start = $anchor->subDays($window - 1);
            if (GoogleAdsCentralRequestFamilyCatalog::isChangeEvent($family)) {
                $oldestSafeStart = $today->subDays(self::CHANGE_EVENT_SAFE_DAYS);
                if ($start->lessThan($oldestSafeStart)) {
                    $start = $oldestSafeStart;
                }
            }

            $out[] = [
                'family' => $family,
                'date_range' => ['start' => $start->toDateString(), 'end' => $closedEnd->toDateString()],
            ];
        }

        return $out;
    }

    /** @return array{start:string,end:string}|null */
    private function repairDateRange(CoreExternalResource $resource, string $family, mixed $range): ?array
    {
        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return null;
        }

        $start = (string) $range['start'];
        $end = (string) $range['end'];
        if (! GoogleAdsCentralRequestFamilyCatalog::isChangeEvent($family)) {
            return ['start' => $start, 'end' => $end];
        }

        $timezone = $this->timezone($resource);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $oldestSafeStart = $today->subDays(self::CHANGE_EVENT_SAFE_DAYS);
        $closedEnd = $today->subDay();

        try {
            $parsedStart = CarbonImmutable::createFromFormat('Y-m-d', $start, $timezone)->startOfDay();
            $parsedEnd = CarbonImmutable::createFromFormat('Y-m-d', $end, $timezone)->startOfDay();
        } catch (\Throwable) {
            return ['start' => $oldestSafeStart->toDateString(), 'end' => $closedEnd->toDateString()];
        }

        if ($parsedStart->lessThan($oldestSafeStart)) {
            $parsedStart = $oldestSafeStart;
        }
        if ($parsedEnd->greaterThan($closedEnd)) {
            $parsedEnd = $closedEnd;
        }
        if ($parsedStart->greaterThan($parsedEnd)) {
            $parsedStart = $parsedEnd;
        }

        return ['start' => $parsedStart->toDateString(), 'end' => $parsedEnd->toDateString()];
    }

    /**
     * @param Collection<int, CoreExternalResource> $resources
     * @param array<int, array{intent:string,families:list<array{family:string,date_range:?array{start:string,end:string}}>} > $plans
     */
    private function startPlan(
        CoreIntegration $integration,
        Collection $resources,
        array $plans,
        string $intent,
        string $label,
        ?User $requestedBy,
    ): CollectionRun {
        $registry = $this->registry->load();
        $registryId = $this->registry->registryId();
        $registryVersion = $this->registry->version();
        $registryChecksum = $this->registry->checksum();
        $planFingerprint = hash('sha256', json_encode([
            'integration' => $integration->id,
            'resources' => $resources->pluck('id')->sort()->values()->all(),
            'plans' => $plans,
        ], JSON_THROW_ON_ERROR));

        $run = DB::transaction(function () use (
            $integration,
            $resources,
            $plans,
            $intent,
            $label,
            $requestedBy,
            $registry,
            $registryId,
            $registryVersion,
            $registryChecksum,
            $planFingerprint,
        ): CollectionRun {
            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $requestedBy?->id,
                'customer_id' => null,
                'brand_id' => null,
                'digital_asset_id' => null,
                'trigger_type' => collect($plans)->every(fn (array $plan): bool => $plan['intent'] === 'google_ads_central_initial')
                    ? CollectionTriggerType::InitialBackfill
                    : CollectionTriggerType::Incremental,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $registryId,
                'contract_registry_version' => $registryVersion,
                'contract_registry_checksum' => $registryChecksum,
                'formula_registry_version' => 1,
                'idempotency_key' => hash('sha256', $planFingerprint.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)),
                'started_at' => now(),
                'last_activity_at' => now(),
                'resources_total' => $resources->count(),
                'resources_completed' => 0,
                'datasets_total' => collect($plans)->sum(fn (array $plan): int => count($plan['families'])),
                'datasets_completed' => 0,
                'datasets_failed' => 0,
                'request_context' => [
                    'provider_sources' => ['GOOGLE_ADS'],
                    'context' => [
                        'collection_scope' => 'provider_resource_first',
                        'google_integration_id' => (int) $integration->id,
                        'asset_binding_required' => false,
                    ],
                ],
                'plan_snapshot' => [
                    'provider_or_source' => 'GOOGLE_ADS',
                    'resources' => $resources->pluck('id')->values()->all(),
                    'plans' => $plans,
                    'runtime_registry_amendments' => data_get($registry, 'metadata.runtime_amendments', []),
                ],
                'metadata' => [
                    'collection_scope' => 'provider_resource_first',
                    'collection_intent' => $intent,
                    'collection_intent_label' => $label,
                    'google_integration_id' => (int) $integration->id,
                    'plan_fingerprint' => $planFingerprint,
                    'initial_history_days' => self::INITIAL_DAYS,
                    'restatement_days' => self::RESTATEMENT_DAYS,
                    'change_event_safe_days' => self::CHANGE_EVENT_SAFE_DAYS,
                ],
            ]);

            foreach ($resources as $resource) {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];
                $resourcePlan = $plans[(int) $resource->id];
                $resourceRun = CollectionResourceRun::query()->create([
                    'collection_run_id' => $run->id,
                    'provider_or_source' => 'GOOGLE_ADS',
                    'resource_kind' => 'provider_resource_first_google_ads_customer',
                    'external_resource_id' => $resource->id,
                    'digital_asset_id' => null,
                    'core_asset_binding_id' => null,
                    'status' => CollectionRunStatus::Queued,
                    'last_activity_at' => now(),
                    'datasets_total' => count($resourcePlan['families']),
                    'datasets_completed' => 0,
                    'datasets_failed' => 0,
                    'metadata' => [
                        'collection_scope' => 'provider_resource_first',
                        'collection_intent' => $resourcePlan['intent'],
                        'customer_id' => preg_replace('/\D+/', '', (string) $resource->external_id),
                        'account_name' => $resource->display_name,
                        'currency_code' => $meta['currency_code'] ?? null,
                        'time_zone' => $meta['time_zone'] ?? $meta['timezone'] ?? null,
                        'login_customer_id' => $meta['login_customer_id'] ?? $meta['manager_customer_id'] ?? null,
                    ],
                ]);

                foreach ($resourcePlan['families'] as $entry) {
                    $family = $entry['family'];
                    $definition = GoogleAdsCentralRequestFamilyCatalog::definition($family);
                    CollectionDatasetRun::query()->create([
                        'collection_run_id' => $run->id,
                        'collection_resource_run_id' => $resourceRun->id,
                        'provider_or_source' => 'GOOGLE_ADS',
                        'dataset_contract_id' => (string) $definition['dataset_id'],
                        'request_family_id' => $family,
                        'execution_variant' => '',
                        'requirement_level' => (string) $definition['requirement_level'],
                        'contract_registry_version' => $registryVersion,
                        'status' => CollectionRunStatus::Queued,
                        'attempt_count' => 0,
                        'max_attempts' => (int) config('moxdop-collection.default_max_attempts', 3),
                        'last_activity_at' => now(),
                        'progress_mode' => ProgressMode::Counted,
                        'progress_current' => 0,
                        'progress_total' => null,
                        'rows_received' => 0,
                        'rows_written' => 0,
                        'chunks_completed' => 0,
                        'chunks_failed' => 0,
                        'pages_completed' => 0,
                        'checkpoint' => [],
                        'depends_on_dataset_run_ids' => [],
                        'metadata' => [
                            'collection_scope' => 'provider_resource_first',
                            'date_range' => $entry['date_range'],
                            'source_family_id' => $definition['source_family_id'],
                            'source_layer' => $definition['layer'],
                            'dataset_label' => $definition['label'],
                            'central_definition' => $definition,
                        ],
                    ]);
                }
            }

            return $run->fresh(['resourceRuns.datasetRuns', 'datasetRuns']) ?? $run;
        });

        CollectionRunStarted::dispatch($run);
        $this->starter->dispatchEligibleRootJobs($run);

        return $run->fresh(['resourceRuns.datasetRuns', 'datasetRuns']) ?? $run;
    }

    private function timezone(CoreExternalResource $resource): string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = $meta['time_zone'] ?? $meta['timezone'] ?? 'UTC';

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
