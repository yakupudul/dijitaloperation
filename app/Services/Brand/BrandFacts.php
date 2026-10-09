<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandExpert;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Ads\AdServiceStats;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Meta\MetaDesk;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Marka bilgi kartı (yakup, 2026-10-09 "tamam yap"): the brand facts every AI step and rule reads, each filled by rules
 * from the brand's own data with its source written next to it: services (★ first), places, experts, what searchers ask,
 * what reviews complain about and praise, what counts as a customer, other brands in the same city and service,
 * the season of demand, site languages, voice and banned phrases. No AI and no guessing: a field without data stays
 * empty and says why. A field the operator writes is locked: the nightly rebuild never overwrites it.
 *
 * Stored in `brand_memory` (kind `facts`): {auto: {field: {items, source}}, manual: {field: {text, by, at}}, built_at}.
 * Rebuilt with the brand file (BrandDossier, nightly and on "Yenile").
 */
final class BrandFacts
{
    public const string KIND = 'facts';

    /** @var array<string, string> */
    public const array FIELDS = [
        'services' => 'Hizmetler',
        'regions' => 'Bölgeler ve şubeler',
        'experts' => 'Hekimler ve uzmanlar',
        'questions' => 'Sık sorulan sorular',
        'objections' => 'Şikâyet ve itirazlar',
        'praise' => 'Övülen yönler',
        'conversions' => 'Müşteri sayılan işlemler',
        'competitors' => 'Aynı şehir ve hizmetteki diğer markalar',
        'seasonality' => 'Talep mevsimi',
        'languages' => 'Site dilleri',
        'voice' => 'Ses ve yasaklar',
    ];

    /** Review themes (folded words), for complaints in 1–3★ and praise in 4–5★ reviews. */
    private const array COMPLAINTS = [
        'Fiyat' => ['fiyat', 'pahali', 'ucret', 'para'],
        'Bekleme' => ['bekle', 'sira', 'gecikme', 'gec kald'],
        'Ulaşma ve dönüş' => ['ulasam', 'telefon', 'donus', 'cevap ver', 'aranmadi', 'donmedi'],
        'Ağrı' => ['agri', 'aci ', 'acidi', 'sizi'],
        'Tavır' => ['kaba', 'ilgisiz', 'tavir', 'saygisiz', 'ukala'],
        'Randevu' => ['randevu', 'iptal', 'erteledi'],
        'Sonuç' => ['bozuldu', 'dustu', 'kirildi', 'tekrar yap', 'iltihap', 'sorun cikti'],
    ];

    private const array PRAISE = [
        'İlgi ve güler yüz' => ['ilgili', 'guler yuz', 'nazik', 'samimi', 'sicak', 'ilgi'],
        'Ağrısız tedavi' => ['agrisiz', 'acisiz', 'hissetmedim', 'hic agri'],
        'Açık bilgilendirme' => ['anlatti', 'bilgilendir', 'acikladi', 'detayli', 'aciklayici'],
        'Temizlik ve hijyen' => ['temiz', 'hijyen', 'steril'],
        'Sonuçtan memnuniyet' => ['memnun', 'harika sonuc', 'gulus', 'mukemmel', 'sonuc'],
        'Hız ve dakiklik' => ['hizli', 'zamaninda', 'tek seans', 'beklemeden'],
        'Uygun fiyat' => ['uygun fiyat', 'makul', 'fiyat performans', 'uygun'],
        'Uzmanlık' => ['uzman', 'tecrubeli', 'deneyimli', 'profesyonel', 'isinin ehli'],
    ];

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    private const array MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    /** A theme needs at least this many reviews. */
    private const int THEME_MIN = 2;

    private const int ITEMS = 8;

    public function __construct(private readonly SiteAnalysisReader $reader) {}

    /**
     * Rebuilds the automatic fields (the operator's locked fields are kept) and stores the card.
     *
     * @return array{fields: array<string, array{key: string, label: string, items: list<string>, source: string, locked: bool, manual: ?string, manual_at: ?string}>, built_at: string}
     */
    public function build(Brand $brand): array
    {
        $auto = [];
        foreach (array_keys(self::FIELDS) as $key) {
            try {
                $auto[$key] = $this->field($key, $brand);
            } catch (Throwable $exception) {
                report($exception);
                $auto[$key] = ['items' => [], 'source' => 'Okunamadı; bir sonraki gece yeniden denenir.'];
            }
        }
        $row = self::row($brand) ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id]);
        $data = (array) ($row->data ?? []);
        $data['auto'] = $auto;
        $data['manual'] = (array) ($data['manual'] ?? []);
        $data['built_at'] = now()->toIso8601String();
        $row->forceFill(['data' => $data, 'summary' => null])->save();

        return self::shape($data);
    }

    /** The stored card, built now when missing. */
    public function card(Brand $brand): array
    {
        $row = self::row($brand);

        return $row !== null && isset($row->data['auto']) ? self::shape((array) $row->data) : $this->build($brand);
    }

    /** The operator writes a field: it is locked from then on (an empty text unlocks it). */
    public static function write(Brand $brand, string $key, string $text, ?int $userId): void
    {
        if (! isset(self::FIELDS[$key])) {
            return;
        }
        $row = self::row($brand) ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id, 'data' => []]);
        $data = (array) ($row->data ?? []);
        $text = trim(mb_substr($text, 0, 2000));
        if ($text === '') {
            unset($data['manual'][$key]);
        } else {
            $data['manual'][$key] = ['text' => $text, 'by' => $userId, 'at' => now()->toIso8601String()];
        }
        $row->forceFill(['data' => $data])->save();
    }

    /**
     * The card for an AI pack: each filled field's lines, the operator's text where locked.
     *
     * @return array<string, list<string>>
     */
    public function forPrompt(Brand $brand): array
    {
        $out = [];
        foreach ($this->card($brand)['fields'] as $key => $field) {
            $lines = $field['locked'] ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $field['manual']) ?: []))) : $field['items'];
            if ($lines !== []) {
                $out[$key] = $lines;
            }
        }

        return $out;
    }

    /** The card as a markdown section of the brand file. */
    public function markdown(Brand $brand): string
    {
        $card = $this->build($brand);
        $lines = [];
        foreach ($card['fields'] as $field) {
            $values = $field['locked'] ? (string) $field['manual'] : implode('; ', $field['items']);
            $lines[] = '- **'.$field['label'].'**: '.($values !== '' ? $values : '—').' _('.($field['locked'] ? 'operatör yazdı' : $field['source']).')_';
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $data */
    private static function shape(array $data): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $label) {
            $auto = (array) ($data['auto'][$key] ?? []);
            $manual = $data['manual'][$key] ?? null;
            $fields[$key] = ['key' => $key, 'label' => $label, 'items' => array_values(array_map('strval', (array) ($auto['items'] ?? []))),
                'source' => (string) ($auto['source'] ?? ''), 'locked' => is_array($manual), 'manual' => is_array($manual) ? (string) $manual['text'] : null,
                'manual_at' => is_array($manual) ? (string) ($manual['at'] ?? '') : null];
        }

        return ['fields' => $fields, 'built_at' => (string) ($data['built_at'] ?? '')];
    }

    private static function row(Brand $brand): ?BrandMemory
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->first();
    }

    /** @return array{items: list<string>, source: string} */
    private function field(string $key, Brand $brand): array
    {
        return match ($key) {
            'services' => $this->services($brand),
            'regions' => $this->regions($brand),
            'experts' => $this->experts($brand),
            'questions' => $this->questions($brand),
            'objections' => $this->themes($brand, self::COMPLAINTS, [1, 2, 3], 'olumsuz'),
            'praise' => $this->themes($brand, self::PRAISE, [4, 5], 'olumlu'),
            'conversions' => $this->conversions($brand),
            'competitors' => $this->competitors($brand),
            'seasonality' => $this->seasonality($brand),
            'languages' => $this->languages($brand),
            'voice' => $this->voice($brand),
        };
    }

    /** @return array{items: list<string>, source: string} */
    private function services(Brand $brand): array
    {
        $items = SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => ($o->isMain() ? '★ ' : '').$o->displayName())->filter()->unique()->values()->all();

        return ['items' => array_slice($items, 0, 20), 'source' => $items !== [] ? 'Marka › Ayarlar › Hizmetler' : 'Hizmet yok; gece çalışan marka tamamlama siteden doldurmayı dener.'];
    }

    /** @return array{items: list<string>, source: string} */
    private function regions(Brand $brand): array
    {
        $items = SiteScope::areas($brand)->map(fn ($a): string => $a->label().($a->physical_branch ? ' (şube)' : ''))->filter()->unique()->values()->all();

        return ['items' => array_slice($items, 0, 12), 'source' => $items !== [] ? 'Hizmet verdiği yerler (İşletme Profili adresleri ve site)' : 'Tanımlı yer yok.'];
    }

    /** @return array{items: list<string>, source: string} */
    private function experts(Brand $brand): array
    {
        $items = BrandExpert::query()->where('brand_id', $brand->id)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (BrandExpert $e): string => trim($e->name.($e->title ? ' · '.$e->title : '')))->filter()->values()->all();
        $sources = $items !== [] ? ['Marka › Ayarlar › Uzmanlar'] : [];
        $resources = $this->profileResources($brand);
        if ($resources !== []) {
            $named = array_map(fn (array $d): string => $d['name'].' ('.$d['count'].' yorumda)', app(ReviewDesk::class)->topics($resources, [(int) $brand->id])['doctors']);
            $known = array_map(fn (string $i): string => Str::ascii(ReviewDesk::lower($i)), $items);
            foreach ($named as $line) {
                $first = Str::ascii(ReviewDesk::lower(strtok($line, ' ') ?: ''));
                if (! collect($known)->contains(fn (string $k): bool => $first !== '' && str_contains($k, $first))) {
                    $items[] = $line;
                }
            }
            if ($named !== []) {
                $sources[] = 'İşletme Profili yorumlarında geçen isimler';
            }
        }

        return ['items' => array_slice($items, 0, self::ITEMS), 'source' => $sources !== [] ? implode(' + ', $sources) : 'Uzman girilmedi, yorumlarda isim geçmiyor.'];
    }

    /** @return array{items: list<string>, source: string} */
    private function questions(Brand $brand): array
    {
        $rows = [];
        foreach ($this->websites($brand) as $site) {
            foreach ($this->reader->queries($site, 90) as $q) {
                if (ContentPlanner::isQuestion((string) $q['query']) && $q['impressions'] >= 10) {
                    $rows[(string) $q['query']] = ($rows[(string) $q['query']] ?? 0) + (int) $q['impressions'];
                }
            }
        }
        arsort($rows);
        $items = [];
        foreach (array_slice($rows, 0, self::ITEMS, true) as $query => $impressions) {
            $items[] = $query.' ('.number_format($impressions, 0, ',', '.').' gösterim)';
        }

        return ['items' => $items, 'source' => $items !== [] ? 'Search Console soru aramaları, 90 gün' : 'Search Console\'da soru biçiminde arama yok.'];
    }

    /**
     * @param  array<string, list<string>>  $themes
     * @param  list<int>  $stars
     * @return array{items: list<string>, source: string}
     */
    private function themes(Brand $brand, array $themes, array $stars, string $kind): array
    {
        $resources = $this->profileResources($brand);
        if ($resources === []) {
            return ['items' => [], 'source' => 'Bağlı İşletme Profili yok.'];
        }
        $reviews = DB::table('gbp_reviews')->whereIn('external_resource_id', $resources)->whereNotNull('comment')->where('create_time', '>=', now()->subMonths(18))
            ->orderByDesc('create_time')->limit(1500)->get(['comment', 'star_rating'])
            ->filter(fn (object $r): bool => in_array(self::STARS[strtoupper((string) $r->star_rating)] ?? 0, $stars, true))
            ->map(fn (object $r): string => ' '.preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(ReviewDesk::lower(ReviewDesk::original((string) $r->comment)))).' ');
        $counts = [];
        foreach ($themes as $label => $words) {
            $n = $reviews->filter(fn (string $text): bool => collect($words)->contains(fn (string $w): bool => str_contains($text, ' '.$w)))->count();
            if ($n >= self::THEME_MIN) {
                $counts[$label] = $n;
            }
        }
        arsort($counts);
        $items = array_map(fn (string $label, int $n): string => $label.' ('.$n.' yorum)', array_keys($counts), array_values($counts));

        return ['items' => array_slice($items, 0, self::ITEMS),
            'source' => $reviews->isEmpty() ? 'Son 18 ayda '.$kind.' yorum yok.' : 'İşletme Profili yorumları, son 18 ay, '.$reviews->count().' '.$kind.' yorum'];
    }

    /** @return array{items: list<string>, source: string} */
    private function conversions(Brand $brand): array
    {
        $rows = BrandConversionSource::query()->where('brand_id', $brand->id)->where('counts', true)->get(['label', 'origin']);
        $items = $rows->pluck('label')->map(fn ($l): string => (string) $l)->filter()->unique()->values()->all();
        $auto = $rows->isNotEmpty() && $rows->every(fn (BrandConversionSource $s): bool => $s->origin === BrandConversionSource::ORIGIN_AUTO);

        return ['items' => array_slice($items, 0, self::ITEMS), 'source' => match (true) {
            $items === [] => 'Seçilmedi (Marka › Ayarlar › Dönüşümler).',
            $auto => 'GA4 / Google Ads / Meta olaylarından otomatik seçildi',
            default => 'Operatör seçti (Marka › Ayarlar › Dönüşümler)',
        }];
    }

    /** @return array{items: list<string>, source: string} */
    private function competitors(Brand $brand): array
    {
        $city = AdServiceStats::city($brand);
        $serviceIds = SiteScope::offerings($brand)->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($city === '' || $serviceIds === []) {
            return ['items' => [], 'source' => $city === '' ? 'Markanın şehri tanımlı değil.' : 'Hizmet yok.'];
        }
        $shared = [];
        foreach (['ad_service_stats', 'web_service_stats'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->whereIn('service_id', $serviceIds)->where('brand_id', '!=', $brand->id)->whereRaw('lower(city) = ?', [mb_strtolower($city)])
                ->distinct()->get(['brand_id', 'service_id'])->each(function (object $r) use (&$shared): void {
                    $shared[(int) $r->brand_id][(int) $r->service_id] = true;
                });
        }
        if ($shared === []) {
            return ['items' => [], 'source' => $city.' şehrinde aynı hizmeti veren başka müşteri yok.'];
        }
        uasort($shared, fn (array $a, array $b): int => count($b) <=> count($a));
        $names = Brand::query()->whereIn('id', array_keys($shared))->pluck('name', 'id')->all();
        $services = MetaDesk::serviceNames(array_values(array_unique(array_merge(...array_map('array_keys', array_values($shared))))));
        $items = [];
        foreach (array_slice($shared, 0, self::ITEMS, true) as $brandId => $ids) {
            $items[] = ($names[$brandId] ?? '#'.$brandId).' ('.implode(', ', array_slice(array_filter(array_map(fn (int $id): string => (string) ($services[$id] ?? ''), array_keys($ids))), 0, 3)).')';
        }

        return ['items' => $items, 'source' => 'Kazananlar verisi: '.$city.', aynı hizmet (yalnız Moximu müşterileri)'];
    }

    /** @return array{items: list<string>, source: string} */
    private function seasonality(Brand $brand): array
    {
        $resources = [];
        foreach ($this->websites($brand) as $site) {
            $resources = array_merge($resources, $this->reader->window($site, 28)['gsc']);
        }
        if ($resources === [] || ! Schema::hasTable('gsc_property_daily')) {
            return ['items' => [], 'source' => 'Search Console bağlı değil.'];
        }
        $from = CarbonImmutable::today()->subMonths(12)->startOfMonth();
        $months = [];
        DB::table('gsc_property_daily')->whereIn('external_resource_id', array_values(array_unique($resources)))
            ->when(Schema::hasColumn('gsc_property_daily', 'search_type'), fn ($q) => $q->where('search_type', 'web'))
            ->where('reporting_date', '>=', $from->toDateString())->where('reporting_date', '<', CarbonImmutable::today()->startOfMonth()->toDateString())
            ->get(['reporting_date', 'impressions'])->each(function (object $r) use (&$months): void {
                $month = (int) substr((string) $r->reporting_date, 5, 2);
                $months[$month] = ($months[$month] ?? 0) + (int) $r->impressions;
            });
        if (count($months) < 9 || array_sum($months) === 0) {
            return ['items' => [], 'source' => 'Search Console\'da en az 9 aylık veri yok ('.count($months).' ay).'];
        }
        $average = array_sum($months) / count($months);
        $high = array_keys(array_filter($months, fn (int $v): bool => $v >= $average * 1.15));
        $low = array_keys(array_filter($months, fn (int $v): bool => $v <= $average * 0.85));
        sort($high);
        sort($low);
        $items = array_values(array_filter([
            $high !== [] ? 'Yoğun: '.implode(', ', array_map(fn (int $m): string => self::MONTHS[$m], $high)) : null,
            $low !== [] ? 'Sakin: '.implode(', ', array_map(fn (int $m): string => self::MONTHS[$m], $low)) : null,
        ]));

        return ['items' => $items !== [] ? $items : ['Yıl boyunca dengeli'], 'source' => 'Search Console aylık gösterim, son '.count($months).' ay (ortalamanın %15 üstü / altı)'];
    }

    /** @return array{items: list<string>, source: string} */
    private function languages(Brand $brand): array
    {
        $items = [];
        foreach ($this->websites($brand) as $site) {
            $main = SiteScope::primaryLanguage($site);
            $counts = Page::query()->where('website_asset_id', $site->id)->whereNotNull('language')->groupBy('language')->selectRaw('language, count(*) as n')
                ->orderByDesc('n')->pluck('n', 'language')->all();
            foreach ($counts as $language => $n) {
                $items[] = strtoupper(substr((string) $language, 0, 2)).' · '.$n.' sayfa'.(strtolower(substr((string) $language, 0, 2)) === strtolower((string) $main) ? ' (ana dil)' : '').' · '.$site->name;
            }
        }

        return ['items' => array_slice($items, 0, self::ITEMS), 'source' => $items !== [] ? 'Site taraması (sayfa dilleri)' : 'Sayfa dili okunmadı.'];
    }

    /** @return array{items: list<string>, source: string} */
    private function voice(Brand $brand): array
    {
        $brand->loadMissing('sectorCategory');
        $phrases = ForbiddenTerms::forBrand($brand)->phrases();
        $constraints = array_values(array_filter(array_map('trim', preg_split('/\R/u', BrandDossier::notes($brand)['constraints']) ?: [])));
        $items = array_values(array_filter([
            $brand->sectorCategory?->name !== null ? 'Sektör: '.$brand->sectorCategory->name : null,
            $phrases !== [] ? count($phrases).' yasaklı ifade, ör. '.implode(', ', array_slice($phrases, 0, 5)) : null,
            ...array_slice($constraints, 0, 5),
        ]));

        return ['items' => $items, 'source' => 'Sektör kuralları + Ayarlar › İş bağlamı kısıtları'];
    }

    /** @return list<DigitalAsset> */
    private function websites(Brand $brand): array
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get()->all();
    }

    /** @return list<int> the brand's Business Profile resources */
    private function profileResources(Brand $brand): array
    {
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->pluck('id')->all();

        return CoreAssetBinding::query()->whereIn('digital_asset_id', $assets ?: [0])->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->distinct()->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
