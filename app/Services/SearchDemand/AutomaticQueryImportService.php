<?php

namespace App\Services\SearchDemand;

use App\Exceptions\QueryExcluded;
use App\Jobs\Async\AutomaticQueryImportJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\ResourceAutomation;
use App\Models\SearchQueryLibraryImport;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AutomaticQueryImportService
{
    private array $dictionaries = [];
    public function dispatchDue(): void
    {
        SearchQueryLibraryImport::query()->whereNotNull('input_payload->recheck_automation_id')
            ->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(5))
            ->orderBy('id')->limit(5)->get()->each(function ($import): void {
                $import->touch();
                \App\Jobs\Async\AutomaticQueryRecheckJob::dispatch($import->id);
            });
        ResourceAutomation::query()->with('resource.integration')->where('query_enabled', true)
            ->orderByRaw('CASE WHEN query_checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('query_checked_at')
            ->limit((int) config('moxdop-resource-automation.query_accounts_per_tick', 10))
            ->get()->each(function (ResourceAutomation $a): void {
                $a->update(['query_checked_at' => now()]);
                if ($a->query_error || app(ResourceAutomationService::class)->readiness($a->resource) !== null) {
                    return;
                }
                try {
                    $batch = $this->nextBatch($a);
                    if ($batch && ($batch->dispatched_at === null || \Carbon\CarbonImmutable::parse($batch->dispatched_at)->lt(now()->subMinutes(5)))) {
                        DB::table('resource_query_batches')->where('id', $batch->id)->update(['dispatched_at' => now()]);
                        AutomaticQueryImportJob::dispatch($batch->id);
                    }
                } catch (\Throwable $e) {
                    $reason = $e instanceof ValidationException ? 'mapping_invalid' : 'import_failed';
                    $a->update(['query_error' => $reason]);
                    app(ResourceAutomationService::class)->alert($a->id, 'queries', $reason);
                    report($e);
                }
            });
    }

    private function serviceIds(ResourceAutomation $a): array
    {
        $ids = app(LibraryImportWorkflow::class)->validateScope(['sector' => $a->sector, 'service_ids' => $a->service_ids ?? []]);
        return $ids !== [] ? $ids : ServiceCatalogItem::query()->where('sector', $a->sector)->where('status', 'active')->pluck('id')->all();
    }

    private function validateStoredScope(array $input): void
    {
        app(LibraryImportWorkflow::class)->validateScope([
            'sector' => $input['sector'], 'service_ids' => $input['selected_service_ids'] ?? $input['service_ids'],
        ]);
        $ids = array_unique(array_map('intval', $input['service_ids']));
        if (ServiceCatalogItem::query()->whereIn('id', $ids)->where('sector', $input['sector'])->where('status', 'active')->count() !== count($ids)) {
            throw ValidationException::withMessages(['automation' => __('resource-auto.mapping_invalid')]);
        }
    }

    private function nextBatch(ResourceAutomation $a): ?object
    {
        return DB::transaction(function () use ($a): ?object {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($a->id);
            if (! $a->query_enabled) {
                return null;
            }
            $active = DB::table('resource_query_batches as b')->join('search_query_library_imports as i', 'i.id', '=', 'b.import_id')
                ->where('b.automation_id', $a->id)->whereIn('i.status', ['queued', 'running', 'failed'])
                ->select('b.*', 'i.status')->orderBy('b.id')->first();
            if ($active) {
                if ($active->status === 'failed') {
                    $a->update(['query_error' => 'import_failed']);
                    return null;
                }
                return $active;
            }
            $inFlight = DB::table('resource_query_batches as b')->join('search_query_library_imports as i', 'i.id', '=', 'b.import_id')
                ->join('resource_automations as owner', 'owner.id', '=', 'b.automation_id')
                ->where('owner.query_enabled', true)->whereIn('i.status', ['queued', 'running'])->count();
            if ($inFlight >= (int) config('moxdop-resource-automation.max_active_query_imports', 4)) {
                return null;
            }
            [$table] = app(LibraryImportWorkflow::class)->providerTable($a->resource->resource_type);
            $dataset = CollectionDatasetRun::query()->where('dataset_contract_id', $table)->where('status', 'completed')
                ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $a->external_resource_id)->whereIn('status', ['completed', 'partial']))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('resource_query_batches')
                    ->where('automation_id', $a->id)->whereColumn('dataset_run_id', 'collection_dataset_runs.id'))
                ->orderBy('id')->first();
            if (! $dataset) {
                return null;
            }
            $ids = $this->serviceIds($a);
            $ruleState = DB::table('service_matching_keywords')->whereIn('service_catalog_item_id', $ids)
                ->selectRaw('COUNT(*) as total, MAX(id) as latest_id, MAX(updated_at) as latest_at')->first();
            $rulesFingerprint = hash('sha256', json_encode([$ids, $ruleState], JSON_THROW_ON_ERROR));
            $facts = DB::table($table)->where('external_resource_id', $a->external_resource_id)->where('last_dataset_run_id', $dataset->id);
            $upper = (int) (clone $facts)->max('id');
            $import = SearchQueryLibraryImport::query()->create([
                'uuid' => (string) Str::uuid(), 'source_type' => $a->resource->resource_type,
                'status' => 'queued', 'created_by' => $a->updated_by,
                'total_rows' => (clone $facts)->where('id', '<=', $upper)->count(),
                'input_payload' => [
                    'automatic' => true, 'automation_id' => $a->id, 'resource_ids' => [$a->external_resource_id],
                    'resource_name' => $a->resource->display_name, 'dataset_run_id' => $dataset->id,
                    'matching_fingerprint' => $rulesFingerprint, 'sector' => $a->sector, 'service_ids' => $ids, 'selected_service_ids' => $a->service_ids ?? [], 'mapping_revision' => (int) $a->mapping_revision,
                    'date_from' => data_get($dataset->metadata, 'date_range.start'),
                    'date_to' => data_get($dataset->metadata, 'date_range.end'),
                ],
            ]);
            $batchId = DB::table('resource_query_batches')->insertGetId([
                'automation_id' => $a->id, 'dataset_run_id' => $dataset->id, 'import_id' => $import->id,
                'upper_id' => $upper, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $a->update(['query_import_id' => $import->id]);
            return DB::table('resource_query_batches')->find($batchId);
        });
    }

    public function execute(int $batchId): void
    {
        $batch = DB::table('resource_query_batches')->find($batchId);
        if (! $batch) {
            return;
        }
        $a = ResourceAutomation::query()->with('resource.integration')->findOrFail($batch->automation_id);
        $import = SearchQueryLibraryImport::query()->findOrFail($batch->import_id);
        if (! in_array($import->status, ['queued', 'running'], true) || ! $a->query_enabled) {
            return;
        }
        if (app(ResourceAutomationService::class)->readiness($a->resource) !== null) {
            $this->fail($batchId, 'reconnect');
            return;
        }
        $actor = $a->updated_by ? User::query()->find($a->updated_by) : null;
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
        $this->validateStoredScope($import->input_payload);
        [$table, $column] = app(LibraryImportWorkflow::class)->providerTable($import->source_type);
        $facts = DB::table($table)->where('external_resource_id', $a->external_resource_id)
            ->where('last_dataset_run_id', $batch->dataset_run_id)->where('id', '>', $batch->cursor)
            ->where('id', '<=', $batch->upper_id)->orderBy('id')->limit((int) config('moxdop-resource-automation.query_rows_per_chunk', 100))->get();
        $import->update(['status' => 'running']);
        foreach ($facts as $fact) {
            DB::transaction(function () use ($batchId, $a, $import, $fact, $column, $actor, $table): void {
                $b = DB::table('resource_query_batches')->lockForUpdate()->find($batchId);
                if ($fact->id <= $b->cursor) {
                    return;
                }
                $i = SearchQueryLibraryImport::query()->lockForUpdate()->findOrFail($import->id);
                $raw = trim((string) $fact->{$column});
                $hash = hash('sha256', $raw);
                $seen = DB::table('resource_query_observations')->where('automation_id', $a->id)->where('text_hash', $hash)->first();
                $decision = 'duplicate';
                $queryId = $seen?->query_id;
                try {
                    if ($raw === '' || mb_strlen($raw) > 2000) {
                        $decision = 'invalid';
                        $i->failed_rows++;
                    } elseif ($seen && $seen->query_id) {
                        app(QueryExclusionService::class)->checkImport($raw, 'tr', null);
                        $item = SearchQueryLibraryItem::withTrashed()->find($seen->query_id);
                        if (! $item || $item->trashed()) {
                            $decision = 'suppressed';
                            DB::table('resource_query_batches')->where('id', $batchId)->increment('suppressed_rows');
                        }
                        // A changed account mapping never reclassifies an already observed query.
                        $i->skipped_rows++;
                        if ($item && ! $item->trashed()) {
                            $item->forceFill(['last_seen_at' => now()])->save();
                            if ((int) $seen->mapping_revision === (int) $i->input_payload['mapping_revision'] && $item->status === 'active' && $seen->matching_fingerprint !== ($i->input_payload['matching_fingerprint'] ?? null)) {
                                $this->matchServices($item, $i->input_payload['service_ids']);
                            }
                            $decision = $item->services()->where('sector', $i->input_payload['sector'])->exists() ? 'assigned' : 'unassigned';
                            $item->sourceRecords()->where('source_type', $i->source_type)
                                ->where('source_reference', $table.':'.$a->external_resource_id.':'.$hash)
                                ->update(['observed_at' => now(), 'period_end' => max($seen->last_seen_date, $fact->reporting_date)]);
                        }
                    } else {
                        $result = app(SearchQueryLibraryService::class)->store($raw, $i->source_type, [
                            'sector' => $i->input_payload['sector'], 'language_code' => 'tr', 'import' => $i,
                            'source_reference' => $table.':'.$a->external_resource_id.':'.$hash,
                            'period_start' => $fact->reporting_date, 'period_end' => $fact->reporting_date,
                            'raw_payload' => ['external_resource_id' => $a->external_resource_id, 'dataset_run_id' => $b->dataset_run_id],
                        ], $actor);
                        $queryId = $result['item']->id;
                        $matched = $this->matchServices($result['item'], $i->input_payload['service_ids']);
                        $decision = $matched === [] ? 'unassigned' : 'assigned';
                        if ($matched === []) {
                            DB::table('resource_query_batches')->where('id', $batchId)->increment('unassigned_rows');
                        }
                        $result['created'] ? $i->accepted_rows++ : $i->skipped_rows++;
                    }
                } catch (QueryExcluded $e) {
                    $decision = 'excluded';
                    $i->excluded_rows++;
                    DB::table('query_exclusion_import_rows')->updateOrInsert(
                        ['import_id' => $i->id, 'row_number' => $fact->id],
                        ['query_text' => $raw, 'expressions' => json_encode($e->expressions, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]
                    );
                } catch (ValidationException $e) {
                    $decision = collect($e->errors())->flatten()->contains(__('query-list.deleted_duplicate')) ? 'suppressed' : 'invalid';
                    if ($decision === 'suppressed') {
                        $i->skipped_rows++;
                        DB::table('resource_query_batches')->where('id', $batchId)->increment('suppressed_rows');
                    } else {
                        $i->failed_rows++;
                        $i->error_summary = __('resource-auto.invalid_rows');
                    }
                }
                $values = [
                    'original_text' => $raw, 'query_id' => $queryId, 'decision' => $decision,
                    'mapping_revision' => $seen?->mapping_revision ?? $i->input_payload['mapping_revision'],
                    'matching_fingerprint' => $i->input_payload['matching_fingerprint'] ?? null,
                    'first_seen_date' => $seen ? min($seen->first_seen_date, $fact->reporting_date) : $fact->reporting_date,
                    'last_seen_date' => $seen ? max($seen->last_seen_date, $fact->reporting_date) : $fact->reporting_date,
                    'updated_at' => now(),
                ];
                if ($seen) {
                    DB::table('resource_query_observations')->where('id', $seen->id)->update($values);
                } else {
                    DB::table('resource_query_observations')->insert($values + ['automation_id' => $a->id, 'text_hash' => $hash, 'created_at' => now()]);
                }
                $i->save();
                DB::table('resource_query_batches')->where('id', $batchId)->update(['cursor' => $fact->id, 'updated_at' => now(), 'dispatched_at' => now()]);
            });
        }
        $batch = DB::table('resource_query_batches')->find($batchId);
        $more = DB::table($table)->where('external_resource_id', $a->external_resource_id)
            ->where('last_dataset_run_id', $batch->dataset_run_id)->where('id', '>', $batch->cursor)->where('id', '<=', $batch->upper_id)->exists();
        if ($more) {
            AutomaticQueryImportJob::dispatch($batchId)->delay(now()->addSeconds(2));
            return;
        }
        $import->refresh();
        $processed = $import->accepted_rows + $import->skipped_rows + $import->failed_rows + $import->excluded_rows;
        $import->update(['status' => $import->failed_rows > 0 ? 'partial' : 'completed', 'completed_at' => now(),
            'skipped_rows' => $import->skipped_rows + max(0, $import->total_rows - $processed)]);
        $a->update(['last_query_success_at' => $import->failed_rows ? $a->last_query_success_at : now(), 'query_error' => $import->failed_rows ? 'invalid_rows' : null]);
        app(ResourceAutomationService::class)->alert($a->id, 'queries', $import->failed_rows ? 'invalid_rows' : null);
    }

    public function matchServices(SearchQueryLibraryItem $item, array $ids): array
    {
        return DB::transaction(function () use ($item, $ids): array {
            $item = SearchQueryLibraryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== 'active') {
                return [];
            }
            $ids = ServiceCatalogItem::query()->whereIn('id', $ids)->where('status', 'active')->pluck('id')->all();
            $blocked = DB::table('library_query_service_blocks')->where('query_id', $item->id)->pluck('service_id')->all();
            $cacheKey = hash('sha256', implode(',', $ids));
            if (count($this->dictionaries) >= 4 && ! isset($this->dictionaries[$cacheKey])) {
                $this->dictionaries = [];
            }
            $words = $this->dictionaries[$cacheKey] ??= \App\Models\ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $ids)->get();
            $matched = array_values(array_diff(app(ServiceKeywordService::class)->matches($item->canonical_text, $ids, $words), $blocked));
            foreach ($matched as $id) {
                if (! $item->services()->whereKey($id)->exists()) {
                    $item->services()->attach($id, ['is_primary' => ! $item->services()->wherePivot('is_primary', true)->exists(), 'provenance' => 'keyword_match']);
                }
            }
            return $matched;
        });
    }

    public function queueRecheck(int $id, User $actor): void
    {
        app(ResourceAutomationService::class)->authorize($actor);
        DB::transaction(function () use ($id, $actor): void {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($id);
            $ids = $this->serviceIds($a);
            $active = SearchQueryLibraryImport::query()->where('input_payload->recheck_automation_id', $id)
                ->whereIn('status', ['queued', 'running'])->exists();
            if ($active) {
                throw ValidationException::withMessages(['automation' => __('resource-auto.busy')]);
            }
            $upper = (int) DB::table('resource_query_observations')->where('automation_id', $id)->max('id');
            $import = SearchQueryLibraryImport::query()->create([
                'uuid' => (string) Str::uuid(), 'source_type' => 'assignment', 'status' => 'queued', 'created_by' => $actor->id,
                'input_payload' => ['recheck_automation_id' => $id, 'sector' => $a->sector, 'service_ids' => $ids, 'selected_service_ids' => $a->service_ids ?? [], 'cursor' => 0, 'upper' => $upper],
                'total_rows' => DB::table('resource_query_observations')->where('automation_id', $id)->where('id', '<=', $upper)->count(),
            ]);
            \App\Jobs\Async\AutomaticQueryRecheckJob::dispatch($import->id)->afterCommit();
        });
    }

    public function recheck(int $importId): void
    {
        $import = SearchQueryLibraryImport::query()->findOrFail($importId);
        if (! in_array($import->status, ['queued', 'running'], true)) {
            return;
        }
        $actor = User::query()->find($import->created_by);
        app(ResourceAutomationService::class)->authorize($actor);
        $input = $import->input_payload;
        $this->validateStoredScope($input);
        $a = ResourceAutomation::query()->findOrFail($input['recheck_automation_id']);
        $observations = DB::table('resource_query_observations')->where('automation_id', $a->id)
            ->where('id', '>', $input['cursor'])->where('id', '<=', $input['upper'])->orderBy('id')->limit(100)->get();
        foreach ($observations as $row) {
            DB::transaction(function () use ($importId, $row, $input): void {
                $i = SearchQueryLibraryImport::query()->lockForUpdate()->findOrFail($importId);
                $payload = $i->input_payload;
                if ($row->id <= $payload['cursor']) {
                    return;
                }
                $item = $row->query_id ? SearchQueryLibraryItem::query()->lockForUpdate()->find($row->query_id) : null;
                if ($item && $item->status === 'active'
                    && $item->sectors()->where('code', $input['sector'])->exists()
                    && ! $item->services()->where('sector', $input['sector'])->exists()) {
                    $matches = $this->matchServices($item, $input['service_ids']);
                    $matches !== [] ? $i->accepted_rows++ : $i->skipped_rows++;
                    if ($matches !== []) {
                        DB::table('resource_query_observations')->where('id', $row->id)->update(['decision' => 'assigned', 'updated_at' => now()]);
                    }
                } else {
                    $i->skipped_rows++;
                }
                $payload['cursor'] = $row->id;
                $i->fill(['status' => 'running', 'input_payload' => $payload])->save();
            });
        }
        $import->refresh();
        if ($observations->count() === 100) {
            \App\Jobs\Async\AutomaticQueryRecheckJob::dispatch($importId)->delay(now()->addSeconds(2));
        } else {
            $import->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    public function fail(int $batchId, string $reason = 'import_failed'): void
    {
        $b = DB::table('resource_query_batches')->find($batchId);
        if (! $b) {
            return;
        }
        $current = SearchQueryLibraryImport::query()->find($b->import_id);
        if (! $current || ! in_array($current->status, ['queued', 'running'], true)) {
            return;
        }
        SearchQueryLibraryImport::query()->whereKey($b->import_id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_summary' => __('resource-auto.'.$reason)]);
        ResourceAutomation::query()->whereKey($b->automation_id)->update(['query_error' => $reason]);
        app(ResourceAutomationService::class)->alert($b->automation_id, 'queries', $reason);
    }

    public function closeFailed(int $automationId, User $actor): void
    {
        app(ResourceAutomationService::class)->authorize($actor);
        DB::transaction(function () use ($automationId): void {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($automationId);
            $i = SearchQueryLibraryImport::query()->lockForUpdate()->find($a->query_import_id);
            if ($i && $i->status === 'failed') {
                $processed = $i->accepted_rows + $i->skipped_rows + $i->failed_rows + $i->excluded_rows;
                $i->update(['status' => 'partial', 'completed_at' => now(), 'skipped_rows' => $i->skipped_rows + max(0, $i->total_rows - $processed)]);
            }
            $a->update(['query_error' => null]);
        });
    }

    public function resume(int $automationId, User $actor): void
    {
        app(ResourceAutomationService::class)->authorize($actor);
        DB::transaction(function () use ($automationId, $actor): void {
            $a = ResourceAutomation::query()->lockForUpdate()->findOrFail($automationId);
            $this->serviceIds($a);
            $b = DB::table('resource_query_batches')->where('automation_id', $a->id)->where('import_id', $a->query_import_id)->first();
            if ($b) {
                $i = SearchQueryLibraryImport::query()->findOrFail($b->import_id);
                $this->validateStoredScope($i->input_payload);
                if ($i->status === 'failed') {
                    $i->update(['status' => 'queued', 'error_summary' => null]);
                    DB::table('resource_query_batches')->where('id', $b->id)->update(['dispatched_at' => null]);
                }
            }
            $a->update(['query_enabled' => true, 'query_error' => null, 'updated_by' => $actor->id, 'query_checked_at' => null]);
        });
    }
}
