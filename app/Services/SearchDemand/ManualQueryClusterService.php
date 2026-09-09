<?php

namespace App\Services\SearchDemand;

use App\Jobs\Async\ManualQueryClusterJob;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Support\Options\LocationOptions;
use App\Support\Permissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManualQueryClusterService
{
    public const PIVOT = 'search_query_library_item_service';

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP), 403);
    }

    public function service(int $id): ServiceCatalogItem
    {
        return ServiceCatalogItem::query()->where('status', 'active')->findOrFail($id);
    }

    public function cluster(int $serviceId, int $id, bool $active = true): ?object
    {
        if ($id === 0) {
            return null;
        }

        return DB::table('library_query_clusters')->where('service_id', $serviceId)
            ->when($active, fn ($q) => $q->where('status', 'active'))->find($id) ?? abort(404);
    }

    public function assertIdle(int $serviceId): void
    {
        if (DB::table('library_cluster_operations')->where('service_id', $serviceId)
            ->whereIn('status', ['queued', 'running', 'failed'])->exists()) {
            throw ValidationException::withMessages(['clusters' => __('manual-clusters.busy')]);
        }
    }

    public function filters(array $input): array
    {
        $f = validator($input, [
            'cluster' => ['required', 'integer', 'min:0'], 'children' => ['boolean'], 'new' => ['boolean'],
            'search' => ['nullable', 'string', 'max:500'], 'include' => ['nullable', 'string', 'max:2000'],
            'exclude' => ['nullable', 'string', 'max:2000'], 'match' => ['required', 'in:any,all'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        if (! empty($f['from']) && ! empty($f['to']) && $f['to'] < $f['from']) {
            throw ValidationException::withMessages(['clusters' => __('manual-clusters.date_error')]);
        }
        foreach (['include', 'exclude'] as $key) {
            $words = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', $f[$key] ?? '') ?: [])));
            if (count($words) > 30) {
                throw ValidationException::withMessages(['clusters' => __('manual-clusters.too_many_words')]);
            }
            $f[$key] = $words;
        }

        return $f;
    }

    /** The existing service relation is the root; a null child ID means direct root membership. */
    public function query(int $serviceId, array $f, bool $includeInactive = false): Builder
    {
        $q = DB::table(self::PIVOT.' as m')
            ->join('search_query_library_items as q', 'q.id', '=', 'm.search_query_library_item_id')
            ->leftJoin('library_query_clusters as c', 'c.id', '=', 'm.library_cluster_id')
            ->where('m.service_catalog_item_id', $serviceId);
        if (! $includeInactive) {
            $q->whereNull('q.deleted_at')->where('q.status', 'active');
        }
        $child = (int) ($f['cluster'] ?? 0);
        if ($child > 0) {
            $q->where('m.library_cluster_id', $child);
        } elseif (empty($f['children'])) {
            $q->whereNull('m.library_cluster_id');
        }
        if (! empty($f['new'])) {
            $q->whereNull('m.cluster_reviewed_at');
        }
        if (! empty($f['from'])) {
            $q->where('m.created_at', '>=', $f['from'].' 00:00:00');
        }
        if (! empty($f['to'])) {
            $q->where('m.created_at', '<', \Illuminate\Support\Carbon::parse($f['to'])->addDay()->startOfDay());
        }
        $like = fn (string $s): string => '%'.addcslashes(
            app(SearchTermNormalizer::class)->normalize($s, 'tr')->foldedText, '\\%_'
        ).'%';
        if (filled($f['search'] ?? null)) {
            $q->where('q.folded_text', 'like', $like($f['search']));
        }
        if (! empty($f['include'])) {
            $q->where(function ($inner) use ($f, $like): void {
                foreach ($f['include'] as $term) {
                    if (($f['match'] ?? 'any') === 'all') {
                        $inner->where('q.folded_text', 'like', $like($term));
                    } else {
                        $inner->orWhere('q.folded_text', 'like', $like($term));
                    }
                }
            });
        }
        foreach ($f['exclude'] ?? [] as $term) {
            $q->where('q.folded_text', 'not like', $like($term));
        }

        return $q;
    }

    public function saveCluster(int $serviceId, ?int $id, string $name, string $description, int $revision, User $actor): int
    {
        $this->authorize($actor);
        $name = trim($name);
        $key = LocationOptions::fold($name);
        validator(['name' => $name, 'key' => $key, 'description' => $description], [
            'name' => ['required', 'max:255'], 'key' => ['required', 'max:255'], 'description' => ['max:2000'],
        ])->validate();

        return DB::transaction(function () use ($serviceId, $id, $name, $key, $description, $revision, $actor): int {
            $this->service($serviceId);
            ServiceCatalogItem::query()->lockForUpdate()->findOrFail($serviceId);
            $this->assertIdle($serviceId);
            $child = $id ? $this->cluster($serviceId, $id) : null;
            if ($child && (int) $child->revision !== $revision) {
                throw ValidationException::withMessages(['clusters' => __('manual-clusters.conflict')]);
            }
            if (DB::table('library_query_clusters')->where('service_id', $serviceId)->where('name_key', $key)
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['clusterName' => __('manual-clusters.duplicate')]);
            }
            $values = ['name' => $name, 'name_key' => $key, 'description' => trim($description), 'updated_at' => now()];
            if ($child) {
                DB::table('library_query_clusters')->where('id', $id)->update($values + ['revision' => $revision + 1]);

                return $id;
            }

            return DB::table('library_query_clusters')->insertGetId($values + [
                'service_id' => $serviceId, 'status' => 'active', 'created_by' => $actor->id, 'created_at' => now(),
            ]);
        });
    }

    /** Snapshot filters once with INSERT SELECT; workers never re-run a changing selection. */
    public function queue(int $serviceId, string $kind, array $filters, array $ids, bool $all, int $target, string $requestKey, User $actor): int
    {
        $this->authorize($actor);
        abort_unless(in_array($kind, ['move', 'keep', 'retire', 'export'], true), 422);
        validator(['ids' => $ids, 'key' => $requestKey], [
            'ids' => ['array', 'max:100'], 'ids.*' => ['integer', 'min:1'], 'key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($serviceId, $kind, $filters, $ids, $all, $target, $requestKey, $actor): int {
            $service = $this->service($serviceId)->load('primaryName');
            ServiceCatalogItem::query()->lockForUpdate()->findOrFail($serviceId);
            $existing = DB::table('library_cluster_operations')->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === $actor->id && (int) $existing->service_id === $serviceId, 403);

                return $existing->id;
            }
            $this->assertIdle($serviceId);
            $expectedCount = $filters['_expected_count'] ?? null;
            $structure = (bool) ($filters['_structure'] ?? false);
            $source = (int) ($filters['cluster'] ?? 0);
            $this->cluster($serviceId, $source);
            $this->cluster($serviceId, $target);
            if ($kind === 'retire') {
                abort_unless($source > 0 && $source !== $target, 422);
                $filters = ['cluster' => $source];
                $all = true;
                DB::table('library_query_clusters')->where('id', $source)->update(['status' => 'retiring', 'updated_at' => now()]);
            }
            if ($kind === 'keep') {
                $target = 0;
            }
            $query = $this->query($serviceId, $filters, $kind === 'retire');
            if (! $all) {
                $query->whereIn('m.id', array_unique($ids));
            }
            $names = DB::table('library_query_clusters')->where('service_id', $serviceId)->pluck('name', 'id')->all();
            $main = $service->primaryName?->raw_label ?? '#'.$serviceId;
            $metadata = [
                'filters' => $filters, 'main' => $main,
                'sector' => \App\Models\ServiceCategory::query()->where('code', $service->sector)->value('name') ?? '',
                'source_name' => $source ? ($names[$source] ?? '') : $main,
                'target_name' => $target ? ($names[$target] ?? '') : $main,
                'locale' => app()->getLocale(), 'parts' => [],
                'source_targets' => $kind === 'retire' ? DB::table('library_cluster_targets as t')
                    ->join('digital_assets as a', 'a.id', '=', 't.digital_asset_id')
                    ->where('t.service_id', $serviceId)->where('t.cluster_key', $source)->where('t.url', '!=', '')
                    ->get(['a.name', 't.url'])->map(fn ($r) => (array) $r)->all() : [],
                'empty_clusters' => $structure ? DB::table('library_query_clusters as child')->where('child.service_id', $serviceId)
                    ->where('child.status', 'active')->whereNotExists(fn ($q) => $q->selectRaw('1')->from(self::PIVOT.' as membership')
                        ->join('search_query_library_items as item', 'item.id', '=', 'membership.search_query_library_item_id')
                        ->whereColumn('membership.library_cluster_id', 'child.id')->whereNull('item.deleted_at')->where('item.status', 'active'))
                    ->orderBy('child.name')->pluck('child.name')->all() : [],
            ];
            $op = DB::table('library_cluster_operations')->insertGetId([
                'request_key' => $requestKey, 'service_id' => $serviceId, 'kind' => $kind,
                'source_cluster_id' => $source ?: null, 'target_cluster_id' => $target ?: null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query->selectRaw('? as operation_id', [$op])->addSelect(['m.id', 'q.id as query_id', 'q.canonical_text'])
                ->selectRaw('COALESCE(c.name, ?) as cluster_name', [$main])
                ->addSelect(['m.library_cluster_id', 'm.cluster_reviewed_at', 'm.cluster_revision']);
            DB::table('library_cluster_operation_rows')->insertUsing([
                'operation_id', 'membership_id', 'query_id', 'query_text', 'cluster_name',
                'before_cluster_id', 'before_reviewed_at', 'before_revision',
            ], $query);
            $total = DB::table('library_cluster_operation_rows')->where('operation_id', $op)->count();
            if ($expectedCount !== null && $total !== (int) $expectedCount) {
                throw ValidationException::withMessages(['clusters' => __('manual-clusters.selection_changed')]);
            }
            if ($total === 0 && $kind !== 'retire' && ! $structure) {
                throw ValidationException::withMessages(['clusters' => __('manual-clusters.nothing_selected')]);
            }
            DB::table('library_cluster_operations')->where('id', $op)->update(['total' => $total]);
            ManualQueryClusterJob::dispatch($op)->afterCommit();

            return $op;
        });
    }

    public function undo(int $operationId, User $actor): int
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($operationId, $actor): int {
            $old = DB::table('library_cluster_operations')->find($operationId);
            abort_unless($old && in_array($old->kind, ['move', 'keep', 'retire'], true)
                && in_array($old->status, ['completed', 'partial'], true), 409);
            $this->service($old->service_id);
            ServiceCatalogItem::query()->lockForUpdate()->findOrFail($old->service_id);
            $existing = DB::table('library_cluster_operations')->where('undo_of', $operationId)->first();
            if ($existing) {
                return $existing->id;
            }
            $this->assertIdle($old->service_id);
            if ($old->kind === 'retire') {
                $child = $this->cluster($old->service_id, $old->source_cluster_id, false);
                $key = LocationOptions::fold($child->name);
                if (DB::table('library_query_clusters')->where('service_id', $old->service_id)
                    ->where('name_key', $key)->where('id', '!=', $child->id)->exists()) {
                    throw ValidationException::withMessages(['clusters' => __('manual-clusters.duplicate')]);
                }
                DB::table('library_query_clusters')->where('id', $child->id)->update([
                    'status' => 'active', 'name_key' => $key, 'revision' => $child->revision + 1, 'updated_at' => now(),
                ]);
            }
            $meta = json_decode($old->metadata, true, 512, JSON_THROW_ON_ERROR);
            unset($meta['resume_actor_id']);
            $meta['locale'] = app()->getLocale();
            $beforeName = $meta['target_name'];
            $meta['source_name'] = $beforeName;
            $meta['target_name'] = __('manual-clusters.previous_clusters');
            $meta['parts'] = [];
            $op = DB::table('library_cluster_operations')->insertGetId([
                'request_key' => (string) Str::uuid(), 'service_id' => $old->service_id, 'created_by' => $actor->id,
                'kind' => 'undo', 'undo_of' => $old->id, 'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
                'total' => $old->changed, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $rows = DB::table('library_cluster_operation_rows')->where('operation_id', $old->id)->where('decision', 'changed')
                ->selectRaw('? as operation_id', [$op])->addSelect(['membership_id', 'query_id', 'query_text'])
                ->selectRaw('? as cluster_name', [$beforeName])
                ->selectRaw('? as before_cluster_id', [$old->target_cluster_id])
                ->addSelect(['after_revision', 'before_cluster_id as restore_cluster_id', 'before_reviewed_at as restore_reviewed_at']);
            DB::table('library_cluster_operation_rows')->insertUsing([
                'operation_id', 'membership_id', 'query_id', 'query_text', 'cluster_name',
                'before_cluster_id', 'before_revision', 'restore_cluster_id', 'restore_reviewed_at',
            ], $rows);
            ManualQueryClusterJob::dispatch($op)->afterCommit();

            return $op;
        });
    }

    public function resume(int $id, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($id, $actor): void {
            $op = DB::table('library_cluster_operations')->lockForUpdate()->find($id);
            abort_unless($op && $op->status === 'failed', 409);
            $this->service($op->service_id);
            $meta = json_decode($op->metadata, true, 512, JSON_THROW_ON_ERROR);
            $meta['resume_actor_id'] = $actor->id;
            DB::table('library_cluster_operations')->where('id', $id)->update([
                'status' => 'queued', 'error' => null, 'metadata' => json_encode($meta, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            ManualQueryClusterJob::dispatch($id)->afterCommit();
        });
    }

    public function stopFailed(int $id, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($id): void {
            $op = DB::table('library_cluster_operations')->lockForUpdate()->find($id);
            abort_unless($op && $op->status === 'failed', 409);
            $count = DB::table('library_cluster_operation_rows')->where('operation_id', $id)
                ->where('decision', 'pending')->update(['decision' => 'skipped']);
            DB::table('library_cluster_operations')->where('id', $id)->update([
                'processed' => $op->processed + $count, 'skipped' => $op->skipped + $count,
                'status' => 'partial', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            if ($op->kind === 'retire') {
                DB::table('library_query_clusters')->where('id', $op->source_cluster_id)->where('status', 'retiring')
                    ->update(['status' => 'active', 'updated_at' => now()]);
            }
        });
    }

    public function execute(int $id): void
    {
        $op = DB::table('library_cluster_operations')->find($id);
        if (! $op || ! in_array($op->status, ['queued', 'running'], true)) {
            return;
        }
        $meta = json_decode($op->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->authorize(User::query()->find($meta['resume_actor_id'] ?? $op->created_by));
        $this->service($op->service_id);
        app()->setLocale(in_array($meta['locale'] ?? '', ['tr', 'en'], true) ? $meta['locale'] : 'tr');
        DB::table('library_cluster_operations')->where('id', $id)->update(['status' => 'running', 'updated_at' => now()]);
        $rows = DB::table('library_cluster_operation_rows')->where('operation_id', $id)
            ->where('decision', 'pending')->orderBy('id')->limit(250)->get();
        if ($op->kind === 'export' && $rows->isNotEmpty()) {
            $this->exportPart($op, $rows, $meta);
        } else {
            foreach ($rows as $row) {
                DB::transaction(function () use ($op, $row): void {
                    $receipt = DB::table('library_cluster_operation_rows')->lockForUpdate()->find($row->id);
                    if ($receipt->decision !== 'pending') {
                        return;
                    }
                    $m = DB::table(self::PIVOT)->where('service_catalog_item_id', $op->service_id)->lockForUpdate()->find($row->membership_id);
                    $valid = $m && (int) $m->search_query_library_item_id === (int) $row->query_id
                        && (int) $m->cluster_revision === (int) $row->before_revision
                        && (int) $m->library_cluster_id === (int) $row->before_cluster_id;
                    $target = $op->kind === 'undo' ? $row->restore_cluster_id : $op->target_cluster_id;
                    if ($target && ! DB::table('library_query_clusters')->where('id', $target)
                        ->where('service_id', $op->service_id)->where('status', 'active')->exists()) {
                        $valid = false;
                    }
                    if ($valid && ! in_array($op->kind, ['retire', 'undo'], true)) {
                        $valid = DB::table('search_query_library_items')->where('id', $row->query_id)
                            ->whereNull('deleted_at')->where('status', 'active')->exists();
                    }
                    $decision = 'skipped';
                    $after = null;
                    if ($valid) {
                        $after = (int) $m->cluster_revision + 1;
                        DB::table(self::PIVOT)->where('id', $m->id)->update([
                            'library_cluster_id' => $target ?: null,
                            'cluster_reviewed_at' => $op->kind === 'undo' ? $row->restore_reviewed_at : now(),
                            'cluster_revision' => $after,
                        ]);
                        $decision = 'changed';
                    }
                    DB::table('library_cluster_operation_rows')->where('id', $row->id)->update([
                        'decision' => $decision, 'after_revision' => $after,
                    ]);
                    DB::table('library_cluster_operations')->where('id', $op->id)
                        ->incrementEach(['processed' => 1, $decision === 'changed' ? 'changed' : 'skipped' => 1], ['updated_at' => now()]);
                });
            }
        }
        if (DB::table('library_cluster_operation_rows')->where('operation_id', $id)->where('decision', 'pending')->exists()) {
            ManualQueryClusterJob::dispatch($id)->delay(now()->addSecond());

            return;
        }
        $this->finish($id);
    }

    private function exportPart(object $op, \Illuminate\Support\Collection $rows, array $meta): void
    {
        $handle = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            $values = [(string) $row->query_id, $row->query_text, (string) $meta['sector'], $meta['main'], $row->cluster_name];
            $values = array_map(fn (string $v): string => preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $v) ? "'".$v : $v, $values);
            if (fputcsv($handle, $values, ';', '"', '') === false) {
                throw new \RuntimeException('Export write failed.');
            }
        }
        rewind($handle);
        $part = 'library-cluster-exports/'.$op->id.'/part-'.$rows->first()->id.'.csv';
        try {
            if (! Storage::disk('local')->put($part, $handle)) {
                throw new \RuntimeException('Export storage unavailable.');
            }
        } finally {
            fclose($handle);
        }
        DB::transaction(function () use ($op, $rows, $part): void {
            $current = DB::table('library_cluster_operations')->lockForUpdate()->find($op->id);
            $meta = json_decode($current->metadata, true, 512, JSON_THROW_ON_ERROR);
            $meta['parts'][] = $part;
            DB::table('library_cluster_operation_rows')->whereIn('id', $rows->pluck('id'))->update(['decision' => 'exported']);
            DB::table('library_cluster_operations')->where('id', $op->id)->update([
                'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
                'processed' => $current->processed + $rows->count(), 'updated_at' => now(),
            ]);
        });
    }

    private function finish(int $id): void
    {
        $op = DB::table('library_cluster_operations')->find($id);
        $path = null;
        if ($op->kind === 'export') {
            $meta = json_decode($op->metadata, true, 512, JSON_THROW_ON_ERROR);
            $path = 'library-cluster-exports/'.$id.'/queries.csv';
            Storage::disk('local')->makeDirectory('library-cluster-exports/'.$id);
            $out = fopen(Storage::disk('local')->path($path), 'wb');
            if ($out === false) {
                throw new \RuntimeException('Export unavailable.');
            }
            try {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['ID', __('manual-clusters.query'), __('manual-clusters.sector'),
                    __('manual-clusters.main'), __('manual-clusters.cluster')], ';', '"', '');
                foreach ($meta['empty_clusters'] ?? [] as $name) {
                    $cells = ['', '', (string) $meta['sector'], $meta['main'], $name];
                    $cells = array_map(fn (string $v): string => preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $v) ? "'".$v : $v, $cells);
                    if (fputcsv($out, $cells, ';', '"', '') === false) {
                        throw new \RuntimeException('Export write failed.');
                    }
                }
                foreach (array_unique($meta['parts']) as $part) {
                    $in = Storage::disk('local')->readStream($part);
                    if ($in === false) {
                        throw new \RuntimeException('Export part unavailable.');
                    }
                    try {
                        if (stream_copy_to_stream($in, $out) === false) {
                            throw new \RuntimeException('Export copy failed.');
                        }
                    } finally {
                        fclose($in);
                    }
                }
            } finally {
                fclose($out);
            }
        }
        DB::transaction(function () use ($op, $path): void {
            if ($op->kind === 'retire') {
                $remaining = DB::table(self::PIVOT)->where('library_cluster_id', $op->source_cluster_id)->exists();
                DB::table('library_query_clusters')->where('id', $op->source_cluster_id)->update([
                    'status' => $remaining ? 'active' : 'archived',
                    'name_key' => $remaining ? LocationOptions::fold($this->cluster($op->service_id, $op->source_cluster_id, false)->name) : null,
                    'updated_at' => now(), 'revision' => DB::raw('revision + 1'),
                ]);
            }
            DB::table('library_cluster_operations')->where('id', $op->id)->update([
                'status' => $op->skipped ? 'partial' : 'completed',
                'file_path' => $path, 'completed_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function fail(int $id): void
    {
        DB::table('library_cluster_operations')->where('id', $id)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => 'manual-clusters.failed_help', 'updated_at' => now(),
        ]);
    }

    public function saveTarget(int $serviceId, int $clusterId, int $assetId, string $url, int $revision, User $actor): void
    {
        $this->authorize($actor);
        $this->service($serviceId);
        $this->cluster($serviceId, $clusterId);
        $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($assetId);
        $url = trim($url);
        if ($url !== '') {
            validator(['url' => $url], ['url' => ['required', 'url:http,https', 'max:2048']])->validate();
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $siteHost = strtolower((string) parse_url($asset->primary_url ?: 'https://'.$asset->domain, PHP_URL_HOST));
            if ($host === '' || $host !== $siteHost || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                throw ValidationException::withMessages(['targetUrl' => __('manual-clusters.wrong_host')]);
            }
        }
        DB::transaction(function () use ($serviceId, $clusterId, $assetId, $url, $revision, $actor): void {
            ServiceCatalogItem::query()->lockForUpdate()->findOrFail($serviceId);
            $this->assertIdle($serviceId);
            $this->cluster($serviceId, $clusterId);
            $q = DB::table('library_cluster_targets')->where('service_id', $serviceId)
                ->where('cluster_key', $clusterId)->where('digital_asset_id', $assetId);
            $existing = (clone $q)->first();
            if ((int) ($existing->revision ?? 0) !== $revision) {
                throw ValidationException::withMessages(['targetUrl' => __('manual-clusters.conflict')]);
            }
            $values = ['url' => $url, 'revision' => $revision + 1, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($existing) {
                $q->update($values);
            } elseif ($url !== '') {
                DB::table('library_cluster_targets')->insert($values + [
                    'service_id' => $serviceId, 'cluster_key' => $clusterId, 'digital_asset_id' => $assetId, 'created_at' => now(),
                ]);
            }
        });
    }
}

