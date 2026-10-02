<?php

namespace App\Services\Integrations;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalSignalFamily;
use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Models\User;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\Activity\CollectionActivityGate;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Meta\MetaCentralCollectionService;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Observability\AlertSubjects;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ResourceAutomationService
{
    /** Free query sources: their queries are pulled from every discovered account, bound or not. */
    public const array QUERY_SOURCES = ['search_console', 'google_ads', 'google_business_profile'];

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
        ])->validate();
        DB::transaction(function () use ($id, $input, $revision, $actor): void {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($id);
            if ($a->revision !== $revision) {
                throw ValidationException::withMessages(['automation' => __('resource-auto.conflict')]);
            }
            // Sector and query intake are no longer per-account settings: every query source feeds the query
            // pipeline and the account's sector lives in asset_sectors (Keşfedilen varlıklar).
            $a->fill([
                'collection_enabled' => $input['collection_enabled'], 'interval_days' => (int) $input['interval_days'],
                'preferred_hour' => isset($input['preferred_hour']) && $input['preferred_hour'] !== '' ? (int) $input['preferred_hour'] : null,
                'revision' => $a->revision + 1, 'updated_by' => $actor->id,
                'collection_error' => null, 'collection_failures' => 0,
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
                ->orderBy('id')->limit(100)->get()->each(function ($automation): void {
                    if ($this->readiness($automation->resource) === null) {
                        $automation->update(['collection_status' => 'waiting', 'collection_error' => null, 'next_collection_at' => now()]);
                    }
                });
            // Accounts parked as unbound / passive resume once assigned to a brand (portfolioGate).
            ResourceAutomation::query()->where('collection_enabled', true)
                ->where('collection_status', 'attention')->whereIn('collection_error', ['unbound', 'customer_passive'])
                ->orderBy('id')->limit(200)->get()->each(function ($automation): void {
                    if ($this->readiness($automation->resource) === null && $this->portfolioGate($automation) === null) {
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
        } finally {
            $lock->release();
        }
    }

    /**
     * Repair for accounts stopped by now-fixed write errors: the empty / missing-dimension rejection
     * ("missing natural key [landingPage | pagePathPlusQueryString | country | …]" — the writer now stores "(empty)" /
     * "(not set)") and missing month partitions of compact fact tables ("no partition of relation gsc_f_…"; now
     * ensured before every write). Runs daily with retry-stopped and on demand (--recover-ga4-landing-pages).
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
                        ->where(fn ($q) => $q->where('error_message', 'like', '%missing natural key [%')
                            // Compact gsc_f_* fact tables had no partition for the month (fixed: ensured before write).
                            ->orWhere('error_message', 'like', '%no partition of relation%'))
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
            // An account whose collection is already running (e.g. started by hand) is not admitted a second time.
            ->whereNotIn('external_resource_id', $activeIds->all() ?: [0])
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
            // Idle / dormant accounts are collected once a week; wait for the weekly pass.
            if ($automation->last_collection_success_at !== null && $resourceType !== 'google_business_profile') {
                $activity = app(CollectionActivityGate::class)->plan($automation->resource);
                if (! $activity->due && $activity->nextDueAt !== null) {
                    $automation->update(['next_collection_at' => $activity->nextDueAt]);

                    continue;
                }
            }
            $automation->update(['collection_status' => 'planning', 'collection_queued_at' => now(), 'collection_run_id' => null]);
            try {
                ResourceCollectionJob::dispatch($automation->id)->onConnection($connection);
                $slots--;
            } catch (Throwable $error) {
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

        return null;
    }

    /**
     * Operator decision (2026-11-05): only accounts bound (active binding) to a digital asset that is assigned to a
     * brand are collected automatically — active or passive customer. Unassigned accounts wait as `unbound`; the tick
     * resumes them as soon as they are assigned.
     */
    public function portfolioGate(ResourceAutomation $automation): ?string
    {
        $assigned = CoreAssetBinding::query()->where('external_resource_id', $automation->external_resource_id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereHas('digitalAsset', fn ($q) => $q->whereNotNull('brand_id'))
            ->exists();

        return $assigned ? null : 'unbound';
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
     * @return array{retried: int, reconnected: int, recovered: int, alerts_resolved: int}
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

        return ['retried' => $retried, 'reconnected' => $reconnected,
            'recovered' => $this->recoverGa4LandingFailures(), 'alerts_resolved' => $this->resolveUnboundAlerts()];
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
        // v2: every discovered account collects the full v2 dataset catalogue (never a query-only subset).
        $queryOnly = $this->isQueryOnly($r);
        if ($r->resource_type === 'google_business_profile') {
            $this->collectGbp($a, $queryOnly);

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
            'google_ads' => app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor, $queryOnly),
            'search_console' => app(SearchConsoleCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor, $queryOnly),
            'ga4' => app(Ga4CentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            'meta_ads' => app(MetaCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            default => throw new \RuntimeException('Unsupported resource type ['.$r->resource_type.'].'),
        };
        $a->update([
            'collection_run_id' => $run?->id, 'collection_status' => $run ? 'collecting' : 'current',
            'collection_queued_at' => null, 'collection_error' => null,
            'next_collection_at' => $this->nextAt($a),
        ]);
        if ($run) {
            $run->update(['metadata' => array_merge($run->metadata ?? [], ['automatic_collection' => true, 'resource_automation_id' => $a->id, 'query_only' => $queryOnly])]);
        }
    }

    private function collectGbp(ResourceAutomation $automation, bool $queryOnly = false): void
    {
        $this->withResourceLocks([$automation->external_resource_id], function () use ($automation, $queryOnly): void {
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
                        'collection_scope' => 'provider_resource_first', 'datasets' => []]
                        + ($queryOnly ? ['only_datasets' => GoogleBusinessProfileBoundCollector::QUERY_DATASETS, 'query_only' => true] : []),
                ]);
                $automation->update(['gbp_run_id' => $run->id]);
            }
            $run = app(GoogleBusinessProfileBoundCollector::class)
                ->collectResourceStep($automation->resource, $run);
            $finished = $run->status !== 'running';
            // Partial = the location's core data arrived and some optional datasets did not (API not enabled,
            // reviews access not approved). It stays current; the missing parts are shown on the profile page.
            $datasets = (array) data_get($run->metadata, 'datasets', []);
            $core = (array) (data_get($run->metadata, 'only_datasets') ?: ['gbp_location', 'gbp_performance_daily']);
            $coreDelivered = collect($core)
                ->every(fn (string $key): bool => in_array(data_get($datasets, $key.'.status'), ['available', 'partial'], true));
            $success = $run->status === 'completed' || ($run->status === 'partial' && $coreDelivered);
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
            if ($finished && $success) {
            }
        });
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
            $this->refreshActivity($a, $resources);
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

    /**
     * After a successful collection: a full pass (all datasets) moves the account's full-collection mark, and the
     * activity tier is recomputed from the facts just written (resume / pause auto-clear happen here).
     *
     * @param  Collection<int, CollectionResourceRun>  $resources
     */
    private function refreshActivity(ResourceAutomation $automation, $resources): void
    {
        try {
            $tiers = app(ActivityTierService::class);
            $modes = $resources->map(fn (CollectionResourceRun $run): string => (string) data_get($run->metadata, 'activity.mode', 'full'));
            if ($modes->contains('full')) {
                $tiers->markFullCollection((int) $automation->external_resource_id);
            }
            $tiers->refreshResource((int) $automation->external_resource_id);
        } catch (Throwable $exception) {
            // Tiering must never undo a durable collection result.
            report($exception);
        }
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
            // Operator alerts only for accounts that serve an operational asset; an unbound / passive account
            // (admitted for its queries only) never pages the operator.
            if ($reason === null || ! $this->isOperationallyBound($a->resource)) {
                $alerts->resolveIfActive($rule, 'external_resource', (string) $a->external_resource_id);

                return;
            }
            $alerts->observeCondition(
                $rule, 1,
                $phase === 'collection' ? OperationalAlertRuleType::CollectionRepeatedFailure : OperationalAlertRuleType::DatasetBlocked,
                OperationalSignalFamily::Collection,
                OperationalAlertSeverity::Warning,
                'external_resource', (string) $a->external_resource_id,
                // Written by queue workers (English app locale); the operator product is Turkish.
                __('resource-auto.alert_title', [], 'tr').' · '.($a->resource?->display_name ?? '#'.$a->external_resource_id),
                __('resource-auto.'.$reason, [], 'tr'), [
                    'automation_id' => $a->id, 'phase' => $phase, 'reason' => $reason,
                    // Brand, asset, account and last error, so the alert names what stopped and why.
                    'affected' => app(AlertSubjects::class)->describe([['resource_id' => (int) $a->external_resource_id]]),
                ]
            );
        } catch (Throwable $e) {
            // A notification outage must not undo a durable collection/import result.
            Log::warning('resource-automation.alert-unavailable', ['automation_id' => $automationId]);
        }
    }

    /** Whether the account is bound (active binding) to at least one operational Digital Asset. */
    public function isOperationallyBound(?CoreExternalResource $resource): bool
    {
        if ($resource === null) {
            return false;
        }
        $assetIds = $resource->bindings()->where('status', 'active')->pluck('digital_asset_id');

        return $assetIds->isNotEmpty() && DigitalAsset::query()->operational()->whereIn('digital_assets.id', $assetIds)->exists();
    }

    /**
     * MoxDOP v2: no account is limited to its queries any more — unbound accounts collect the full v2 dataset
     * catalogue like bound ones (query sources included). Always false; kept for the collectors' signature.
     */
    public function isQueryOnly(?CoreExternalResource $resource): bool
    {
        return false;
    }

    /**
     * Cleanup: resolves open collection alerts of accounts that serve no operational asset (raised before only
     * bound accounts could alert). Returns how many were resolved.
     */
    public function resolveUnboundAlerts(): int
    {
        $resolved = 0;
        $alerts = app(OperationalAlertLifecycleService::class);
        OperationalAlert::query()->where('rule_key', 'like', 'resource-automation.%')->where('scope_type', 'external_resource')
            ->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value])
            ->orderBy('id')->chunkById(200, function ($chunk) use (&$resolved, $alerts): void {
                $resources = CoreExternalResource::query()->whereIn('id', $chunk->pluck('scope_key')->map(fn ($id): int => (int) $id)->all())->get()->keyBy('id');
                foreach ($chunk as $alert) {
                    if (! $this->isOperationallyBound($resources->get((int) $alert->scope_key))) {
                        $resolved += $alerts->resolveIfActive((string) $alert->rule_key, 'external_resource', (string) $alert->scope_key, 'NOT_OPERATIONAL') !== null ? 1 : 0;
                    }
                }
            });

        return $resolved;
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
     * when one is set. Idle / dormant accounts (activity tier) are collected weekly; an account that just resumed
     * activity is due immediately so its gap is backfilled.
     */
    public function nextAt(ResourceAutomation $automation): CarbonInterface
    {
        $activity = app(ActivityTierService::class)->row((int) $automation->external_resource_id);
        if ($activity !== null && (bool) config('moxdop-collection-activity.enabled', true)) {
            if ($activity->effectiveTier()->value !== 'active') {
                // Idle / dormant: one light pass per week.
                return now()->addDays(max(1, (int) config('moxdop-collection-activity.light_interval_days', 7)));
            }
            if ($activity->backfill_from !== null) {
                // Activity resumed: backfill the gap right away.
                return now();
            }
        }
        $next = now()->addDays(max(1, (int) $automation->interval_days));
        if ($automation->preferred_hour === null) {
            return $next;
        }

        return $next->copy()->timezone('Europe/Istanbul')->startOfDay()->addHours((int) $automation->preferred_hour)->utc();
    }
}
