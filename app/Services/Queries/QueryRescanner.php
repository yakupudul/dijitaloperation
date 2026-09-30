<?php

namespace App\Services\Queries;

use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use Illuminate\Support\Facades\DB;

/**
 * Onaylı tarama: ALL filter terms (every sector) × ALL library queries, and the matching keywords re-run for every
 * query (manual / locked-cluster assignments untouched). The result is a review (one open at a time): queries that
 * contain a filter term (to delete) and queries whose service would change. Nothing changes before the operator
 * approves; only the checked lines are applied.
 */
final class QueryRescanner
{
    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly QueryServiceMatcher $matcher,
    ) {}

    public function scan(?int $userId): QueryReview
    {
        $this->normalizer->forget();
        $this->matcher->forget();
        // One open review at a time: an older unapplied one is replaced.
        QueryReview::query()->whereIn('status', [QueryReview::RUNNING, QueryReview::READY, QueryReview::FAILED])->delete();
        $review = QueryReview::query()->create(['status' => QueryReview::RUNNING, 'created_by' => $userId]);
        $deletions = 0;
        $changes = 0;
        DB::table('queries')->select(['id', 'text', 'sector_id', 'service_id', 'locked'])
            ->chunkById(QueryPipeline::CHUNK, function ($rows) use ($review, &$deletions, &$changes): void {
                $inLockedCluster = array_flip(DB::table('cluster_queries as cq')->join('clusters as c', 'c.id', '=', 'cq.cluster_id')
                    ->whereIn('cq.query_id', $rows->pluck('id')->all())->where('c.locked', true)->pluck('cq.query_id')->map(fn ($id): int => (int) $id)->all());
                $items = [];
                foreach ($rows as $row) {
                    $term = $this->normalizer->matchingTerm((string) $row->text);
                    $base = ['query_review_id' => $review->id, 'query_id' => (int) $row->id, 'term' => null, 'from_service_id' => null, 'to_service_id' => null];
                    if ($term !== null) {
                        $items[] = ['kind' => QueryReviewItem::DELETE, 'term' => mb_substr($term, 0, 200)] + $base;
                        $deletions++;

                        continue;
                    }
                    if ((bool) $row->locked || isset($inLockedCluster[(int) $row->id])) {
                        continue;
                    }
                    $current = $row->service_id !== null ? (int) $row->service_id : null;
                    $service = $this->matcher->match((string) $row->text, $row->sector_id !== null ? (int) $row->sector_id : null);
                    if ($service !== $current) {
                        $items[] = ['kind' => QueryReviewItem::SERVICE, 'from_service_id' => $current, 'to_service_id' => $service] + $base;
                        $changes++;
                    }
                }
                foreach (array_chunk($items, 500) as $chunk) {
                    DB::table('query_review_items')->insert($chunk);
                }
            });
        $review->forceFill(['status' => QueryReview::READY, 'deletions' => $deletions, 'changes' => $changes])->save();

        return $review;
    }

    /**
     * Applies the checked lines of a ready review: deletes (remembered, never come back through Bekleyenler) and
     * service changes (skipped when the query was locked meanwhile).
     *
     * @param  list<int>  $itemIds
     * @return array{deleted: int, changed: int}
     */
    public function apply(QueryReview $review, array $itemIds): array
    {
        abort_unless($review->status === QueryReview::READY, 409);
        $deleted = 0;
        $changed = 0;
        foreach (array_chunk(array_values(array_unique($itemIds)), QueryPipeline::CHUNK) as $chunk) {
            $items = QueryReviewItem::query()->where('query_review_id', $review->id)->whereIn('id', $chunk)->orderBy('id')->get();
            $deleted += QueryPipeline::deleteQueries($items->where('kind', QueryReviewItem::DELETE)->pluck('query_id')->map(fn ($id): int => (int) $id)->values()->all(), remember: true);
            $moved = [];
            foreach ($items->where('kind', QueryReviewItem::SERVICE) as $item) {
                $updated = DB::table('queries')->where('id', $item->query_id)->where('locked', false)->update([
                    'service_id' => $item->to_service_id, 'assignment' => $item->to_service_id === null ? 'none' : 'rule', 'updated_at' => now(),
                ]);
                if ($updated > 0) {
                    $moved[] = (int) $item->query_id;
                    $changed++;
                }
            }
            QueryPipeline::dropForeignMemberships($moved);
        }
        $review->forceFill(['status' => QueryReview::APPLIED, 'applied_at' => now()])->save();
        $review->items()->delete();

        return ['deleted' => $deleted, 'changed' => $changed];
    }
}
