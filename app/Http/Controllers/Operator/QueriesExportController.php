<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Queries\QueryRuleEngine;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sorgular › "CSV indir": every library query (hidden and AI-suggested included) with its sector, service, cluster,
 * metrics and rule-engine keys — the operator sends this file to have query rules and filter terms written.
 * Streamed in id order (UTF-8 with BOM, comma separated) so 200k rows never sit in memory.
 */
final class QueriesExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        $filename = 'sorgular-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'sorgu', 'sektor', 'hizmet', 'kume', 'gosterim', 'tiklama', 'ads_maliyet', 'kaynaklar', 'atama', 'gizli', 'onerilen',
                'varyant_anahtari', 'varyant_basi', 'konu_anahtari', 'yonler', 'kural_surumu']);
            $sectors = DB::table('service_categories')->pluck('name', 'id')->all();
            $services = DB::table('service_catalog_names')->where('is_primary', true)->pluck('raw_label', 'service_catalog_item_id')->all();
            $version = QueryRuleEngine::version();
            DB::table('queries as q')
                ->leftJoin('cluster_queries as cq', 'cq.query_id', '=', 'q.id')
                ->leftJoin('clusters as c', 'c.id', '=', 'cq.cluster_id')
                ->select(['q.id', 'q.text', 'q.sector_id', 'q.service_id', 'c.name as cluster', 'q.impressions', 'q.clicks', 'q.ads_cost', 'q.sources',
                    'q.assignment', 'q.hidden', 'q.is_suggested', 'q.variant_key', 'q.variant_head', 'q.topic_key', 'q.facets'])
                ->orderBy('q.id')
                ->chunkById(5000, function ($rows) use ($out, $sectors, $services, $version): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->id, $row->text, $sectors[$row->sector_id] ?? '', $services[$row->service_id] ?? '', $row->cluster ?? '',
                            $row->impressions, $row->clicks, $row->ads_cost, is_string($row->sources) ? $row->sources : '',
                            $row->assignment, $row->hidden ? 1 : 0, $row->is_suggested ? 1 : 0,
                            $row->variant_key ?? '', $row->variant_head ? 1 : 0, $row->topic_key ?? '', $row->facets ?? '', $version,
                        ]);
                    }
                    fflush($out);
                }, 'q.id', 'id');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
