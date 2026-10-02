<?php

namespace App\Services\Site\Analysis;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teknik SEO of a website from stored rows only: what Google reports (latest Search Console URL inspection per page,
 * sitemaps) and what the site's own HTML shows (latest crawl issues per URL, duplicate titles of the page inventory).
 * Each finding: severity (kritik / uyarı / bilgi), what is happening, how to fix it, who fixes it (WordPress'ten ·
 * geliştirici · Google) and the affected pages. Nothing is guessed: a missing source gives no findings and says so.
 *
 * @phpstan-type Finding array{key: string, source: string, severity: string, title: string, why: string, fix: list<string>, fixer: string, action: string, pages: list<string>, count: int}
 */
final class TechnicalSeoReader
{
    public const array SEVERITIES = ['critical' => 'Kritik', 'warning' => 'Uyarı', 'info' => 'Bilgi'];

    public const array SOURCES = ['google' => 'Google (Search Console)', 'html' => 'Sitenin HTML’i'];

    public const array FIXERS = ['wordpress' => 'WordPress’ten düzeltilir', 'developer' => 'Geliştirici gerekir', 'google' => 'Google tarafında'];

    /** Pages listed per finding. */
    private const int PAGES = 50;

    /**
     * Search Console coverage states (Google's English text, matched case-insensitively) → finding.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>, 6: string, 7: string}> needle, key, severity, title, why, fix, fixer, action
     */
    private const array COVERAGE = [
        ['soft 404', 'g_soft_404', 'warning', 'Google sayfayı boş sayıyor (soft 404)', 'Sayfa açılıyor ama Google içeriği yetersiz ya da “bulunamadı” gibi görüyor; dizine almıyor.', ['Sayfaya gerçek içerik ekleyin ya da ilgili sayfaya 301 ile yönlendirin.', 'Search Console’da “Düzeltmeyi doğrula” deyin.'], 'wordpress', 'İçeriği düzelt'],
        ['not found (404)', 'g_404', 'critical', 'Google 404 buluyor', 'Google bu adresleri biliyor ama sayfa yok; bağlantı ve sıralama değeri kayboluyor.', ['Her adresi en yakın sayfaya 301 ile yönlendirin.', 'Siteden bu adrese giden iç bağlantıları düzeltin.'], 'wordpress', '301 yönlendir'],
        ['server error', 'g_5xx', 'critical', 'Sunucu hatası (5xx)', 'Google sayfayı istediğinde sunucu hata verdi; tekrarlanırsa sayfa dizinden düşer.', ['Sunucu / hosting hata kayıtlarına bakın.', 'Eklenti çakışması ya da bellek sınırı olabilir.'], 'developer', 'Geliştiriciye ilet'],
        ['redirect error', 'g_redirect_error', 'critical', 'Yönlendirme hatası', 'Yönlendirme döngüsü, çok uzun zincir ya da bozuk hedef var.', ['Yönlendirmeyi doğrudan son adrese çevirin.', 'Döngü yaratan kuralı kaldırın.'], 'developer', 'Geliştiriciye ilet'],
        ['blocked by robots.txt', 'g_robots', 'critical', 'robots.txt engelliyor', 'robots.txt Google’ın bu sayfaları taramasını engelliyor.', ['robots.txt’deki ilgili Disallow satırını kaldırın.'], 'developer', 'Geliştiriciye ilet'],
        ['unauthorized', 'g_401', 'critical', 'Erişim engeli (401/403)', 'Sayfa giriş ya da izin istiyor; Google içeriği göremiyor.', ['Herkese açık olması gereken sayfaların erişim kısıtını kaldırın.'], 'developer', 'Geliştiriciye ilet'],
        ['forbidden', 'g_401', 'critical', 'Erişim engeli (401/403)', 'Sayfa giriş ya da izin istiyor; Google içeriği göremiyor.', ['Herkese açık olması gereken sayfaların erişim kısıtını kaldırın.'], 'developer', 'Geliştiriciye ilet'],
        ['noindex', 'g_noindex', 'warning', '“noindex” ile dışarıda bırakılmış', 'Sayfada noindex var; Google dizine almıyor. Bilerek değilse trafik kaybı.', ['WordPress’te SEO eklentisinden (Yoast / Rank Math) “arama motorlarına göster”i açın.', 'Bilerek kapalıysa dokunmayın.'], 'wordpress', 'noindex kaldır'],
        ['crawled - currently not indexed', 'g_crawled_not_indexed', 'warning', 'Tarandı, dizine eklenmedi', 'Google sayfayı okudu ama dizine almaya değer bulmadı: içerik zayıf, benzer ya da iç bağlantı az.', ['İçeriği zenginleştirin (soru, fiyat, bölge, görsel).', 'Önemli sayfalardan bu sayfaya iç bağlantı verin.'], 'wordpress', 'İçeriği güçlendir'],
        ['discovered - currently not indexed', 'g_discovered_not_indexed', 'warning', 'Bulundu, henüz taranmadı', 'Google adresi biliyor ama taramaya sıra gelmedi; genelde iç bağlantı az ya da site yavaş.', ['Sayfaya menü / ilgili sayfalardan bağlantı verin.', 'Site haritasında olduğundan emin olun.'], 'wordpress', 'İç bağlantı ekle'],
        ['duplicate without user-selected canonical', 'g_duplicate', 'warning', 'Kopya sayfa, canonical seçilmemiş', 'Google bu sayfayı başka bir sayfanın kopyası sayıyor ve hangisinin asıl olduğunu siz belirtmemişsiniz.', ['Asıl sayfayı canonical olarak işaretleyin ya da kopyayı 301 ile birleştirin.'], 'wordpress', 'Canonical belirle'],
        ['google chose different canonical', 'g_canonical_diff', 'warning', 'Google başka canonical seçti', 'Belirttiğiniz canonical ile Google’ın seçtiği farklı; sinyaller bölünüyor.', ['İçeriği gerçekten farklılaştırın ya da Google’ın seçtiği sayfaya birleştirin.'], 'wordpress', 'Birleştir / ayır'],
        ['indexed, not submitted in sitemap', 'g_not_in_sitemap', 'info', 'Dizinde ama site haritasında yok', 'Sayfa dizinde; ancak site haritasında olmadığı için güncellemeleri geç fark edilebilir.', ['SEO eklentisinin site haritası ayarında bu içerik türünü açın.'], 'wordpress', 'Site haritasına ekle'],
        ['page with redirect', 'g_redirect', 'info', 'Yönlendirilen sayfa', 'Adres başka sayfaya yönleniyor; Google hedefi dizine alır. Genelde sorun değil.', ['Site içindeki bağlantıları doğrudan hedef adrese çevirin.'], 'wordpress', 'Bağlantıyı güncelle'],
        ['alternate page with proper canonical', 'g_alternate', 'info', 'Doğru canonical’lı alternatif sayfa', 'Sayfa asıl sayfayı canonical gösteriyor; beklenen durum.', ['Bir şey yapmanız gerekmez.'], 'google', '—'],
        ['unknown to google', 'g_unknown', 'info', 'Google bu adresi bilmiyor', 'Google bu sayfayı hiç görmemiş.', ['Site haritasına ekleyin ve iç bağlantı verin; Search Console’dan dizine eklenmesini isteyin.'], 'google', 'Dizine ekleme iste'],
    ];

    /** Crawl issue codes → finding (severity, title, why, fix, fixer, action). */
    private const array HTML = [
        'HTTP_5XX' => ['critical', 'Sayfa sunucu hatası veriyor', 'Taramada sayfa 5xx döndü; ziyaretçi ve Google sayfayı göremiyor.', ['Hosting hata kayıtlarına bakın; son eklenti / tema güncellemesini kontrol edin.'], 'developer', 'Geliştiriciye ilet'],
        'WORDPRESS_CRITICAL_ERROR' => ['critical', 'WordPress kritik hata ekranı', 'Sayfa “Kritik bir hata oluştu” gösteriyor.', ['Kurtarma modu e-postasından ya da hata kayıtlarından sorunlu eklentiyi bulun.'], 'developer', 'Geliştiriciye ilet'],
        'WORDPRESS_DATABASE_ERROR' => ['critical', 'WordPress veritabanı hatası', 'Sayfa veritabanına bağlanamıyor.', ['Hosting veritabanı ayarlarını ve kotasını kontrol edin.'], 'developer', 'Geliştiriciye ilet'],
        'APPLICATION_ERROR_PAGE' => ['critical', 'Hata / bakım sayfası', 'Sayfa hata ya da bakım ekranı gösteriyor.', ['Bakım modunu kapatın ya da hatayı giderin.'], 'developer', 'Geliştiriciye ilet'],
        'FETCH_FAILED' => ['critical', 'Sayfa alınamadı', 'Tarayıcımız sayfaya ulaşamadı (zaman aşımı / bağlantı).', ['Sitenin erişilebilir ve hızlı olduğundan emin olun; güvenlik duvarı engelliyor olabilir.'], 'developer', 'Geliştiriciye ilet'],
        'HTTP_4XX' => ['critical', 'Kırık sayfa (4xx)', 'Sitede bağlantı verilen sayfa açılmıyor.', ['İlgili sayfaya 301 yönlendirme ekleyin ya da bağlantıyı düzeltin.'], 'wordpress', '301 yönlendir'],
        'MISSING_TITLE' => ['critical', 'Başlık (title) yok', 'Arama sonucunda gösterilecek başlık yok; Google kendisi uydurur.', ['SEO eklentisinde her sayfaya hizmet + bölge içeren bir başlık yazın.'], 'wordpress', 'Başlık yaz'],
        'SOFT_404' => ['warning', 'İçi boş sayfa (soft 404)', 'Sayfa açılıyor ama “bulunamadı” gibi görünüyor.', ['Gerçek içerik ekleyin ya da 301 ile yönlendirin.'], 'wordpress', 'İçeriği düzelt'],
        'NOINDEX' => ['warning', 'noindex etiketi var', 'Sayfa arama motorlarına kapalı.', ['Bilerek değilse SEO eklentisinden dizine açın.'], 'wordpress', 'noindex kaldır'],
        'MISSING_META_DESCRIPTION' => ['warning', 'Meta açıklama yok', 'Arama sonucundaki açıklamayı Google sayfadan rastgele seçiyor; tıklama oranı düşebilir.', ['SEO eklentisinde 140–160 karakterlik, harekete çağıran açıklama yazın.'], 'wordpress', 'Açıklama yaz'],
        'MISSING_H1' => ['warning', 'H1 başlığı yok', 'Sayfanın ana başlığı yok; konusu belirsiz kalıyor.', ['Sayfanın en üstüne konuyu söyleyen tek bir H1 ekleyin.'], 'wordpress', 'H1 ekle'],
        'CANONICAL_MULTIPLE' => ['warning', 'Birden fazla canonical', 'Sayfada çelişen canonical etiketleri var; Google birini yok sayar.', ['Tema ile SEO eklentisinin ikisinin birden canonical basmadığından emin olun.'], 'developer', 'Geliştiriciye ilet'],
        'REDIRECT_CHAIN' => ['warning', 'Yönlendirme zinciri', 'Sayfaya birden çok yönlendirmeyle ulaşılıyor; yavaşlatır ve değer kaybettirir.', ['Yönlendirmeyi doğrudan son adrese çevirin.'], 'wordpress', 'Yönlendirmeyi kısalt'],
        'MULTIPLE_H1' => ['info', 'Birden fazla H1', 'Sayfada birden çok ana başlık var.', ['Ana başlık dışındakileri H2 yapın (çoğu zaman temadan gelir).'], 'wordpress', 'Başlıkları düzenle'],
        'CANONICAL_MISSING' => ['info', 'Canonical etiketi yok', 'Sayfa kendini asıl sayfa olarak belirtmiyor.', ['SEO eklentisi açıksa canonical kendiliğinden eklenir; eklentiyi kontrol edin.'], 'wordpress', 'Canonical ekle'],
    ];

    public function __construct(private readonly SeoPlanInputCollector $collector) {}

    /**
     * @return array{google: list<Finding>, html: list<Finding>, tiles: array{critical: int, warning: int, info: int, validating: int}, index: array{inspected: int, indexed: int, not_indexed: int}, has_gsc: bool, has_crawl: bool, sitemaps: int}
     */
    public function read(DigitalAsset $site): array
    {
        [$google, $index, $hasGsc, $sitemaps] = $this->google($site);
        [$html, $hasCrawl] = $this->html($site);
        $all = [...$google, ...$html];
        $tiles = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($all as $finding) {
            $tiles[$finding['severity']] += $finding['count'];
        }

        return [
            'google' => $google, 'html' => $html,
            'tiles' => $tiles + ['validating' => $this->validating($site)],
            'index' => $index, 'has_gsc' => $hasGsc, 'has_crawl' => $hasCrawl, 'sitemaps' => $sitemaps,
        ];
    }

    /** @return array{0: list<Finding>, 1: array{inspected: int, indexed: int, not_indexed: int}, 2: bool, 3: int} */
    private function google(DigitalAsset $site): array
    {
        $inspections = $this->collector->inspections($site);
        $groups = [];
        $index = ['inspected' => 0, 'indexed' => 0, 'not_indexed' => 0];
        foreach ($inspections as $inspection) {
            $state = mb_strtolower((string) $inspection['coverage_state']);
            if ($state === '') {
                continue;
            }
            $index['inspected']++;
            $indexed = str_contains($state, 'indexed') && ! str_contains($state, 'not indexed');
            $index[$indexed ? 'indexed' : 'not_indexed']++;
            $path = SiteAnalysisReader::path($inspection['url']);
            foreach (self::COVERAGE as [$needle, $key, $severity, $title, $why, $fix, $fixer, $action]) {
                if (str_contains($state, $needle)) {
                    $groups[$key] ??= self::finding($key, 'google', $severity, $title, $why, $fix, $fixer, $action);
                    $groups[$key]['pages'][] = $path;
                    break;
                }
            }
            $google = $inspection['google_canonical'];
            $user = $inspection['user_canonical'];
            if ($google !== null && $user !== null && SeoText::urlKey($google) !== SeoText::urlKey($user) && ! str_contains($state, 'google chose different canonical')) {
                $groups['g_canonical_diff'] ??= self::finding('g_canonical_diff', 'google', 'warning', 'Google başka canonical seçti',
                    'Belirttiğiniz canonical ile Google’ın seçtiği farklı; sinyaller bölünüyor.', ['İçeriği gerçekten farklılaştırın ya da Google’ın seçtiği sayfaya birleştirin.'], 'wordpress', 'Birleştir / ayır');
                $groups['g_canonical_diff']['pages'][] = $path;
            }
        }
        $sitemaps = $this->collector->sitemaps($site);
        foreach ($sitemaps as $sitemap) {
            if ($sitemap['errors'] > 0 || $sitemap['warnings'] > 0) {
                $key = $sitemap['errors'] > 0 ? 'g_sitemap_error' : 'g_sitemap_warning';
                $groups[$key] ??= self::finding($key, 'google', $sitemap['errors'] > 0 ? 'critical' : 'warning',
                    $sitemap['errors'] > 0 ? 'Site haritasında hata' : 'Site haritasında uyarı',
                    'Search Console site haritasını okurken sorun bildirdi; yeni sayfalar geç bulunabilir.',
                    ['Search Console › Site haritaları’nda hatanın ayrıntısına bakın.', 'SEO eklentisinin site haritasını yeniden oluşturup tekrar gönderin.'], 'wordpress', 'Site haritasını yenile');
                $groups[$key]['pages'][] = $sitemap['path'];
            }
        }

        return [self::sorted($groups), $index, $inspections !== [] || $sitemaps !== [], count($sitemaps)];
    }

    /** @return array{0: list<Finding>, 1: bool} */
    private function html(DigitalAsset $site): array
    {
        $groups = [];
        $hasCrawl = false;
        if (Schema::hasTable('website_crawl_issue_snapshot') && Schema::hasTable('website_http_snapshot')) {
            $latest = DB::table('website_http_snapshot')->where('digital_asset_id', $site->id)->groupBy('url')->select('url')->selectRaw('max(observed_at) as observed_at');
            $hasCrawl = DB::table('website_http_snapshot')->where('digital_asset_id', $site->id)->exists();
            DB::table('website_crawl_issue_snapshot as i')->joinSub($latest, 'l', fn ($join) => $join->on('l.url', '=', 'i.url')->on('l.observed_at', '=', 'i.observed_at'))
                ->where('i.digital_asset_id', $site->id)->orderBy('i.url')->get(['i.url', 'i.issue_code', 'i.severity', 'i.message'])
                ->each(function (object $row) use (&$groups): void {
                    $code = (string) $row->issue_code;
                    $key = 'h_'.mb_strtolower($code);
                    if (! isset($groups[$key])) {
                        [$severity, $title, $why, $fix, $fixer, $action] = self::HTML[$code] ?? [
                            in_array($row->severity, ['critical', 'high'], true) ? 'critical' : (in_array($row->severity, ['medium'], true) ? 'warning' : 'info'),
                            (string) $row->message, (string) $row->message, ['Sayfayı kontrol edin.'], 'developer', 'Geliştiriciye ilet',
                        ];
                        $groups[$key] = self::finding($key, 'html', $severity, $title, $why, $fix, $fixer, $action);
                    }
                    $groups[$key]['pages'][] = SiteAnalysisReader::path((string) $row->url);
                });
        }
        $duplicates = Page::query()->where('website_asset_id', $site->id)->whereNotNull('title')->where('title', '!=', '')
            ->get(['path', 'title'])->groupBy(fn (Page $page): string => mb_strtolower(trim((string) $page->title)))->filter(fn ($pages): bool => $pages->count() > 1);
        if ($duplicates->isNotEmpty()) {
            $groups['h_duplicate_title'] = self::finding('h_duplicate_title', 'html', 'warning', 'Aynı başlığı taşıyan sayfalar',
                'Birden çok sayfa aynı başlıkla görünüyor; Google hangisini göstereceğini seçemiyor.', ['Her sayfaya konusuna özel başlık yazın; gerçekten aynıysa birleştirin.'], 'wordpress', 'Başlıkları ayır');
            foreach ($duplicates as $pages) {
                foreach ($pages as $page) {
                    $groups['h_duplicate_title']['pages'][] = (string) ($page->path ?: '/');
                }
            }
        }

        return [self::sorted($groups), $hasCrawl || Page::query()->where('website_asset_id', $site->id)->exists()];
    }

    /** Fixes applied in the last 28 days: Google re-crawls to confirm them. */
    private function validating(DigitalAsset $site): int
    {
        return Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('status', Suggestion::APPLIED)->where('applied_at', '>=', now()->subDays(28))
            ->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))->count();
    }

    /**
     * @param  list<string>  $fix
     * @return Finding
     */
    private static function finding(string $key, string $source, string $severity, string $title, string $why, array $fix, string $fixer, string $action): array
    {
        return ['key' => $key, 'source' => $source, 'severity' => $severity, 'title' => $title, 'why' => $why, 'fix' => $fix, 'fixer' => $fixer, 'action' => $action, 'pages' => [], 'count' => 0];
    }

    /**
     * @param  array<string, Finding>  $groups
     * @return list<Finding>
     */
    private static function sorted(array $groups): array
    {
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $out = array_map(function (array $g): array {
            $pages = array_values(array_unique($g['pages']));
            sort($pages);

            return ['pages' => array_slice($pages, 0, self::PAGES), 'count' => count($pages)] + $g;
        }, array_values($groups));
        usort($out, fn (array $a, array $b): int => [$rank[$a['severity']], -$a['count'], $a['title']] <=> [$rank[$b['severity']], -$b['count'], $b['title']]);

        return $out;
    }
}
