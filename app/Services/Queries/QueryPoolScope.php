<?php

namespace App\Services\Queries;

use App\Models\PendingQuery;
use App\Models\Query;
use Illuminate\Support\Facades\DB;

/**
 * Which accounts feed the query pool (admin audit 2026-11-07 V6; brandless-sites rule, yakup 2026-10-20): only an
 * account bound to an asset of a brand. An unbound account (an old customer's property under the agency's access),
 * an account of a brandless website and a brand candidate's account never reach `query_sources`, so their queries
 * never sit in the library as "Diğer / atanmamış" (10.658 of the 11.300 unassigned queries were theirs).
 *
 * Reversible: give the site a brand and the next collection (or `moxdop:queries:sources`) brings its queries back.
 * {@see self::prune()} runs hourly and removes what is already in the pool from accounts outside the scope, then the
 * library queries left without a source (AI-suggested ones stay) through the usual delete path.
 */
final class QueryPoolScope
{
    /**
     * Accounts bound (active binding) to an asset of a brand: asset and brand not deleted.
     *
     * @return list<int>
     */
    public static function resourceIds(): array
    {
        return DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('brands as br', 'br.id', '=', 'a.brand_id')
            ->where('b.status', 'active')->whereNull('a.deleted_at')->whereNull('br.deleted_at')
            ->distinct()->pluck('b.external_resource_id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function feeds(int $resourceId): bool
    {
        return DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('brands as br', 'br.id', '=', 'a.brand_id')
            ->where('b.external_resource_id', $resourceId)
            ->where('b.status', 'active')->whereNull('a.deleted_at')->whereNull('br.deleted_at')
            ->exists();
    }

    /**
     * Removes the pool rows of accounts outside the scope, their pending (Bekleyenler) texts, and the library queries
     * left without any source.
     *
     * @return array{sources: int, pending: int, queries: int}
     */
    public function prune(): array
    {
        $eligible = self::resourceIds() ?: [0];
        $sources = 0;
        DB::table('query_sources')->whereNotIn('external_resource_id', $eligible)->select('id')
            ->chunkById(QueryPipeline::CHUNK, function ($rows) use (&$sources): void {
                $sources += DB::table('query_sources')->whereIn('id', $rows->pluck('id')->all())->delete();
            });
        $pending = PendingQuery::query()->where('status', PendingQuery::PENDING)->whereNotIn('external_resource_id', $eligible)->delete();
        $orphans = [];
        Query::query()->where('is_suggested', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('query_sources')->whereColumn('query_sources.query_id', 'queries.id'))
            ->select('id')->chunkById(QueryPipeline::CHUNK, function ($rows) use (&$orphans): void {
                array_push($orphans, ...$rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
            });
        $queries = $orphans === [] ? 0 : QueryPipeline::deleteQueries($orphans, remember: false);

        return ['sources' => $sources, 'pending' => $pending, 'queries' => $queries];
    }
}
