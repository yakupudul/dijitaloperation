<?php

namespace App\Services\Repair;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteScope;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Onarım Faz 3 (yakup 2026-10-08/09): the nightly technical check of every website, from data MoxDOP already collects
 * (Search Console URL inspection and sitemaps, the site crawl's links and status codes, PageSpeed / CrUX, GA4 landing
 * pages) plus two light reads of its own (the home page's response headers, a capped HEAD check of outbound links).
 *
 * What the system can fix through the approved site-fix path (ADR-070) arrives on the Onarım masası ready to approve:
 * a service page Google cannot index because of noindex (noindex off), an internal link or a Search Console address
 * answering 404 (301 to the closest live page, kept by the MoxDOP connector) and duplicate pages without a canonical
 * (their own address as canonical). Pages Google leaves out of the index get "link to it" suggestions on related pages
 * and service pages with traffic but no conversion get a "Dönüşüm adımı" suggestion; both are prepared by "AI ile yap"
 * overnight and approved on the desk (yakup 2026-10-09: "Otomatik olmuyor mu?"). What only the operator can do on the site or the hosting
 * (headers, speed settings, SEO-plugin archive settings, Search Console) arrives as a "Senin yapacağın" row with the
 * exact steps; "Yaptım" hides it for a week and the next nightly run closes it once the problem is gone.
 */
final class WebHealthAudit
{
    public const string TYPE = 'web_health';

    public const array CHECKS = [
        'index' => 'Google dizin sorunu',
        'sitemap' => 'Site haritası',
        'broken_link' => 'Kırık iç bağlantı',
        'external_link' => 'Kırık dış bağlantı',
        'speed' => 'Hız',
        'headers' => 'Güvenlik başlıkları',
        'bloat' => 'Gereksiz dizin',
        'conversion' => 'Dönüşüm yolu',
    ];

    /** Outbound links checked per site per night (results are kept for a week). */
    public const int EXTERNAL_PER_RUN = 60;

    /** Lines of evidence shown on one desk row. */
    private const int LIST = 12;

    /** Not-indexed pages per site that get "link to it" suggestions per run (two source pages each). */
    public const int INLINK_TARGETS = 20;

    /** Days a sent fix may wait for Google / the crawl to see it before the row returns with a warning. */
    private const array GRACE_DAYS = ['index' => 30, 'bloat' => 45, 'broken_link' => 14, 'external_link' => 14];

    /** Index states Google gives a page it knows but leaves out: more internal links help. */
    private const array WEAK_STATES = ['crawled - currently not indexed', 'discovered - currently not indexed', 'url is unknown to google'];

    /** Words that say nothing about a page's subject. */
    private const array STOP_WORDS = ['nasil', 'nedir', 'neden', 'hakkinda', 'icin', 'olan', 'gibi', 'daha', 'veya', 'kadar', 'sonra', 'once',
        'what', 'with', 'your', 'from', 'that', 'this', 'html', 'page', 'sayfa', 'blog'];

    /** @var array<string, array{0: string, 1: list<string>}> Search Console coverage state → Turkish name + steps */
    private const array STATES = [
        'crawled - currently not indexed' => ['Google taradı ama dizine almadı', [
            'Sayfayı güçlendir: eksik bilgi, özgün metin ve ilgili sayfalardan iç bağlantı (Web sitesi › öneriler).',
            'Zayıf ya da başka sayfanın tekrarıysa 301 ile birleştir.',
            'Düzelttikten sonra Search Console › URL denetimi › "Dizine eklenmesini iste".',
        ]],
        'discovered - currently not indexed' => ['Google buldu ama henüz taramadı', [
            'Bu sayfalara ilgili sayfalardan iç bağlantı ver ve site haritasında olduklarını kontrol et.',
            'Sunucu yavaşsa Google taramayı erteler; hız satırına da bak.',
        ]],
        'url is unknown to google' => ['Google bu adresi hiç bilmiyor', [
            'Sayfanın site haritasında olduğunu kontrol et ve ilgili sayfalardan link ver.',
            'Search Console › URL denetimi › "Dizine eklenmesini iste".',
        ]],
        'not found (404)' => ['Bulunamadı (404)', ['Site haritasından ve iç linklerden çıkar; değerli bir adresse yeni sayfaya 301 ver.']],
        'soft 404' => ['Boş görünen sayfa (soft 404)', ['Sayfaya gerçek içerik koy ya da kaldırıp en yakın sayfaya 301 ver.']],
        'server error (5xx)' => ['Sunucu hatası (5xx)', ['Hosting hata kayıtlarına bak; sayfa açılıyorsa Search Console › URL denetimi ile yeniden test et.']],
        'page with redirect' => ['Yönlendirilen adres', ['Site haritası ve iç linklerde son (yönlendirilen) adresi kullan.']],
        'excluded by ‘noindex’ tag' => ['noindex ile kapalı', ['Bilerek değilse sayfayı dizine aç; bilerek kapalıysa site haritasından çıkar.']],
        'blocked by robots.txt' => ['robots.txt engelliyor', ['robots.txt dosyasında bu yolu engelleyen satırı kaldır (SEO eklentisi › robots.txt).']],
        'duplicate without user-selected canonical' => ['Yinelenen sayfa (asıl sayfa belirtilmemiş)', ['Sayfaya kendi canonical etiketini ver ya da benzer sayfayla 301 ile birleştir.']],
        'duplicate, google chose different canonical than user' => ['Google başka sayfayı asıl seçti', ['İki sayfanın içeriği çok benziyor: ayrıştır ya da 301 ile birleştir.']],
        'alternate page with proper canonical tag' => ['Başka sayfaya canonical veren sayfa', ['Normal durum; bu sayfa bilerek başka sayfayı gösteriyorsa dokunma.']],
    ];

    /** Hosts that refuse bots or are not pages (never reported as broken). */
    private const string SKIP_HOSTS = '/(^|\.)(facebook|instagram|linkedin|twitter|x|youtube|youtu|tiktok|wa|whatsapp|google|goo|maps|t)\.(com|be|me|gl|co)$/';

    public function __construct(private readonly ExternalWriteService $writes) {}

    /** @return array{sites: int, opened: int, closed: int} */
    public function run(): array
    {
        $done = ['sites' => 0, 'opened' => 0, 'closed' => 0];
        DigitalAsset::query()->operational()->where('type', 'website')->whereNotNull('brand_id')->orderBy('id')->each(function (DigitalAsset $site) use (&$done): void {
            try {
                $result = $this->audit($site);
            } catch (Throwable $e) {
                report($e);

                return;
            }
            $done['sites']++;
            $done['opened'] += $result['opened'];
            $done['closed'] += $result['closed'];
        });

        return $done;
    }

    /** @return array{opened: int, closed: int} */
    public function audit(DigitalAsset $site): array
    {
        $brand = Brand::query()->find($site->brand_id);
        if (! $brand instanceof Brand) {
            return ['opened' => 0, 'closed' => 0];
        }
        $items = [];
        $ran = [];
        foreach (array_keys(self::CHECKS) as $check) {
            try {
                $found = match ($check) {
                    'index' => $this->index($site, $brand),
                    'sitemap' => $this->sitemaps($site, $brand),
                    'broken_link' => $this->brokenLinks($site),
                    'external_link' => $this->externalLinks($site),
                    'speed' => $this->speed($site),
                    'headers' => $this->headers($site),
                    'bloat' => $this->bloat($site, $brand),
                    'conversion' => $this->conversion($site, $brand),
                };
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($found === null) {
                // No data for this check (source not connected or not collected yet): its rows stay as they are.
                continue;
            }
            $ran[] = $check;
            foreach ($found as $item) {
                $items[] = $item + ['check' => $check];
            }
        }

        return $this->store($site, $items, $ran);
    }

    /** Onayla on a fix row: the prepared site fix goes to the site (Admin-approved, logged, undoable). */
    public function send(User $user, Suggestion $suggestion): ExternalWriteAction
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->findOrFail((int) ($action['site_id'] ?? 0));
        $changes = (array) ($action['changes'] ?? []);
        if ($changes === []) {
            throw ValidationException::withMessages(['write' => 'Bu satır sitede elle yapılacak bir iş; "Yaptım" ile işaretle.']);
        }
        $write = $this->writes->requestSiteFixes($user, $site, $changes, $suggestion);
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id,
            'action' => array_merge($action, ['write_id' => (int) $write->id, 'sent_at' => now()->toIso8601String(), 'last_write_error' => null])])->save();

        return $write;
    }

    /**
     * The write of an approved fix finished: a failed one returns to the desk now (not only at the nightly run), with the
     * site's reason in plain words.
     */
    public function writeFinished(ExternalWriteAction $write): void
    {
        if ($write->status !== 'failed' || $write->suggestion_id === null) {
            return;
        }
        $suggestion = Suggestion::query()->where('action_type', self::TYPE)->where('status', Suggestion::APPROVED)->find($write->suggestion_id);
        if ($suggestion === null || (int) data_get($suggestion->action, 'write_id') !== (int) $write->id) {
            return;
        }
        $error = self::plainError($write->error);
        $suggestion->forceFill(['status' => Suggestion::OPEN, 'resolved_at' => null, 'resolved_by' => null,
            'action' => array_merge((array) $suggestion->action, ['last_write_error' => mb_substr($error, 0, 300)])])->save();
    }

    /** The site's failure reason in words the operator can act on. */
    public static function plainError(?string $error): string
    {
        $error = trim((string) $error);
        if ($error === '') {
            return 'gönderim tamamlanmadı';
        }
        if (str_contains($error, 'no SEO plugin can hold redirects')) {
            return 'Sitedeki MoxDOP eklentisi eski; 1.12.0 ve sonrası 301\'i kendi listesine yazar. Eklentiyi güncelle, sonra yeniden onayla.';
        }

        return $error;
    }

    /** "Yaptım" on a task row: hidden for a week; the nightly run closes it when the problem is gone, else it returns. */
    public function markDone(User $user, Suggestion $suggestion): void
    {
        $suggestion->forceFill(['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->addDays(7), 'resolved_by' => $user->id,
            'operator_note' => 'Yaptım dendi; gece denetimi kontrol edip kapatacak.'])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $ran
     * @return array{opened: int, closed: int}
     */
    private function store(DigitalAsset $site, array $items, array $ran): array
    {
        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', self::TYPE)
            ->where('target_type', 'asset')->where('target_id', $site->id)->get()->keyBy('fingerprint');
        $opened = 0;
        $seen = [];
        foreach ($items as $item) {
            $fingerprint = hash('sha256', $site->brand_id.'|web-health|'.$site->id.'|'.$item['check'].'|'.$item['key']);
            $seen[] = $fingerprint;
            $action = ['site_id' => (int) $site->id, 'check' => $item['check'], 'target' => (string) ($item['target'] ?? ''),
                'before' => array_values($item['before']), 'after' => array_values($item['after']), 'changes' => array_values($item['changes'] ?? [])];
            $values = [
                'channel' => 'search', 'decision_key' => 'repair.'.self::TYPE, 'title' => mb_substr((string) $item['title'], 0, 160),
                'reason' => mb_substr((string) $item['reason'], 0, 240), 'priority' => (int) ($item['priority'] ?? 3), 'action_type' => self::TYPE,
                'target_type' => 'asset', 'target_id' => $site->id, 'page_id' => $item['page_id'] ?? null, 'last_seen_at' => now(),
                'material_hash' => hash('sha256', json_encode([$action['before'], $action['changes']])),
                'evidence' => [['kind' => 'quote', 'value' => mb_substr(implode(' · ', $action['before']), 0, 400), 'source' => (string) ($item['target'] ?? '')]],
            ];
            $row = $existing->get($fingerprint);
            if ($row === null) {
                Suggestion::query()->create($values + ['brand_id' => $site->brand_id, 'fingerprint' => $fingerprint, 'status' => Suggestion::OPEN,
                    'first_seen_at' => now(), 'action' => $action]);
                $opened++;

                continue;
            }
            $previous = (array) $row->action;
            $status = $row->status;
            if ($status === Suggestion::APPLIED) {
                $status = Suggestion::OPEN;
                $opened++;
            } elseif ($status === Suggestion::APPROVED) {
                $write = ExternalWriteAction::query()->find((int) ($previous['write_id'] ?? 0));
                if ($write === null || in_array($write->status, ['failed', 'undone'], true)) {
                    $status = Suggestion::OPEN;
                    $action['last_write_error'] = self::plainError($write?->error);
                } elseif ($write->status === 'succeeded' && $write->finished_at !== null
                    && $write->finished_at->lt(now()->subDays(self::GRACE_DAYS[$item['check']] ?? 3))) {
                    // Written days ago and the crawl still sees it: back on the desk.
                    $status = Suggestion::OPEN;
                    $action['last_write_error'] = 'Yazıldı ama sitede hâlâ görülüyor (önbellek ya da başka bir ayar).';
                } else {
                    $action += array_intersect_key($previous, array_flip(['write_id', 'sent_at']));
                }
            }
            $row->forceFill($values + ['status' => $status, 'action' => $action]
                + ($status === Suggestion::OPEN && $row->status !== Suggestion::OPEN ? ['resolved_at' => null, 'resolved_by' => null] : []))->save();
        }
        $closed = Suggestion::query()->whereIn('id', $existing->filter(fn (Suggestion $s): bool => ! in_array($s->fingerprint, $seen, true)
            && in_array((string) data_get($s->action, 'check'), $ran, true)
            && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPROVED], true))->pluck('id'))
            ->update(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(),
                'operator_note' => 'Sorun sitede kalmadı (otomatik kapandı).']);

        return ['opened' => $opened, 'closed' => $closed];
    }

    /* ------------------------------------------------------------------ checks */

    /** @return list<array<string, mixed>>|null */
    private function index(DigitalAsset $site, Brand $brand): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'search_console');
        if ($ids === []) {
            return null;
        }
        $host = $this->host($site);
        $latest = [];
        DB::table('gsc_url_inspection_snapshot')->whereIn('external_resource_id', $ids)->where('inspected_at', '>=', now()->subDays(30))
            ->orderByDesc('inspected_at')->limit(5000)->get(['page', 'metadata'])->each(function (object $row) use (&$latest, $host): void {
                $key = SeoText::urlKey((string) $row->page);
                if ($key !== '' && str_starts_with($key, $host) && ! isset($latest[$key])) {
                    $latest[$key] = ['url' => (string) $row->page, 'meta' => $this->json($row->metadata)];
                }
            });
        if ($latest === []) {
            return null;
        }
        $pages = $this->pages($site);
        $live = $pages->filter(fn (Page $p): bool => (bool) $p->is_indexable);
        $items = [];
        $groups = [];
        $canonicals = [];
        $weak = [];
        foreach ($latest as $key => $inspection) {
            $meta = $inspection['meta'];
            if (mb_strtoupper((string) ($meta['verdict'] ?? '')) === 'PASS') {
                continue;
            }
            $state = trim((string) ($meta['coverage_state'] ?? ''));
            $page = $pages->get($key);
            $blocked = mb_strtoupper((string) ($meta['indexing_state'] ?? '')) === 'BLOCKED_BY_META_TAG' || str_contains(mb_strtolower($state), 'noindex');
            if ($blocked && $page !== null && in_array($page->category, ['hizmet', 'lokasyon'], true) && (int) $page->wp_post_id > 0) {
                $items[] = ['key' => 'noindex-'.$page->id, 'page_id' => (int) $page->id, 'priority' => 1, 'target' => (string) $page->url,
                    'title' => 'Hizmet sayfası Google\'a kapalı (noindex): '.$this->path((string) $page->url),
                    'reason' => 'Search Console: "'.$state.'". Hizmet / bölge sayfası aramada görünmüyor.',
                    'before' => ['noindex açık', 'Google: '.$state], 'after' => ['noindex kaldırılır, sayfa dizine açılır'],
                    'changes' => [['type' => 'noindex', 'object_id' => (int) $page->wp_post_id, 'reference' => 'web-health-noindex-'.$page->id, 'value' => false]]];

                continue;
            }
            $google = (string) ($meta['google_canonical'] ?? '');
            $user = (string) ($meta['user_canonical'] ?? '');
            $group = match (true) {
                mb_strtoupper((string) ($meta['robots_txt_state'] ?? '')) === 'DISALLOWED' => 'blocked by robots.txt',
                $google !== '' && $user !== '' && SeoText::urlKey($google) !== SeoText::urlKey($user) => 'duplicate, google chose different canonical than user',
                default => mb_strtolower($state !== '' ? $state : 'url is unknown to google'),
            };
            if ($group === 'page with redirect' || $group === 'alternate page with proper canonical tag') {
                // Normal: the address is redirected / points at its main page on purpose.
                continue;
            }
            $path = $this->path($inspection['url']);
            if ($group === 'not found (404)' && ($match = $this->closest($path, $live)) !== null && count($items) < 60) {
                $items[] = ['key' => 'gone-'.md5($key), 'priority' => 2, 'target' => $inspection['url'], 'title' => 'Google 404 görüyor: '.$path,
                    'reason' => 'Search Console: adres bulunamadı (404). Google ve eski linkler bu adrese gelmeye devam ediyor.',
                    'before' => [$path.' → 404'], 'after' => ['301 → '.$match, 'Adres en yakın canlı sayfaya gider (MoxDOP eklentisine yazılır)'],
                    'changes' => [['type' => 'redirect', 'from' => $path, 'value' => $match, 'reference' => 'web-health-gsc404-'.substr(md5($key), 0, 12)]]];

                continue;
            }
            if ($group === 'duplicate without user-selected canonical' && $page !== null && (int) $page->wp_post_id > 0) {
                $canonicals[] = $page;

                continue;
            }
            if (in_array($group, self::WEAK_STATES, true) && $page !== null && (bool) $page->is_indexable) {
                $weak[] = ['page' => $page, 'state' => $state !== '' ? $state : 'URL is unknown to Google'];
            }
            $groups[$group][] = $path.($group === 'duplicate, google chose different canonical than user' ? ' → Google: '.$this->path($google) : '');
        }
        if ($canonicals !== []) {
            $canonicals = array_slice($canonicals, 0, 100);
            $items[] = ['key' => 'canonical', 'priority' => 3, 'target' => $this->origin($site),
                'title' => sprintf('Yinelenen sayfalara kendi asıl adresini (canonical) ver (%d sayfa)', count($canonicals)),
                'reason' => 'Search Console: "Yinelenen sayfa, asıl sayfa belirtilmemiş". Her sayfa kendi adresini asıl adres olarak gösterir.',
                'before' => $this->list(array_map(fn (Page $p): string => $this->path((string) $p->url), $canonicals)),
                'after' => ['Her sayfaya kendi adresi canonical olarak yazılır (SEO eklentisinin alanına; geri alınabilir).'],
                'changes' => array_map(fn (Page $p): array => ['type' => 'canonical', 'object_id' => (int) $p->wp_post_id,
                    'reference' => 'web-health-canonical-'.$p->id, 'value' => (string) $p->url], $canonicals)];
        }
        $linked = $this->linkSuggestions($site, $weak, $live);
        foreach (self::WEAK_STATES as $state) {
            // Pages that got "link to it" suggestions leave the task row; Google then decides on its own.
            $groups[$state] = array_values(array_diff($groups[$state] ?? [], $linked));
            if ($groups[$state] === []) {
                unset($groups[$state]);
            }
        }
        foreach ($groups as $state => $paths) {
            [$label, $steps] = self::STATES[$state] ?? [$state, ['Search Console › URL denetimi ile sayfanın durumuna bak.']];
            $items[] = ['key' => 'state-'.md5($state), 'priority' => in_array($state, ['crawled - currently not indexed', 'server error (5xx)', 'blocked by robots.txt'], true) ? 2 : 3,
                'target' => $this->origin($site), 'title' => sprintf('Google dizin sorunu: %s (%d sayfa)', $label, count($paths)),
                'reason' => 'Search Console URL denetimi (son 30 gün, sitenin denetlenen sayfaları).', 'before' => $this->list($paths), 'after' => $steps];
        }

        return $items;
    }

    /** @return list<array<string, mixed>>|null */
    private function sitemaps(DigitalAsset $site, Brand $brand): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'search_console');
        $host = $this->host($site);
        $latest = [];
        if ($ids !== []) {
            DB::table('gsc_sitemap_snapshot')->whereIn('external_resource_id', $ids)->where('retrieved_at', '>=', now()->subDays(30))
                ->orderByDesc('retrieved_at')->limit(500)->get(['sitemap_path', 'metadata'])->each(function (object $row) use (&$latest, $host): void {
                    $key = SeoText::urlKey((string) $row->sitemap_path);
                    if (str_starts_with($key, $host) && ! isset($latest[$key])) {
                        $latest[$key] = ['url' => (string) $row->sitemap_path, 'meta' => $this->json($row->metadata)];
                    }
                });
        }
        if ($latest === []) {
            return null;
        }
        $items = [];
        foreach ($latest as $key => $sitemap) {
            $errors = (int) ($sitemap['meta']['errors'] ?? 0);
            $warnings = (int) ($sitemap['meta']['warnings'] ?? 0);
            if ($errors > 0 || $warnings > 0) {
                $junk = SeoText::isJunkSitemap($sitemap['url']);
                $items[] = ['key' => 'errors-'.md5($key), 'priority' => $errors > 0 ? 2 : 3, 'target' => $sitemap['url'],
                    'title' => sprintf('Site haritasında %d hata, %d uyarı: %s', $errors, $warnings, $this->path($sitemap['url'])),
                    'reason' => 'Google bu haritayı okurken sorun bildirdi. MoxDOP haritayı kendisi açıp nedenini aradı (aşağıda).',
                    'before' => [$errors.' hata · '.$warnings.' uyarı (Search Console)', ...$this->sitemapDiagnosis($sitemap['url'])],
                    'after' => $junk
                        ? ['Bu harita gereksiz; düzeltmesi onu kapatmak (bir alttaki satır). Kapanınca bu hata da gider.']
                        : ['Aşağıdaki nedeni düzelt; harita yenilenince Google bir sonraki okumada hatayı kaldırır.']];
            }
            if (SeoText::isJunkSitemap($sitemap['url'])) {
                $items[] = ['key' => 'junk-'.md5($key), 'priority' => 3, 'target' => $sitemap['url'],
                    'title' => 'Gereksiz site haritası gönderilmiş: '.$this->path($sitemap['url']),
                    'reason' => 'Bu harita etiket, yazar, medya ya da şablon sayfalarını listeliyor. Bu sayfalar ziyaretçiye bir şey anlatmıyor; Google\'a göstermek sitenin değerini düşürür.',
                    'before' => [$sitemap['url']],
                    'after' => ['SEO eklentisinin site haritası ayarında bu türü kapat (Rank Math › Site haritası / Yoast › Ayarlar › İçerik türleri).',
                        'Search Console › Site haritaları › bu haritayı kaldır.', 'İkisini sistemin yapması için "yeni yazma türleri" onayın gerekiyor.']];
            }
        }

        return $items;
    }

    /**
     * Search Console gives only an error count for a sitemap; MoxDOP opens the sitemap itself and names the cause in
     * plain words (does not open, broken XML, empty, listed sitemaps or pages that do not open).
     *
     * @return list<string>
     */
    private function sitemapDiagnosis(string $url): array
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP sitemap check)'])->get($url);
        } catch (Throwable $error) {
            return ['Neden: harita açılamadı ('.mb_substr($error->getMessage(), 0, 120).').'];
        }
        if (! $response->successful()) {
            return ['Neden: harita açılmıyor (HTTP '.$response->status().'). Google da okuyamıyor; SEO eklentisinde site haritasını yeniden oluştur ya da bu haritayı kapat.'];
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string(ltrim($response->body()));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return ['Neden: harita geçerli bir XML değil (başında bir eklentinin ya da PHP\'nin yazdığı fazladan metin olabilir).'];
        }
        $isIndex = $xml->getName() === 'sitemapindex';
        $locations = [];
        foreach ($isIndex ? $xml->sitemap : $xml->url as $entry) {
            $location = trim((string) $entry->loc);
            if ($location !== '') {
                $locations[] = $location;
            }
        }
        if ($locations === []) {
            return ['Neden: harita boş, hiç adres listelemiyor. Boş harita Google\'da hata sayılır; bu türü kapatmak düzeltir.'];
        }
        $lines = [($isIndex ? 'Harita '.count($locations).' alt harita listeliyor.' : 'Harita '.count($locations).' adres listeliyor.')];
        $sample = array_slice($locations, 0, $isIndex ? 30 : 40);
        $responses = Http::pool(fn (Pool $pool): array => array_map(fn (string $u) => $pool->as($u)->timeout(8)->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP sitemap check)'])->withOptions(['allow_redirects' => false])->head($u), $sample));
        $bad = [];
        foreach ($sample as $location) {
            $result = $responses[$location] ?? null;
            $code = $result instanceof Response ? $result->status() : 0;
            if ($code === 405 || $code === 501) {
                continue;
            }
            if ($code >= 300 || $code === 0) {
                $bad[] = $this->path($location).' → '.match (true) {
                    $code === 0 => 'açılmıyor',
                    $code < 400 => 'yönlendiriyor ('.$code.')',
                    default => 'HTTP '.$code,
                };
            }
        }
        if ($bad === []) {
            $lines[] = 'Biz açınca sorun görmedik ('.count($sample).' adres denendi). Hata eski olabilir; Google haritayı yeniden okuyunca kalkar.';

            return $lines;
        }
        $lines[] = 'Neden: '.count($bad).' '.($isIndex ? 'alt harita' : 'adres').' düzgün açılmıyor. Haritada yalnız açılan sayfalar olmalı:';

        return [...$lines, ...array_slice($bad, 0, 8)];
    }

    /** @return list<array<string, mixed>>|null */
    private function brokenLinks(DigitalAsset $site): ?array
    {
        $edges = DB::table('website_link_edge')->where('digital_asset_id', $site->id)->where('is_internal', true)
            ->limit(30000)->get(['source_url', 'normalized_target_url', 'target_url']);
        if ($edges->isEmpty()) {
            return null;
        }
        $status = [];
        DB::table('website_html_snapshot')->where('digital_asset_id', $site->id)->where('observed_at', '>=', now()->subDays(60))
            ->orderByDesc('observed_at')->limit(50000)->get(['url', 'status_code'])->each(function (object $row) use (&$status): void {
                $key = SeoText::urlKey((string) $row->url);
                $status[$key] ??= (int) $row->status_code;
            });
        $sources = [];
        foreach ($edges as $edge) {
            $target = (string) ($edge->normalized_target_url ?: $edge->target_url);
            $key = SeoText::urlKey($target);
            if (in_array($status[$key] ?? 0, [404, 410], true) && SeoText::urlKey((string) $edge->source_url) !== $key) {
                $sources[$key]['url'] = $target;
                $sources[$key]['from'][$this->path((string) $edge->source_url)] = true;
            }
        }
        $pages = $this->pages($site)->filter(fn (Page $p): bool => (bool) $p->is_indexable && ! in_array($status[SeoText::urlKey((string) $p->url)] ?? 200, [404, 410], true));
        $items = [];
        foreach (array_slice($sources, 0, 50, true) as $key => $dead) {
            $path = $this->path($dead['url']);
            $from = array_keys($dead['from']);
            $match = $this->closest($path, $pages);
            $before = [$path.' → '.($status[$key] ?? 404), count($from).' sayfa bu adrese link veriyor:', ...array_slice($from, 0, 5)];
            $base = ['key' => 'dead-'.md5($key), 'priority' => 2, 'target' => $dead['url'], 'title' => 'Kırık iç bağlantı: '.$path,
                'reason' => 'Sitenin kendi sayfaları kaldırılmış bir adrese link veriyor; ziyaretçi ve Google boş sayfaya düşüyor.', 'before' => $before];
            $items[] = $match !== null
                ? $base + ['after' => ['301 → '.$match, 'Eski linkler en yakın canlı sayfaya gider (MoxDOP eklentisine yazılır)'],
                    'changes' => [['type' => 'redirect', 'from' => $path, 'value' => $match, 'reference' => 'web-health-404-'.substr(md5($key), 0, 12)]]]
                : $base + ['after' => ['Benzer canlı sayfa bulunamadı: bu sayfalardaki linki WordPress\'te düzelt ya da kaldır.']];
        }

        return $items;
    }

    /** @return list<array<string, mixed>>|null */
    private function externalLinks(DigitalAsset $site): ?array
    {
        $edges = DB::table('website_link_edge')->where('digital_asset_id', $site->id)->where('is_internal', false)
            ->limit(20000)->get(['source_url', 'target_url']);
        if ($edges->isEmpty()) {
            return null;
        }
        $targets = [];
        foreach ($edges as $edge) {
            $url = (string) $edge->target_url;
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! preg_match('#^https?://#i', $url) || $host === '' || preg_match(self::SKIP_HOSTS, $host) === 1) {
                continue;
            }
            $targets[$url][$this->path((string) $edge->source_url)] = true;
        }
        $results = [];
        $unchecked = [];
        foreach (array_keys($targets) as $url) {
            $known = Cache::get($this->cacheKey($url));
            if ($known === null) {
                $unchecked[] = $url;
            } else {
                $results[$url] = (int) $known;
            }
        }
        foreach (array_chunk(array_slice($unchecked, 0, self::EXTERNAL_PER_RUN), 10) as $chunk) {
            $responses = Http::pool(fn (Pool $pool): array => array_map(fn (string $url) => $pool->as($url)->timeout(8)->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP link check)'])->head($url), $chunk));
            foreach ($chunk as $url) {
                $response = $responses[$url] ?? null;
                $code = match (true) {
                    $response instanceof Response => $response->status(),
                    $response instanceof Throwable && str_contains($response->getMessage(), 'resolve host') => 0,
                    default => -1,
                };
                Cache::put($this->cacheKey($url), $code, now()->addDays(7));
                $results[$url] = $code;
            }
        }
        $broken = array_filter($results, fn (int $code): bool => in_array($code, [0, 404, 410], true));
        if ($broken === []) {
            return [];
        }
        $lines = [];
        foreach ($broken as $url => $code) {
            $from = array_keys($targets[$url] ?? []);
            $lines[] = $url.' ('.($code === 0 ? 'alan adı yok' : $code).') ← '.implode(', ', array_slice($from, 0, 3)).(count($from) > 3 ? ' +'.(count($from) - 3) : '');
        }

        return [['key' => 'external', 'priority' => 3, 'target' => $this->origin($site), 'title' => sprintf('Kırık dış bağlantı (%d)', count($broken)),
            'reason' => 'Sayfalardaki bazı linkler artık açılmayan adreslere gidiyor; güveni ve kullanıcı deneyimini bozar.',
            'before' => $this->list($lines), 'after' => ['Her linki WordPress\'te güncel adresle değiştir ya da kaldır (← sonrası linkin bulunduğu sayfa).']]];
    }

    /** @return list<array<string, mixed>>|null */
    private function speed(DigitalAsset $site): ?array
    {
        $row = DB::table('website_performance_measurement')->where('digital_asset_id', $site->id)->where('observed_at', '>=', now()->subDays(30))
            ->orderByRaw("CASE WHEN strategy = 'mobile' THEN 0 ELSE 1 END")->orderByDesc('observed_at')->first(['url', 'strategy', 'metadata']);
        if ($row === null) {
            return null;
        }
        $meta = $this->json($row->metadata);
        $field = (array) ($meta['field'] ?? []);
        $lcp = $field['lcp_ms'] ?? $meta['lcp_ms'] ?? null;
        $inp = $field['inp_ms'] ?? null;
        $cls = $field['cls'] ?? null;
        $problems = [];
        if (is_numeric($lcp) && (float) $lcp > 2500) {
            $problems[] = sprintf('En büyük içerik %s sn\'de görünüyor (iyi: 2,5 sn altı)', number_format((float) $lcp / 1000, 1, ',', ''));
        }
        if (is_numeric($inp) && (float) $inp > 200) {
            $problems[] = sprintf('Dokunmaya tepki %d ms (iyi: 200 ms altı)', (int) $inp);
        }
        if (is_numeric($cls) && (float) $cls > 0.1) {
            $problems[] = sprintf('Sayfa kayması %s (iyi: 0,1 altı)', number_format((float) $cls, 2, ',', ''));
        }
        if ($problems === []) {
            return [];
        }
        $poor = (is_numeric($lcp) && $lcp > 4000) || (is_numeric($inp) && $inp > 500) || (is_numeric($cls) && $cls > 0.25);

        return [['key' => 'speed', 'priority' => $poor ? 2 : 3, 'target' => (string) $row->url,
            'title' => ($poor ? 'Site yavaş' : 'Site hızı iyileştirilmeli').' ('.($row->strategy === 'mobile' ? 'mobil' : 'masaüstü').')',
            'reason' => ($field !== [] ? 'Gerçek ziyaretçi verisi (Chrome / CrUX, '.(($field['scope'] ?? '') === 'origin' ? 'tüm site' : 'bu sayfa').')' : 'PageSpeed laboratuvar ölçümü').'; hız sıralamayı ve dönüşümü etkiler.',
            'before' => $problems, 'after' => [
                'Önbellek eklentisinde (LiteSpeed Cache / WP Rocket) görselleri WebP\'ye çevir ve geç yükle; CSS / JS küçült, JS\'yi ertele.',
                'Ana sayfanın üstteki büyük görselini küçült (en çok ~200 KB) ve geç yüklemeden çıkar.',
                'Kullanılmayan eklentileri kapat; sayfa düzenleyicinin kullanılmayan widget\'larını kapat.',
            ]]];
    }

    /** @return list<array<string, mixed>>|null */
    private function headers(DigitalAsset $site): ?array
    {
        $origin = $this->origin($site);
        if (! str_starts_with($origin, 'https://')) {
            return null;
        }
        try {
            $response = Http::timeout(10)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP header check)'])->get($origin.'/');
        } catch (Throwable) {
            return null;
        }
        if ($response->status() >= 400) {
            return null;
        }
        $wanted = [
            'Strict-Transport-Security' => ['HSTS (tarayıcı siteyi hep https açar)', 'Header always set Strict-Transport-Security "max-age=31536000"'],
            'X-Content-Type-Options' => ['Dosya türü koruması', 'Header always set X-Content-Type-Options "nosniff"'],
            'X-Frame-Options' => ['Başka sitede çerçeve içinde açılma koruması', 'Header always set X-Frame-Options "SAMEORIGIN"'],
            'Referrer-Policy' => ['Yönlendiren bilgisi politikası', 'Header always set Referrer-Policy "strict-origin-when-cross-origin"'],
        ];
        $missing = array_filter($wanted, fn (array $w, string $name): bool => $response->header($name) === ''
            && ! ($name === 'X-Frame-Options' && str_contains(mb_strtolower($response->header('Content-Security-Policy')), 'frame-ancestors')), ARRAY_FILTER_USE_BOTH);
        if ($missing === []) {
            return [];
        }

        return [['key' => 'headers', 'priority' => 3, 'target' => $origin, 'title' => sprintf('Güvenlik başlıkları eksik (%d)', count($missing)),
            'reason' => 'Sunucu yanıtında temel güvenlik başlıkları yok; tarayıcılar ve güvenlik taramaları bunu açık olarak görür.',
            'before' => array_map(fn (string $n, array $w): string => $n.': yok ('.$w[0].')', array_keys($missing), $missing),
            'after' => ['Hosting dosya yöneticisinde sitenin .htaccess dosyasının en üstüne şunu ekle (ya da hostinge ilet):', '<IfModule mod_headers.c>',
                ...array_column($missing, 1), '</IfModule>', 'Sonra siteyi aç, her şeyin çalıştığını kontrol et.']]];
    }

    /** @return list<array<string, mixed>>|null */
    private function bloat(DigitalAsset $site, Brand $brand): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'search_console');
        if ($ids === []) {
            return null;
        }
        $host = $this->host($site);
        $junk = [];
        $any = false;
        DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->where('search_type', 'web')
            ->where('reporting_date', '>=', now()->subDays(28)->toDateString())->groupBy('page')
            ->selectRaw('page, sum(impressions) as impressions')->get()->each(function (object $row) use (&$junk, &$any, $host): void {
                $key = SeoText::urlKey((string) $row->page);
                if (! str_starts_with($key, $host)) {
                    return;
                }
                $any = true;
                if (! SeoText::isCrawlablePage((string) $row->page) && (int) $row->impressions > 0) {
                    $junk[(string) $row->page] = (int) $row->impressions;
                }
            });
        if (! $any) {
            return null;
        }
        if ($junk === []) {
            return [];
        }
        arsort($junk);

        return [['key' => 'bloat', 'priority' => 3, 'target' => $this->origin($site), 'title' => sprintf('Google gereksiz adresleri gösteriyor (%d adres)', count($junk)),
            'reason' => 'Etiket, yazar, sayfalama, medya eki ya da parametreli adresler aramada çıkıyor; asıl sayfaların gücünü bölüyor (son 28 gün).',
            'before' => $this->list(array_map(fn (string $u, int $i): string => $this->path($u).' · '.$i.' gösterim', array_keys($junk), $junk)),
            'after' => ['SEO eklentisinde (SEOPress / Yoast / Rank Math › Arşivler): etiket, yazar ve tarih arşivlerini "noindex" yap.',
                'Medya ek sayfalarını dosyanın kendisine yönlendir (SEO eklentisinde "attachment" ayarı).', 'Site haritasından bu türleri çıkar.']]];
    }

    /** @return list<array<string, mixed>>|null */
    private function conversion(DigitalAsset $site, Brand $brand): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'ga4');
        $last = $ids === [] ? null : DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $ids)->max('reporting_date');
        if ($last === null) {
            return null;
        }
        $from = now()->parse((string) $last)->subDays(27)->toDateString();
        $rows = DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $ids)->whereBetween('reporting_date', [$from, (string) $last])
            ->groupBy('landingPage')->selectRaw($this->wrap('landingPage').' as landing, sum(sessions) as sessions, sum('.$this->wrap('keyEvents').') as key_events')->get();
        if ((float) $rows->sum('key_events') < 3) {
            // No counted conversions at all: the tracking alert (conversions_not_defined) already covers it.
            return [];
        }
        $pages = $this->pages($site)->filter(fn (Page $p): bool => in_array($p->category, ['hizmet', 'lokasyon'], true) && (bool) $p->is_indexable)
            ->keyBy(fn (Page $p): string => rtrim($this->path((string) $p->url), '/'));
        $found = [];
        foreach ($rows->sortByDesc('sessions') as $row) {
            $path = rtrim((string) strtok((string) $row->landing, '?'), '/');
            if ((int) $row->sessions >= 60 && (float) $row->key_events <= 0 && $pages->has($path)) {
                $found[] = ['page' => $pages->get($path), 'sessions' => (int) $row->sessions];
            }
        }
        $this->conversionSuggestions($site, $found);

        // The pages are handled as "AI ile yap" page suggestions (Dönüşüm adımı); no manual row.
        return [];
    }

    /* ------------------------------------------------------- AI page suggestions */

    /**
     * Pages Google knows but leaves out of the index get "link to it" suggestions on the two most related pages of the
     * site that do not link to it yet ("AI ile yap" picks the anchor in that page's own text overnight; approved on the
     * Onarım masası through the existing internal-link fix). Suggestions whose target got indexed close by themselves.
     *
     * @param  list<array{page: Page, state: string}>  $weak
     * @param  Collection<string, Page>  $live
     * @return list<string> paths of the pages that have a suggestion
     */
    private function linkSuggestions(DigitalAsset $site, array $weak, Collection $live): array
    {
        $key = 'repair.'.self::TYPE.'.inlink';
        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('decision_key', $key)
            ->where('target_type', 'page')->whereIn('page_id', $live->pluck('id'))->get()->keyBy('fingerprint');
        $edges = DB::table('website_link_edge')->where('digital_asset_id', $site->id)->where('is_internal', true)->limit(30000)
            ->get(['source_url', 'normalized_target_url', 'target_url'])
            ->map(fn (object $e): string => SeoText::urlKey((string) $e->source_url).'>'.SeoText::urlKey((string) ($e->normalized_target_url ?: $e->target_url)))
            ->flip();
        $sources = $live->filter(fn (Page $p): bool => (int) $p->wp_post_id > 0);
        $seen = [];
        $linked = [];
        foreach (array_slice($weak, 0, self::INLINK_TARGETS) as $entry) {
            $target = $entry['page'];
            $targetKey = SeoText::urlKey((string) $target->url);
            $words = $this->words((string) $target->title.' '.$this->path((string) $target->url));
            $ranked = $sources->filter(fn (Page $p): bool => $p->id !== $target->id && (string) $p->language === (string) $target->language
                && ! $edges->has(SeoText::urlKey((string) $p->url).'>'.$targetKey))
                ->map(fn (Page $p): array => ['page' => $p, 'shared' => count(array_intersect($words, $this->words((string) $p->title.' '.$this->path((string) $p->url))))])
                ->filter(fn (array $r): bool => $r['shared'] >= 2)->sortByDesc('shared')->take(2);
            foreach ($ranked as $r) {
                $source = $r['page'];
                $fingerprint = hash('sha256', $site->brand_id.'|web-health-inlink|'.$source->id.'|'.$target->id);
                $seen[] = $fingerprint;
                $linked[$this->path((string) $target->url)] = true;
                $row = $existing->get($fingerprint);
                if ($row !== null && ! in_array($row->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
                    continue;
                }
                $values = ['channel' => 'search', 'decision_key' => $key, 'action_type' => 'internal_links', 'priority' => 2,
                    'title' => mb_substr('İç link ekle: '.$this->path((string) $source->url).' → '.$this->path((string) $target->url), 0, 160),
                    'reason' => mb_substr('Google bu sayfayı dizine almadı ('.$entry['state'].'): '.(string) $target->url
                        .'. Bu sayfanın metninde ona doğal bir iç link ver ('.($target->title ?: $this->path((string) $target->url)).').', 0, 240),
                    'target_type' => 'page', 'target_id' => $source->id, 'page_id' => $source->id, 'last_seen_at' => now(), 'material_hash' => hash('sha256', 'inlink|'.$target->url),
                    'evidence' => [['kind' => 'quote', 'value' => 'Search Console: '.$entry['state'], 'source' => (string) $target->url]]];
                if ($row === null) {
                    Suggestion::query()->create($values + ['brand_id' => $site->brand_id, 'fingerprint' => $fingerprint, 'status' => Suggestion::OPEN,
                        'first_seen_at' => now(), 'action' => ['site_id' => (int) $site->id, 'link_to' => (string) $target->url]]);
                } else {
                    $row->forceFill($values)->save();
                }
            }
        }
        $this->closeUnseen($existing, $seen, 'Sayfa dizine girdi ya da artık link alıyor (otomatik kapandı).');

        return array_keys($linked);
    }

    /**
     * A service / location page with traffic but no conversion gets a "Dönüşüm adımı" suggestion: "AI ile yap" writes a
     * visible call / WhatsApp / form section overnight and the new page text goes to WordPress as a draft copy after
     * approval (then "Canlıya al"), like every page-text fix.
     *
     * @param  list<array{page: Page, sessions: int}>  $pages
     */
    private function conversionSuggestions(DigitalAsset $site, array $pages): void
    {
        $key = 'repair.'.self::TYPE.'.conversion';
        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('decision_key', $key)->get()->keyBy('fingerprint');
        $busy = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', 'conversion')->where('decision_key', '!=', $key)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::APPROVED])->pluck('page_id')->all();
        $seen = [];
        foreach ($pages as $entry) {
            $page = $entry['page'];
            if (in_array($page->id, $busy, true)) {
                continue;
            }
            $fingerprint = hash('sha256', $site->brand_id.'|web-health-conversion|'.$page->id);
            $seen[] = $fingerprint;
            $row = $existing->get($fingerprint);
            if ($row !== null && ! in_array($row->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
                continue;
            }
            $values = ['channel' => 'search', 'decision_key' => $key, 'action_type' => 'conversion', 'priority' => 2,
                'title' => mb_substr('Dönüşüm adımı ekle: '.$this->path((string) $page->url), 0, 160),
                'reason' => mb_substr('GA4 son 28 gün: '.$entry['sessions'].' oturum, hiç dönüşüm yok. Sayfanın üstüne ve sonuna görünür bir arama / WhatsApp / form çağrısı ekle; mevcut metni koru.', 0, 240),
                'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id, 'last_seen_at' => now(), 'material_hash' => hash('sha256', 'conversion|'.$page->url),
                'evidence' => [['kind' => 'quote', 'value' => $entry['sessions'].' oturum · 0 dönüşüm (GA4, 28 gün)', 'source' => (string) $page->url]]];
            if ($row === null) {
                Suggestion::query()->create($values + ['brand_id' => $site->brand_id, 'fingerprint' => $fingerprint, 'status' => Suggestion::OPEN,
                    'first_seen_at' => now(), 'action' => ['site_id' => (int) $site->id]]);
            } else {
                $row->forceFill($values)->save();
            }
        }
        $this->closeUnseen($existing, $seen, 'Sayfa artık dönüşüm getiriyor (otomatik kapandı).');
    }

    /**
     * @param  Collection<string, Suggestion>  $existing
     * @param  list<string>  $seen
     */
    private function closeUnseen(Collection $existing, array $seen, string $note): void
    {
        Suggestion::query()->whereIn('id', $existing->filter(fn (Suggestion $s): bool => ! in_array($s->fingerprint, $seen, true)
            && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK], true))->pluck('id'))
            ->update(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(), 'operator_note' => $note]);
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', SeoText::fold($text)) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 4 && ! is_numeric($w) && ! in_array($w, self::STOP_WORDS, true))));
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return Collection<string, Page> the site's pages by URL key */
    private function pages(DigitalAsset $site): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->get(['id', 'url', 'title', 'category', 'wp_post_id', 'is_indexable', 'language'])
            ->keyBy(fn (Page $p): string => SeoText::urlKey((string) $p->url));
    }

    /**
     * The live page whose address shares the most words with the dead one (at least two and half of them), in the
     * same language folder.
     *
     * @param  Collection<string, Page>  $pages
     */
    private function closest(string $deadPath, Collection $pages): ?string
    {
        $words = fn (string $path): array => array_values(array_unique(array_filter(preg_split('/[-_\/]+/', mb_strtolower(trim($path, '/'))) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 3 && ! is_numeric($w))));
        $lang = fn (string $path): string => preg_match('#^/([a-z]{2})/#', $path, $m) === 1 ? $m[1] : '';
        $dead = $words($deadPath);
        if (count($dead) < 2) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($pages as $page) {
            $path = $this->path((string) $page->url);
            if ($path === '/' || $lang($path) !== $lang($deadPath)) {
                continue;
            }
            $candidate = $words($path);
            $shared = count(array_intersect($dead, $candidate));
            $score = $shared / max(1, count(array_unique([...$dead, ...$candidate])));
            if ($shared >= 2 && $score >= 0.5 && $score > $bestScore) {
                [$best, $bestScore] = [(string) $page->url, $score];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function list(array $lines): array
    {
        $shown = array_slice($lines, 0, self::LIST);

        return count($lines) > self::LIST ? [...$shown, '… ve '.(count($lines) - self::LIST).' tane daha'] : $shown;
    }

    private function origin(DigitalAsset $site): string
    {
        return rtrim(SiteScope::origin($site), '/');
    }

    private function host(DigitalAsset $site): string
    {
        return SeoText::urlKey(SiteScope::origin($site));
    }

    private function path(string $url): string
    {
        $path = SeoText::urlPath($url);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $path.($query !== '' ? '?'.$query : '');
    }

    private function wrap(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    private function cacheKey(string $url): string
    {
        return 'web-health:external:'.sha1($url);
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        return is_array($value) ? $value : (is_string($value) ? (array) json_decode($value, true) : []);
    }
}
