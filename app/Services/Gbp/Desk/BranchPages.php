<?php

namespace App\Services\Gbp\Desk;

use App\Ai\Agents\GbpBranchPageAgent;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpBranchPage;
use App\Models\Page;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpAssistant;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Şube sayfaları (ADR-079): every Business Profile should point to its own page on the brand's website (address, hours,
 * services of that branch, local-business markup). Per location the desk shows one state:
 *  - `linked`: the profile's website link already opens a non-home page of the brand's site (✓) — with several
 *    profiles, not a page that belongs to another branch (that profile is matched on as if unlinked);
 *  - `unlinked`: a page for the branch exists (found by area / name, or the one MoxDOP sent and the operator published)
 *    but the profile links elsewhere → "Profili bu sayfaya bağla" (ADR-079 website-link write, with UTM tags);
 *  - `sent`: the draft page is in WordPress, waiting for the operator to publish it;
 *  - `ready`: AI text ready to read / edit → "WordPress'e taslak gönder" (ADR-064/076 draft + markup);
 *  - `missing`: no page → "Sayfayı hazırla"; `no_site`: the brand has no website.
 * Address, phone, hours, the map link and the JSON-LD markup always come from the profile (rules), never from AI.
 */
final class BranchPages
{
    public const string UTM = 'utm_source=google&utm_medium=organic&utm_campaign=isletme-profili';

    public const array STATES = [
        'linked' => 'Profil kendi sayfasına bağlı',
        'single' => 'Tek işletme: ana sayfa yeterli',
        'unlinked' => 'Sayfa var, profil ona bağlı değil',
        'sent' => 'WordPress’te taslak; yayınlanmayı bekliyor',
        'ready' => 'Sayfa metni hazır; okunup gönderilecek',
        'failed' => 'Gönderilemedi',
        'missing' => 'Şube sayfası yok',
        'no_data' => 'Profil verisi henüz çekilmedi',
        'no_site' => 'Markaya web sitesi bağlı değil',
    ];

    /** States that need nothing more. */
    public const array DONE = ['linked', 'single'];

    /** Steps of a branch page, in order (stepper on the screen). */
    public const array STEPS = ['Sayfa metni', 'WordPress taslağı', 'Sitede yayında', 'Profil bağlı'];

    /** Branch hub ("Şubelerimiz") is proposed from this many profiles of one brand. */
    public const int HUB_MIN = 3;

    /** A site page whose address or title says it lists the branches. */
    private const string HUB_PATTERN = '/\b(subeler|subelerimiz|subelerimizi|lokasyonlar|lokasyonlarimiz|kliniklerimiz|magazalarimiz|adreslerimiz)\b/';

    /** Words that say nothing about which branch a page is for. */
    private const array GENERIC = ['sube', 'subesi', 'subemiz', 'klinik', 'klinigi', 'dis', 'agiz', 'sagligi', 'poliklinigi', 'merkezi', 'isletme', 'profili', 've'];

    public function __construct(
        private readonly GbpDesk $desk,
        private readonly AiTaskQueue $tasks,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    public static function reference(int $assetId): string
    {
        return 'gbp-branch-'.$assetId;
    }

    /**
     * The state of every location.
     *
     * @param  Collection<int, DigitalAsset>  $locations
     * @param  array<int, array<string, mixed>>  $snapshots  GbpDesk::snapshots()
     * @return array<int, array{state: string, label: string, page: ?Page, row: ?GbpBranchPage, site: ?DigitalAsset, link: array{current: string, on_site: bool, home: bool}, chosen: bool, step: int}>
     */
    public function states(Collection $locations, array $snapshots): array
    {
        $brandIds = $locations->pluck('brand_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $sites = DigitalAsset::query()->whereIn('brand_id', $brandIds)->where('type', 'website')->orderBy('id')->get(['id', 'brand_id', 'name', 'domain', 'primary_url']);
        $pages = Page::query()->whereIn('website_asset_id', $sites->pluck('id'))->where('is_indexable', true)
            ->get(['id', 'website_asset_id', 'url', 'path', 'title', 'h1', 'category', 'wp_post_id', 'language']);
        $rows = GbpBranchPage::query()->whereIn('digital_asset_id', $locations->pluck('id'))->with('draftAction:id,status,error,result')->get()->keyBy('digital_asset_id');
        $byKey = $pages->keyBy(fn (Page $p): string => GbpDesk::urlKey((string) $p->url));
        $perBrand = $this->desk->locations()->countBy('brand_id');
        $sitesByBrand = $sites->groupBy('brand_id');
        $pagesBySite = $pages->groupBy('website_asset_id');
        $branchCandidates = $this->branchCandidates($pages);
        $claimed = GbpBranchPage::query()->whereIn('brand_id', $brandIds)->get(['digital_asset_id', 'page_id', 'wp_post_id', 'website_asset_id']);
        $taken = [];
        $out = [];
        foreach ($locations as $location) {
            $brandSites = $sitesByBrand->get($location->brand_id, collect());
            $site = $brandSites->first();
            $snapshot = $snapshots[$location->id] ?? null;
            $row = $rows->get($location->id);
            $current = $snapshot !== null ? (string) $snapshot['website'] : '';
            $domains = $brandSites->map(fn (DigitalAsset $s): string => (string) preg_replace('/^www\./', '', strtolower((string) ($s->domain ?: parse_url((string) $s->primary_url, PHP_URL_HOST)))))->filter()->all();
            $currentHost = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($current, PHP_URL_HOST)));
            $link = ['current' => $current, 'on_site' => $currentHost !== '' && in_array($currentHost, $domains, true),
                'home' => $current !== '' && in_array(trim((string) parse_url($current, PHP_URL_PATH), '/'), ['', 'tr'], true)];
            $state = static fn (string $key, ?Page $page = null, ?string $label = null) => ['state' => $key, 'label' => $label ?? self::STATES[$key], 'page' => $page, 'row' => $row, 'site' => $site,
                'link' => $link, 'chosen' => $page !== null && $row?->page_id !== null && (int) $row->page_id === (int) $page->id, 'step' => self::step($key)];
            if ($site === null) {
                $out[$location->id] = $state('no_site');

                continue;
            }
            $sitePages = $brandSites->flatMap(fn (DigitalAsset $s): Collection => $pagesBySite->get($s->id, collect()));
            $linkKey = GbpDesk::urlKey($current);
            $linked = $linkKey !== '' ? $byKey->get($linkKey) : null;
            if ($linked !== null && $sitePages->contains('id', $linked->id) && ! self::isHome($linked)
                && ((int) ($perBrand[$location->brand_id] ?? 0) <= 1 || ! self::isOtherBranchPage($location, $snapshot, $linked, $branchCandidates, $claimed))) {
                $taken[$linked->id] = true;
                $out[$location->id] = $state('linked', $linked);

                continue;
            }
            $chosen = $row?->page_id !== null ? $sitePages->firstWhere('id', (int) $row->page_id) : null;
            if ($chosen === null && (int) ($perBrand[$location->brand_id] ?? 0) <= 1 && ! in_array($row?->status, [GbpBranchPage::READY, GbpBranchPage::SENT, GbpBranchPage::FAILED], true)) {
                $home = $sitePages->first(fn (Page $p): bool => self::isHome($p));
                $out[$location->id] = $state('single', $home, $snapshot === null ? 'Tek işletme · profil verisi henüz çekilmedi'
                    : ($link['on_site'] ? self::STATES['single'] : 'Tek işletme · profil sitenize bağlı değil'));

                continue;
            }
            $sent = $row?->wp_post_id !== null ? $sitePages->first(fn (Page $p): bool => (int) $p->wp_post_id === (int) $row->wp_post_id) : null;
            $found = $chosen ?? $sent ?? $this->candidate($location, $snapshot, $brandSites->pluck('id')->all(), $branchCandidates, $taken);
            if ($found !== null) {
                $taken[$found->id] = true;
                $out[$location->id] = $state('unlinked', $found);

                continue;
            }
            $key = match ($row?->status) {
                GbpBranchPage::SENT => 'sent',
                GbpBranchPage::READY => 'ready',
                GbpBranchPage::FAILED => 'failed',
                default => $snapshot === null ? 'no_data' : 'missing',
            };
            $out[$location->id] = $state($key);
        }

        return $out;
    }

    /** Completed steps of a state (0–4; STEPS). */
    public static function step(string $state): int
    {
        return match ($state) {
            'ready', 'failed' => 1,
            'sent' => 2,
            'unlinked' => 3,
            'linked', 'single' => 4,
            default => 0,
        };
    }

    /**
     * The operator points a profile at a page the site already has (when the automatic match missed it).
     */
    public function choose(User $user, DigitalAsset $location, Page $page): void
    {
        $this->guard($user);
        $sites = DigitalAsset::query()->where('brand_id', $location->brand_id)->where('type', 'website')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (! in_array((int) $page->website_asset_id, $sites, true)) {
            throw ValidationException::withMessages(['page' => 'Sayfa bu markanın sitesinde değil.']);
        }
        $row = GbpBranchPage::query()->firstOrNew(['digital_asset_id' => $location->id]);
        if (! $row->exists) {
            $row->forceFill(['brand_id' => $location->brand_id, 'website_asset_id' => $page->website_asset_id, 'status' => GbpBranchPage::CHOSEN]);
        }
        $row->forceFill(['page_id' => $page->id])->save();
    }

    public function unchoose(User $user, DigitalAsset $location): void
    {
        $this->guard($user);
        $row = GbpBranchPage::query()->where('digital_asset_id', $location->id)->first();
        if ($row?->status === GbpBranchPage::CHOSEN) {
            $row->delete();
        } else {
            $row?->forceFill(['page_id' => null])->save();
        }
    }

    /**
     * Pages of the brand's site to pick from: matching the search, or likely branch / contact pages when empty.
     *
     * @return Collection<int, Page>
     */
    public function searchPages(DigitalAsset $location, string $query): Collection
    {
        $sites = DigitalAsset::query()->where('brand_id', $location->brand_id)->where('type', 'website')->pluck('id');
        $query = trim($query);

        return Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereNull('language')->orWhere('language', 'tr'))
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$query.'%')->orWhere('path', 'like', '%'.$query.'%')->orWhere('h1', 'like', '%'.$query.'%')),
                fn ($q) => $q->where(fn ($w) => $w->where('category', 'lokasyon')->orWhere('path', 'like', '%sube%')->orWhere('path', 'like', '%iletisim%')->orWhere('path', 'like', '%lokasyon%')))
            ->orderByRaw("case when category = 'lokasyon' then 0 else 1 end")->orderBy('path')->limit(12)->get(['id', 'url', 'path', 'title', 'category']);
    }

    /**
     * The brand's branch hub page ("Şubelerimiz"): the site page listing the branches, or the draft MoxDOP sent.
     *
     * @return array{needed: bool, page: ?Page, action: ?ExternalWriteAction, count: int, ready: int}
     */
    public function hub(int $brandId): array
    {
        $locations = $this->desk->locations($brandId);
        $snapshots = $this->desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $sites = DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')->pluck('id');
        $page = Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)->get(['id', 'url', 'path', 'title', 'h1', 'language'])
            ->first(fn (Page $p): bool => ($p->language === null || $p->language === 'tr')
                && preg_match(self::HUB_PATTERN, SeoText::fold(implode(' ', [(string) $p->title, (string) $p->h1, str_replace(['-', '/'], ' ', (string) $p->path)]))) === 1);
        $action = ExternalWriteAction::query()->whereIn('digital_asset_id', $sites)->where('action', ExternalWriteAction::ACTION_ARTICLE_DRAFTS)
            ->where('request_payload->reference', self::hubReference($brandId))->latest('id')->first();

        return ['needed' => $locations->count() >= self::HUB_MIN && $sites->isNotEmpty(), 'page' => $page, 'action' => $action, 'count' => $locations->count(), 'ready' => count($snapshots)];
    }

    public static function hubReference(int $brandId): string
    {
        return 'gbp-branch-hub-'.$brandId;
    }

    /**
     * The hub page built by rules from the profiles (no AI): every branch with its address, phone, hours, map link and
     * its own page when it has one.
     *
     * @return array{title: string, html: string, branches: int}
     */
    public function hubContent(int $brandId): array
    {
        $brand = Brand::query()->findOrFail($brandId);
        $locations = $this->desk->locations($brandId);
        $snapshots = $this->desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $states = $this->states($locations, $snapshots);
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $branches = $locations->filter(fn (DigitalAsset $l): bool => isset($snapshots[$l->id]))
            ->sortBy(fn (DigitalAsset $l): string => mb_strtolower((string) ($snapshots[$l->id]['area'] ?: GbpDesk::shortName((string) $l->name))));
        $html = '<p>'.$e($brand->name.' şubelerinin adresleri, telefonları ve çalışma saatleri. Size en yakın şubeyi seçip yol tarifi alabilirsiniz.')."</p>\n";
        foreach ($branches as $location) {
            $snapshot = $snapshots[$location->id];
            $name = (string) ($snapshot['title'] ?: GbpDesk::shortName((string) $location->name));
            $page = in_array($states[$location->id]['state'] ?? '', ['linked', 'unlinked'], true) ? $states[$location->id]['page'] : null;
            $html .= '<h2>'.($page !== null ? '<a href="'.$e((string) $page->url).'">'.$e($name).'</a>' : $e($name))."</h2>\n";
            if ($snapshot['address_text'] !== '') {
                $html .= '<p><strong>Adres:</strong> '.$e((string) $snapshot['address_text'])."</p>\n";
            }
            if ($snapshot['phone'] !== '') {
                $html .= '<p><strong>Telefon:</strong> <a href="tel:'.$e((string) preg_replace('/[^\d+]/', '', (string) $snapshot['phone'])).'">'.$e((string) $snapshot['phone'])."</a></p>\n";
            }
            $hours = GbpDesk::weekHours($snapshot['regular_hours']);
            if ($hours !== []) {
                $html .= '<p><strong>Çalışma saatleri:</strong> '.$e(implode(' · ', array_map(fn (string $d, string $h): string => $d.' '.$h, array_keys($hours), $hours)))."</p>\n";
            }
            $links = array_filter([
                $page !== null ? '<a href="'.$e((string) $page->url).'">Şube sayfası</a>' : null,
                $snapshot['maps_uri'] !== '' ? '<a href="'.$e((string) $snapshot['maps_uri']).'" target="_blank" rel="noopener">Yol tarifi al</a>' : null,
            ]);
            if ($links !== []) {
                $html .= '<p>'.implode(' · ', $links)."</p>\n";
            }
        }

        return ['title' => $brand->name.' Şubeleri', 'html' => $html, 'branches' => $branches->count()];
    }

    /** Admin: the hub page goes to WordPress as a draft page (ADR-064/076 draft). */
    public function sendHub(User $user, int $brandId): ExternalWriteAction
    {
        $this->guard($user);
        $hub = $this->hub($brandId);
        if (! $hub['needed']) {
            throw ValidationException::withMessages(['page' => 'Şubelerimiz sayfası en az '.self::HUB_MIN.' şubesi olan markalar için hazırlanır.']);
        }
        $content = $this->hubContent($brandId);
        if ($content['branches'] < 2) {
            throw ValidationException::withMessages(['page' => 'Şube bilgisi yok: önce İşletme Profili verisini çekin.']);
        }
        $site = DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')->orderBy('id')->firstOrFail();

        return app(ExternalWriteService::class)->requestArticleDrafts($user, $site, ArticleDraft::fromArray([
            'title' => $content['title'], 'html' => $content['html'], 'reference' => self::hubReference($brandId), 'slug' => 'subelerimiz',
            'excerpt' => '', 'meta_title' => mb_substr($content['title'], 0, 70), 'meta_description' => mb_substr(strip_tags((string) strtok($content['html'], "\n")), 0, 160),
            'focus_keyword' => mb_strtolower($content['title']), 'language' => 'tr', 'post_type' => 'page',
        ]));
    }

    /**
     * The site page that is about this branch: a location page (or one whose address says "şube") naming the branch's
     * district, preferring the one that also carries the branch's own words (two branches in one district).
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<int>  $siteIds  the brand's website assets
     * @param  array<int, array{page: Page, text: string}>  $branchCandidates  branchCandidates()
     * @param  array<int, true>  $taken  pages already given to another profile
     */
    private function candidate(DigitalAsset $location, ?array $snapshot, array $siteIds, array $branchCandidates, array $taken): ?Page
    {
        $district = SeoText::fold((string) ($snapshot['address']['sublocality'] ?? ''));
        $city = SeoText::fold((string) ($snapshot['address']['locality'] ?? ''));
        if ($district === '') {
            return null;
        }
        $brandWords = SeoText::tokens((string) $location->brand?->name);
        $own = array_values(array_diff(SeoText::tokens(GbpDesk::shortName((string) $location->name)), $brandWords, self::GENERIC, explode(' ', $district), $city !== '' ? explode(' ', $city) : []));
        $districtPattern = '/\b'.preg_quote($district, '/').'\b/';
        $best = null;
        $bestScore = 0;
        foreach ($branchCandidates as $pageId => ['page' => $page, 'text' => $text]) {
            if (isset($taken[$pageId]) || ! in_array((int) $page->website_asset_id, $siteIds, true) || preg_match($districtPattern, $text) !== 1) {
                continue;
            }
            $score = 10 + count(array_filter($own, fn (string $w): bool => preg_match('/\b'.preg_quote($w, '/').'\b/', $text) === 1));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$page, $score];
            }
        }

        return $best;
    }

    /**
     * Turkish pages that look like branch pages (category "lokasyon" or "şube" in the address / title), with their folded
     * text: worked out once per call and shared by every profile, so a site with many pages is scanned once, not once per profile.
     *
     * @param  Collection<int, Page>  $pages
     * @return array<int, array{page: Page, text: string}> page id => page and folded title / h1 / path
     */
    private function branchCandidates(Collection $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            if (self::isHome($page) || ($page->language !== null && $page->language !== 'tr')) {
                continue;
            }
            $text = SeoText::fold(implode(' ', [(string) $page->title, (string) $page->h1, str_replace(['-', '/'], ' ', (string) $page->path)]));
            if ($page->category === 'lokasyon' || preg_match('/\b(sube|subesi|subemiz|subelerimiz)\b/', $text) === 1) {
                $out[(int) $page->id] = ['page' => $page, 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * Whether a page belongs to another branch of the brand: another profile chose it or MoxDOP sent it for another
     * profile, or it is a branch page that does not name this profile's district. Unknown district = not decided here.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  array<int, array{page: Page, text: string}>  $branchCandidates
     * @param  Collection<int, GbpBranchPage>  $claimed  branch page rows of the brands
     */
    private static function isOtherBranchPage(DigitalAsset $location, ?array $snapshot, Page $page, array $branchCandidates, Collection $claimed): bool
    {
        $owners = $claimed->filter(fn (GbpBranchPage $r): bool => ($r->page_id !== null && (int) $r->page_id === (int) $page->id)
            || ($r->wp_post_id !== null && $page->wp_post_id !== null && (int) $r->wp_post_id === (int) $page->wp_post_id && (int) $r->website_asset_id === (int) $page->website_asset_id))
            ->pluck('digital_asset_id')->map(fn ($id): int => (int) $id);
        if ($owners->contains((int) $location->id)) {
            return false;
        }
        if ($owners->isNotEmpty()) {
            return true;
        }
        $district = SeoText::fold((string) ($snapshot['address']['sublocality'] ?? ''));
        if (! isset($branchCandidates[(int) $page->id]) || $district === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($district, '/').'\b/', $branchCandidates[(int) $page->id]['text']) !== 1;
    }

    private static function isHome(Page $page): bool
    {
        return in_array(trim((string) $page->path, '/'), ['', 'tr', 'en', 'anasayfa', 'home'], true);
    }

    /**
     * AI writes the page text; rules add the facts. Returns null while Claude has not answered.
     *
     * @return array{issues: int}|null
     */
    public function prepare(DigitalAsset $location): ?array
    {
        $location->loadMissing('brand.customer');
        $brand = $location->brand ?? throw new RuntimeException('İşletme bir markaya bağlı değil.');
        if (! $brand->isOperational()) {
            throw new RuntimeException('Marka operasyonel değil; AI çalışmaz.');
        }
        $snapshot = $this->desk->snapshots([(int) $location->id])[$location->id] ?? throw new RuntimeException('Profilin verisi henüz toplanmadı; önce İşletme Profili verisini çekin.');
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->first() ?? throw new RuntimeException('Markaya web sitesi bağlı değil.');
        $servicePages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->where('category', 'hizmet')
            ->where(fn ($q) => $q->whereNull('language')->orWhere('language', 'tr'))->orderBy('id')->limit(40)->get(['id', 'url', 'title', 'h1', 'content_summary']);
        $siblings = $this->desk->locations((int) $brand->id)->reject(fn (DigitalAsset $l): bool => $l->id === $location->id)->take(30);
        $siblingSnapshots = $this->desk->snapshots($siblings->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $data = [
            'business' => (string) $brand->name,
            'branch' => ['name' => $snapshot['title'] ?: GbpDesk::shortName((string) $location->name), 'area' => $snapshot['area'], 'address' => $snapshot['address_text'], 'category' => $snapshot['primary_category']],
            'other_branches' => $siblings->map(fn (DigitalAsset $l): array => ['name' => GbpDesk::shortName((string) $l->name), 'area' => (string) ($siblingSnapshots[$l->id]['area'] ?? '')])->values()->all(),
            'offerings' => array_column(app(GbpAssistant::class)->offerings($brand), 'name'),
            'service_pages' => $servicePages->map(fn (Page $p): array => ['title' => (string) ($p->title ?: $p->h1), 'url' => (string) $p->url, 'summary' => mb_substr((string) $p->content_summary, 0, 300)])->values()->all(),
            'compliance' => app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all(),
            'language' => 'tr',
        ];
        [$raw, $versionId] = $this->ask($data);
        if ($raw === null) {
            return null;
        }
        $content = $this->content($location, $snapshot, $raw, $servicePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        $issues = $this->issues($location, $content);
        GbpBranchPage::query()->updateOrCreate(['digital_asset_id' => $location->id], [
            'brand_id' => $brand->id, 'website_asset_id' => $site->id, 'status' => GbpBranchPage::READY, 'content' => $content, 'issues' => $issues,
            'prompt_version_id' => $versionId, 'draft_action_id' => null, 'wp_post_id' => null, 'edit_url' => null, 'note' => null,
        ]);

        return ['issues' => count($issues)];
    }

    /**
     * The checked AI parts plus the rule-built facts.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowedUrls
     * @return array<string, mixed>
     */
    public function content(DigitalAsset $location, array $snapshot, array $raw, array $allowedUrls): array
    {
        $clean = static fn (mixed $text, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text))), 0, $max);
        $noContact = static fn (string $text): string => preg_match('~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d|#\w~u', $text) === 1 ? '' : $text;
        $allowed = array_flip($allowedUrls);
        $services = [];
        foreach (array_slice((array) ($raw['services'] ?? []), 0, 8) as $row) {
            $name = $clean($row['name'] ?? '', 120);
            $text = $noContact($clean($row['text'] ?? '', 400));
            if ($name !== '' && $text !== '') {
                $url = (string) ($row['page_url'] ?? '');
                $services[] = ['name' => $name, 'text' => $text, 'page_url' => isset($allowed[$url]) ? $url : ''];
            }
        }
        $faq = [];
        foreach (array_slice((array) ($raw['faq'] ?? []), 0, 6) as $row) {
            $question = $noContact($clean($row['question'] ?? '', 200));
            $answer = $noContact($clean($row['answer'] ?? '', 600));
            if ($question !== '' && $answer !== '') {
                $faq[] = ['question' => $question, 'answer' => $answer];
            }
        }
        $intro = array_values(array_filter(array_map(fn (mixed $p): string => $noContact($clean($p, 900)), array_slice((array) ($raw['intro'] ?? []), 0, 3))));
        $title = $clean($raw['title'] ?? '', 90) ?: trim(($location->brand?->name ?? '').' '.$snapshot['area']);
        if ($intro === [] || $services === []) {
            throw new RuntimeException('AI sayfa metnini eksik döndürdü (giriş ya da hizmetler yok); yeniden hazırlayın.');
        }
        $slug = SeoText::slugify($clean($raw['slug'] ?? '', 80) ?: $title);

        return [
            'title' => $title,
            'slug' => mb_substr($slug !== '' ? $slug : 'sube-'.$location->id, 0, 70),
            'meta_title' => $clean($raw['meta_title'] ?? '', 70) ?: $title,
            'meta_description' => $clean($raw['meta_description'] ?? '', 160),
            'focus_keyword' => $clean($raw['focus_keyword'] ?? '', 80),
            'intro' => $intro,
            'services' => $services,
            'access' => $noContact($clean($raw['access'] ?? '', 500)),
            'faq' => $faq,
            'facts' => [
                'name' => $snapshot['title'] ?: GbpDesk::shortName((string) $location->name),
                'address' => $snapshot['address_text'], 'phone' => $snapshot['phone'], 'maps_uri' => $snapshot['maps_uri'],
                'hours' => GbpDesk::weekHours($snapshot['regular_hours']),
            ],
        ];
    }

    /** The page body: AI text (escaped) and the profile's facts. */
    public static function html(array $content): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $facts = (array) ($content['facts'] ?? []);
        $html = '';
        foreach ((array) ($content['intro'] ?? []) as $paragraph) {
            $html .= '<p>'.$e((string) $paragraph)."</p>\n";
        }
        $html .= "<h2>Hizmetlerimiz</h2>\n<ul>\n";
        foreach ((array) ($content['services'] ?? []) as $service) {
            $name = $service['page_url'] !== '' ? '<a href="'.$e((string) $service['page_url']).'">'.$e((string) $service['name']).'</a>' : $e((string) $service['name']);
            $html .= '<li><strong>'.$name.'</strong>: '.$e((string) $service['text'])."</li>\n";
        }
        $html .= "</ul>\n<h2>Adres ve ulaşım</h2>\n";
        if (($facts['address'] ?? '') !== '') {
            $html .= '<p><strong>Adres:</strong> '.$e((string) $facts['address'])."</p>\n";
        }
        if (($facts['phone'] ?? '') !== '') {
            $html .= '<p><strong>Telefon:</strong> <a href="tel:'.$e((string) preg_replace('/[^\d+]/', '', (string) $facts['phone'])).'">'.$e((string) $facts['phone'])."</a></p>\n";
        }
        if (($content['access'] ?? '') !== '') {
            $html .= '<p>'.$e((string) $content['access'])."</p>\n";
        }
        if (($facts['maps_uri'] ?? '') !== '') {
            $html .= '<p><a href="'.$e((string) $facts['maps_uri']).'" target="_blank" rel="noopener">Google Haritalar’da yol tarifi al</a></p>'."\n";
        }
        if ((array) ($facts['hours'] ?? []) !== []) {
            $html .= "<h2>Çalışma saatleri</h2>\n<table>\n<tbody>\n";
            foreach ((array) $facts['hours'] as $day => $hours) {
                $html .= '<tr><th>'.$e((string) $day).'</th><td>'.$e((string) $hours)."</td></tr>\n";
            }
            $html .= "</tbody>\n</table>\n";
        }
        if ((array) ($content['faq'] ?? []) !== []) {
            $html .= "<h2>Sık sorulan sorular</h2>\n";
            foreach ((array) $content['faq'] as $row) {
                $html .= '<h3>'.$e((string) $row['question']).'</h3>'."\n".'<p>'.$e((string) $row['answer'])."</p>\n";
            }
        }

        return $html;
    }

    public static function article(DigitalAsset $location, array $content): ArticleDraft
    {
        return ArticleDraft::fromArray([
            'title' => (string) $content['title'], 'html' => self::html($content), 'reference' => self::reference((int) $location->id),
            'slug' => (string) $content['slug'], 'excerpt' => (string) ($content['intro'][0] ?? ''), 'meta_title' => (string) $content['meta_title'],
            'meta_description' => (string) $content['meta_description'], 'focus_keyword' => (string) $content['focus_keyword'],
            'language' => 'tr', 'post_type' => 'page',
        ]);
    }

    /**
     * Sector-rule hits of the page (blocking ones stop the send).
     *
     * @param  array<string, mixed>  $content
     * @return list<array{field: string, matched: string, message: string, blocking: bool}>
     */
    public function issues(DigitalAsset $location, array $content): array
    {
        $violations = app(ContentComplianceGate::class)->violations($location->loadMissing('brand')->brand, self::article($location, $content));

        return array_map(fn (array $v): array => ['field' => $v['field_label'], 'matched' => $v['matched'], 'message' => $v['message'], 'blocking' => $v['blocking']], $violations);
    }

    /**
     * The operator's edit of the text parts (one field at a time from the screen).
     *
     * @param  array<string, mixed>  $changes  title / meta_title / meta_description / slug / intro (text, paragraphs by blank line) / access
     */
    public function edit(User $user, GbpBranchPage $row, array $changes): void
    {
        $this->guard($user);
        if ($row->status !== GbpBranchPage::READY && $row->status !== GbpBranchPage::FAILED) {
            throw ValidationException::withMessages(['page' => 'Gönderilmiş sayfa burada düzenlenmez; WordPress’te düzenleyin.']);
        }
        $content = (array) $row->content;
        foreach (['title' => 90, 'meta_title' => 70, 'meta_description' => 160, 'access' => 500] as $key => $max) {
            if (array_key_exists($key, $changes)) {
                $content[$key] = mb_substr(trim(strip_tags((string) $changes[$key])), 0, $max);
            }
        }
        if (array_key_exists('slug', $changes)) {
            $content['slug'] = mb_substr(SeoText::slugify((string) $changes['slug']), 0, 70) ?: $content['slug'];
        }
        if (array_key_exists('intro', $changes)) {
            $intro = array_values(array_filter(array_map(fn (string $p): string => trim(strip_tags($p)), preg_split('/\n\s*\n/u', (string) $changes['intro']) ?: [])));
            if ($intro === []) {
                throw ValidationException::withMessages(['page' => 'Giriş metni boş olamaz.']);
            }
            $content['intro'] = $intro;
        }
        if (trim((string) ($content['title'] ?? '')) === '') {
            throw ValidationException::withMessages(['page' => 'Başlık boş olamaz.']);
        }
        $location = $row->digitalAsset()->with('brand')->firstOrFail();
        $row->forceFill(['content' => $content, 'issues' => $this->issues($location, $content), 'status' => GbpBranchPage::READY])->save();
    }

    /** Admin: the page goes to WordPress as a draft page; its markup is added when the draft exists (draftFinished). */
    public function send(User $user, GbpBranchPage $row): ExternalWriteAction
    {
        $this->guard($user);
        if (! in_array($row->status, [GbpBranchPage::READY, GbpBranchPage::FAILED], true)) {
            throw ValidationException::withMessages(['page' => 'Bu sayfa zaten gönderildi.']);
        }
        $blocking = array_filter((array) $row->issues, fn (array $i): bool => (bool) $i['blocking']);
        if ($blocking !== []) {
            throw ValidationException::withMessages(['page' => 'Sektör kuralına takılan ifade var: '.implode(', ', array_map(fn (array $i): string => '«'.$i['matched'].'»', $blocking)).'. Düzenleyin.']);
        }
        $location = $row->digitalAsset()->with('brand')->firstOrFail();
        $site = DigitalAsset::query()->find($row->website_asset_id) ?? throw ValidationException::withMessages(['page' => 'Web sitesi bulunamadı.']);
        $action = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, self::article($location, (array) $row->content));
        $row->forceFill(['status' => GbpBranchPage::SENT, 'draft_action_id' => $action->id, 'note' => null])->save();

        return $action;
    }

    /**
     * After the draft write: keep the WordPress post and add the local-business markup to it (same Admin approval).
     * A failed draft makes the page sendable again.
     */
    public function draftFinished(ExternalWriteAction $action): void
    {
        $row = GbpBranchPage::query()->where('draft_action_id', $action->id)->first();
        if ($row === null) {
            return;
        }
        if (! in_array($action->status, ['succeeded', 'partial'], true)) {
            $row->forceFill(['status' => GbpBranchPage::FAILED, 'note' => mb_substr('WordPress taslağı oluşturulamadı: '.$action->error, 0, 500)])->save();

            return;
        }
        $postId = (int) data_get($action->result, 'post_id');
        $editUrl = (string) data_get(collect((array) data_get($action->result, 'posts', []))->firstWhere('post_id', $postId), 'edit_url', '');
        $row->forceFill(['wp_post_id' => $postId ?: null, 'edit_url' => $editUrl !== '' ? $editUrl : null])->save();
        $requester = User::query()->find($action->requested_by);
        $location = $row->digitalAsset()->with('brand')->first();
        $site = DigitalAsset::query()->find($row->website_asset_id);
        if ($postId < 1 || $requester === null || $location === null || $site === null) {
            return;
        }
        try {
            $this->requestMarkup($requester, $location, $site, $postId, rtrim((string) $site->primary_url, '/').'/'.((array) $row->content)['slug'].'/');
        } catch (Throwable $exception) {
            $row->forceFill(['note' => mb_substr('Taslak hazır; işaretleme eklenemedi: '.$exception->getMessage(), 0, 500)])->save();
        }
    }

    /** Admin: the profile's website link points at the branch page (UTM-tagged so the visits are measurable). */
    public function link(User $user, DigitalAsset $location, Page $page): ExternalWriteAction
    {
        $this->guard($user);
        $url = (string) $page->url;
        $url .= (str_contains($url, '?') ? '&' : '?').self::UTM;

        return app(ExternalWriteService::class)->requestProfileFields($user, $location, ['website_uri' => $url], 'Web sitesi bağlantısı → şube sayfası');
    }

    /** Admin: local-business markup on an existing branch page (e.g. one the brand already had). */
    public function markup(User $user, DigitalAsset $location, Page $page): ExternalWriteAction
    {
        $this->guard($user);
        if ($page->wp_post_id === null) {
            throw ValidationException::withMessages(['page' => 'Sayfanın WordPress kimliği bilinmiyor; işaretleme için sitenin MoxDOP eklentisi bağlı olmalı.']);
        }
        $site = DigitalAsset::query()->findOrFail($page->website_asset_id);

        return $this->requestMarkup($user, $location, $site, (int) $page->wp_post_id, (string) $page->url);
    }

    private function requestMarkup(User $user, DigitalAsset $location, DigitalAsset $site, int $postId, string $url): ExternalWriteAction
    {
        $snapshot = $this->desk->snapshots([(int) $location->id])[$location->id] ?? throw ValidationException::withMessages(['page' => 'Profilin verisi yok.']);

        return app(ExternalWriteService::class)->requestSiteFixes($user, $site, [[
            'type' => 'schema', 'object_id' => $postId, 'reference' => 'gbp-branch-schema-'.$location->id, 'value' => self::schema($location, $snapshot, $url),
        ]]);
    }

    /**
     * JSON-LD of the branch from the profile (Dentist / MedicalClinic / LocalBusiness by the brand's sector).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function schema(DigitalAsset $location, array $snapshot, string $url): array
    {
        $sector = $location->loadMissing('brand.sectorCategory')->brand?->sectorCodes()[0] ?? '';
        $type = match (true) {
            in_array($sector, ['dental', 'dis', 'dis-sagligi'], true) => 'Dentist',
            in_array($sector, ['healthcare', 'health', 'saglik', 'medical', 'medical_aesthetics'], true) => 'MedicalClinic',
            default => 'LocalBusiness',
        };
        $address = (array) $snapshot['address'];
        $days = ['MONDAY' => 'Monday', 'TUESDAY' => 'Tuesday', 'WEDNESDAY' => 'Wednesday', 'THURSDAY' => 'Thursday', 'FRIDAY' => 'Friday', 'SATURDAY' => 'Saturday', 'SUNDAY' => 'Sunday'];
        $time = static fn (mixed $t): string => is_array($t) ? sprintf('%02d:%02d', (int) ($t['hours'] ?? 0), (int) ($t['minutes'] ?? 0)) : '00:00';
        $hours = [];
        foreach ((array) $snapshot['regular_hours'] as $period) {
            $day = $days[strtoupper((string) ($period['openDay'] ?? ''))] ?? null;
            if ($day !== null) {
                $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $day, 'opens' => $time($period['openTime'] ?? null), 'closes' => $time($period['closeTime'] ?? null) === '00:00' ? '23:59' : $time($period['closeTime'] ?? null)];
            }
        }
        $latlng = (array) $snapshot['latlng'];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => (string) ($snapshot['title'] ?: GbpDesk::shortName((string) $location->name)),
            'url' => $url,
            'telephone' => (string) $snapshot['phone'] ?: null,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => implode(', ', array_filter(array_map('strval', (array) ($address['addressLines'] ?? [])))) ?: null,
                'addressLocality' => (string) ($address['locality'] ?? '') ?: null,
                'addressRegion' => (string) ($address['administrativeArea'] ?? '') ?: null,
                'postalCode' => (string) ($address['postalCode'] ?? '') ?: null,
                'addressCountry' => (string) ($address['regionCode'] ?? 'TR') ?: 'TR',
            ]),
            'geo' => isset($latlng['latitude'], $latlng['longitude']) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $latlng['latitude'], 'longitude' => (float) $latlng['longitude']] : null,
            'hasMap' => (string) $snapshot['maps_uri'] ?: null,
            'openingHoursSpecification' => $hours !== [] ? $hours : null,
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?array<mixed>, 1: ?int}
     */
    private function ask(array $data): array
    {
        $agent = new GbpBranchPageAgent;
        $answer = $this->tasks->delegatedCall($agent, $data);
        if ($answer === 'queued') {
            return [null, null];
        }
        if ($answer === 'error') {
            throw new RuntimeException('Claude şube sayfasını yazamadı.');
        }
        if (is_array($answer)) {
            return [$answer, $agent->promptVersionId()];
        }
        $route = $this->routes->resolve(GbpBranchPageAgent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    private function guard(User $user): void
    {
        abort_unless(ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GBP) && ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_WORDPRESS), 403, 'Bunu yalnız Admin yapar.');
    }
}
