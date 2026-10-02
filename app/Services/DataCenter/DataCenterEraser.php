<?php

namespace App\Services\DataCenter;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes the operator-selected data sets of a source. Protected data sets (queries, search terms, keywords) are
 * never deleted. Collection is not changed: a still-bound account keeps collecting new days.
 */
final class DataCenterEraser
{
    public function __construct(private readonly DataCenterCatalog $catalog) {}

    /**
     * @param  list<string>  $datasets
     * @return array{deleted_rows: int, skipped_protected: list<string>}
     */
    public function erase(string $kind, int $id, array $datasets, ?User $actor = null): array
    {
        $deleted = 0;
        $skipped = [];
        foreach (array_values(array_unique($datasets)) as $dataset) {
            if ($this->catalog->isProtected($dataset)) {
                $skipped[] = $dataset;

                continue;
            }
            $deleted += $dataset === DataCenterCatalog::RAW
                ? $this->eraseRaw($kind, $id)
                : DB::transaction(fn (): int => $this->catalog->deleteRows($dataset, $kind, $id));
            if ($dataset !== DataCenterCatalog::RAW && Schema::hasTable('dataset_materializations')) {
                DB::table('dataset_materializations')->where('dataset_id', $dataset)
                    ->where($kind === 'resource' ? 'external_resource_id' : 'digital_asset_id', $id)
                    ->when($kind === 'asset', fn ($q) => $q->whereNull('external_resource_id'))
                    ->delete();
            }
        }
        Log::info('data-center.erased', ['kind' => $kind, 'id' => $id, 'datasets' => $datasets, 'rows' => $deleted, 'actor_id' => $actor?->id]);

        return ['deleted_rows' => $deleted, 'skipped_protected' => $skipped];
    }

    private function eraseRaw(string $kind, int $id): int
    {
        if (! Schema::hasTable('raw_ingestion_objects')) {
            return 0;
        }
        $deleted = 0;
        DB::table('raw_ingestion_objects as o')->join('collection_resource_runs as r', 'r.id', '=', 'o.resource_run_id')
            ->where($kind === 'resource' ? 'r.external_resource_id' : 'r.digital_asset_id', $id)
            ->when($kind === 'asset', fn ($q) => $q->whereNull('r.external_resource_id'))
            ->orderBy('o.id')->select(['o.id', 'o.storage_disk', 'o.object_key'])
            ->chunkById(1000, function ($objects) use (&$deleted): void {
                foreach ($objects as $object) {
                    try {
                        Storage::disk((string) $object->storage_disk)->delete((string) $object->object_key);
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
                $deleted += DB::table('raw_ingestion_objects')->whereIn('id', $objects->pluck('id'))->delete();
            }, 'o.id', 'id');

        return $deleted;
    }
}
