<?php

namespace App\Services\ContentDelivery;

use App\Models\DigitalAsset;
use App\Models\SiteFixItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ADR-076: WXR export of a site's articles behind the compliance gate. Nothing leaves MoxDOP while an article still
 * breaks a blocking sector rule. Read only; the operator downloads the file and imports it into WordPress.
 */
final class ContentExportService
{
    public function __construct(
        private readonly ContentComplianceGate $gate,
        private readonly WxrExporter $exporter,
        private readonly LanguageLinkMap $links,
    ) {}

    /**
     * @param  iterable<ArticleDraft>  $articles
     * @param  array<string, mixed>  $options  WxrExporter options (site values are filled in)
     * @return array{xml: string, filename: string, count: int}
     */
    public function wxr(DigitalAsset $site, iterable $articles, array $options = []): array
    {
        $articles = is_array($articles) ? array_values($articles) : iterator_to_array($articles, false);
        if ($articles === []) {
            throw ValidationException::withMessages(['export' => 'Dışa aktarılacak makale yok.']);
        }
        $brand = $site->brand;
        foreach ($articles as $article) {
            $this->gate->assertCompliant($brand, $article, '"'.mb_substr($article->title, 0, 60).'"');
        }
        $languages = $this->links->languages($site);
        $default = collect($languages)->firstWhere('default', true)['slug'] ?? null;
        $now = CarbonImmutable::now();
        $xml = $this->exporter->export($articles, $options + [
            'site_url' => $this->siteUrl($site),
            'site_title' => (string) ($site->brand?->name ?? $site->name),
            'language' => $default ?? ($articles[0]->language ?? 'tr'),
            'generated_at' => $now,
        ]);

        return ['xml' => $xml, 'filename' => $this->exporter->filename(str_replace('.', '-', (string) ($site->domain ?: $site->name)), $now), 'count' => count($articles)];
    }

    /**
     * New-page proposals of the site fixes with AI / operator text (open, failed or undone).
     *
     * @param  list<int>  $itemIds  empty = all
     * @return list<ArticleDraft>
     */
    public function siteFixArticles(DigitalAsset $site, array $itemIds = []): array
    {
        return SiteFixItem::query()->where('digital_asset_id', $site->id)->where('type', 'new_page')
            ->whereIn('status', ['open', 'failed', 'undone'])
            ->when($itemIds !== [], fn ($q) => $q->whereIn('id', $itemIds))
            ->orderBy('id')->limit(200)->get()
            ->filter(fn (SiteFixItem $item): bool => filled(data_get($item->proposed, 'value.html')) && filled(data_get($item->proposed, 'value.title')))
            ->map(fn (SiteFixItem $item): ArticleDraft => ArticleDraft::fromSiteFixItem($item))->values()->all();
    }

    private function siteUrl(DigitalAsset $site): string
    {
        if (Schema::hasTable('website_cms_site_snapshot')) {
            $home = DB::table('website_cms_site_snapshot')->where('digital_asset_id', $site->id)->orderByDesc('observed_at')->value('home_url');
            if (filled($home)) {
                return (string) $home;
            }
        }
        $url = trim((string) ($site->primary_url ?: $site->domain ?: ''));

        return $url === '' ? 'https://example.com' : (str_contains($url, '://') ? $url : 'https://'.$url);
    }
}
