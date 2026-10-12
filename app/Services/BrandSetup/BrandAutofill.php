<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandSetupProposal;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\User;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Website\SitemapChangeWatcher;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Marka tamamlama (yakup, 2026-10-07: "Claude bunları kendi doldurabilir mi?", then "Hemen kullan"): every night a brand
 * whose card still misses something "Otomatik kur" can fill (services and ★, places, sector, İş bağlamı, Search Console
 * / GA4 match) gets an "Otomatik kur" run by itself, and the ready proposal is applied at once with its own default
 * picks (confident rows only). Nothing the operator wrote is overwritten: the applier only adds services, fills empty
 * context fields, never moves an account bound elsewhere and never changes a sector already set. A site whose pages
 * were never read is crawled first. Applied by the inactive "Claude (otomatik)" user, so the brand card can ask for a
 * look ("Kontrol et") until the operator confirms it.
 */
final class BrandAutofill
{
    /**
     * Checklist items an "Otomatik kur" run can fill. Places are not among them (yakup, 2026-10-09): the nightly
     * Marka verisi denetimi (BrandDataAudit) proposes them with their evidence on the Onarım masası for approval.
     */
    public const array FILLABLE = ['services', 'main_service', 'sector', 'context', 'search_console', 'ga4'];

    /** A brand is not asked again sooner than this after its last automatic run or crawl. */
    public const int COOLDOWN_DAYS = 7;

    /** The operator's own ready proposal stops holding the brand back after this many days. */
    public const int STALE_DAYS = 7;

    public const string SYSTEM_EMAIL = 'claude-otomatik@moxdop.local';

    public function __construct(
        private readonly BrandSetupAssistant $assistant,
        private readonly BrandSetupApplier $applier,
        private readonly BrandWorkspaceReadService $workspace,
    ) {}

    /** The inactive Admin user automatic runs act as (cannot log in: inactive, unknown password). */
    public static function systemUser(): User
    {
        $user = User::query()->firstOrCreate(['email' => self::SYSTEM_EMAIL],
            ['name' => 'Claude (otomatik)', 'password' => Str::random(64), 'is_active' => false]);
        if (! $user->hasRole(Roles::ADMIN)) {
            $user->assignRole(Roles::ADMIN);
        }

        return $user;
    }

    public static function isSystem(?int $userId): bool
    {
        return $userId !== null && User::query()->whereKey($userId)->where('email', self::SYSTEM_EMAIL)->exists();
    }

    /**
     * One night's round over the operational brands.
     *
     * @return array<string, int> brands per outcome (queued, applied, crawl, waiting, complete, no_site) and services archived / renamed / linked by the language round
     */
    public function run(?int $brandId = null): array
    {
        $out = ['queued' => 0, 'applied' => 0, 'crawl' => 0, 'waiting' => 0, 'complete' => 0, 'no_site' => 0, 'archived' => 0, 'renamed' => 0, 'linked' => 0];
        try {
            $out = array_merge($out, app(LanguageServices::class)->run($brandId));
        } catch (Throwable $exception) {
            report($exception);
        }
        $brands = Brand::query()->operational()->when($brandId !== null, fn ($q) => $q->whereKey($brandId))->orderBy('id')->get();
        foreach ($brands as $brand) {
            try {
                $out[$this->forBrand($brand)]++;
            } catch (Throwable $exception) {
                report($exception);
                self::note((int) $brand->id, 'Marka tamamlama hata verdi: '.mb_substr(trim($exception->getMessage()) ?: class_basename($exception), 0, 160).'; bir sonraki gece yeniden denenir.');
            }
        }

        return $out;
    }

    /** @return string queued | applied | crawl | waiting | complete | no_site */
    public function forBrand(Brand $brand): string
    {
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
        $url = (string) ($site?->primary_url ?: $site?->domain);
        if ($site === null || $url === '') {
            return 'no_site';
        }
        $latest = BrandSetupProposal::query()->where('brand_id', $brand->id)->latest('id')->first();
        if ($latest !== null && $latest->isPending() && ! $latest->isStuck()) {
            return 'waiting';
        }
        if ($latest !== null && $latest->status === BrandSetupProposal::STATUS_READY && $latest->auto_apply) {
            $this->apply($latest);

            return 'applied';
        }
        if ($this->missing($brand) === []) {
            Cache::forget('brand-autofill:note:'.$brand->id);

            return 'complete';
        }
        if ($latest !== null && $latest->status === BrandSetupProposal::STATUS_READY && ! $this->isStale($latest)) {
            self::note((int) $brand->id, 'Senin başlattığın "Otomatik kur" önerisi hazır ve uygulanmayı bekliyor; uygulanana kadar marka tamamlama bekler ('
                .self::STALE_DAYS.' gün içinde uygulanmazsa kendisi devam eder).');

            return 'waiting'; // the operator's own proposal waits for the operator, for a week at most
        }
        if ($latest !== null && $latest->auto_apply && $latest->created_at?->gt(now()->subDays(self::COOLDOWN_DAYS))) {
            return 'waiting';
        }
        if (! Page::query()->where('website_asset_id', $site->id)->exists() && $this->crawl($brand, $site) !== 'read') {
            return 'crawl';
        }
        $proposal = $this->assistant->queue($brand, $url, null);
        $proposal->forceFill(['auto_apply' => true])->save();
        self::note((int) $brand->id, 'Eksikler ('.implode(', ', $this->missing($brand)).') sitesinden ve hesaplarından dolduruluyor; hazır olunca kendiliğinden uygulanır.');

        return 'queued';
    }

    /** An operator's ready proposal nobody applied for a week no longer blocks the nightly round. */
    private function isStale(BrandSetupProposal $proposal): bool
    {
        $readyAt = $proposal->updated_at ?? $proposal->created_at;

        return $readyAt === null || $readyAt->lt(now()->subDays(self::STALE_DAYS));
    }

    /**
     * The checklist items an automatic run can still fill.
     *
     * @return list<string>
     */
    public function missing(Brand $brand): array
    {
        $items = $this->workspace->checklist($brand, $this->workspace->assets($brand), $this->workspace->services($brand))['items'];

        return array_values(array_map(fn (array $i): string => $i['key'], array_filter($items, fn (array $i): bool => ! $i['done'] && in_array($i['key'], self::FILLABLE, true))));
    }

    /**
     * Applies a ready automatic proposal with its default picks, then makes sure exactly one service is ★ (the applier
     * marks every core service; an automatic run leaves one, and never adds one next to the operator's own ★).
     *
     * @return list<array{key: string, label: string, ok: bool, message: string}>
     */
    public function apply(BrandSetupProposal $proposal): array
    {
        $items = array_values(array_map(fn (array $i): string => (string) $i['key'], array_filter($proposal->itemRows(), fn (array $i): bool => (bool) ($i['selected'] ?? false))));
        $services = array_keys(array_filter($proposal->serviceRows(), fn (array $s): bool => (bool) ($s['selected'] ?? false)));
        $mainBefore = BrandOffering::query()->where('brand_id', $proposal->brand_id)->where('status', 'active')
            ->where(fn ($q) => $q->where('priority', 'main')->orWhere('is_priority', true))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        // Places wait for approval on the Onarım masası (BrandDataAudit), never applied silently.
        $results = $this->applier->apply($proposal, self::systemUser(), $items, $services, true, []);
        if (($brand = $proposal->brand()->first()) instanceof Brand) {
            $this->ensureMain($brand, $proposal, $mainBefore);
        }

        return $results;
    }

    /**
     * One ★ after an automatic run: the operator's ★ stays alone; else the proposed service with the most searches
     * (else the first one) is the main service and any other ★ the run added is taken back.
     *
     * @param  list<int>  $mainBefore  services that were ★ before the run
     */
    private function ensureMain(Brand $brand, BrandSetupProposal $proposal, array $mainBefore = []): void
    {
        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $brand->id)->where('status', 'active')->get();
        if ($offerings->isEmpty()) {
            return;
        }
        $demand = [];
        foreach ($proposal->serviceRows() as $row) {
            $demand[mb_strtolower($row['name'])] = array_sum(array_column($row['keywords'], 'impressions'));
        }
        $added = $offerings->filter(fn (BrandOffering $o): bool => $o->isMain() && ! in_array((int) $o->id, $mainBefore, true));
        $operatorMain = $offerings->contains(fn (BrandOffering $o): bool => $o->isMain() && in_array((int) $o->id, $mainBefore, true));
        $keep = $operatorMain ? null
            : ($added->isNotEmpty() ? $added : $offerings)->sortByDesc(fn (BrandOffering $o): int => $demand[mb_strtolower($o->displayName())] ?? 0)->first();
        foreach ($added as $offering) {
            if ($keep === null || (int) $offering->id !== (int) $keep->id) {
                $offering->forceFill(['priority' => 'secondary', 'is_priority' => false])->save();
            }
        }
        if ($keep !== null && ! $keep->isMain()) {
            $keep->forceFill(['priority' => 'main'])->save();
        }
    }

    /**
     * A site whose pages were never read: its sitemap is read at once (the pages are stored, bounded per pass), else a
     * full collection is started; tried again every night until pages exist, with the reason on the brand card.
     *
     * @return string read (pages exist now) | crawl (started or waiting)
     */
    private function crawl(Brand $brand, DigitalAsset $site): string
    {
        $key = 'brand-autofill:crawl:'.$site->id;
        if (Cache::has($key)) {
            return 'crawl';
        }
        Cache::put($key, now()->toIso8601String(), now()->addHours(20));
        try {
            $sitemap = app(SitemapChangeWatcher::class)->check($site);
            if (Page::query()->where('website_asset_id', $site->id)->exists()) {
                return 'read';
            }
            app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestedBy: self::systemUser(), context: ['trigger' => 'brand.autofill', 'force_refresh' => true]);
            self::note((int) $brand->id, 'Sitenin sayfaları okunamadı ('.($sitemap['status'] === 'no_sitemap' ? 'site haritası açılmadı' : 'site haritasında okunabilir sayfa yok')
                .'); tam tarama başlatıldı, yarın gece yeniden denenir. Site güvenlik duvarı MoxDOP\'u engelliyorsa izin verilmeli.');
        } catch (Throwable $exception) {
            report($exception);
            self::note((int) $brand->id, 'Sitenin sayfaları okunamadı: '.mb_substr(trim($exception->getMessage()) ?: class_basename($exception), 0, 160).'; yarın gece yeniden denenir.');
        }

        return 'crawl';
    }

    /** What the last automatic round did or why it stopped for the brand (shown on the brand card and the content line). */
    public static function note(int $brandId, ?string $text = null): ?array
    {
        $key = 'brand-autofill:note:'.$brandId;
        if ($text !== null) {
            Cache::put($key, ['at' => now()->toIso8601String(), 'text' => $text], now()->addDays(30));
        }
        $note = Cache::get($key);

        return is_array($note) ? $note : null;
    }
}
