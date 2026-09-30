<?php

namespace App\Services\Queries;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Models\PendingQuery;
use Illuminate\Support\Facades\DB;

/**
 * Bekleyenler: approve ("Seçilenleri içe aktar" → library queries, service from the matching keywords, then the
 * pipeline links their sources and totals) or dismiss ("Yoksay" → remembered, never shown again). The queue holds only
 * texts that are NOT in the library and that NO filter term would delete (prune: after every pipeline run and every
 * filter / keyword rescan); a text pruned for a filter term comes back on the next run if the term is removed.
 */
final class PendingQueries
{
    public function __construct(
        private readonly QueryServiceMatcher $matcher,
        private readonly QueryNormalizer $normalizer,
    ) {}

    /** Removes pending rows already in the library or caught by a filter term (current basket). */
    public function prune(): int
    {
        $this->normalizer->forget();
        $removed = 0;
        DB::table('pending_queries')->where('status', PendingQuery::PENDING)->select(['id', 'text', 'text_hash'])
            ->chunkById(QueryPipeline::CHUNK, function ($rows) use (&$removed): void {
                $inLibrary = array_flip(DB::table('queries')->whereIn('text_hash', $rows->pluck('text_hash')->all())->pluck('text_hash')->all());
                $ids = $rows->filter(fn (object $row): bool => isset($inLibrary[$row->text_hash]) || $this->normalizer->matchingTerm((string) $row->text) !== null)
                    ->pluck('id')->all();
                if ($ids !== []) {
                    $removed += DB::table('pending_queries')->whereIn('id', $ids)->delete();
                }
            });

        return $removed;
    }

    /** @param list<int> $ids */
    public function import(array $ids): int
    {
        $this->normalizer->forget();
        $imported = 0;
        foreach (array_chunk(array_values(array_unique($ids)), QueryPipeline::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, &$imported): void {
                $rows = PendingQuery::query()->whereIn('id', $chunk)->where('status', PendingQuery::PENDING)->orderBy('id')->get();
                $now = now();
                $insert = $rows->filter(fn (PendingQuery $row): bool => $this->normalizer->matchingTerm($row->text) === null)->map(function (PendingQuery $row) use ($now): array {
                    $service = $this->matcher->match($row->text, $row->sector_id !== null ? (int) $row->sector_id : null);

                    return [
                        'text' => $row->text, 'text_hash' => $row->text_hash, 'sector_id' => $row->sector_id, 'service_id' => $service,
                        'assignment' => $service === null ? 'none' : 'rule', 'impressions' => $row->impressions, 'clicks' => $row->clicks,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                })->values()->all();
                if ($insert !== []) {
                    $imported += DB::table('queries')->insertOrIgnore($insert);
                }
                PendingQuery::query()->whereIn('id', $rows->pluck('id'))->delete();
            });
        }
        if ($imported > 0) {
            ProcessQueriesJob::dispatch();
        }

        return $imported;
    }

    /** @param list<int> $ids */
    public function dismiss(array $ids): int
    {
        $dismissed = 0;
        foreach (array_chunk(array_values(array_unique($ids)), QueryPipeline::CHUNK) as $chunk) {
            $dismissed += PendingQuery::query()->whereIn('id', $chunk)->where('status', PendingQuery::PENDING)
                ->update(['status' => PendingQuery::DISMISSED, 'updated_at' => now()]);
        }

        return $dismissed;
    }
}
