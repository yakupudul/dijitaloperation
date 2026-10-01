<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Eksikler (operator decision 2026-11-18): what blocks the brand's AI work — no website, no service, no area, service
 * pages that are not brand services, services matched to no page — found by rules (no AI) every night and on the
 * Marka dosyası tab. Each gap is one line in the ONE work list (`suggestions`, decision `brand.gap`); a gap with a fix
 * runs it only on the operator's approval ("Onayla ve yap"); all fixes stay inside MoxDOP (nothing is written outside).
 * A gap that is gone is closed by itself.
 */
final class BrandGaps
{
    public const string DECISION = 'brand.gap';

    public const string FIX_ADD_SERVICES = 'add_services';

    public const string FIX_SITE_SETUP = 'site_setup';

    private const string NON_SERVICE = '/^(ana ?sayfa|home|hakkimizda|hakkinda|iletisim|blog|sss|sikca sorulan|galeri|ekibimiz|ekip|kariyer|kvkk|gizlilik|cerez|randevu|tesekkur|fiyat|referans|basinda|haber|tedavilerimiz|hizmetlerimiz)/u';

    /**
     * @return list<array{key: string, title: string, why: string, fix: ?string, params: array<string, mixed>, url: ?string}>
     */
    public function detect(Brand $brand): array
    {
        $gaps = [];
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get();
        $offerings = SiteScope::offerings($brand);
        if ($sites->isEmpty()) {
            $gaps[] = ['key' => 'no_website', 'title' => 'Web sitesi bağlı değil', 'why' => 'Hizmet sayfaları, sorgular ve içerik önerileri siteden okunur; site olmadan ajanlar çalışamaz.',
                'fix' => null, 'params' => [], 'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'assets'])];
        }
        if ($brand->sector_id === null) {
            $gaps[] = ['key' => 'no_sector', 'title' => 'Sektör seçilmemiş', 'why' => 'Sorgu kütüphanesi ve kümeler sektöre göre okunur.', 'fix' => null, 'params' => [],
                'url' => route('operator.brand.setup', ['brand' => $brand->id])];
        }
        if ($brand->serviceAreas()->where('status', 'active')->doesntExist()) {
            $gaps[] = ['key' => 'no_area', 'title' => 'Hizmet bölgesi yok', 'why' => 'Yerel sorgular, rakip ve harita önerileri bölgeye göre yapılır.', 'fix' => null, 'params' => [],
                'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'business'])];
        }
        $names = $offerings->map(fn (BrandOffering $o): string => $o->displayName())->all();
        foreach ($sites as $site) {
            $label = (string) ($site->domain ?: $site->name);
            $missing = self::uncoveredServicePages($site, $names);
            if ($missing !== []) {
                $titles = array_column($missing, 0);
                $gaps[] = ['key' => 'services:'.$site->id, 'title' => count($titles).' hizmet sayfası markanın hizmetlerinde yok ('.$label.')',
                    'why' => 'Sitede sayfası olan hizmetler: '.implode(', ', array_slice($titles, 0, 8)).(count($titles) > 8 ? ' …' : '').'. Onaylarsan hizmet olarak eklenir ve sayfalarıyla eşlenir.',
                    'fix' => self::FIX_ADD_SERVICES, 'params' => ['site_id' => (int) $site->id, 'names' => $titles], 'url' => null];
            }
            $unmatched = $offerings->isNotEmpty() && Page::query()->where('website_asset_id', $site->id)->exists()
                ? $offerings->filter(fn (BrandOffering $o): bool => DB::table('offering_pages as op')->join('pages as p', 'p.id', '=', 'op.page_id')
                    ->where('op.brand_offering_id', $o->id)->where('p.website_asset_id', $site->id)->doesntExist())->count() : 0;
            if ($unmatched > 0 && BrandDossier::servicePageCount($site) > 0) {
                $gaps[] = ['key' => 'pages:'.$site->id, 'title' => $unmatched.' hizmet hiçbir sayfayla eşleşmemiş ('.$label.')',
                    'why' => 'Sayfa ↔ hizmet eşlemesi yoksa küme sayfaları, içerik ve sorgu önerileri o hizmet için çalışmaz. Onaylarsan sayfalar kategorilenir ve hizmetlerle eşlenir (önce kurallar, kalanı AI).',
                    'fix' => self::FIX_SITE_SETUP, 'params' => ['site_id' => (int) $site->id], 'url' => null];
            }
        }
        if ($offerings->isEmpty() && $sites->isNotEmpty()) {
            $gaps[] = ['key' => 'no_services', 'title' => 'Markanın hizmeti yok', 'why' => 'Otomatik kur siteden hizmetleri çıkarır.', 'fix' => null, 'params' => [],
                'url' => route('operator.brand.setup', ['brand' => $brand->id])];
        }

        return $gaps;
    }

    /** Writes the gaps to the work list; gaps that are gone are closed. @return int open gaps */
    public function sync(Brand $brand): int
    {
        $gaps = $this->detect($brand);
        $kept = [];
        foreach ($gaps as $gap) {
            $fingerprint = hash('sha256', implode('|', [$brand->id, self::DECISION, $gap['key']]));
            $suggestion = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
            $material = hash('sha256', $gap['title'].'|'.json_encode($gap['params']));
            $reopen = in_array($suggestion->status, [Suggestion::DISMISSED, Suggestion::APPLIED], true) && $suggestion->material_hash !== $material;
            $suggestion->forceFill([
                'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint, 'material_hash' => $material,
                'title' => mb_substr($gap['title'], 0, 160), 'reason' => mb_substr($gap['why'], 0, 240), 'priority' => $gap['fix'] !== null ? 1 : 2,
                'evidence' => [['kind' => 'gap', 'value' => $gap['why'], 'source' => 'Eksikler']], 'action_type' => 'brand_gap',
                'action' => ['gap' => $gap['key'], 'fix' => $gap['fix'], 'params' => $gap['params'], 'url' => $gap['url']],
                'target_type' => 'brand', 'target_id' => (int) $brand->id,
                'status' => $suggestion->exists && ! $reopen ? $suggestion->status : Suggestion::OPEN,
                'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(),
            ])->save();
            $kept[] = (int) $suggestion->id;
        }
        Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK])
            ->whereNotIn('id', $kept ?: [0])->update(['status' => Suggestion::APPLIED, 'resolved_at' => now(), 'operator_note' => 'Eksik giderildi.']);

        return count($gaps);
    }

    /** "Onayla ve yap": runs the gap's fix (inside MoxDOP only). @return string message for the operator */
    public function apply(Suggestion $suggestion, ?User $actor): string
    {
        $action = (array) $suggestion->action;
        $brand = Brand::query()->findOrFail($suggestion->brand_id);
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->whereKey((int) data_get($action, 'params.site_id'))->first();
        $message = match ($action['fix'] ?? null) {
            self::FIX_ADD_SERVICES => $this->addServices($brand, $site, array_values(array_filter((array) data_get($action, 'params.names'), 'is_string')), $actor),
            self::FIX_SITE_SETUP => $site !== null ? $this->siteSetup($site) : throw ValidationException::withMessages(['gap' => 'Site bulunamadı.']),
            default => throw ValidationException::withMessages(['gap' => 'Bu eksiğin otomatik düzeltmesi yok; bağlantıdan elle yapın.']),
        };
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'resolved_by' => $actor?->id, 'resolved_at' => now(), 'applied_at' => now()])->save();

        return $message;
    }

    /**
     * Service pages of the site (categorized "hizmet", or not categorized yet and under the service section) that
     * none of the given service names covers — every service page the agency built is a service.
     *
     * @param  list<string>  $names
     * @return list<array{0: string, 1: string}> [title, path]
     */
    public static function uncoveredServicePages(DigitalAsset $site, array $names): array
    {
        $out = [];
        foreach (Page::query()->where('website_asset_id', $site->id)->orderBy('path')->limit(3000)->get(['url', 'path', 'title', 'h1', 'category']) as $page) {
            $path = (string) ($page->path ?: SeoText::urlPath((string) $page->url));
            if ($page->category !== 'hizmet' && ($page->category !== null || SitePagesReader::pathCategory($path) !== 'hizmet')) {
                continue;
            }
            $title = self::serviceTitle((string) ($page->h1 ?: $page->title ?: SitePagesReader::slugTitle($path)));
            if ($title === '' || preg_match(self::NON_SERVICE, SeoText::fold($title)) === 1) {
                continue;
            }
            $covered = false;
            foreach ($names as $name) {
                if (SiteText::serviceScore($title, $name) >= 0.99 || SiteText::serviceScore($name, $title) >= 0.99) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered && ! isset($out[SeoText::fold($title)])) {
                $out[SeoText::fold($title)] = [$title, $path];
                $names[] = $title;
            }
        }

        return array_values($out);
    }

    /** "Şeffaf Plak (Invisalign) - Dr. Dt. … | Ankara Diş Hekimi" → "Şeffaf Plak (Invisalign)"; the city stays (removed when added). */
    public static function serviceTitle(string $title): string
    {
        return trim((string) preg_replace('/\s+[|–—-]\s+.*$/u', '', trim($title)));
    }

    /** @param list<string> $names */
    private function addServices(Brand $brand, ?DigitalAsset $site, array $names, ?User $actor): string
    {
        $offerings = app(BrandOfferingService::class);
        $added = 0;
        foreach ($names as $name) {
            $clean = trim(LocationOptions::strip($name)['text']);
            if (mb_strlen($clean) < 2) {
                continue;
            }
            try {
                $offerings->resolveOrCreate($brand, $clean, actor: $actor);
                $added++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if ($site !== null) {
            $this->siteSetup($site);
        }

        return $added.' hizmet eklendi; sayfalarıyla eşleme arka planda başladı.';
    }

    private function siteSetup(DigitalAsset $site): string
    {
        SiteOperations::dispatch((int) $site->id, SiteOperations::SETUP);

        return 'Site hazırlanıyor (kategoriler, hizmet ↔ sayfa, küme sayfaları); bitince marka dosyası yenilenir.';
    }
}
