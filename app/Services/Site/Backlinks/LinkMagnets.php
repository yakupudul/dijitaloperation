<?php

namespace App\Services\Site\Backlinks;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\LinkTarget;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandPack;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\BrandlessSites;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestionTypes;

/**
 * Link mıknatısı (Backlink hizmeti mimarisi, motor 6): one lasting page per brand that other sites link to by
 * themselves (a guide, a table, a checklist people quote) instead of asking for every link. By the brand's sector a
 * subject is chosen from MAGNETS and put in İçerik as a title waiting for the operator's approval (the normal content
 * flow writes it with the brand's facts and compliance rules). Once the page is live it becomes a target of the desk:
 * local news, associations and universities are asked to point to it. One magnet per brand; a magnet the operator
 * dismissed is not offered again.
 *
 * Calculators and a daily price table need a tool on the site, not an article: they are listed as "geliştirme" ideas
 * on the screen, never written as a page without the tool.
 */
final class LinkMagnets
{
    public const string SOURCE = 'link_magnet';

    public const string TARGET_KIND = 'miknatis';

    /** Pool kinds that are asked to point to the magnet rather than to a service page. */
    public const array MAGNET_SOURCES = ['yerel_haber', 'dernek', 'oda', 'universite', 'blog'];

    /** folded sector words => [title, outline, tool idea] */
    public const array MAGNETS = [
        'dis' => ['{service} için seans ve iyileşme takvimi', ['Tedavi kaç seansta biter', 'Seanslar arası bekleme süreleri', 'İyileşme döneminde dikkat edilecekler', 'Sık sorulan sorular'], null],
        'kadin' => ['Gebelik haftası tablosu ve kontrol takvimi', ['Hafta hafta gelişim tablosu', 'Hangi haftada hangi kontrol', 'Doktora hemen başvurulacak durumlar', 'Sık sorulan sorular'], 'Gebelik haftası hesaplayıcı'],
        'jinekoloji' => ['Gebelik haftası tablosu ve kontrol takvimi', ['Hafta hafta gelişim tablosu', 'Hangi haftada hangi kontrol', 'Doktora hemen başvurulacak durumlar', 'Sık sorulan sorular'], 'Gebelik haftası hesaplayıcı'],
        'dermatoloji' => ['Mevsime göre cilt bakım takvimi', ['Ay ay bakım önerileri', 'Güneş koruması tablosu', 'Uzmana başvurulacak belirtiler', 'Sık sorulan sorular'], null],
        'psikolog' => ['Terapiye başlamadan önce bilinmesi gerekenler', ['İlk seansta neler olur', 'Terapi türleri karşılaştırması', 'Seans süresi ve sıklığı', 'Sık sorulan sorular'], null],
        'hurda' => ['Hurda türleri nasıl ayrılır', ['Metal türlerini ayırt etme', 'Değerini düşüren hatalar', 'Teslim öncesi hazırlık listesi', 'Sık sorulan sorular'], 'Günlük hurda fiyat tablosu'],
        'perde' => ['Perde ölçüsü nasıl alınır', ['Pencere tipine göre ölçü', 'Pile payı tablosu', 'Sık yapılan ölçü hataları', 'Sık sorulan sorular'], 'Perde ölçüsü hesaplayıcı'],
        'tesisat' => ['Evde su kaçağı belirtileri kontrol listesi', ['Faturadan kaçağı anlama', 'Duvar ve zemindeki işaretler', 'Ustaya kadar yapılacaklar', 'Sık sorulan sorular'], null],
    ];

    /** The magnet of a brand's sector, or null when none fits. */
    public static function forBrand(Brand $brand): ?array
    {
        $sector = SeoText::fold((string) $brand->sectorCategory?->name.' '.$brand->sectorCategory?->code);
        foreach (self::MAGNETS as $word => [$title, $outline, $tool]) {
            if (str_contains($sector, $word) || ($word === 'dis' && str_contains($sector, 'dental'))) {
                $service = BrandPack::serviceNames($brand, 1)[0] ?? 'Tedavi';

                return ['title' => mb_substr(str_replace('{service}', $service, $title), 0, 60), 'outline' => $outline, 'tool' => $tool];
            }
        }

        return null;
    }

    /**
     * Puts the brand's magnet into İçerik as a title waiting for approval (once).
     *
     * @return string|null why nothing was added; null when added
     */
    public function propose(Brand $brand): ?string
    {
        $magnet = self::forBrand($brand);
        if ($magnet === null) {
            return 'Bu sektör için hazır bir mıknatıs konusu yok.';
        }
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
        if ($site === null || ! BrandlessSites::serviced($site)) {
            return 'Markanın aktif web sitesi yok.';
        }
        $fingerprint = hash('sha256', $brand->id.'|link-magnet');
        if (Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->exists()) {
            return 'Mıknatıs konusu zaten İçerik\'te.';
        }
        $slug = SeoText::slugify($magnet['title']);
        Suggestion::query()->create([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', $slug),
            'title' => $magnet['title'], 'priority' => 2,
            'reason' => 'Link mıknatısı: diğer sitelerin kendiliğinden kaynak gösterdiği kalıcı bir rehber; yayınlanınca Bağlantı masası yerel haber ve derneklerden bu sayfaya link ister.',
            'evidence' => [['kind' => 'none', 'value' => 'Backlink hizmeti', 'source' => '']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'site', 'target_id' => $site->id, 'page_id' => null, 'status' => Suggestion::OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => (int) $site->id, 'kind' => 'new', 'page_type' => 'blog', 'target_url' => rtrim(SiteScope::origin($site), '/').'/'.$slug.'/',
                'outline' => $magnet['outline'], 'source' => self::SOURCE, 'week' => now()->format('o-\WW')],
        ]);

        return null;
    }

    /** The brand's magnet idea in İçerik (any state), or null. */
    public static function idea(Brand $brand): ?Suggestion
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', hash('sha256', $brand->id.'|link-magnet'))->first();
    }

    /**
     * Once the magnet's article is live (a page of the site at its address, or with its title), it becomes a target of
     * the desk and stays one while it is live.
     */
    public static function ensureTarget(Brand $brand): ?LinkTarget
    {
        $idea = self::idea($brand);
        if ($idea === null || $idea->status === Suggestion::DISMISSED) {
            return null;
        }
        $action = (array) $idea->action;
        $key = SeoText::urlKey((string) ($action['target_url'] ?? ''));
        $title = SeoText::fold((string) $idea->title);
        $page = Page::query()->where('website_asset_id', (int) ($action['site_id'] ?? 0))->where(fn ($q) => $q->where('is_indexable', true)->orWhereNull('is_indexable'))
            ->get(['id', 'url', 'title'])->first(fn (Page $p): bool => ($key !== '' && SeoText::urlKey((string) $p->url) === $key) || ($title !== '' && SeoText::fold((string) $p->title) === $title));
        if ($page === null) {
            return null;
        }
        $urlKey = hash('sha256', SeoText::urlKey((string) $page->url));
        $existing = LinkTarget::query()->where('brand_id', $brand->id)->where('url_key', $urlKey)->first();
        if ($existing !== null && $existing->kind === self::TARGET_KIND && $existing->status === LinkTarget::ACTIVE) {
            return $existing;
        }
        $domain = preg_replace('/^www\./', '', (string) parse_url((string) $page->url, PHP_URL_HOST)) ?: (string) $brand->name;

        return LinkTarget::query()->updateOrCreate(['brand_id' => $brand->id, 'url_key' => $urlKey], [
            'kind' => self::TARGET_KIND, 'page_id' => $page->id, 'url' => (string) $page->url, 'title' => mb_substr((string) ($page->title ?: $idea->title), 0, 300),
            'query' => null, 'status' => LinkTarget::ACTIVE, 'rank' => 0, 'picked_at' => $existing?->picked_at ?? now(), 'released_at' => null,
            'reason' => 'Link mıknatısı: yerel haber siteleri, dernekler ve üniversiteler bu rehbere kaynak olarak link verir; değeri sitenin bütününe yayılır.',
            'anchor_plan' => [['text' => (string) $brand->name, 'kind' => 'marka', 'share' => 50], ['text' => mb_substr((string) ($page->title ?: $idea->title), 0, 80), 'kind' => 'baslik', 'share' => 30],
                ['text' => $domain, 'kind' => 'adres', 'share' => 20]],
        ]);
    }
}
