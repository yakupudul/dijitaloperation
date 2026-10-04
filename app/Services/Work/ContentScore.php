<?php

namespace App\Services\Work;

use App\Enums\OfferingStatus;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\Suggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İçerik fikri öncelik puanı (yakup, 2026-10-02): one rule-based 1–100 score per content idea, so the titles of every
 * brand sort in one list without a weekly cap. No AI:
 *
 *   Puan = 100 × (0,35 × Talep + 0,25 × Hizmet + 0,25 × Boşluk + 0,15 × Niyet)
 *
 * - Talep: the cluster's demand (monthly search volume of its queries, or the brand's Search Console impressions on
 *   it, whichever is larger) on a log scale against the brand's own largest cluster, so a big-city brand does not
 *   outrank a small-city one by size alone. No demand data at all → 0,5 ("talep verisi yok").
 * - Hizmet: ★ service of the brand 1, other active service 0,6, no service of the brand 0,3.
 * - Boşluk: a new page for a cluster without a good page 1; an update of a page with page score < 40 (or none) 0,8,
 *   40–70 0,5, > 70 0,1; a new extra page for a cluster whose page already scores > 70 0,5.
 * - Niyet: hizmet / lokasyon page 1, SSS 0,7, rehber / blog 0,5.
 */
final class ContentScore
{
    public const array WEIGHTS = ['demand' => 0.35, 'service' => 0.25, 'gap' => 0.25, 'intent' => 0.15];

    public const array LABELS = ['demand' => 'Talep', 'service' => 'Hizmet', 'gap' => 'Boşluk', 'intent' => 'Niyet'];

    private const array INTENT = ['hizmet' => 1.0, 'lokasyon' => 1.0, 'sss' => 0.7, 'blog' => 0.5];

    /**
     * @param  Collection<int, Suggestion>  $suggestions  content ideas (action_type content)
     * @return array<int, array{score: int, parts: array<string, float>, notes: list<string>}> suggestion id => score
     */
    public function forSuggestions(Collection $suggestions): array
    {
        if ($suggestions->isEmpty()) {
            return [];
        }
        $brandIds = $suggestions->pluck('brand_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $rows = DB::table('brand_cluster_pages')->whereIn('brand_id', $brandIds)
            ->select(['id', 'brand_id', 'cluster_id', 'page_id', 'impressions_28d'])->get();
        $clusterIds = $rows->pluck('cluster_id')->merge($suggestions->pluck('cluster_id'))->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $volumes = DB::table('cluster_queries')->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->whereIn('cluster_queries.cluster_id', $clusterIds ?: [0])->groupBy('cluster_queries.cluster_id')
            ->selectRaw('cluster_queries.cluster_id as cluster_id, sum(coalesce(queries.volume, 0)) as volume')->pluck('volume', 'cluster_id');
        $clusterServices = Cluster::query()->whereIn('id', $clusterIds ?: [0])->pluck('service_id', 'id');
        $pageScores = DB::table('cluster_page_scores')->whereIn('brand_cluster_page_id', $rows->pluck('id')->all() ?: [0])
            ->where('state', 'scored')->pluck('score', 'brand_cluster_page_id');
        $offerings = BrandOffering::query()->whereIn('brand_id', $brandIds)->where('status', OfferingStatus::Active->value)->get()
            ->groupBy('brand_id')->map(fn (Collection $list): Collection => $list->keyBy('service_catalog_item_id'));

        $rowOf = $rows->keyBy(fn (object $row): string => $row->brand_id.'|'.$row->cluster_id);
        $demandOf = fn (int $brandId, int $clusterId): ?float => $this->demand($rowOf->get($brandId.'|'.$clusterId), $volumes->get($clusterId));
        $brandMax = $rows->groupBy('brand_id')->map(fn (Collection $list): float => (float) $list->map(fn (object $row): float => $demandOf((int) $row->brand_id, (int) $row->cluster_id) ?? 0.0)->max());

        $out = [];
        foreach ($suggestions as $suggestion) {
            $brandId = (int) $suggestion->brand_id;
            $action = (array) $suggestion->action;
            $clusterId = $suggestion->cluster_id !== null ? (int) $suggestion->cluster_id : null;
            $row = $clusterId !== null ? $rowOf->get($brandId.'|'.$clusterId) : null;
            $notes = [];

            $demand = $clusterId !== null ? $demandOf($brandId, $clusterId) : null;
            $max = (float) ($brandMax->get($brandId) ?? 0.0);
            if ($demand === null || ($demand <= 0 && $max <= 0)) {
                $demandPart = 0.5;
                $notes[] = 'talep verisi yok';
            } else {
                $demandPart = log(1 + max(0.0, $demand)) / log(1 + max($demand, $max));
            }

            $serviceId = $clusterId !== null ? $clusterServices->get($clusterId) : null;
            $serviceId ??= $action['service_id'] ?? null;
            $offering = $serviceId !== null ? $offerings->get($brandId)?->get((int) $serviceId) : null;
            $servicePart = $offering === null ? 0.3 : ($offering->isMain() ? 1.0 : 0.6);

            $pageScore = $row !== null ? $pageScores->get($row->id) : null;
            if (($action['kind'] ?? 'new') === 'update') {
                $gapPart = $pageScore === null || $pageScore < 40 ? 0.8 : ($pageScore <= 70 ? 0.5 : 0.1);
            } else {
                $gapPart = $row !== null && $row->page_id !== null && $pageScore !== null && $pageScore > 70 ? 0.5 : 1.0;
            }

            $intentPart = self::INTENT[(string) ($action['page_type'] ?? 'blog')] ?? 0.5;

            $parts = ['demand' => round($demandPart, 2), 'service' => $servicePart, 'gap' => $gapPart, 'intent' => $intentPart];
            $total = 0.0;
            foreach (self::WEIGHTS as $key => $weight) {
                $total += $weight * $parts[$key];
            }
            $out[(int) $suggestion->id] = ['score' => max(1, min(100, (int) round(100 * $total))), 'parts' => $parts, 'notes' => $notes];
        }

        return $out;
    }

    /** One line for the score chip's tooltip: "Talep 0,85 · Hizmet 1 · Boşluk 1 · Niyet 1". */
    public static function explain(array $scored): string
    {
        $line = implode(' · ', array_map(fn (string $key): string => self::LABELS[$key].' '.str_replace('.', ',', (string) $scored['parts'][$key]), array_keys(self::WEIGHTS)));

        return $scored['notes'] !== [] ? $line.' ('.implode(', ', $scored['notes']).')' : $line;
    }

    private function demand(?object $row, mixed $volume): ?float
    {
        $impressions = $row !== null ? (float) ($row->impressions_28d ?? 0) : null;
        $volume = $volume !== null ? (float) $volume : null;
        if ($impressions === null && $volume === null) {
            return null;
        }

        return max($impressions ?? 0.0, $volume ?? 0.0);
    }
}
