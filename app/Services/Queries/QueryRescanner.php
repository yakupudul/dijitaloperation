<?php

namespace App\Services\Queries;

use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use Illuminate\Support\Facades\DB;

/**
 * Onaylı tarama: ALL filter terms (every sector) × ALL library queries, and the matching keywords re-run for every
 * query (manual / locked-cluster assignments untouched). Proposals go into ONE permanent pool (Sorgular ›
 * Silinecekler): queries that contain a filter term (to delete) and queries whose service would change — one line per
 * query, the latest scan wins, lines no scan proposes any more leave. Nothing changes before the operator approves
 * ("Onayla ve sil"); "Tut" keeps a line out of the pool until its proposal changes (another term / another service).
 */
final class QueryRescanner
{
    /** Assignments the keyword rules never change (operator's choice, AI proposal the operator approved). */
    public const array KEPT_ASSIGNMENTS = ['manual', 'ai'];

    /** @var array<int, string> sector id → code (during a scan) */
    private array $sectorCodes = [];

    /** @var array<int, string> service id → its sector code (during a scan) */
    private array $serviceSectors = [];

    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly QueryServiceMatcher $matcher,
        private readonly PendingQueries $pending,
    ) {}

    public function scan(?int $userId): QueryReview
    {
        $this->normalizer->forget();
        $this->matcher->forget();
        // Bekleyenler follows the current filter basket right away (no approval: nothing is in the library yet).
        $this->pending->prune();
        $review = QueryReview::query()->create(['status' => QueryReview::RUNNING, 'created_by' => $userId]);
        $deletions = 0;
        $changes = 0;
        $this->sectorCodes = DB::table('service_categories')->pluck('code', 'id')->mapWithKeys(fn ($code, $id): array => [(int) $id => (string) $code])->all();
        $this->serviceSectors = DB::table('service_catalog_items')->pluck('sector', 'id')->mapWithKeys(fn ($code, $id): array => [(int) $id => (string) $code])->all();
        DB::table('queries')->select(['id', 'text', 'sector_id', 'service_id', 'locked', 'assignment'])
            ->chunkById(QueryPipeline::CHUNK, function ($rows) use ($review, &$deletions, &$changes): void {
                $ids = $rows->pluck('id')->all();
                $inLockedCluster = array_flip(DB::table('cluster_queries as cq')->join('clusters as c', 'c.id', '=', 'cq.cluster_id')
                    ->whereIn('cq.query_id', $ids)->where('c.locked', true)->pluck('cq.query_id')->map(fn ($id): int => (int) $id)->all());
                $existing = DB::table('query_review_items')->whereIn('query_id', $ids)->get(['query_id', 'kind', 'term', 'reason', 'to_service_id', 'kept_at'])
                    ->keyBy(fn (object $row): int => (int) $row->query_id);
                $items = [];
                foreach ($rows as $row) {
                    $item = $this->proposal($row, isset($inLockedCluster[(int) $row->id]));
                    if ($item === null) {
                        continue;
                    }
                    $old = $existing->get((int) $row->id);
                    $kept = $old !== null && $old->kept_at !== null && self::signature((array) $old) === self::signature($item) ? $old->kept_at : null;
                    $items[] = $item + ['query_review_id' => $review->id, 'query_id' => (int) $row->id, 'kept_at' => $kept];
                    if ($kept === null) {
                        $item['kind'] === QueryReviewItem::DELETE ? $deletions++ : $changes++;
                    }
                }
                foreach (array_chunk($items, 500) as $chunk) {
                    DB::table('query_review_items')->upsert($chunk, ['query_id'], ['query_review_id', 'kind', 'term', 'reason', 'from_service_id', 'to_service_id', 'kept_at']);
                }
            });
        // Kelime önerileri / Çakışmalar follow the new assignments.
        KeywordInsights::forgetSuggestions();
        // Lines this (full) scan did not propose again are stale; emptied older scans go with them.
        DB::table('query_review_items')->where('query_review_id', '<', $review->id)->delete();
        QueryReview::query()->where('id', '<', $review->id)->whereNotIn('status', [QueryReview::RUNNING])->whereDoesntHave('items')->delete();
        $review->forceFill(['status' => QueryReview::READY, 'deletions' => $deletions, 'changes' => $changes])->save();

        return $review;
    }

    /** @return array{delete: int, service: int} open (not kept) lines of the pool */
    public static function openCounts(): array
    {
        $counts = DB::table('query_review_items')->whereNull('kept_at')->groupBy('kind')->selectRaw('kind, count(*) as total')->pluck('total', 'kind');

        return ['delete' => (int) ($counts[QueryReviewItem::DELETE] ?? 0), 'service' => (int) ($counts[QueryReviewItem::SERVICE] ?? 0)];
    }

    /**
     * Applies pool lines: deletes (remembered, never come back through Bekleyenler) — only while a filter term still
     * matches — and service changes — only while the query is unlocked and still has the proposed "from" service. The
     * lines leave the pool either way (a stale one is proposed again by the next scan if still valid). Kept lines are
     * skipped.
     *
     * @param  list<int>  $itemIds
     * @return array{deleted: int, changed: int}
     */
    public function apply(array $itemIds): array
    {
        $this->normalizer->forget();
        $deleted = 0;
        $changed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $itemIds))), QueryPipeline::CHUNK) as $chunk) {
            $items = QueryReviewItem::query()->whereIn('id', $chunk)->whereNull('kept_at')->with('searchQuery:id,text')->orderBy('id')->get();
            $toDelete = $items->where('kind', QueryReviewItem::DELETE)
                ->filter(fn (QueryReviewItem $item): bool => $item->searchQuery !== null && $this->normalizer->matchingTerm((string) $item->searchQuery->text) !== null)
                ->pluck('query_id')->map(fn ($id): int => (int) $id)->values()->all();
            $moved = [];
            foreach ($items->where('kind', QueryReviewItem::SERVICE) as $item) {
                $updated = DB::table('queries')->where('id', $item->query_id)->where('locked', false)
                    ->where(fn ($q) => $q->whereNull('assignment')->orWhereNotIn('assignment', self::KEPT_ASSIGNMENTS))
                    ->when($item->from_service_id === null, fn ($q) => $q->whereNull('service_id'), fn ($q) => $q->where('service_id', $item->from_service_id))
                    ->update(['service_id' => $item->to_service_id, 'assignment' => $item->to_service_id === null ? 'none' : 'rule', 'cluster_checked_at' => null, 'updated_at' => now()]);
                if ($updated > 0) {
                    $moved[] = (int) $item->query_id;
                    $changed++;
                }
            }
            DB::table('query_review_items')->whereIn('id', $items->pluck('id')->all() ?: [0])->delete();
            $deleted += QueryPipeline::deleteQueries($toDelete, remember: true);
            QueryPipeline::dropForeignMemberships($moved);
        }

        return ['deleted' => $deleted, 'changed' => $changed];
    }

    /**
     * "Tut" (keep = true): the lines leave the open list; a later scan brings a line back only when its proposal
     * changes. "Geri al" (keep = false) reopens kept lines.
     *
     * @param  list<int>  $itemIds
     */
    public function keep(array $itemIds, bool $keep = true): int
    {
        $count = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $itemIds))), QueryPipeline::CHUNK) as $chunk) {
            $count += DB::table('query_review_items')->whereIn('id', $chunk)
                ->when($keep, fn ($q) => $q->whereNull('kept_at'), fn ($q) => $q->whereNotNull('kept_at'))
                ->update(['kept_at' => $keep ? now() : null]);
        }

        return $count;
    }

    /** @return array{kind: string, term: ?string, reason: ?string, from_service_id: ?int, to_service_id: ?int}|null */
    private function proposal(object $row, bool $inLockedCluster): ?array
    {
        $term = $this->normalizer->matchingTerm((string) $row->text);
        if ($term !== null) {
            return ['kind' => QueryReviewItem::DELETE, 'term' => mb_substr($term, 0, 200), 'reason' => null, 'from_service_id' => null, 'to_service_id' => null];
        }
        // Only keyword-rule assignments follow the matching keywords; a service set by the operator or by AI stays.
        if ((bool) $row->locked || $inLockedCluster || in_array($row->assignment ?? null, self::KEPT_ASSIGNMENTS, true)) {
            return null;
        }
        $current = $row->service_id !== null ? (int) $row->service_id : null;
        $sectorId = $row->sector_id !== null ? (int) $row->sector_id : null;
        $match = $this->matcher->matchWithKeyword((string) $row->text, $sectorId);
        if ($match['service'] === $current) {
            return null;
        }
        // `term` of a service line = the matching keyword that decided it (null: no keyword matches any more), or the
        // conflicting keywords ("a / b") when two services' keywords hit the query.
        $term = $match['keyword'];
        $reason = null;
        if ($match['conflicts'] !== []) {
            $term = implode(' / ', array_column($match['conflicts'], 'keyword'));
            $reason = QueryReviewItem::REASON_CONFLICT;
        } elseif ($current !== null && $sectorId !== null && ($this->serviceSectors[$current] ?? '') !== ''
            && isset($this->sectorCodes[$sectorId]) && $this->serviceSectors[$current] !== $this->sectorCodes[$sectorId]) {
            $reason = QueryReviewItem::REASON_SECTOR;
        }

        return ['kind' => QueryReviewItem::SERVICE, 'term' => $term !== null ? mb_substr($term, 0, 200) : null, 'reason' => $reason,
            'from_service_id' => $current, 'to_service_id' => $match['service']];
    }

    /** @param array<string, mixed> $item what "Tut" was decided on: the term to delete for, or the target service */
    private static function signature(array $item): string
    {
        return $item['kind'] === QueryReviewItem::DELETE ? 'd:'.$item['term'] : 's:'.($item['to_service_id'] ?? '').($item['reason'] === QueryReviewItem::REASON_CONFLICT ? ':c' : '');
    }
}
