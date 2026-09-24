<?php

namespace App\Services\SiteFixes;

use App\Ai\Agents\SiteFixes\InternalLinkAgent;
use App\Ai\Agents\SiteFixes\PageWriterAgent;
use App\Ai\Agents\SiteFixes\SiteFixValuesAgent;
use App\Jobs\RunSiteFixAiJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SiteFixItem;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ExternalWrites\WordPressDraftWriter;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\ResolvedAiRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * ADR-070: AI proposals for site fixes, only on click. Values land on the site_fix_items rows as a proposal the
 * operator can edit; nothing is written to the site here. Page texts are read live from the connector (read only).
 */
final class SiteFixAi
{
    public const string KIND_VALUES = 'values';

    public const string KIND_LINKS = 'links';

    public const string KIND_PAGE = 'page';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly WordPressConnectorClient $client,
    ) {}

    public function queue(string $kind, int $id): void
    {
        $route = match ($kind) {
            self::KIND_VALUES => AiRouteKeys::SITE_FIX_VALUES,
            self::KIND_LINKS => AiRouteKeys::SITE_FIX_LINKS,
            default => AiRouteKeys::SITE_FIX_PAGE,
        };
        if ($this->routes->resolve($route)->isEmpty()) {
            throw ValidationException::withMessages(['ai' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put($this->stateKey($kind, $id), 'running', now()->addMinutes(15));
        RunSiteFixAiJob::dispatch($kind, $id);
    }

    public function state(string $kind, int $id): ?string
    {
        $state = Cache::get($this->stateKey($kind, $id));

        return is_string($state) ? $state : null;
    }

    public function run(string $kind, int $id): void
    {
        try {
            match ($kind) {
                self::KIND_VALUES => $this->values(DigitalAsset::query()->findOrFail($id)),
                self::KIND_LINKS => $this->links(DigitalAsset::query()->findOrFail($id)),
                default => $this->page(SiteFixItem::query()->findOrFail($id)),
            };
            Cache::forget($this->stateKey($kind, $id));
        } catch (Throwable $exception) {
            Cache::put($this->stateKey($kind, $id), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    /** Proposed values for open items without one (title, description, alt, schema, redirect target). */
    public function values(DigitalAsset $site): int
    {
        $items = SiteFixItem::query()->where('digital_asset_id', $site->id)->whereIn('status', ['open', 'failed', 'undone'])
            ->whereIn('type', ['seo_title', 'seo_description', 'alt_text', 'schema', 'redirect'])
            ->where(fn ($q) => $q->whereNull('proposed')->orWhere('proposed_by', 'rule'))->orderBy('id')->limit(60)->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $input = [
            'business' => $this->businessFacts($site),
            'compliance' => $this->compliance($site->brand),
            'published_pages' => $items->contains('type', 'redirect') ? $this->publishedPages($site, 150) : [],
            'items' => $items->map(fn (SiteFixItem $i): array => [
                'id' => $i->id, 'type' => $i->type, 'url' => $i->url, 'page' => $i->label, 'h1' => data_get($i->current, 'h1'),
                'current' => data_get($i->current, 'value'), 'file' => data_get($i->current, 'file'), 'problem' => $i->reason,
            ])->all(),
        ];
        $route = $this->route(AiRouteKeys::SITE_FIX_VALUES);
        $response = (array) (new SiteFixValuesAgent)->prompt("INPUT_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), provider: $route->providerModels, timeout: 180)->toArray();
        $byId = $items->keyBy('id');
        $allowed = collect($input['published_pages'])->pluck('url')->map(fn ($u): string => SeoText::urlKey((string) $u))->all();
        $count = 0;
        foreach ((array) ($response['items'] ?? []) as $row) {
            $item = $byId->get((int) ($row['id'] ?? 0));
            $value = trim(strip_tags((string) ($row['value'] ?? '')));
            if ($item === null || ($value === '' && $item->type !== 'alt_text')) {
                continue;
            }
            if ($item->type === 'schema') {
                $decoded = json_decode((string) $row['value'], true);
                if (! is_array($decoded) || ! isset($decoded['@type'])) {
                    continue;
                }
                $decoded['@context'] ??= 'https://schema.org';
                $value = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            if ($item->type === 'redirect' && ! in_array(SeoText::urlKey($value), $allowed, true)) {
                continue;
            }
            $item->forceFill(['proposed' => ['value' => $value, 'note' => mb_substr((string) ($row['note'] ?? ''), 0, 300), 'provider' => $route->primaryModel(), 'prompt_version' => SiteFixValuesAgent::PROMPT_VERSION], 'proposed_by' => 'ai'])->save();
            $count++;
        }

        return $count;
    }

    /** New internal link items; the anchor must already appear in the source page text. */
    public function links(DigitalAsset $site): int
    {
        $pages = $this->publishedPages($site, 40, withText: true);
        if (count($pages) < 2) {
            throw new RuntimeException('İç bağlantı önermek için en az iki yayında sayfa gerekli.');
        }
        $route = $this->route(AiRouteKeys::SITE_FIX_LINKS);
        $response = (array) (new InternalLinkAgent)->prompt("INPUT_JSON\n".json_encode(['business' => $this->businessFacts($site), 'pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();
        $byObject = collect($pages)->keyBy('object_id');
        $urls = collect($pages)->mapWithKeys(fn (array $p): array => [SeoText::urlKey((string) $p['url']) => $p['url']]);
        $count = 0;
        foreach (array_slice((array) ($response['links'] ?? []), 0, 12) as $link) {
            $source = $byObject->get((string) ($link['source_object_id'] ?? ''));
            $target = $urls->get(SeoText::urlKey((string) ($link['target_url'] ?? '')));
            $anchor = trim((string) ($link['anchor'] ?? ''));
            if ($source === null || $target === null || $anchor === '' || SeoText::urlKey((string) $source['url']) === SeoText::urlKey((string) $target)
                || mb_stripos((string) $source['text'], $anchor) === false) {
                continue;
            }
            SiteFixItem::query()->updateOrCreate(['digital_asset_id' => $site->id, 'item_key' => hash('sha256', 'internal_link|'.$source['object_id'].'|'.SeoText::urlKey((string) $target))], [
                'brand_id' => $site->brand_id, 'type' => 'internal_link', 'phase' => 2, 'object_id' => (string) $source['object_id'], 'url' => $source['url'],
                'label' => $source['title'].' → '.$target, 'reason' => mb_substr((string) ($link['reason'] ?? 'İç bağlantı önerisi'), 0, 500),
                'current' => ['value' => null], 'proposed' => ['value' => ['anchor' => $anchor, 'url' => $target], 'provider' => $route->primaryModel(), 'prompt_version' => InternalLinkAgent::PROMPT_VERSION],
                'proposed_by' => 'ai', 'status' => 'open',
            ]);
            $count++;
        }

        return $count;
    }

    /** The new version of a thin page (rewrite) or a new page from an SEO brief. */
    public function page(SiteFixItem $item): void
    {
        $site = $item->digitalAsset()->firstOrFail();
        $input = ['mode' => $item->type === 'new_page' ? 'new' : 'rewrite', 'business' => $this->businessFacts($site), 'compliance' => $this->compliance($site->brand)];
        if ($item->type === 'new_page') {
            $input['brief'] = data_get($item->current, 'brief');
        } else {
            $current = $this->pageTexts($site, [(int) $item->object_id])[(string) $item->object_id] ?? null;
            if ($current === null) {
                throw new RuntimeException('Sayfanın mevcut metni WordPress’ten okunamadı.');
            }
            $input['current_page'] = ['url' => $item->url, 'title' => $item->label, 'text' => mb_substr($current['text'], 0, 12000)];
        }
        $route = $this->route(AiRouteKeys::SITE_FIX_PAGE);
        $response = (array) (new PageWriterAgent)->prompt("INPUT_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), provider: $route->providerModels, timeout: 240)->toArray();
        $html = trim((string) ($response['html'] ?? ''));
        if ($html === '') {
            throw new RuntimeException('AI boş sayfa döndürdü.');
        }
        $html = strip_tags($html, '<h2><h3><h4><p><ul><ol><li><strong><em><a><br><blockquote>');
        // Only href survives on links; every other attribute (on*, style, class…) is dropped.
        $html = preg_replace_callback('/<(\/?)(h2|h3|h4|p|ul|ol|li|strong|em|a|br|blockquote)\b([^>]*)>/i', static function (array $m): string {
            if ($m[1] === '/' || strtolower($m[2]) !== 'a') {
                return '<'.$m[1].strtolower($m[2]).'>';
            }
            preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $m[3], $href);
            $url = $href[1] ?? '';

            return preg_match('#^(https?://|/)#i', $url) ? '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">' : '<a>';
        }, $html) ?? '';
        $item->forceFill(['proposed' => ['value' => ['title' => mb_substr(trim(strip_tags((string) ($response['title'] ?? $item->label))), 0, 200), 'html' => $html],
            'note' => mb_substr((string) ($response['summary'] ?? ''), 0, 500), 'provider' => $route->primaryModel(), 'prompt_version' => PageWriterAgent::PROMPT_VERSION], 'proposed_by' => 'ai'])->save();
        app(ProductionArchive::class)->record('website.page_draft', $item, ['title' => data_get($item->proposed, 'value.title'), 'html' => $html, 'summary' => $response['summary'] ?? null],
            ['brand_id' => $item->brand_id, 'digital_asset_id' => $item->digital_asset_id, 'title' => 'Sayfa metni · '.$item->label]);
    }

    /** @return array<string, mixed> */
    public function businessFacts(DigitalAsset $site): array
    {
        $brand = $site->brand;
        $facts = ['name' => $brand?->name, 'website' => $site->domain ?? $site->name, 'sector' => $brand?->sector];
        if ($brand !== null) {
            $facts['services'] = $brand->offerings()->where('status', 'active')->with('primaryName')->limit(30)->get()->map(fn ($o): string => (string) $o->primaryName?->raw_label)->filter()->values()->all();
            $facts['areas'] = $brand->serviceAreas()->where('status', 'active')->limit(20)->get()->map(fn ($a): string => trim(implode(' / ', array_filter([$a->city_name, $a->district_name]))))->filter()->values()->all();
        }
        if ($brand !== null && Schema::hasTable('gbp_location_snapshots')) {
            $location = DB::table('gbp_location_snapshots')->whereIn('digital_asset_id', DigitalAsset::query()->where('brand_id', $brand->id)->pluck('id'))->orderByDesc('captured_at')->first();
            if ($location !== null) {
                $decode = fn ($v) => is_string($v) ? json_decode($v, true) : $v;
                $facts['google_business_profile'] = [
                    'title' => $location->title, 'category' => $location->primary_category, 'address' => $decode($location->storefront_address),
                    'phones' => $decode($location->phone_numbers), 'hours' => $decode($location->regular_hours), 'geo' => $decode($location->latlng), 'maps_url' => $location->maps_uri,
                ];
            }
        }

        return $facts;
    }

    /** @return list<string> */
    private function compliance(?Brand $brand): array
    {
        return $brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(10)->all() : [];
    }

    /**
     * Published pages / posts from the connector snapshot, optionally with a text excerpt read live.
     *
     * @return list<array{object_id: string, url: string, title: ?string, text?: string}>
     */
    private function publishedPages(DigitalAsset $site, int $limit, bool $withText = false): array
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return [];
        }
        $latest = [];
        foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->whereIn('object_type', ['page', 'post'])
            ->orderBy('observed_at')->get(['object_id', 'object_type', 'status', 'title', 'permalink']) as $row) {
            $latest[(string) $row->object_id] = $row;
        }
        $pages = collect($latest)->filter(fn ($r): bool => $r->status === 'publish' && filled($r->permalink))
            ->sortBy(fn ($r): int => $r->object_type === 'page' ? 0 : 1)->take($limit)
            ->map(fn ($r): array => ['object_id' => (string) $r->object_id, 'url' => (string) $r->permalink, 'title' => $r->title])->values()->all();
        if (! $withText) {
            return $pages;
        }
        $texts = $this->pageTexts($site, array_map(fn (array $p): int => (int) $p['object_id'], $pages));

        return array_values(array_filter(array_map(fn (array $p): array => $p + ['text' => mb_substr((string) ($texts[$p['object_id']]['text'] ?? ''), 0, 1500)], $pages),
            fn (array $p): bool => $p['text'] !== ''));
    }

    /**
     * Current text of pages, read live from the connector (read only).
     *
     * @param  list<int>  $objectIds
     * @return array<string, array{text: string, html: string}>
     */
    private function pageTexts(DigitalAsset $site, array $objectIds): array
    {
        $connection = app(WordPressDraftWriter::class)->connection((int) $site->id);
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($objectIds))), 50) as $chunk) {
            $data = $this->client->snapshot($connection, 'content', 1, 50, $chunk);
            foreach ((array) ($data['records'] ?? []) as $record) {
                $html = (string) ($record['content_rendered'] ?? $record['content_raw'] ?? '');
                $out[(string) $record['object_id']] = ['html' => $html, 'text' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))) ?? '')];
            }
        }

        return $out;
    }

    private function route(string $key): ResolvedAiRoute
    {
        $route = $this->routes->resolve($key);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        return $route;
    }

    private function stateKey(string $kind, int $id): string
    {
        return 'site-fix-ai:'.$kind.':'.$id;
    }
}
