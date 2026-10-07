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
    /** Checklist items an "Otomatik kur" run can fill. */
    public const array FILLABLE = ['services', 'main_service', 'areas', 'sector', 'context', 'search_console', 'ga4'];

    /** A brand is not asked again sooner than this after its last automatic run or crawl. */
    public const int COOLDOWN_DAYS = 7;

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
     * @return array<string, int> brands per outcome (queued, applied, crawl, waiting, complete, no_site)
     */
    public function run(?int $brandId = null): array
    {
        $out = ['queued' => 0, 'applied' => 0, 'crawl' => 0, 'waiting' => 0, 'complete' => 0, 'no_site' => 0];
        $brands = Brand::query()->operational()->when($brandId !== null, fn ($q) => $q->whereKey($brandId))->orderBy('id')->get();
        foreach ($brands as $brand) {
            try {
                $out[$this->forBrand($brand)]++;
            } catch (Throwable $exception) {
                report($exception);
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
        if ($latest !== null && $latest->status === BrandSetupProposal::STATUS_READY) {
            if (! $latest->auto_apply) {
                return 'waiting'; // the operator's own proposal waits for the operator
            }
            $this->apply($latest);

            return 'applied';
        }
        if ($this->missing($brand) === []) {
            return 'complete';
        }
        if ($latest !== null && $latest->auto_apply && $latest->created_at?->gt(now()->subDays(self::COOLDOWN_DAYS))) {
            return 'waiting';
        }
        if (! Page::query()->where('website_asset_id', $site->id)->exists()) {
            return $this->crawl($site);
        }
        $proposal = $this->assistant->queue($brand, $url, null);
        $proposal->forceFill(['auto_apply' => true])->save();

        return 'queued';
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
     * Applies a ready automatic proposal with its default picks, then makes sure one service is ★.
     *
     * @return list<array{key: string, label: string, ok: bool, message: string}>
     */
    public function apply(BrandSetupProposal $proposal): array
    {
        $items = array_values(array_map(fn (array $i): string => (string) $i['key'], array_filter($proposal->itemRows(), fn (array $i): bool => (bool) ($i['selected'] ?? false))));
        $services = array_keys(array_filter($proposal->serviceRows(), fn (array $s): bool => (bool) ($s['selected'] ?? false)));
        $areas = array_keys(array_filter(array_values((array) data_get($proposal->summary, 'areas', [])), fn ($a): bool => is_array($a) && (bool) ($a['selected'] ?? false)));
        $results = $this->applier->apply($proposal, self::systemUser(), $items, $services, true, $areas);
        if (($brand = $proposal->brand()->first()) instanceof Brand) {
            $this->ensureMain($brand, $proposal);
        }

        return $results;
    }

    /** No ★ yet: the proposed service with the most searches (else the first one) becomes the main service. */
    private function ensureMain(Brand $brand, BrandSetupProposal $proposal): void
    {
        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $brand->id)->where('status', 'active')->get();
        if ($offerings->isEmpty() || $offerings->contains(fn (BrandOffering $o): bool => $o->isMain())) {
            return;
        }
        $demand = [];
        foreach ($proposal->serviceRows() as $row) {
            $demand[mb_strtolower($row['name'])] = array_sum(array_column($row['keywords'], 'impressions'));
        }
        $main = $offerings->sortByDesc(fn (BrandOffering $o): int => $demand[mb_strtolower($o->displayName())] ?? 0)->first();
        $main?->forceFill(['priority' => 'main'])->save();
    }

    private function crawl(DigitalAsset $site): string
    {
        $key = 'brand-autofill:crawl:'.$site->id;
        if (Cache::has($key)) {
            return 'waiting';
        }
        Cache::put($key, now()->toIso8601String(), now()->addDays(self::COOLDOWN_DAYS));
        app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestedBy: self::systemUser(), context: ['trigger' => 'brand.autofill', 'force_refresh' => true]);

        return 'crawl';
    }
}
