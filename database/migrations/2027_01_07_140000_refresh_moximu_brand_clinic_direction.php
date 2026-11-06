<?php

use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Brand\BrandDossier;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\BrandCommercialContextService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Site\ServicePageMapper;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * yakup 2026-11-06 ("Moximu.com'u güncelledik artık rotamız belli; moxdop'taki moximu markamızın verileri hedefleri
 * vs. halen eski"): the agency's own brand card still described the old web-design / e-commerce agency. It now follows
 * the live moximu.com: a clinic-only growth agency on monthly subscription. Services become the seven on the site
 * (Klinik SEO and Google İşletme Profili are the ★ main ones), the old e-commerce / hosting / plug-in services are
 * archived (restorable from Marka › Hizmetler), and the business context, goals, conversion goals, constraints and
 * the cities on the site's city pages are rewritten. Then the service pages are linked, the brand file and the
 * epikriz are rebuilt. Runs once (the cache key), skipped in tests; apply() is called by the test directly.
 */
return new class extends Migration
{
    /** label => [site path, description] in the order of the site's menu. */
    public const array SERVICES = [
        'Klinik SEO' => ['/hizmetlerimiz/klinik-seo/', 'Tedavi ve ilçe aramalarında Google’da üst sıralar: teknik düzeltme, tedavi × ilçe sayfaları, hekim onaylı haftalık içerik, aylık tek sayfa rapor.'],
        'Google İşletme Profili Yönetimi' => ['/hizmetlerimiz/google-isletme-profili/', 'Google harita kaydı ve İşletme Profili: kayıt, doğrulama, haftalık gönderi, yorum cevapları, harita sıralaması takibi.'],
        'Google Ads Danışmanlığı' => ['/hizmetlerimiz/google-ads/', 'Klinik için Google arama reklamlarının kurulumu ve yönetimi; reklam bütçesi klinik tarafından ödenir.'],
        'Instagram ve Facebook Reklamları' => ['/hizmetlerimiz/instagram-facebook-reklamlari/', 'Meta reklamları: form ve WhatsApp kampanyaları; yurt dışı hasta için ülke bazlı.'],
        'Klinik Web Sitesi' => ['/hizmetlerimiz/klinik-web-sitesi/', 'Tedavi ve hekim sayfaları kurulu, hızlı açılan klinik sitesi; randevu formu, WhatsApp ve ölçüm kurulumu dahil.'],
        'Yerel Klinik Paketi' => ['/fiyatlar/yerel-klinik-paketi/', 'Dört kanal tek fiyatla: SEO + İşletme Profili, Google Ads ve Instagram/Facebook reklamı.'],
        'Sağlık Turizmi Paketi' => ['/fiyatlar/saglik-turizmi-paketi/', 'Yurt dışından hasta için çok dilli site, ülke sayfaları ve yurt dışı reklam.'],
    ];

    /** ★ main services (the site's recommended plan: SEO + İşletme Profili). */
    public const array MAIN = ['Klinik SEO', 'Google İşletme Profili Yönetimi'];

    /** Cities with their own page on the site (/sehirler/…). */
    public const array CITIES = ['Manisa', 'İzmir', 'İstanbul', 'Ankara', 'Antalya', 'Bursa'];

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $brand = Brand::query()->where('name', 'Moximu')->first();
        if ($brand === null || ! Cache::add('migration:moximu-brand-clinic-direction', 1, now()->addYear())) {
            return;
        }
        try {
            $this->apply($brand);
        } catch (Throwable $e) {
            Cache::forget('migration:moximu-brand-clinic-direction');
            Log::warning('Moximu brand refresh failed.', ['error' => $e->getMessage()]);
        }
    }

    public function apply(Brand $brand): void
    {
        $catalog = app(ServiceCatalogService::class);
        $offerings = app(BrandOfferingService::class);

        // 1) Services: the site's seven, locked (the operator's list); everything else archived.
        $keep = [];
        foreach (self::SERVICES as $label => [, $description]) {
            $item = $catalog->resolveOrCreate($label, $brand->sector, $description)['service'];
            $offering = $offerings->resolveOrCreate($brand, $label, recordActivity: false, catalog: $item)['offering'];
            $offering->forceFill(['status' => 'active', 'locked' => true])->save();
            $keep[$label] = $offering;
        }
        $keepIds = collect($keep)->map(fn (BrandOffering $o): int => (int) $o->id)->values()->all();
        BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNotIn('id', $keepIds)->get()
            ->each(fn (BrandOffering $o) => $offerings->archive($o));
        $offerings->setPriorityOrder($brand, array_map(fn (string $l): int => (int) $keep[$l]->id, self::MAIN), recordActivity: false);

        // 2) Cities on the site; the Manisa office stays the physical branch.
        $areas = app(BrandCommercialContextService::class);
        foreach (self::CITIES as $city) {
            try {
                $areas->addServiceArea($brand, ['country_code' => 'TR', 'city_name' => $city, 'district_name' => '']);
            } catch (Throwable) {
                // archived earlier: left as the operator set it
            }
        }

        // 3) Business context, goals, conversion goals.
        $goals = [
            'Kliniklere hasta kazandıran klinik büyüme ajansı olarak tanınmak; yalnız klinik, muayenehane ve hastanelerle çalışmak.',
            'Aylık abonelikli klinik müşteri sayısını artırmak; önerilen başlangıç planı SEO + İşletme Profili.',
            'Kasım kampanyası: 30 Kasım 2026 sonuna kadar 6 aylık planla başlayan kliniklere klinik web sitesi hediye.',
            'Sağlık turizmi yapan kliniklere çok dilli site ve yurt dışı reklam (Almanya, İngiltere, Hollanda, Belçika, Fransa, Avusturya, İsviçre).',
            'Branş sayfalarıyla (diş, estetik, dermatoloji, saç ekimi, psikolog, diyetisyen, fizyoterapi, hastane) Google’da klinik sahiplerinin aramalarında görünmek.',
        ];
        $constraints = implode("\n", [
            'Yalnız klinik ve sağlık işletmelerine yazılır; e-ticaret, Ticimax, Shopify, hosting, eklenti gibi eski hizmetler anılmaz.',
            'Garanti, kesin sıralama, "en iyi", öncesi/sonrası ve indirim dili kullanılmaz; sonuç vaadi verilmez.',
            '"Google kartı" değil "Google İşletme Profili" yazılır. Sosyal medya yönetimi satılmaz, yalnız Instagram ve Facebook reklamı.',
            'MoxDOP, iç araçlar, "tek onay", "1 ekip" anılmaz. Ekibimiz sayfası ve ekip büyüklüğü iddiası yok.',
            'Fiyatlar KDV hariç, reklam bütçesi ayrı ve klinik tarafından ödenir; taahhüt zorunlu değildir, "en az 6 ay" denmez.',
            'Kurumsal, sade Türkçe; samimi slogan ve büyük harf slogan yok. Referanslarda yalnız logo ve ölçülmüş yüzde artış; yurt içi reklam vakası yazılmaz.',
        ]);
        app(BrandIntelligenceContextWriteService::class)->saveFromForm($brand, [
            'business_summary' => 'Moximu, yalnız kliniklerle, muayenehanelerle ve hastanelerle çalışan klinik SEO ve dijital pazarlama ajansıdır. '
                .'Klinik SEO, Google İşletme Profili yönetimi, Google Ads ve Instagram/Facebook reklamlarıyla hastanın kliniği bulmasını sağlar; '
                .'site yoksa kurar, eskiyse yeniler. Sağlık turizmi yapan kliniklere çok dilli site ve yurt dışı reklam sunar. Manisa’dan bütün Türkiye’ye hizmet verir.',
            'business_model' => 'Klinik ajansı — aylık abonelik',
            'priority_offerings' => self::MAIN,
            'target_audiences' => array_map(fn (string $v): array => ['name' => $v, 'note' => null], [
                'Diş kliniği sahipleri ve diş hekimleri',
                'Estetik, dermatoloji ve saç ekimi klinikleri',
                'Muayenehanesi olan hekimler; psikolog, diyetisyen, fizyoterapist',
                'Sağlık turizmi yapan klinikler ve hastaneler',
                'Yeni açılan klinikler',
            ]),
            'business_goals' => array_map(fn (string $v): array => ['goal' => $v, 'note' => null], $goals),
            'conversion_goals' => [
                ['type' => ConversionGoalTypes::FORM_SUBMISSION, 'label' => 'Ücretsiz ön değerlendirme formu', 'note' => 'Sitedeki form; 24 saatte ilk bulgular.'],
                ['type' => ConversionGoalTypes::WHATSAPP_CONVERSATION, 'label' => 'WhatsApp’tan yazan klinik', 'note' => null],
                ['type' => ConversionGoalTypes::PHONE_CALL, 'label' => 'Telefonla arayan klinik', 'note' => 'Hafta içi 09:00–18:00.'],
                ['type' => ConversionGoalTypes::QUALIFIED_LEAD, 'label' => 'Aylık plana başlayan klinik', 'note' => null],
            ],
            'positioning' => 'Kliniklere hasta kazandıran, yalnız sağlık alanında çalışan klinik büyüme ajansı: aramada, haritada ve reklamda görünürlük tek planla, açık fiyatla.',
            'differentiators' => [
                'Yalnız kliniklerle ve muayenehanelerle çalışır',
                'İşi yapan kişiyle konuşulur; arada hesap yöneticisi yoktur',
                'Google Ads, İşletme Profili, Meta ve site kliniğin adına açılır; ayrılınca kliniğe kalır',
                'Her ay tek sayfa rapor: talep sayısı, talep maliyeti, kanal ve bu ayın kararı',
                'Sağlık reklamı kurallarına uygun metin; hekim onayı olmadan yayın yok',
                'Açık fiyat, kurulum ücreti yok, taahhüt zorunlu değil',
                '12 yılda 500’den fazla web sitesi, 50’den fazla klinik deneyimi',
            ],
            'known_competitors' => (array) ($brand->intelligenceContext?->known_competitors ?? []),
            'important_constraints' => $constraints,
            'target_markets' => (array) ($brand->intelligenceContext?->target_markets ?? []),
            'products_services' => [],
        ]);
        BrandDossier::saveNotes($brand, implode("\n", $goals), $constraints);

        // 4) Service ↔ page, by the site's own paths (locked like an operator's choice).
        $site = DigitalAsset::query()->where('type', 'website')->where('brand_id', $brand->id)->orderBy('id')->first();
        if ($site !== null) {
            $mapper = app(ServicePageMapper::class);
            $pages = Page::query()->where('website_asset_id', $site->id)->get(['id', 'url', 'path', 'website_asset_id']);
            foreach (self::SERVICES as $label => [$path]) {
                $page = $pages->first(fn (Page $p): bool => rtrim((string) ($p->path ?: parse_url((string) $p->url, PHP_URL_PATH)), '/').'/' === $path);
                if ($page !== null) {
                    $mapper->setOffering($page, (int) $keep[$label]->id);
                }
            }
        }

        // 5) Rebuild the brand file and the epikriz from the new records.
        foreach ([fn () => app(BrandDossier::class)->build($brand->fresh() ?? $brand), fn () => app(Epicrisis::class)->build($brand->fresh() ?? $brand)] as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                report($e);
            }
        }
        RefreshBrandFilesJob::soon((int) $brand->id);
    }

    public function down(): void {}
};
