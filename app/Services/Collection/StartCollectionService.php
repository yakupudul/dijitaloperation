<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Events\Collection\CollectionRunStarted;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\Support\StartCollectionRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StartCollectionService
{
    public function __construct(
        private readonly CollectionPlanner $planner,
        private readonly CollectionStatusAggregator $aggregator,
        private readonly CollectionQueueGate $queueGate,
    ) {}

    public function start(StartCollectionRequest $request): CollectionRun
    {
        $this->queueGate->assertReady();

        if ($request->idempotencyKey !== null) {
            $existing = CollectionRun::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $plan = $this->planner->plan($request);

        $run = DB::transaction(function () use ($request, $plan): CollectionRun {
            $asset = $request->digitalAsset->loadMissing('brand');

            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $request->requestedBy?->id,
                'customer_id' => $asset->brand?->customer_id,
                'brand_id' => $asset->brand_id,
                'digital_asset_id' => $asset->id,
                'trigger_type' => $request->triggerType,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $plan['contract_registry_id'],
                'contract_registry_version' => $plan['contract_registry_version'],
                'contract_registry_checksum' => $plan['contract_registry_checksum'],
                'idempotency_key' => $request->idempotencyKey,
                'last_activity_at' => now(),
                'resources_total' => count($plan['resources']),
                'datasets_total' => count($plan['datasets']),
                'request_context' => [
                    'force_refresh' => $request->forceRefresh,
                    'date_range' => $request->dateRange,
                    'request_family_ids' => $request->requestFamilyIds,
                    'provider_sources' => $request->providerSources,
                    'context' => $request->context,
                ],
                'plan_snapshot' => [
                    'resources' => $plan['resources'],
                    'datasets' => array_map(static fn (array $d): array => [
                        'provider_or_source' => $d['provider_or_source'],
                        'dataset_contract_id' => $d['dataset_contract_id'],
                        'request_family_id' => $d['request_family_id'],
                        'requirement_ids' => $d['requirement_ids'] ?? [],
                        'requirement_level' => $d['requirement_level'],
                        'planned_status' => $d['planned_status'],
                        'plan_disposition' => $d['plan_disposition'] ?? null,
                        'date_range' => $d['date_range'] ?? null,
                        'coverage_target' => $d['coverage_target'] ?? null,
                        'core_asset_binding_id' => $d['core_asset_binding_id'] ?? null,
                        'digital_asset_id' => $d['digital_asset_id'] ?? null,
                        'external_resource_id' => $d['external_resource_id'] ?? null,
                    ], $plan['datasets']),
                    'dispositions' => $plan['dispositions'],
                    'contract_registry_version' => $plan['contract_registry_version'],
                    'planner_version' => 1,
                ],
                'metadata' => [
                    'plan_fingerprint' => $request->context['plan_fingerprint'] ?? null,
                    'collection_intent' => $request->context['collection_intent'] ?? null,
                    'collection_intent_label' => $request->context['collection_intent_label'] ?? null,
                ],
            ]);

            $resourceMap = [];
            foreach ($plan['resources'] as $resource) {
                $resourceRun = CollectionResourceRun::query()->create([
                    'collection_run_id' => $run->id,
                    'provider_or_source' => $resource['provider_or_source'],
                    'resource_kind' => $resource['resource_kind'],
                    'external_resource_id' => $resource['external_resource_id'],
                    'digital_asset_id' => $resource['digital_asset_id'],
                    'core_asset_binding_id' => $resource['core_asset_binding_id'],
                    'status' => CollectionRunStatus::Queued,
                    'last_activity_at' => now(),
                    'metadata' => ['capability' => $resource['capability'] ?? null],
                ]);
                $resourceMap[$resource['key']] = $resourceRun;
            }

            $datasetByFamily = [];
            foreach ($plan['datasets'] as $dataset) {
                $resourceRun = $resourceMap[$dataset['resource_key']];
                $planned = CollectionRunStatus::from($dataset['planned_status']);
                $datasetRun = CollectionDatasetRun::query()->create([
                    'collection_run_id' => $run->id,
                    'collection_resource_run_id' => $resourceRun->id,
                    'provider_or_source' => $dataset['provider_or_source'],
                    'dataset_contract_id' => $dataset['dataset_contract_id'],
                    'request_family_id' => $dataset['request_family_id'],
                    'requirement_level' => RequirementLevel::from($dataset['requirement_level']),
                    'contract_registry_version' => $plan['contract_registry_version'],
                    'status' => $planned,
                    'max_attempts' => (int) config('moxdop-collection.default_max_attempts', 3),
                    'progress_mode' => ProgressMode::Indeterminate,
                    'last_activity_at' => now(),
                    'finished_at' => $planned->isTerminal() ? now() : null,
                    'metadata' => [
                        'depends_on_request_family_ids' => $dataset['depends_on_request_family_ids'] ?? [],
                        'requirement_ids' => $dataset['requirement_ids'] ?? [],
                        'plan_disposition' => $dataset['plan_disposition'] ?? null,
                        'date_range' => $dataset['date_range'] ?? null,
                        'coverage_target' => $dataset['coverage_target'] ?? null,
                    ],
                ]);
                $datasetByFamily[$resourceRun->id.':'.$dataset['request_family_id']] = $datasetRun;
                $resourceRun->increment('datasets_total');
            }

            foreach ($datasetByFamily as $datasetRun) {
                $depFamilies = $datasetRun->metadata['depends_on_request_family_ids'] ?? [];
                $depIds = [];
                foreach ($depFamilies as $familyId) {
                    $key = $datasetRun->collection_resource_run_id.':'.$familyId;
                    if (isset($datasetByFamily[$key])) {
                        $depIds[] = $datasetByFamily[$key]->id;
                    }
                }
                if ($depIds !== []) {
                    $datasetRun->forceFill(['depends_on_dataset_run_ids' => $depIds])->save();
                }
            }

            foreach ($resourceMap as $resourceRun) {
                $resourceRun->refresh();
            }

            return $run->fresh(['resourceRuns', 'datasetRuns']) ?? $run;
        });

        CollectionRunStarted::dispatch($run);

        $this->dispatchEligibleRootJobs($run);

        Log::info('collection.run.started', [
            'collection_run_id' => $run->id,
            'collection_run_uuid' => $run->uuid,
            'datasets_total' => $run->datasets_total,
        ]);

        return $run->fresh() ?? $run;
    }

    public function dispatchEligibleRootJobs(CollectionRun $run): void
    {
        $run->loadMissing('datasetRuns');

        foreach ($run->datasetRuns as $datasetRun) {
            if ($datasetRun->status !== CollectionRunStatus::Queued) {
                continue;
            }
            if (! $this->dependenciesSatisfied($datasetRun)) {
                continue;
            }
            $this->dispatchDatasetJob($datasetRun);
        }
    }

    public function dispatchDatasetJob(CollectionDatasetRun $datasetRun): void
    {
        ExecuteDatasetRunJob::dispatch($datasetRun->id)
            ->onConnection((string) config('moxdop-collection.queue_connection', 'redis'))
            ->onQueue((string) config('moxdop-collection.queue', 'collection'))
            ->afterCommit();
    }

    public function dependenciesSatisfied(CollectionDatasetRun $datasetRun): bool
    {
        $deps = $datasetRun->depends_on_dataset_run_ids ?? [];
        if ($deps === []) {
            return true;
        }

        $parents = CollectionDatasetRun::query()->whereIn('id', $deps)->get();
        foreach ($parents as $parent) {
            if ($parent->status !== CollectionRunStatus::Completed) {
                return false;
            }
        }

        return $parents->count() === count($deps);
    }
}
