<?php

namespace App\Services\ContentStudio;

use App\Models\TopicCluster;
use App\Models\TopicClusterQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator edits of the topic map (Faz 3): rename, skip, merge, split and move a query. The queries of every cluster the
 * operator merged, split or moved into / out of are pinned, so later rebuilds keep them where the operator put them
 * (new queries may still join); renamed / skipped clusters keep their label / status.
 */
final class TopicMapEditor
{
    public function __construct(private readonly TopicMapBuilder $builder) {}

    public function rename(TopicCluster $cluster, string $label, ?User $user): void
    {
        $label = mb_substr(trim(strip_tags($label)), 0, 200);
        if ($label === '') {
            throw ValidationException::withMessages(['cluster' => 'Küme adı boş olamaz.']);
        }
        $cluster->forceFill(['label' => $label, 'label_source' => 'operator', 'edited_by' => $user?->id, 'edited_at' => now()])->save();
    }

    public function skip(TopicCluster $cluster, bool $skip, ?User $user): void
    {
        if (! in_array($cluster->status, ['active', 'skipped'], true)) {
            throw ValidationException::withMessages(['cluster' => 'Bu küme artık haritada değil.']);
        }
        $cluster->forceFill(['status' => $skip ? 'skipped' : 'active', 'edited_by' => $user?->id, 'edited_at' => now()])->save();
    }

    /** Every query of $source moves (pinned) into $target; $source is kept as "merged" for history. */
    public function merge(TopicCluster $source, TopicCluster $target, ?User $user): void
    {
        $this->sameSite($source, $target);
        if ($source->is($target)) {
            throw ValidationException::withMessages(['cluster' => 'Bir küme kendisiyle birleştirilemez.']);
        }
        DB::transaction(function () use ($source, $target, $user): void {
            TopicClusterQuery::query()->where('topic_cluster_id', $source->id)->update(['topic_cluster_id' => $target->id, 'pinned' => true, 'is_head' => false, 'updated_at' => now()]);
            TopicClusterQuery::query()->where('topic_cluster_id', $target->id)->update(['pinned' => true]);
            $source->forceFill(['status' => 'merged', 'merged_into_id' => $target->id, 'query_count' => 0, 'edited_by' => $user?->id, 'edited_at' => now()])->save();
            $target->forceFill(['edited_by' => $user?->id, 'edited_at' => now()])->save();
        });
        $this->builder->reassess($target->refresh());
    }

    /**
     * The chosen queries become a new cluster (operator-made, same service).
     *
     * @param  list<int>  $queryIds  topic_cluster_queries ids
     */
    public function split(TopicCluster $cluster, array $queryIds, string $label, ?User $user): TopicCluster
    {
        $rows = TopicClusterQuery::query()->where('topic_cluster_id', $cluster->id)->whereIn('id', $queryIds ?: [0])->get();
        if ($rows->isEmpty() || $rows->count() >= $cluster->queries()->count()) {
            throw ValidationException::withMessages(['cluster' => 'Ayırmak için kümenin bir kısmını seç (hepsini değil).']);
        }
        $label = mb_substr(trim(strip_tags($label)), 0, 200);
        $new = DB::transaction(function () use ($cluster, $rows, $label, $user): TopicCluster {
            $new = TopicCluster::query()->create([
                'brand_id' => $cluster->brand_id, 'digital_asset_id' => $cluster->digital_asset_id, 'brand_offering_id' => $cluster->brand_offering_id,
                'label' => $label !== '' ? $label : TopicText::label((string) $rows->sortByDesc('demand')->first()->query), 'label_source' => $label !== '' ? 'operator' : 'auto',
                'origin' => 'operator', 'intent' => $cluster->intent, 'page_type' => $cluster->page_type, 'status' => 'active', 'version' => $cluster->version,
                'edited_by' => $user?->id, 'edited_at' => now(),
            ]);
            TopicClusterQuery::query()->whereIn('id', $rows->pluck('id'))->update(['topic_cluster_id' => $new->id, 'pinned' => true, 'is_head' => false, 'updated_at' => now()]);
            TopicClusterQuery::query()->where('topic_cluster_id', $cluster->id)->update(['pinned' => true]);

            return $new;
        });
        $this->builder->reassess($cluster->refresh());

        return $this->builder->reassess($new->refresh());
    }

    /** One query moves (pinned) to another cluster of the same website. */
    public function moveQuery(TopicClusterQuery $query, TopicCluster $target, ?User $user): void
    {
        $source = TopicCluster::query()->findOrFail($query->topic_cluster_id);
        $this->sameSite($source, $target);
        if (! in_array($target->status, ['active', 'skipped'], true)) {
            throw ValidationException::withMessages(['cluster' => 'Hedef küme artık haritada değil.']);
        }
        $query->forceFill(['topic_cluster_id' => $target->id, 'pinned' => true, 'is_head' => false])->save();
        // The operator judged both clusters: their current membership is kept as is on rebuilds.
        TopicClusterQuery::query()->whereIn('topic_cluster_id', [$source->id, $target->id])->update(['pinned' => true]);
        $target->forceFill(['edited_by' => $user?->id, 'edited_at' => now()])->save();
        $this->builder->reassess($source->refresh());
        $this->builder->reassess($target->refresh());
    }

    private function sameSite(TopicCluster $a, TopicCluster $b): void
    {
        if ((int) $a->digital_asset_id !== (int) $b->digital_asset_id) {
            throw ValidationException::withMessages(['cluster' => 'Kümeler aynı siteye ait olmalı.']);
        }
    }
}
