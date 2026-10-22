<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\DoorwayCheckAgent;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandPack;
use App\Services\Repair\WebHealthAudit;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kapı sayfaları (yakup, 2026-10-22: "sadece riskli olanları kaldır"). Some sites carry hundreds of template pages: the
 * same contact table under a search phrase as title ("Hurdacı İsmail Bolu için iletişim bilgileri"). Google's spam
 * rules count them as doorway pages when the phrase targets someone else or a place the business does not serve.
 * Claude reads each template page's title once (`site.doorway_check`, 100 per call); pages naming another business or
 * person, or an unserved place, go to Onarım masası as noindex rows (web_health, check "doorway"), up to 100 pages a
 * row (one site write). The pages stay on the site; nothing is written without the operator's approval, and an
 * applied noindex can be undone. Pages about the business's own products in its own area stay in Google.
 */
final class DoorwayCheck
{
    public const string CHECK = 'doorway';

    /** A site needs this many template pages before they count as a doorway set (one contact page is not one). */
    public const int MIN_TEMPLATE_PAGES = 20;

    public const int BATCH = 100;

    public const int PER_RUN = 1000;

    /** Pages per desk row: one site write takes at most 100 changes (ExternalWriteService). */
    public const int PER_ROW = 100;

    /** Example lines shown on one row. */
    private const int SHOWN = 12;

    private const string CACHE = 'site-doorway-check:';

    public const array TITLES = [
        'other_business' => 'Başka firma ya da kişi adıyla açılmış sayfalar',
        'out_of_area' => 'Hizmet verilmeyen yer için açılmış sayfalar',
    ];

    /** @return array{checked: int, flagged: int, closed: int, waiting: int} waiting: pages whose batch waits for Claude (MCP) */
    public function run(int $limit = self::PER_RUN): array
    {
        $result = ['checked' => 0, 'flagged' => 0, 'closed' => 0, 'waiting' => 0];
        DigitalAsset::query()->operational()->where('type', 'website')->whereNotNull('brand_id')->orderBy('id')
            ->each(function (DigitalAsset $site) use (&$result, &$limit): void {
                $brand = Brand::query()->with('sectorCategory')->find($site->brand_id);
                if (! BrandlessSites::serviced($site) || ! SiteScope::aiAllowed($brand)) {
                    return;
                }
                $pages = self::templatePages($site);
                if ($pages->count() < self::MIN_TEMPLATE_PAGES) {
                    $result['closed'] += $this->rows($site, $brand, collect());

                    return;
                }
                $todo = $pages->filter(fn (Page $p): bool => (Cache::get(self::CACHE.$p->id)['fingerprint'] ?? null) !== self::fingerprint($p))->take(max(0, $limit));
                $limit -= $todo->count();
                foreach ($todo->chunk(self::BATCH) as $batch) {
                    $done = $this->check($brand, $site, $batch->values());
                    if ($done === null) {
                        $result['waiting'] += $batch->count();

                        continue;
                    }
                    $result['checked'] += $done['checked'];
                    $result['flagged'] += $done['flagged'];
                }
                $result['closed'] += $this->rows($site, $brand, $pages);
            });

        return $result;
    }

    /**
     * Indexable WordPress pages whose text opens with the contact table of their own title.
     *
     * @return Collection<int, Page>
     */
    public static function templatePages(DigitalAsset $site): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->where('wp_post_id', '>', 0)
            ->select(['id', 'website_asset_id', 'url', 'title', 'h1', 'wp_post_id'])->selectRaw('substr(content_text, 1, 600) as head')
            ->orderBy('id')->get()
            ->filter(fn (Page $p): bool => self::isTemplate((string) ($p->h1 ?: $p->title), (string) $p->getAttribute('head')))->values();
    }

    public static function isTemplate(string $title, string $head): bool
    {
        $title = SeoText::fold($title);

        return $title !== '' && str_contains(SeoText::fold($head), $title.' icin iletisim bilgileri');
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return array{checked: int, flagged: int}|null null while Claude (MCP) has not answered the batch
     */
    private function check(Brand $brand, DigitalAsset $site, Collection $pages): ?array
    {
        $result = app(SiteAi::class)->run(new DoorwayCheckAgent, [
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name, 'services' => BrandPack::serviceNames($brand, 30),
                'areas' => self::areas($brand)],
            'pages' => $pages->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => (string) ($p->h1 ?: $p->title)])->all(),
        ], 240, 'doorway-check-'.$site->id.'-'.md5($pages->map(fn (Page $p): string => $p->id.':'.self::fingerprint($p))->implode(',')));
        if ($result['status'] === 'queued') {
            return null;
        }
        if ($result['status'] !== 'ready') {
            return ['checked' => 0, 'flagged' => 0]; // no verdict: the next run tries again
        }
        $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
        $done = ['checked' => 0, 'flagged' => 0];
        foreach ($pages as $page) {
            $answer = (array) $answers->get((int) $page->id, []);
            $verdict = in_array($answer['verdict'] ?? null, DoorwayCheckAgent::VERDICTS, true) ? (string) $answer['verdict'] : 'unsure';
            Cache::forever(self::CACHE.$page->id, ['fingerprint' => self::fingerprint($page), 'verdict' => $verdict,
                'reason' => mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 180)]);
            $done['checked']++;
            $done['flagged'] += isset(self::TITLES[$verdict]) ? 1 : 0;
        }

        return $done;
    }

    /**
     * The site's doorway rows from the current verdicts: pages already on an approved, snoozed or refused row stay
     * there; the other risky pages fill the open rows (100 a row) per verdict. An open row with nothing left, and an
     * approved row whose pages all left Google, are done.
     *
     * @param  Collection<int, Page>  $templates
     * @return int rows closed
     */
    private function rows(DigitalAsset $site, Brand $brand, Collection $templates): int
    {
        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', WebHealthAudit::TYPE)
            ->where('target_type', 'asset')->where('target_id', $site->id)->where('action->check', self::CHECK)->orderBy('id')->get();
        $live = $templates->keyBy('id');
        $pagesOf = fn (Suggestion $s): array => array_map('intval', (array) data_get($s->action, 'page_ids', []));
        $open = $existing->filter(fn (Suggestion $s): bool => in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK], true));
        $held = $existing->reject(fn (Suggestion $s): bool => $open->contains('id', $s->id) || $s->status === Suggestion::APPLIED);
        $taken = array_flip($held->flatMap($pagesOf)->all());
        $verdicts = $live->isEmpty() ? [] : Cache::many($live->keys()->map(fn (int $id): string => self::CACHE.$id)->all());
        $risky = [];
        foreach ($live as $id => $page) {
            $known = $verdicts[self::CACHE.$id] ?? null;
            if (is_array($known) && ($known['fingerprint'] ?? null) === self::fingerprint($page) && isset(self::TITLES[$known['verdict'] ?? '']) && ! isset($taken[$id])) {
                $risky[(string) $known['verdict']][] = $page;
            }
        }
        $clicks = $risky === [] ? null : $this->clicks($brand, collect($risky)->flatten(1));
        $reused = [];
        foreach ($risky as $verdict => $pages) {
            $slots = $open->filter(fn (Suggestion $s): bool => data_get($s->action, 'verdict') === $verdict)->values();
            foreach (array_chunk($pages, self::PER_ROW) as $n => $chunk) {
                $row = $slots->get($n);
                $this->save($site, $verdict, $chunk, $clicks, $verdicts, $row);
                if ($row !== null) {
                    $reused[] = $row->id;
                }
            }
        }
        $done = $open->reject(fn (Suggestion $s): bool => in_array($s->id, $reused, true))
            ->merge($held->filter(fn (Suggestion $s): bool => $s->status === Suggestion::APPROVED
                && collect($pagesOf($s))->every(fn (int $id): bool => ! $live->has($id))));
        foreach ($done->filter(fn (Suggestion $s): bool => $s->resolved_by !== null && $s->applied_at === null) as $row) {
            $row->forceFill(['applied_at' => now()])->save();
        }

        return Suggestion::query()->whereIn('id', $done->pluck('id')->all() ?: [0])
            ->update(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(),
                'operator_note' => 'Sayfalar artık Google\'a açık değil (otomatik kapandı).']);
    }

    /**
     * @param  list<Page>  $pages
     * @param  array<string, int>|null  $clicks
     * @param  array<string, mixed>  $verdicts
     */
    private function save(DigitalAsset $site, string $verdict, array $pages, ?array $clicks, array $verdicts, ?Suggestion $row): void
    {
        $line = fn (Page $p): string => SeoText::urlPath((string) $p->url).' · '.(string) ($p->h1 ?: $p->title)
            .($clicks !== null ? ' · '.($clicks[SeoText::urlKey((string) $p->url)] ?? 0).' tıklama (90 gün)' : '');
        usort($pages, fn (Page $a, Page $b): int => ($clicks[SeoText::urlKey((string) $b->url)] ?? 0) <=> ($clicks[SeoText::urlKey((string) $a->url)] ?? 0) ?: $a->id <=> $b->id);
        $lines = array_map($line, array_slice($pages, 0, self::SHOWN));
        if (count($pages) > self::SHOWN) {
            $lines[] = '… ve '.(count($pages) - self::SHOWN).' sayfa daha';
        }
        $example = (string) ($verdicts[self::CACHE.$pages[0]->id]['reason'] ?? '');
        $action = ['site_id' => (int) $site->id, 'check' => self::CHECK, 'target' => $this->origin($site), 'verdict' => $verdict,
            'page_ids' => array_map(fn (Page $p): int => (int) $p->id, $pages), 'before' => $lines,
            'after' => [count($pages).' sayfa Google\'a kapatılır (noindex), sitede kalır', 'Hizmet bölgenizdeki kendi konularınızın sayfaları açık kalır', 'Geri alınabilir'],
            'changes' => array_map(fn (Page $p): array => ['type' => 'noindex', 'object_id' => (int) $p->wp_post_id, 'reference' => 'doorway-noindex-'.$p->id, 'value' => true], $pages)];
        $values = [
            'channel' => 'search', 'decision_key' => 'repair.'.WebHealthAudit::TYPE, 'title' => mb_substr(sprintf('%s (%d sayfa)', self::TITLES[$verdict], count($pages)), 0, 160),
            'reason' => mb_substr('Claude başlıkları okudu'.($example !== '' ? ' ('.$example.')' : '').'. Google bu şablon sayfaları kapı sayfası (spam) sayar; tüm sitenin sıralamasını riske atar.', 0, 240),
            'priority' => 3, 'action_type' => WebHealthAudit::TYPE, 'target_type' => 'asset', 'target_id' => $site->id, 'page_id' => null,
            'last_seen_at' => now(), 'material_hash' => hash('sha256', json_encode([$action['page_ids'], $verdict])),
            'evidence' => [['kind' => 'quote', 'value' => mb_substr(implode(' · ', $lines), 0, 400), 'source' => $this->origin($site)]],
        ];
        if ($row !== null) {
            $row->forceFill($values + ['action' => $action])->save();

            return;
        }
        Suggestion::openOnce($values + ['brand_id' => $site->brand_id, 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'action' => $action,
            'fingerprint' => hash('sha256', $site->brand_id.'|web-health|'.$site->id.'|'.self::CHECK.'|'.$verdict.'-'.$pages[0]->id.'-'.now()->timestamp)]);
    }

    private function origin(DigitalAsset $site): string
    {
        return rtrim((string) ($site->primary_url ?: 'https://'.$site->domain), '/').'/';
    }

    /** @return list<string> the brand's served places, city and district ("İzmir", "İzmir / Bornova") */
    private static function areas(Brand $brand): array
    {
        return $brand->serviceAreas()->where('status', 'active')->get(['city_name', 'district_name', 'name'])
            ->map(fn ($a): string => trim(implode(' / ', array_filter([trim((string) $a->city_name), trim((string) $a->district_name)])) ?: (string) $a->name))
            ->filter()->unique()->take(40)->values()->all();
    }

    /**
     * Clicks of the last 90 days per page (Search Console), when the brand has it.
     *
     * @param  Collection<int, Page>  $pages
     * @return array<string, int>|null null without a Search Console property
     */
    private function clicks(Brand $brand, Collection $pages): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'search_console');
        if ($ids === []) {
            return null;
        }
        $out = [];
        DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->whereIn('page', $pages->pluck('url')->all())
            ->where('reporting_date', '>=', now()->subDays(90)->toDateString())->groupBy('page')->selectRaw('page, sum(clicks) as clicks')->get()
            ->each(function (object $r) use (&$out): void {
                $out[SeoText::urlKey((string) $r->page)] = (int) $r->clicks;
            });

        return $out;
    }

    public static function fingerprint(Page $page): string
    {
        return md5((int) $page->id.'|'.(string) ($page->h1 ?: $page->title).'|'.(string) $page->url);
    }
}
