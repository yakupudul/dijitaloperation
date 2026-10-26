<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\ContentSiteState;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Meta\MetaDesk;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentTopics;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Küme kapsaması (yakup, 2026-10-26: "bir markada kaç küme için içerik yazılmadı, hangileri yazıldı bilmiyorum"): per
 * website of a brand, in its main language, how many of each service's clusters have a sufficient page, a thin one or
 * none, which of them already have an article written, being written or waiting as an idea, and which service the
 * weekly cycle writes now and next. Rules only, read only.
 */
final class ClusterCoverage
{
    /** What the content line of a cluster says, most advanced first. */
    public const array CONTENT = [
        'sent' => 'Yazı gönderildi',
        'ready' => 'Yazı gönderime hazır',
        'writing' => 'Yazılıyor',
        'idea' => 'Fikir onay bekliyor',
        'covered' => 'Sayfası yeterli',
        'none' => 'Henüz plan yok',
    ];

    /** Cluster page types in plain words. */
    public const array PAGE_TYPES = ['service' => 'hizmet sayfası', 'guide' => 'rehber yazısı', 'faq' => 'soru-cevap yazısı', 'location' => 'bölge sayfası',
        'comparison' => 'karşılaştırma yazısı', 'blog' => 'blog yazısı'];

    /** Cluster states counted as "kapsam yetersiz" (a page exists but does not answer it well). */
    public const array THIN_STATES = ['thin_coverage', 'weak_performance', 'possible_conflict', 'wrong_page'];

    /**
     * @return list<array{site: DigitalAsset, language: string, total: int, ok: int, thin: int, none: int, other: int, planned: int, sent: int,
     *     services: list<array{service_id: int, service: string, main: bool, active: bool, map: ?int, total: int, ok: int, thin: int, none: int, other: int, planned: int, sent: int}>,
     *     active: list<string>, next: ?string}>
     */
    public function forBrand(Brand $brand): array
    {
        $out = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get() as $site) {
            $site->setRelation('brand', $brand);
            $rows = ContentTopics::mainRows($site);
            if ($rows->isEmpty()) {
                continue;
            }
            $content = $this->contentStates($brand, $site, $rows);
            $names = MetaDesk::serviceNames($rows->map(fn (BrandClusterPage $r): int => (int) ($r->cluster?->service_id ?? 0))->filter()->all());
            $main = BrandOffering::query()->where('brand_id', $brand->id)->where(fn ($q) => $q->where('priority', 'main')->orWhere('is_priority', true))
                ->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->flip()->all();
            $state = ContentSiteState::query()->where('digital_asset_id', $site->id)->first();
            $active = array_map('intval', (array) ($state?->active_service_ids ?? []));
            $topics = app(ContentTopics::class);
            $maps = collect($topics->completion($site))->keyBy('service_id');
            $services = [];
            foreach ($rows->groupBy(fn (BrandClusterPage $r): int => (int) ($r->cluster?->service_id ?? 0)) as $serviceId => $group) {
                $services[] = ['service_id' => (int) $serviceId, 'service' => $names[(int) $serviceId] ?? 'Hizmetsiz kümeler', 'main' => isset($main[(int) $serviceId]),
                    'active' => in_array((int) $serviceId, $active, true), 'map' => $maps->has($serviceId) ? (int) $maps->get($serviceId)['percent'] : null]
                    + self::counts($group, $content);
            }
            usort($services, fn (array $a, array $b): int => [$b['active'], $b['main'], $b['none'] + $b['thin'], $a['service']] <=> [$a['active'], $a['main'], $a['none'] + $a['thin'], $b['service']]);
            $next = null;
            foreach (array_keys($topics->serviceScores($site, $brand)) as $serviceId) {
                if (! in_array((int) $serviceId, $active, true) && ! $topics->finished($site, (int) $serviceId)) {
                    $next = $names[(int) $serviceId] ?? null;
                    break;
                }
            }
            $counts = self::counts($rows, $content);
            $rhythm = app(ContentRhythm::class)->for($site);
            $open = max(0, $counts['none'] + $counts['thin'] - $counts['sent'] - $counts['planned']);
            $perWeek = max(1, (int) $rhythm['new'] + (int) $rhythm['update']);
            $out[] = ['site' => $site, 'language' => ContentPlanner::siteLanguages($site)[0], ...$counts, 'services' => $services,
                'open' => $open, 'per_week' => $perWeek, 'weeks' => (int) ceil($open / $perWeek),
                'active' => collect($services)->where('active', true)->map(fn (array $s): string => $s['service'].($s['map'] !== null ? ' %'.$s['map'] : ''))->values()->all(),
                'next' => $next];
        }

        return $out;
    }

    /**
     * One service's clusters on the site (main language), the ones that still need work first, most searched first.
     *
     * @return list<array{cluster_id: int, name: string, state: string, state_label: string, demand: int, page_url: ?string, page_title: ?string, content: string, content_label: string}>
     */
    public function clusters(Brand $brand, DigitalAsset $site, int $serviceId): array
    {
        $site->setRelation('brand', $brand);
        $rows = ContentTopics::mainRows($site)->filter(fn (BrandClusterPage $r): bool => (int) ($r->cluster?->service_id ?? 0) === $serviceId)->values();
        $content = $this->contentStates($brand, $site, $rows);
        $demand = DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $rows->pluck('cluster_id')->all() ?: [0])
            ->where('q.hidden', false)->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) + sum(coalesce(q.volume, 0)) as demand')->pluck('demand', 'cq.cluster_id')->all();
        $order = ['no_page' => 0, 'thin_coverage' => 1, 'weak_performance' => 1, 'possible_conflict' => 1, 'wrong_page' => 1, 'insufficient_data' => 2, 'sufficient' => 3];

        $rows->loadMissing('cluster.mainQuery');

        return $rows->map(function (BrandClusterPage $r) use ($content, $demand): array {
            $line = $content[(int) $r->cluster_id] ?? ($r->state === 'sufficient' ? 'covered' : 'none');

            return ['cluster_id' => (int) $r->cluster_id, 'name' => (string) ($r->cluster?->name ?? ''), 'state' => (string) $r->state, 'state_label' => $r->stateLabel(),
                'demand' => (int) ($demand[(int) $r->cluster_id] ?? 0) + (int) ($r->impressions_28d ?? 0), 'page_url' => $r->page?->url, 'page_title' => $r->page?->title,
                'content' => $line, 'content_label' => self::CONTENT[$line], 'plan' => $line === 'none' ? self::plan($r) : null];
        })->sortBy(fn (array $c): array => [$order[$c['state']] ?? 2, -$c['demand'], $c['name']])->values()->all();
    }

    /**
     * What a cluster without content gets, by rules: its existing page strengthened (a page answers it thinly) or a new
     * page of the cluster's type for its main search. The title is written when the weekly cycle reaches it.
     */
    public static function plan(BrandClusterPage $row): string
    {
        $query = (string) ($row->cluster?->mainQuery?->text ?? '');
        if ($row->state !== 'no_page' && $row->page !== null) {
            return 'Sayfası güncellenecek: '.SeoText::urlPath((string) $row->page->url);
        }
        $type = self::PAGE_TYPES[(string) ($row->cluster?->page_type ?? '')] ?? 'yazı';

        return 'Yeni '.$type.($query !== '' ? ' · «'.$query.'»' : '');
    }

    /**
     * The most advanced content idea of each cluster on the site: sent, ready to send, being written, waiting.
     *
     * @param  Collection<int, BrandClusterPage>  $rows
     * @return array<int, string> cluster id => CONTENT key
     */
    private function contentStates(Brand $brand, DigitalAsset $site, Collection $rows): array
    {
        $rank = array_flip(array_keys(self::CONTENT));
        $out = [];
        Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereIn('cluster_id', $rows->pluck('cluster_id')->all() ?: [0])
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPLIED])
            ->get(['id', 'cluster_id', 'status', 'action'])
            ->each(function (Suggestion $s) use (&$out, $rank, $site): void {
                $action = (array) $s->action;
                if ((int) ($action['site_id'] ?? $site->id) !== (int) $site->id) {
                    return;
                }
                $line = match (true) {
                    isset($action['article_write_id']) || $s->status === Suggestion::APPLIED => 'sent',
                    is_array($action['article'] ?? null) || isset($action['article_blocked']) => 'ready',
                    $s->status === Suggestion::APPROVED => 'writing',
                    default => 'idea',
                };
                $id = (int) $s->cluster_id;
                if (! isset($out[$id]) || $rank[$line] < $rank[$out[$id]]) {
                    $out[$id] = $line;
                }
            });

        return $out;
    }

    /**
     * @param  Collection<int, BrandClusterPage>  $rows
     * @param  array<int, string>  $content
     * @return array{total: int, ok: int, thin: int, none: int, other: int, planned: int, sent: int}
     */
    private static function counts(Collection $rows, array $content): array
    {
        $ok = $rows->where('state', 'sufficient')->count();
        $thin = $rows->whereIn('state', self::THIN_STATES)->count();
        $none = $rows->where('state', 'no_page')->count();
        $lines = $rows->map(fn (BrandClusterPage $r): ?string => $content[(int) $r->cluster_id] ?? null);

        return ['total' => $rows->count(), 'ok' => $ok, 'thin' => $thin, 'none' => $none, 'other' => $rows->count() - $ok - $thin - $none,
            'planned' => $lines->filter(fn (?string $l): bool => in_array($l, ['ready', 'writing', 'idea'], true))->count(),
            'sent' => $lines->filter(fn (?string $l): bool => $l === 'sent')->count()];
    }
}
