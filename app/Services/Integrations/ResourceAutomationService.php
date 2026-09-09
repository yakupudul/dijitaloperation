<?php

namespace App\Services\Integrations;

use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\SearchDemand\AutomaticQueryImportService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
                    'next_collection_at' => now()->addMinutes($r->id % 1440),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
    }

    public function save(int $id, array $input, int $revision, User $actor): void
    {
        $this->authorize($actor);
        validator($input, [
            'collection_enabled' => ['required', 'boolean'], 'interval_days' => ['required', 'in:1,3'],
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
                $ids = app(\App\Services\SearchDemand\LibraryImportWorkflow::class)->validateScope($input);
            }
            $mappingChanged = $a->sector !== ($input['sector'] ?: null) || array_map('intval', $a->service_ids ?? []) !== $ids;
            $a->fill([
                'mapping_revision' => (int) $a->mapping_revision + ($mappingChanged ? 1 : 0),
                'collection_enabled' => $input['collection_enabled'], 'interval_days' => (int) $input['interval_days'],
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
        if (! config('moxdop-resource-automation.enabled', true) || config('queue.default') === 'sync') {
            return;
        }
        $lock = Cache::lock('resource-automation-tick', 55);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->discover();
            ResourceAutomation::query()->whereNotNull('collection_run_id')->whereIn('collection_status', ['collecting', 'planning'])
                ->limit(100)->get()->each(fn ($a) => $this->reconcile($a));
            ResourceAutomation::query()->where('collection_status', 'planning')
                ->where('collection_queued_at', '<', now()->subMinutes(15))
                ->update(['collection_status' => 'waiting', 'collection_queued_at' => null]);

            $active = CollectionResourceRun::query()->whereIn('status', self::ACTIVE)->distinct()->count('external_resource_id');
            $planning = ResourceAutomation::query()->where('collection_status', 'planning')->count();
            $slots = max(0, (int) config('moxdop-resource-automation.max_active_collections', 2) - $active - $planning);
            $accounts = ResourceAutomation::query()->with('resource.integration')
                ->where('collection_enabled', true)->whereNotIn('collection_status', ['planning', 'collecting'])
                ->whereNotNull('next_collection_at')->where('next_collection_at', '<=', now())
                ->orderBy('next_collection_at')->limit(min($slots, (int) config('moxdop-resource-automation.accounts_per_tick', 10)))->get();
            foreach ($accounts as $a) {
                $error = $this->readiness($a->resource);
                if ($error !== null) {
                    $a->update(['collection_status' => 'attention', 'collection_error' => $error, 'next_collection_at' => now()->addDays($a->interval_days)]);
                    continue;
                }
                $a->update(['collection_status' => 'planning', 'collection_queued_at' => now()]);
                ResourceCollectionJob::dispatch($a->id);
            }
            app(AutomaticQueryImportService::class)->dispatchDue();
        } finally {
            $lock->release();
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
        if (in_array($resource->resource_type, ['meta_ads', 'google_business_profile'], true)
            && ! $resource->bindings()->where('status', 'active')->where('capability', $resource->resource_type)->exists()) {
            return 'binding';
        }

        return null;
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
        if ($error = $this->readiness($a->resource)) {
            $a->update(['collection_status' => 'attention', 'collection_error' => $error, 'collection_queued_at' => null,
                'next_collection_at' => now()->addDays($a->interval_days)]);
            return;
        }
        $r = $a->resource;
        $active = CollectionResourceRun::query()->where('external_resource_id', $r->id)->whereIn('status', self::ACTIVE)->latest('id')->first();
        if ($active) {
            $a->update(['collection_run_id' => $active->collection_run_id, 'collection_status' => 'collecting', 'collection_queued_at' => null]);
            return;
        }
        $actor = $a->updated_by ? User::query()->find($a->updated_by) : null;
        if ($actor && (! $actor->is_active || ! $actor->can(Permissions::ACCESS_APP))) {
            $actor = null;
        }
        $run = match ($r->resource_type) {
            'google_ads' => app(\App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            'search_console' => app(\App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            'ga4' => app(\App\Services\Collection\Ga4\Ga4CentralCollectionService::class)->startSmartUpdate($r->integration, [$r->id], $actor),
            default => $this->collectBound($r, $actor),
        };
        $a->update([
            'collection_run_id' => $run?->id, 'collection_status' => $run ? 'collecting' : 'current',
            'collection_queued_at' => null, 'collection_error' => null,
            'next_collection_at' => now()->addDays($a->interval_days),
        ]);
    }

    private function collectBound(CoreExternalResource $r, ?User $actor): ?CollectionRun
    {
        $binding = $r->bindings()->with('digitalAsset')->where('status', 'active')->where('capability', $r->resource_type)->orderBy('id')->firstOrFail();
        if ($r->resource_type === 'meta_ads') {
            $result = app(\App\Services\Collection\Meta\MetaSingleBindingCollectionOrchestrator::class)->start($r->integration, $binding, $actor);
            if (! $result['collection_run'] && ! in_array($result['outcome'], ['data_current', 'no_work'], true)) {
                throw new \RuntimeException('Account requires attention.');
            }
            return $result['collection_run'];
        }
        $result = app(\App\Services\CollectionScheduler\ExecuteCollectionLifecycleService::class)->executeForDigitalAsset(
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
        if (! $run || ! $run->status->isTerminal()) {
            return;
        }
        // A multi-account manual run can finish partially while this exact account succeeded.
        $resources = $run->resourceRuns()->where('external_resource_id', $a->external_resource_id)->get();
        $success = $resources->isNotEmpty() && $resources->every(fn ($r) => $r->status->value === 'completed');
        if ($success) {
            $through = $run->datasetRuns()->whereIn('collection_resource_run_id', $resources->pluck('id'))->where('status', 'completed')
                ->get(['metadata'])->map(fn ($d) => data_get($d->metadata, 'date_range.end'))->filter()->min();
            $this->alert($a->id, 'collection', null);
            $a->update(['data_through' => $through ?: $a->data_through,'collection_status' => 'current', 'collection_error' => null, 'collection_failures' => 0,
                'last_collection_success_at' => now(), 'next_collection_at' => now()->addDays($a->interval_days)]);
            return;
        }
        $authError = $run->datasetRuns()->whereIn('collection_resource_run_id', $resources->pluck('id'))
            ->get(['error_category'])->contains(fn ($d) => preg_match('/auth|permission|credential/i', $d->error_category?->value ?? '') === 1);
        $this->fail($a->id, $authError ? 'reconnect' : ($run->status->value === 'cancelled' ? 'cancelled' : 'collection_failed'));
    }

    public function fail(int $id, string $reason = 'collection_failed'): void
    {
        $a = ResourceAutomation::query()->find($id);
        if (! $a) {
            return;
        }
        $failures = (int) $a->collection_failures + 1;
        $stop = in_array($reason, ['reconnect', 'cancelled'], true) || $failures >= 3;
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
            $alerts = app(\App\Services\Observability\OperationalAlertLifecycleService::class);
            $rule = 'resource-automation.'.$phase;
            if ($reason === null) {
                $alerts->resolveIfActive($rule, 'external_resource', (string) $a->external_resource_id);
                return;
            }
            $alerts->observeCondition(
                $rule, 1,
                $phase === 'collection' ? \App\Enums\Observability\OperationalAlertRuleType::CollectionRepeatedFailure : \App\Enums\Observability\OperationalAlertRuleType::DatasetBlocked,
                \App\Enums\Observability\OperationalSignalFamily::Collection,
                \App\Enums\Observability\OperationalAlertSeverity::Warning,
                'external_resource', (string) $a->external_resource_id,
                __('resource-auto.title').' · '.($a->resource?->display_name ?? '#'.$a->external_resource_id),
                __('resource-auto.'.$reason), ['automation_id' => $a->id, 'phase' => $phase, 'reason' => $reason]
            );
        } catch (\Throwable $e) {
            // A notification outage must not undo a durable collection/import result.
            \Illuminate\Support\Facades\Log::warning('resource-automation.alert-unavailable', ['automation_id' => $automationId]);
        }
    }

    public function coverageEnd(int $resourceId, string $provider, string $family, ?string $contract = null, ?string $variant = null): ?string
    {
        $dataset = \App\Models\Collection\CollectionDatasetRun::query()
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
            if (CollectionResourceRun::query()->whereIn('external_resource_id', $ids)->whereIn('status', self::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['collection' => __('resource-auto.busy')]);
            }
            return $action();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
}
