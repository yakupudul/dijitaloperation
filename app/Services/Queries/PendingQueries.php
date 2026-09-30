<?php

namespace App\Services\Queries;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Models\PendingQuery;
use Illuminate\Support\Facades\DB;

/**
 * Bekleyenler: approve ("Seçilenleri içe aktar" → library queries, service from the matching keywords, then the
 * pipeline links their sources and totals) or dismiss ("Yoksay" → remembered, never shown again).
 */
final class PendingQueries
{
    public function __construct(private readonly QueryServiceMatcher $matcher) {}

    /** @param list<int> $ids */
    public function import(array $ids): int
    {
        $imported = 0;
        foreach (array_chunk(array_values(array_unique($ids)), QueryPipeline::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, &$imported): void {
                $rows = PendingQuery::query()->whereIn('id', $chunk)->where('status', PendingQuery::PENDING)->orderBy('id')->get();
                $now = now();
                $insert = $rows->map(function (PendingQuery $row) use ($now): array {
                    $service = $this->matcher->match($row->text, $row->sector_id !== null ? (int) $row->sector_id : null);

                    return [
                        'text' => $row->text, 'text_hash' => $row->text_hash, 'sector_id' => $row->sector_id, 'service_id' => $service,
                        'assignment' => $service === null ? 'none' : 'rule', 'impressions' => $row->impressions, 'clicks' => $row->clicks,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                })->all();
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
