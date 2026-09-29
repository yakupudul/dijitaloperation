<?php

namespace App\Services\Queries;

use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator edits of clusters (rename, approve, split, merge, move queries, delete). Every edited cluster is locked:
 * "AI ile kümele" never touches it again. Moves stay within one sector + service.
 */
final class ClusterEditor
{
    public function rename(Cluster $cluster, string $name): Cluster
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            throw ValidationException::withMessages(['clusterName' => 'Küme adı 2–200 karakter olmalı.']);
        }
        $cluster->forceFill(['name' => $name, 'locked' => true])->save();

        return $cluster;
    }

    public function approve(Cluster $cluster): Cluster
    {
        $cluster->forceFill(['approved' => true, 'locked' => true])->save();

        return $cluster;
    }

    /**
     * Moves queries (of clusters of the same sector + service) into the target cluster.
     *
     * @param  list<int>  $queryIds
     */
    public function move(array $queryIds, Cluster $target): int
    {
        return DB::transaction(function () use ($queryIds, $target): int {
            $links = ClusterQuery::query()->with('cluster')->whereIn('query_id', $queryIds)->get()
                ->filter(fn (ClusterQuery $link): bool => $link->cluster !== null && $link->cluster->sector_id === $target->sector_id
                    && $link->cluster->service_id === $target->service_id && $link->cluster_id !== $target->id);
            if ($links->isEmpty()) {
                return 0;
            }
            $sources = $links->pluck('cluster_id')->unique()->all();
            ClusterQuery::query()->whereIn('id', $links->pluck('id'))->update(['cluster_id' => $target->id, 'updated_at' => now()]);
            $target->forceFill(['locked' => true])->save();
            foreach (Cluster::query()->whereIn('id', $sources)->get() as $source) {
                $source->forceFill(['locked' => true])->save();
                $this->repair($source);
            }
            $this->repair($target);

            return $links->count();
        });
    }

    /**
     * Selected queries of the cluster become a new (locked) cluster with the same intent / page type.
     *
     * @param  list<int>  $queryIds
     */
    public function split(Cluster $cluster, array $queryIds, string $name): Cluster
    {
        $ids = ClusterQuery::query()->where('cluster_id', $cluster->id)->whereIn('query_id', $queryIds)->pluck('query_id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['selectedClusterQueries' => 'Ayırmak için kümeden sorgu seçin.']);
        }
        if (count($ids) === $cluster->clusterQueries()->count()) {
            throw ValidationException::withMessages(['selectedClusterQueries' => 'Tüm sorgular seçili; en az biri kümede kalmalı.']);
        }
        $name = trim($name) !== '' ? trim($name) : $cluster->name.' (2)';

        return DB::transaction(function () use ($cluster, $ids, $name): Cluster {
            $new = $this->rename(Cluster::query()->create([
                'sector_id' => $cluster->sector_id, 'service_id' => $cluster->service_id, 'name' => $name,
                'intent' => $cluster->intent, 'page_type' => $cluster->page_type, 'locked' => true,
            ]), $name);
            $this->move($ids, $new);

            return $new->refresh();
        });
    }

    /**
     * Other clusters (same sector + service) are merged into the target and deleted.
     *
     * @param  list<int>  $otherIds
     */
    public function merge(Cluster $target, array $otherIds): int
    {
        return DB::transaction(function () use ($target, $otherIds): int {
            $others = Cluster::query()->whereIn('id', $otherIds)->whereKeyNot($target->id)
                ->where('sector_id', $target->sector_id)->where('service_id', $target->service_id)->get();
            foreach ($others as $other) {
                ClusterQuery::query()->where('cluster_id', $other->id)->update(['cluster_id' => $target->id, 'updated_at' => now()]);
                $target->subtopics = array_values(array_unique(array_merge((array) $target->subtopics, (array) $other->subtopics)));
                $other->delete();
            }
            $target->forceFill(['locked' => true])->save();
            $this->repair($target);

            return $others->count();
        });
    }

    public function delete(Cluster $cluster): void
    {
        DB::transaction(function () use ($cluster): void {
            $suggested = ClusterQuery::query()->where('cluster_id', $cluster->id)->where('is_suggested', true)->pluck('query_id');
            $cluster->delete();
            Query::query()->whereIn('id', $suggested)->where('is_suggested', true)->delete();
        });
    }

    /** Main / representative queries must stay members of the cluster. */
    private function repair(Cluster $cluster): void
    {
        $members = ClusterQuery::query()->where('cluster_id', $cluster->id)->pluck('query_id')->map(fn ($id): int => (int) $id)->all();
        $main = in_array((int) $cluster->main_query_id, $members, true) ? (int) $cluster->main_query_id : null;
        $main ??= Query::query()->whereIn('id', $members)->where('is_suggested', false)->orderByDesc('impressions')->orderBy('id')->value('id');
        $cluster->forceFill([
            'main_query_id' => $main,
            'representative_query_ids' => array_values(array_intersect(array_map('intval', (array) $cluster->representative_query_ids), $members)),
        ])->save();
    }
}
