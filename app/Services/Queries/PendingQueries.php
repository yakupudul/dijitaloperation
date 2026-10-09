<?php

namespace App\Services\Queries;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Models\PendingQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bekleyenler: approve ("Seçilenleri içe aktar" → library queries, service from the matching keywords, then the
 * pipeline links their sources and totals) or dismiss ("Yoksay" → remembered, never shown again). The queue holds only
 * texts that are NOT in the library and that NO filter term would delete (prune: after every pipeline run and every
 * filter / keyword rescan, on every filter basket change, on the Sorgular page load when the basket / library / queue changed, hourly on
 * the schedule and for the visible page on every render); a text pruned for a filter term comes back on the next run if
 * the term is removed.
 */
final class PendingQueries
{
    private const string PRUNED_KEY = 'queries:pending:pruned-at';

    public function __construct(
        private readonly QueryServiceMatcher $matcher,
        private readonly QueryNormalizer $normalizer,
    ) {}

    /**
     * Removes pending rows already in the library or caught by a filter term (current basket). "In the library" is
     * checked on the library's normalized form: the stored hash, the hash of the re-normalized text (rows written by an
     * older normalization: mixed case, Turkish İ / I, extra spaces, punctuation) and the normalized text itself.
     *
     * @param  list<int>|null  $ids  only these rows (the visible page); null = the whole queue
     */
    public function prune(?array $ids = null): int
    {
        $this->normalizer->forget();
        $removed = 0;
        DB::table('pending_queries')->where('status', PendingQuery::PENDING)->select(['id', 'text', 'text_hash', 'sector_id'])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->chunkById(QueryPipeline::CHUNK, function ($rows) use (&$removed): void {
                $normalized = $rows->mapWithKeys(fn (object $row): array => [(int) $row->id => $this->normalizer->normalize((string) $row->text)]);
                $hashes = $rows->pluck('text_hash')->merge($normalized->map(fn (string $text): string => QueryNormalizer::hash($text)))->unique()->values()->all();
                $inLibrary = array_flip(DB::table('queries')->whereIn('text_hash', $hashes)->pluck('text_hash')->all());
                $texts = array_flip(DB::table('queries')->whereIn('text', $normalized->filter()->unique()->values()->all() ?: [''])->pluck('text')->all());
                $remove = $rows->filter(function (object $row) use ($inLibrary, $texts, $normalized): bool {
                    $text = $normalized[(int) $row->id];

                    return isset($inLibrary[$row->text_hash]) || isset($inLibrary[QueryNormalizer::hash($text)]) || isset($texts[$text])
                        || $this->normalizer->matchingTerm((string) $row->text, $row->sector_id !== null ? (int) $row->sector_id : null) !== null;
                })->pluck('id')->all();
                if ($remove !== []) {
                    $removed += DB::table('pending_queries')->whereIn('id', $remove)->delete();
                }
            });
        if ($ids === null) {
            Cache::put(self::PRUNED_KEY, self::fingerprint(), now()->addDay());
        }

        return $removed;
    }

    /**
     * Whole-queue prune on the Sorgular page load, skipped while nothing it depends on changed since the last one (same
     * filter terms, library and queue); filter changes call prune() directly.
     */
    public function pruneIfChanged(): int
    {
        return Cache::get(self::PRUNED_KEY) === self::fingerprint() ? 0 : $this->prune();
    }

    /** Filter basket, library and pending queue state (counts, last ids, last term change). */
    private static function fingerprint(): string
    {
        $terms = DB::table('filter_terms')->selectRaw('count(*) as total, max(id) as last, max(updated_at) as changed')->first();
        $library = DB::table('queries')->selectRaw('count(*) as total, max(id) as last')->first();
        $pending = DB::table('pending_queries')->where('status', PendingQuery::PENDING)->selectRaw('count(*) as total, max(id) as last')->first();

        return implode('|', [$terms->total ?? 0, $terms->last ?? 0, $terms->changed ?? '', $library->total ?? 0, $library->last ?? 0, $pending->total ?? 0, $pending->last ?? 0]);
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
                $insert = $rows->filter(fn (PendingQuery $row): bool => $this->normalizer->matchingTerm($row->text, $row->sector_id !== null ? (int) $row->sector_id : null) === null && $this->normalizer->normalize($row->text) !== '')->map(function (PendingQuery $row) use ($now): array {
                    $service = $this->matcher->match($row->text, $row->sector_id !== null ? (int) $row->sector_id : null);
                    $text = $this->normalizer->normalize($row->text);

                    return [
                        'text' => $text, 'text_hash' => QueryNormalizer::hash($text), 'sector_id' => $row->sector_id, 'service_id' => $service,
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
