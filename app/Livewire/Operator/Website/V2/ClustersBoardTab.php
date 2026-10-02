<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\UsesSiteRange;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ContentIdeaPool;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Web sitesi › Kümeler: the brand's approved clusters as cards, grouped by service (main services first). In a service the
 * ana küme (the service-page cluster with the most demand) comes first, then the alt kümeler; three cards show, the rest
 * open with "Tüm alt kümeler". A card: topic, Eşlendi / Eşlenmedi, sector demand, the site's impressions and position in
 * the screen's range, and two tabs — İçerik fikirleri (the cluster itself first as "Olmazsa olmaz", then the pool's
 * ideas; each with its page on the site and Geliştir / Oluştur) and Sorgular. Same actions as the detailed list
 * (ContentIdeasTab); every AI step runs on the queue and only on a click.
 */
class ClustersBoardTab extends ContentIdeasTab
{
    use UsesSiteRange;

    public const array MATCH = ['eslendi' => 'Eşlendi', 'eslenmedi' => 'Eşlenmedi'];

    /** Idea type → short label on the card. */
    public const array TYPE_LABELS = ['service' => 'Hizmet', 'guide' => 'Blog', 'faq' => 'SSS', 'comparison' => 'Karşılaştırma', 'location' => 'Lokasyon', 'other' => 'Diğer'];

    /** Cards shown per service before "Tüm alt kümeler". */
    public const int VISIBLE = 3;

    private const int QUERIES = 8;

    #[Url(as: 'eslesme')]
    public string $match = '';

    #[Url(as: 'ara')]
    public string $search = '';

    /** @param  array{days?: int, start?: ?string, end?: ?string, compare?: string}  $range */
    public function mount(int $assetId, array $range = []): void
    {
        parent::mount($assetId);
        $this->range = $range;
    }

    public function render(): View
    {
        $this->siteRange();
        $site = DigitalAsset::query()->with('brand')->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        [, $groups] = $this->clusterGroups($site);
        $reader = app(SiteAnalysisReader::class);
        $days = $this->siteRange()->days;
        $ours = collect($reader->clusters($site, $days))->keyBy('cluster_id');
        $siteQueries = collect($reader->queries($site, $days))->keyBy(fn (array $q): string => mb_strtolower(trim($q['query'])));
        $queries = $this->clusterQueries($groups->map(fn (array $g): int => (int) $g['cluster']->id)->values()->all());

        $offerings = $brand !== null ? SiteScope::offerings($brand) : collect();
        $services = $offerings->filter(fn ($o): bool => $o->service_catalog_item_id !== null)->unique('service_catalog_item_id')
            ->mapWithKeys(fn ($o): array => [(int) $o->service_catalog_item_id => ['name' => $o->displayName(), 'main' => $o->priority === 'main']]);
        $cards = $groups->map(fn (array $g): array => $this->card($g, $ours->get((int) $g['cluster']->id), $queries[(int) $g['cluster']->id] ?? [], $siteQueries))
            ->map(fn (array $c): array => $c + ['service_key' => $services->has($c['service_id']) ? $c['service_id'] : 0]);
        $sections = $this->sections($cards->filter(fn (array $c): bool => $this->visible($c))->values(), $services);
        $statuses = $this->statuses($groups);
        $ideaStatuses = $groups->mapWithKeys(fn (array $g): array => [(int) $g['cluster']->id => Cache::get(ContentIdeaPool::cacheKey((int) $g['cluster']->id))])->filter()->all();
        $auditStatus = SiteOperations::line(SiteOperations::status($site->id, SiteOperations::CLUSTER_AUDIT));

        return view('livewire.operator.website.v2.clusters-board-tab', [
            'brand' => $brand,
            'sections' => $sections,
            'serviceOptions' => $services->map(fn (array $s): string => $s['name'])->all() + ($cards->contains(fn (array $c): bool => $c['service_key'] === 0) ? [0 => 'Hizmete bağlı olmayan'] : []),
            'totals' => ['all' => $cards->count(), 'matched' => $cards->where('matched', true)->count(), 'unmatched' => $cards->where('matched', false)->count()],
            'pendingClusters' => $brand !== null ? SiteScope::pendingClusters($brand) : collect(),
            'statuses' => $statuses,
            'ideaStatuses' => $ideaStatuses,
            'suggestions' => $this->suggestions($groups),
            'auditStatus' => $auditStatus,
            'polling' => in_array('çalışıyor…', $statuses, true) || $auditStatus === 'çalışıyor…'
                || collect($ideaStatuses)->contains(fn ($s): bool => ($s['status'] ?? null) === 'running'),
            'overlapCount' => Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('decision_key', ClusterOverlaps::DECISION)
                ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->get(['id', 'action'])
                ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id)->count(),
        ]);
    }

    /**
     * One card: the cluster, its numbers and its ideas (the cluster itself first as the must-have one).
     *
     * @param  array<string, mixed>  $g  a group of clusterGroups()
     * @param  array<string, mixed>|null  $ours  SiteAnalysisReader::clusters row
     * @param  list<array{text: string, demand: int}>  $queries
     * @param  Collection<string, array<string, mixed>>  $siteQueries
     * @return array<string, mixed>
     */
    private function card(array $g, ?array $ours, array $queries, Collection $siteQueries): array
    {
        /** @var Cluster $cluster */
        $cluster = $g['cluster'];
        $ideas = [];
        foreach ($g['mains'] as $i => $r) {
            $ideas[] = $r + ['title' => $i === 0 ? $cluster->name : ($r['model']->page?->title ?: $cluster->name), 'type' => (string) $cluster->page_type, 'must' => $i === 0, 'idea' => null];
        }
        foreach ($g['extras'] as $r) {
            $ideas[] = $r + ['title' => (string) $r['idea']->title, 'type' => (string) $r['idea']->type, 'must' => false];
        }

        return [
            'cluster' => $cluster,
            'service_id' => (int) $cluster->service_id,
            'matched' => $g['mains']->contains(fn (array $r): bool => $r['model']->page_id !== null),
            'page' => $g['mains']->map(fn (array $r) => $r['model']->page)->filter()->first(),
            'demand' => (int) $g['demand'],
            'impressions' => $ours['impressions'] ?? null,
            'clicks' => $ours['clicks'] ?? null,
            'position' => $ours['position'] ?? null,
            'ideas' => $ideas,
            'types' => array_values(array_unique(array_column($ideas, 'type'))),
            'queries' => array_map(function (array $q) use ($siteQueries): array {
                $site = $siteQueries->get(mb_strtolower(trim($q['text'])));

                return $q + ['impressions' => $site['impressions'] ?? null, 'position' => $site['position'] ?? null];
            }, $queries),
            'warning' => collect($g['mains'])->first(fn (array $r): bool => $r['state'] === 'technical')['reason'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $card */
    private function visible(array $card): bool
    {
        if ($this->service !== '' && (string) $card['service_key'] !== $this->service) {
            return false;
        }
        if ($this->match !== '' && $card['matched'] !== ($this->match === 'eslendi')) {
            return false;
        }
        if ($this->type !== '' && ! in_array($this->type, $card['types'], true)) {
            return false;
        }
        $term = mb_strtolower(trim($this->search));
        if ($term === '') {
            return true;
        }

        return str_contains(mb_strtolower((string) $card['cluster']->name), $term)
            || collect($card['queries'])->contains(fn (array $q): bool => str_contains(mb_strtolower($q['text']), $term))
            || collect($card['ideas'])->contains(fn (array $i): bool => str_contains(mb_strtolower($i['title']), $term));
    }

    /**
     * Cards by service (main services first, then the others in the brand's order, then clusters of no brand service);
     * in a service the ana küme first (service-page type, most demand), then the alt kümeler by demand.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, array{name: string, main: bool}>  $services
     * @return list<array{id: int, name: string, main: bool, cards: list<array<string, mixed>>, matched: int, page: mixed}>
     */
    private function sections(Collection $cards, Collection $services): array
    {
        $order = $services->keys()->flip();
        $out = [];
        foreach ($cards->groupBy('service_key') as $serviceId => $group) {
            $serviceId = (int) $serviceId;
            $sorted = $group->sortBy([fn (array $a, array $b): int => [$b['cluster']->page_type === 'service', $b['demand'], $a['cluster']->name]
                <=> [$a['cluster']->page_type === 'service', $a['demand'], $b['cluster']->name]])->values()->all();
            $out[] = [
                'id' => (int) $serviceId,
                'name' => $serviceId === 0 ? 'Hizmete bağlı olmayan kümeler' : $services[$serviceId]['name'],
                'main' => $serviceId !== 0 && $services[$serviceId]['main'],
                'order' => $serviceId === 0 ? PHP_INT_MAX : (int) ($order[$serviceId] ?? PHP_INT_MAX - 1),
                'cards' => $sorted,
                'matched' => count(array_filter($sorted, fn (array $c): bool => $c['matched'])),
                'page' => $sorted[0]['page'] ?? null,
            ];
        }
        usort($out, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $out;
    }

    /**
     * The top queries of each cluster by sector demand.
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<array{text: string, demand: int}>>
     */
    private function clusterQueries(array $clusterIds): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])
            ->where('q.hidden', false)->orderByDesc('q.impressions')->orderBy('q.text')->get(['cq.cluster_id', 'q.text', 'q.impressions'])
            ->each(function (object $q) use (&$out): void {
                $out[(int) $q->cluster_id] ??= [];
                if (count($out[(int) $q->cluster_id]) < self::QUERIES) {
                    $out[(int) $q->cluster_id][] = ['text' => (string) $q->text, 'demand' => (int) $q->impressions];
                }
            });

        return $out;
    }
}
