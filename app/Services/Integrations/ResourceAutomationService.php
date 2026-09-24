<?php

namespace App\Services\Integrations;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Models\User;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Meta\MetaSingleBindingCollectionOrchestrator;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Services\CollectionScheduler\ExecuteCollectionLifecycleService;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\SearchDemand\AutomaticQueryImportService;
use App\Services\SearchDemand\LibraryImportWorkflow;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ResourceAutomationService
{
    public const TYPES = ['google_ads', 'search_console', 'ga4', 'meta_ads', 'google_business_profile'];

    public const ACTIVE = ['queued', 'running', 'retrying', 'cancellation_requested'];

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
    }

    public function discover(): void
    {
        CoreExternalResource::query()->whereIn('resource_type', self::TYPES)
            ->whereIn('provider', ['google', 'meta'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('resource_automations')
                ->whereColumn('external_resource_id', 'core_external_resources.id'))
            ->orderBy('id')->limit(200)->get()->each(function ($r): void {
                DB::table('resource_automations')->insertOrIgnore([
                    'external_resource_id' => $r->id,
                    'next_collection_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
    }

    public function save(int $id, array $input, int $revision, User $actor): void
    {
        $this->authorize($actor);
        validator($input, [
            'collection_enabled' => ['required', 'boolean'], 'interval_days' => ['required', 'in:1,3'],
            'preferred_hour' => ['nullable', 'integer', 'between:0,23'],
            'query_enabled' => ['required', 'boolean'], 'sector' => ['nullable', 'string', 'max:255'],
            'service_ids' => ['array', 'max:200'], 'service_ids.*' => ['integer'],
        ])->validate();
        DB::transaction(function () use ($id, $input, $revision, $actor): void {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($id);
            if ($a->revision !== $revision) {
                throw ValidationException::withMessages(['automation' => __('resource-auto.conflict')]);
            }
            $source = $a->resource->resource_type;
            if ($input['query_enabled'] && ! in_array($source, ['google_ads', 'search_console'], true)) {
                abort(422);
            }
            $ids = [];
            if (filled($input['sector'] ?? null) || $input['query_enabled']) {
                $ids = app(LibraryImportWorkflow::class)->validateScope($input);
            }
            $mappingChanged = $a->sector !== ($input['sector'] ?: null) || array_map('intval', $a->service_ids ?? []) !== $ids;
            $a->fill([
                'mapping_revision' => (int) $a->mapping_revision + ($mappingChanged ? 1 : 0),
                'collection_enabled' => $input['collection_enabled'], 'interval_days' => (int) $input['interval_days'],
                'preferred_hour' => isset($input['preferred_hour']) && $input['preferred_hour'] !== '' ? (int) $input['preferred_hour'] : null,
                'query_enabled' => $input['query_enabled'], 'sector' => $input['sector'] ?: null,
                'service_ids' => $ids, 'revision' => $a->revision + 1, 'updated_by' => $actor->id,
                'query_error' => null, 'collection_error' => null, 'collection_failures' => 0,
                'next_collection_at' => $a->next_collection_at?->isFuture() ? $a->next_collection_at : now(),
            ])->save();
        });
    }

    public function runNow(int $id, User $actor): void
    {
        $this->authorize($actor);
        ResourceAutomation::query()->findOrFail($id)->update([
            'collection_enabled' => true, 'next_collection_at' => now(),
            'collection_error' => null, 'collection_failures' => 0, 'updated_by' => $actor->id,
        ]);
    }

    public function tick(): void
    {
        if (! config('moxdop-resource-automation.enabled', true)) {
            return;
        }
        $connection = (string) config('moxdop-resource-automation.queue_connection', config('queue.default'));
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            throw new \RuntimeException('Resource automation requires a durable queue connection with a running worker.');
        }
        $lock = Cache::lock('resource-automation-tick', 55);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->discover();
            ResourceAutomation::query()->where('collection_enabled', true)
                ->where('collection_status', 'attention')->where('collection_error', 'binding')
                ->where(function ($q): void {
                    $q->whereHas('resource', fn ($r) => $r->where('resource_type', 'google_business_profile'))
                        ->orWhereHas('resource.bindings', fn ($b) => $b->where('status', 'active')->where('capability', 'meta_ads'));
                })
                ->orderBy('id')->limit(100)->get()->each(function ($automation): void {
                    if ($this->readiness($automation->resource) === null) {
                        $automation->update(['collection_status' => 'waiting', 'collection_error' => null, 'next_collection_at' => now()]);
                    }
                });
            ResourceAutomation::query()->where('collection_enabled', true)->where('collection_status', 'waiting')
                ->whereNull('collection_run_id')->whereNull('gbp_run_id')->whereNull('last_collection_success_at')->whereNull('collection_error')
                ->where('next_collection_at', '>', now())->update(['next_collection_at' => now()]);
            ResourceAutomation::query()->whereNotNull('collection_run_id')->whereIn('collection_status', ['collecting', 'planning'])
                ->limit(100)->get()->each(fn ($a) => $this->reconcile($a));
            ResourceAutomation::query()->where('collection_status', 'planning')
                ->where('collection_queued_at', '<', now()->subMinutes(15))
                ->update(['collection_status' => 'waiting', 'collection_queued_at' => null]);

            // Admission is per provider type; a long GSC history must not block GA4 or Meta.
            // Existing workers still bound actual HTTP concurrency.
            foreach (self::TYPES as $resourceType) {
                $this->admitCollections($resourceType, $connection);
            }
            app(AutomaticQueryImportService::class)->dispatchDue();
        } finally {
            $lock->release();
        }
    }

    /**
     * Explicit deployment repair for accounts stopped by the now-fixed empty-dimension rejection
     * ("missing natural key [landingPage | country | region | city | itemCategory | …]"): the writer now stores an
     * empty provider dimension as "(empty)", so these accounts can collect again.
     */
    public function recoverGa4LandingFailures(): int
    {
        $recovered = 0;
        ResourceAutomation::query()->where('collection_enabled', true)
            ->whereIn('collection_status', ['attention', 'waiting'])->whereIn('collection_error', ['collection_failed', 'request_requires_fix'])
            ->orderBy('id')->chunkById(100, function ($accounts) use (&$recovered): void {
                foreach ($accounts as $automation) {
                    $knownFailure = CollectionDatasetRun::query()
                        ->where('collection_run_id', $automation->collection_run_id)->where('status', 'failed')
                        ->where('error_code', 'PERSISTENCE')
                        ->where('error_message', 'like', '%missing natural key [%')
                        ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $automation->external_resource_id))
                        ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', ['failed', 'partial']))->exists();
                    if (! $knownFailure) {
                        continue;
                    }
                    $recovered += ResourceAutomation::query()->whereKey($automation->id)
                        ->where('collection_enabled', true)->where('collection_run_id', $automation->collection_run_id)
                        ->whereIn('collection_status', ['attention', 'waiting'])->whereIn('collection_error', ['collection_failed', 'request_requires_fix'])
                        ->update(['collection_status' => 'waiting', 'collection_error' => null,
                            'collection_failures' => 0, 'next_collection_at' => now()]);
                }
            });

        return $recovered;
    }

    /** Re-admit only the known Search Appearance aggregation error after its request fix. */
    public function recoverGscAppearanceFailures(): int
    {
        $recovered = 0;
        ResourceAutomation::query()->where('collection_enabled', true)
            ->whereIn('collection_status', ['attention', 'waiting', 'collecting'])
            ->whereHas('resource', fn ($q) => $q->where('resource_type', 'search_console'))
            ->orderBy('id')->chunkById(100, function ($accounts) use (&$recovered): void {
                foreach ($accounts as $automation) {
                    $knownFailure = CollectionDatasetRun::query()
                        ->where('collection_run_id', $automation->collection_run_id)->where('status', 'failed')
                        ->where('error_code', 'INVALID_REQUEST')->where('error_message', 'like', '%BY_PROPERTY%')
                        ->where('dataset_contract_id', 'gsc_search_appearance_daily')
                        ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $automation->external_resource_id))
                        ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', ['failed', 'partial']))->exists();
                    if (! $knownFailure) {
                        continue;
                    }
                    $recovered += ResourceAutomation::query()->whereKey($automation->id)
                        ->where('collection_enabled', true)->where('collection_run_id', $automation->collection_run_id)
                        ->whereIn('collection_status', ['attention', 'waiting', 'collecting'])
                        ->update(['collection_status' => 'waiting', 'collection_error' => null,
                            'collection_failures' => 0, 'next_collection_at' => now()]);
                }
            });

        return $recovered;
    }

    private function admitCollections(string $resourceType, string $connection): void
    {
        $scope = fn ($q) => $q->where('resource_type', $resourceType);
        $activeIds = CollectionResourceRun::query()->whereIn('status', self::ACTIVE)
            ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', self::ACTIVE))
            ->whereHas('externalResource', $scope)->pluck('external_resource_id');
        $planningIds = ResourceAutomation::query()->where('collection_status', 'planning')
            ->whereHas('resource', $scope)->pluck('external_resource_id');
        $occupied = $activeIds->merge($planningIds)->unique()->count();
        $slots = max(0, (int) config('moxdop-resource-automation.max_active_collections', 2) - $occupied);
        if ($slots === 0) {
            return;
        }
        $accounts = ResourceAutomation::query()->with('resource.integration')
            ->whereHas('resource', $scope)
            ->where('collection_enabled', true)->whereNotIn('collection_status', ['planning', 'collecting'])
            ->whereNotNull('next_collection_at')->where('next_collection_at', '<=', now())
            ->orderByRaw('CASE WHEN last_collection_success_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('next_collection_at')->orderBy('id')
            ->limit(max(0, (int) config('moxdop-resource-automation.accounts_per_tick', 10)))->get();
        foreach ($accounts as $automation) {
            if ($slots <= 0) {
                break;
            }
            $error = $this->readiness($automation->resource) ?? $this->portfolioGate($automation);
            if ($error !== null) {
                $automation->update(['collection_status' => 'attention', 'collection_error' => $error,
                    'next_collection_at' => $this->nextAt($automation)]);

                continue;
            }
            $automation->update(['collection_status' => 'planning', 'collection_queued_at' => now(), 'collection_run_id' => null]);
            try {
                ResourceCollectionJob::dispatch($automation->id)->onConnection($connection);
                $slots--;
            } catch (\Throwable $error) {
                $this->fail($automation->id);
                report($error);
            }
        }
    }

    public function readiness(CoreExternalResource $resource): ?string
    {
        if ($resource->status !== 'available' || ! $resource->integration?->isActive()) {
            return 'reconnect';
        }
        if ($resource->resource_type === 'google_ads' && ((bool) data_get($resource->metadata, 'is_manager', false)
            || ! (bool) data_get($resource->metadata, 'selectable', true))) {
            return 'manager';
        }
        if ($resource->resource_type === 'meta_ads'
            && ! $resource->bindings()->where('status', 'active')->where('capability', $resource->resource_type)->exists()) {
            return 'binding';
        }

        return null;
    }

    /**
     * Lean-data gate: a resource bound only to passive assets (inactive asset or customer) is not
     * collected, and an unbound resource is collected only when it feeds the query library (sector set).
     */
    public function portfolioGate(ResourceAutomation $automation): ?string
    {
        $assetIds = $automation->resource?->bindings()->where('status', 'active')->pluck('digital_asset_id') ?? collect();
        if ($assetIds->isEmpty()) {
            return filled($automation->sector) ? null : 'unbound';
        }

        return DigitalAsset::query()->operational()->whereIn('digital_assets.id', $assetIds)->exists() ? null : 'customer_passive';
    }

    /** A customer turned active again: its accounts paused by the portfolio gate are due immediately. */
    public function resumeForCustomer(int $customerId): int
    {
        $assetIds = DigitalAsset::query()->whereHas('brand', fn ($q) => $q->where('customer_id', $customerId))->pluck('id');

        return ResourceAutomation::query()
            ->where('collection_error', 'customer_passive')
            ->whereHas('resource.bindings', fn ($q) => $q->where('status', 'active')->whereIn('digital_asset_id', $assetIds))
            ->update(['collection_status' => 'waiting', 'collection_error' => null, 'next_collection_at' => now()]);
    }

    /**
     * The integration was authorized again: accounts stopped for "reconnect" are due immediately.
     * Only automations that are still enabled are resumed.
     */
    public function resumeAfterReconnect(int $integrationId): int
    {
        return ResourceAutomation::query()
            ->where('collection_enabled', true)
            ->where('collection_error', 'reconnect')
            ->whereHas('resource', fn ($q) => $q->where('integration_id', $integrationId))
            ->update(['collection_status' => 'waiting', 'collection_error' => null, 'collection_failures' => 0, 'next_collection_at' => now()]);
    }

    /**
     * Daily second chance for stopped collections: repeated provider failures, and "reconnect" stops whose
     * integration and resource are usable again (e.g. a token refreshed elsewhere). Contract errors,
     * cancellations and portfolio gates are left alone; they need a code fix or an operator decision.
     *
     * @return array{retried: int, reconnected: int}
     */
    public function retryStopped(): array
    {
        $cutoff = now()->subHours((int) config('moxdop-collection.stopped_retry_after_hours', 20));
        $retried = ResourceAutomation::query()
            ->where('collection_enabled', true)->where('collection_status', 'attention')
            ->where('collection_error', 'collection_failed')->where('updated_at', '<=', $cutoff)
            ->update(['collection_status' => 'waiting', 'collection_error' => null, 'collection_failures' => 0, 'next_collection_at' => now()]);
        $reconnected = 0;
        ResourceAutomation::query()->with('resource.integration')
            ->where('collection_enabled', true)->where('collection_status', 'attention')->where('collection_error', 'reconnect')
            ->get()
            ->each(function (ResourceAutomation $automation) use (&$reconnected): void {
                if ($automation->resource !== null && $this->readiness($automation->resource) === null) {
                    $automation->update(['collection_status' => 'waiting', 'collection_error' => null, 'collection_failures' => 0, 'next_collection_at' => now()]);
                    $reconnected++;
                }
            });

        return ['retried' => $retried, 'reconnected' => $reconnected];
    }

    public function collect(int $id): void
    {
        $a = ResourceAutomation::query()->with('resource.integration')->findOrFail($id);
        if (! $a->collection_enabled || $a->collection_status !== 'planning') {
            if ($a->collection_status === 'planning') {
                $a->update(['collection_status' => 'waiting', 'collection_queued_at' => null]);
            }

            return;
        }
        if ($error = $this->readiness($a->resource) ?? $this->portfolioGate($a)) {
            $a->update(['collection_status' => 'attention', 'collection_error' => $error, 'collection_queued_at' => null,
                'next_collection_at' => $this->nextAt($a)]);

            return;
        }
        $r = $a->resource;
        if ($r->resource_type === 'google_business_profile') {
            $this->collectGbp($a);

            return;
        }
        $active = CollectionResourceRun::query()->where('external_resource_id', $r->id)->whereIn('status', self::ACTIVE)
            ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', self::ACTIVE))->latest('id')->first();
        if ($active) {
            $a->update(['collection_run_id' => $active->collection_run_id, 'collection_status' => 'collecting', 'collection_queued_at' => null]);

            return;
        }
        $actor = $a->updated_by ? User::query()->find($a->updated_by) : null;
        if ($actor && (! $actor->is_active || ! $actor->can(Permissions::ACCESS_APP))) {
            $actor = null;
        }
        $run = match ($r->resource_type) {
            'google_ads' => app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            'search_console' => app(SearchConsoleCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            'ga4' => app(Ga4CentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            default => $this->collectBound($r, $actor),
        };
        $a->update([
            'collection_run_id' => $run?->id, 'collection_status' => $run ? 'collecting' : 'current',
            'collection_queued_at' => null, 'collection_error' => null,
            'next_collection_at' => $this->nextAt($a),
        ]);
        if ($run) {
            $run->update(['metadata' => array_merge($run->metadata ?? [], ['automatic_collection' => true, 'resource_automation_id' => $a->id])]);
        }
    }

    private function collectGbp(ResourceAutomation $automation): void
    {
        $this->withResourceLocks([$automation->external_resource_id], function () use ($automation): void {
            $run = $automation->gbp_run_id ? Run::query()->find($automation->gbp_run_id) : null;
            if (! $run || $run->status !== 'running') {
                $bindings = $automation->resource->bindings()->where('status', 'active')
                    ->where('capability', 'google_business_profile')->get();
                if ($bindings->count() > 1) {
                    throw new \RuntimeException('Multiple active GBP bindings require review.');
                }
                $binding = $bindings->first();
                $assetId = $binding ? app(BoundCollectionGuard::class)
                    ->assertCollectable($binding, 'google_business_profile')['asset']->id : null;
                $run = Run::query()->create([
                    'module_id' => 'google-business-profile', 'status' => 'running', 'started_at' => now(),
                    'digital_asset_id' => $assetId, 'core_asset_binding_id' => $binding?->id,
                    'metadata' => ['provider' => 'google', 'capability' => 'google_business_profile',
                        'external_resource_id' => $automation->external_resource_id,
                        'integration_id' => $automation->resource->integration_id,
                        'collection_scope' => 'provider_resource_first', 'datasets' => []],
                ]);
                $automation->update(['gbp_run_id' => $run->id]);
            }
            $run = app(GoogleBusinessProfileBoundCollector::class)
                ->collectResourceStep($automation->resource, $run);
            $finished = $run->status !== 'running';
            $success = $run->status === 'completed';
            $automation->update([
                'collection_status' => $finished ? ($success ? 'current' : 'attention') : 'waiting',
                'collection_queued_at' => null,
                'collection_error' => $finished && ! $success ? 'collection_failed' : null,
                'collection_failures' => 0,
                'last_collection_success_at' => $success ? now() : $automation->last_collection_success_at,
                'next_collection_at' => $finished ? $this->nextAt($automation)
                    : now()->addMinutes((int) data_get($run->metadata, 'retry_minutes', 0)),
            ]);
            if ($finished) {
                $this->alert($automation->id, 'collection', $success ? null : 'collection_failed');
            }
        });
    }

    private function collectBound(CoreExternalResource $r, ?User $actor): ?CollectionRun
    {
        $binding = $r->bindings()->with('digitalAsset')->where('status', 'active')->where('capability', $r->resource_type)->orderBy('id')->firstOrFail();
        if ($r->resource_type === 'meta_ads') {
            $result = app(MetaSingleBindingCollectionOrchestrator::class)->start($r->integration, $binding, $actor);
            if (! $result['collection_run'] && ! in_array($result['outcome'], ['data_current', 'no_work'], true)) {
                throw new \RuntimeException('Account requires attention.');
            }

            return $result['collection_run'];
        }
        $result = app(ExecuteCollectionLifecycleService::class)->executeForDigitalAsset(
            $binding->digitalAsset, $actor, context: ['manual' => true, 'binding_ids' => [$binding->id]]
        );
        if ($result->outcome === 'blocked') {
            throw new \RuntimeException('Account requires attention.');
        }

        return $result->collectionRun;
    }

    private function reconcile(ResourceAutomation $a): void
    {
        $run = CollectionRun::query()->find($a->collection_run_id);
        if (! $run) {
            $this->fail($a->id);

            return;
        }
        if (! $run->status->isTerminal()) {
            return;
        }
        // A multi-account manual run can finish partially while this exact account succeeded.
        $resources = $run->resourceRuns()->where('external_resource_id', $a->external_resource_id)->get();
        $success = $resources->isNotEmpty() && $resources->every(fn ($r) => $r->status->value === 'completed');
        if ($success) {
            $through = $run->datasetRuns()->whereIn('collection_resource_run_id', $resources->pluck('id'))->where('status', 'completed')
                ->get(['dataset_contract_id', 'metadata'])
                ->filter(fn ($d) => filled(data_get($d->metadata, 'date_range.end')))
                ->groupBy(fn ($d) => $d->dataset_contract_id.'|'.data_get($d->metadata, 'search_type', ''))
                ->map(fn ($group) => $group->max(fn ($d) => data_get($d->metadata, 'date_range.end')))->min();
            $this->alert($a->id, 'collection', null);
            $a->update(['data_through' => $through ?: $a->data_through, 'collection_status' => 'current', 'collection_error' => null, 'collection_failures' => 0,
                'last_collection_success_at' => now(), 'next_collection_at' => $this->nextAt($a)]);

            return;
        }
        $authError = $run->datasetRuns()->whereIn('collection_resource_run_id', $resources->pluck('id'))
            ->get(['error_category'])->contains(fn ($d) => preg_match('/auth|permission|credential/i', $d->error_category?->value ?? '') === 1);
        $contractError = $run->datasetRuns()->whereIn('collection_resource_run_id', $resources->pluck('id'))
            ->whereIn('error_category', ['invalid_request', 'persistence'])->exists();
        $this->fail($a->id, $authError ? 'reconnect' : ($run->status->value === 'cancelled' ? 'cancelled'
            : ($contractError ? 'request_requires_fix' : 'collection_failed')));
    }

    public function fail(int $id, string $reason = 'collection_failed'): void
    {
        $a = ResourceAutomation::query()->find($id);
        if (! $a) {
            return;
        }
        $failures = (int) $a->collection_failures + 1;
        $stop = in_array($reason, ['reconnect', 'cancelled', 'request_requires_fix'], true) || $failures >= 3;
        if ($stop && $reason !== 'cancelled') {
            $this->alert($a->id, 'collection', $reason);
        }
        $a->update([
            'collection_status' => $stop ? 'attention' : 'waiting', 'collection_error' => $reason,
            'collection_failures' => $failures, 'collection_queued_at' => null,
            'next_collection_at' => $stop ? null : now()->addMinutes($failures === 1 ? 30 : 180),
        ]);
    }

    public function alert(int $automationId, string $phase, ?string $reason): void
    {
        try {
            $a = ResourceAutomation::query()->find($automationId);
            if (! $a) {
                return;
            }
            $alerts = app(OperationalAlertLifecycleService::class);
            $rule = 'resource-automation.'.$phase;
            if ($reason === null) {
                $alerts->resolveIfActive($rule, 'external_resource', (string) $a->external_resource_id);

                return;
            }
            $alerts->observeCondition(
                $rule, 1,
                $phase === 'collection' ? OperationalAlertRuleType::CollectionRepeatedFailure : OperationalAlertRuleType::DatasetBlocked,
                OperationalSignalFamily::Collection,
                OperationalAlertSeverity::Warning,
                'external_resource', (string) $a->external_resource_id,
                __('resource-auto.title').' · '.($a->resource?->display_name ?? '#'.$a->external_resource_id),
                __('resource-auto.'.$reason), ['automation_id' => $a->id, 'phase' => $phase, 'reason' => $reason]
            );
        } catch (\Throwable $e) {
            // A notification outage must not undo a durable collection/import result.
            Log::warning('resource-automation.alert-unavailable', ['automation_id' => $automationId]);
        }
    }

    public function coverageEnd(int $resourceId, string $provider, string $family, ?string $contract = null, ?string $variant = null): ?string
    {
        $dataset = CollectionDatasetRun::query()
            ->where('provider_or_source', $provider)->where('request_family_id', $family)->where('status', 'completed')
            ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $resourceId))
            ->when($contract !== null, fn ($q) => $q->where('dataset_contract_id', $contract))
            ->when($variant !== null, fn ($q) => $q->where('execution_variant', $variant))
            ->whereNotNull('metadata->date_range->end')->orderByDesc('metadata->date_range->end')->first();

        return $dataset ? data_get($dataset->metadata, 'date_range.end') : null;
    }

    /** Shared by manual and scheduled central starters; credentials never leave the existing collectors. */
    public function withResourceLocks(array $ids, callable $action): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $locks = [];
        try {
            foreach ($ids as $id) {
                $lock = Cache::lock('provider-account-collection:'.$id, 600);
                if (! $lock->get()) {
                    throw ValidationException::withMessages(['collection' => __('resource-auto.busy')]);
                }
                $locks[] = $lock;
            }
            if (CollectionResourceRun::query()->whereIn('external_resource_id', $ids)->whereIn('status', self::ACTIVE)
                ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', self::ACTIVE))->exists()) {
                throw ValidationException::withMessages(['collection' => __('resource-auto.busy')]);
            }

            return $action();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Faz 14: next automatic collection — `interval_days` later, at the account's preferred hour (Europe/Istanbul)
     * when one is set.
     */
    public function nextAt(ResourceAutomation $automation): CarbonInterface
    {
        $next = now()->addDays(max(1, (int) $automation->interval_days));
        if ($automation->preferred_hour === null) {
            return $next;
        }

        return $next->copy()->timezone('Europe/Istanbul')->startOfDay()->addHours((int) $automation->preferred_hour)->utc();
    }
}
