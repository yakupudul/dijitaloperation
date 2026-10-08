<?php

namespace App\Services\DataStatus;

use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Operator\DormantAccountHint;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Bağlantı sağlığı (Onarım Faz 1, yakup 2026-10-08): "can the system see every digital asset of every brand?" One row
 * per gap, split into what the system repairs by itself (late data → collect again, site never crawled → crawl) and
 * what needs the operator (access, wrong / silent account, unbound account of the brand). Only MoxDOP's own collection
 * is restarted; nothing is written to Google, Meta or a site.
 */
final class ConnectionHealth
{
    public const string SYSTEM = 'system';

    public const string OPERATOR = 'operator';

    /** A website crawl older than this is due again. */
    public const int CRAWL_MAX_AGE_DAYS = 30;

    /** A failed crawl is not started again by the nightly repair within this many hours. */
    public const int CRAWL_RETRY_HOURS = 24;

    public function __construct(
        private readonly DataStatusReader $statuses,
        private readonly BrandAccountCandidates $candidates,
    ) {}

    /**
     * @return list<array{brand_id: int, brand: string, asset_id: ?int, asset: ?string, kind: string, who: string, title: string, detail: string, automation_id?: ?int, url?: ?string}>
     */
    public function issues(): array
    {
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name']);
        if ($brands->isEmpty()) {
            return [];
        }
        $assets = DigitalAsset::query()->operational()->whereIn('brand_id', $brands->pluck('id'))
            ->with(['assetBindings' => fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE)])->orderBy('id')->get();
        $byBrand = $assets->groupBy('brand_id');
        $statuses = $this->statuses->forAssets($assets);
        $resourceIds = collect($statuses)->flatten()->map(fn (DataStatus $s): ?int => $s->externalResourceId)->filter()->unique()->values()->all();
        $automations = ResourceAutomation::query()->whereIn('external_resource_id', $resourceIds)->pluck('id', 'external_resource_id');
        $adsResources = collect($statuses)->flatten()
            ->filter(fn (DataStatus $s): bool => $s->capability === 'google_ads' && $s->externalResourceId !== null && $s->state !== DataStatus::ACCESS_PROBLEM)
            ->map(fn (DataStatus $s): int => (int) $s->externalResourceId)->unique()->values()->all();
        $lastSpend = DormantAccountHint::lastGoogleAdsSpendByResource($adsResources);
        try {
            $candidates = $this->candidates->strongForBrands($brands);
        } catch (Throwable $error) {
            report($error);
            $candidates = [];
        }

        $rows = [];
        foreach ($brands as $brand) {
            $brandAssets = $byBrand->get($brand->id, collect());
            $row = fn (array $values): array => $values + ['brand_id' => (int) $brand->id, 'brand' => (string) $brand->name];
            $site = $brandAssets->firstWhere('type', 'website');
            if ($site === null) {
                $rows[] = $row(['asset_id' => null, 'asset' => null, 'kind' => 'no_site', 'who' => self::OPERATOR, 'title' => 'Web sitesi eklenmemiş',
                    'detail' => 'Markanın sitesi yok; site, Search Console ve GA4 görünmez.', 'url' => route('operator.brand.setup', ['brand' => $brand->id])]);
            } else {
                $crawl = $this->crawlGap($site);
                if ($crawl !== null) {
                    $rows[] = $row($crawl);
                }
            }
            foreach ($brandAssets as $asset) {
                foreach ($statuses[(int) $asset->id] ?? [] as $status) {
                    $issue = $this->statusGap($asset, $status, $automations, $lastSpend);
                    if ($issue !== null) {
                        $rows[] = $row($issue);
                    }
                }
            }
            foreach ($candidates[(int) $brand->id] ?? [] as $candidate) {
                $rows[] = $row(['asset_id' => null, 'asset' => (string) $candidate['name'], 'kind' => 'unbound', 'who' => self::OPERATOR,
                    'title' => (string) $candidate['type_label'].' hesabı markaya bağlı değil',
                    'detail' => sprintf('"%s" bu markanın işletmesine ait görünüyor ama bağlanmamış; verisi markada görünmez.', $candidate['name']),
                    'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'varliklar']).'#hesap-ekle']);
            }
        }

        return $rows;
    }

    /** @return array{system: int, operator: int, brands: int} */
    public function summary(?array $issues = null): array
    {
        $issues ??= $this->issues();

        return [
            'system' => count(array_filter($issues, fn (array $i): bool => $i['who'] === self::SYSTEM)),
            'operator' => count(array_filter($issues, fn (array $i): bool => $i['who'] === self::OPERATOR)),
            'brands' => count(array_unique(array_column($issues, 'brand_id'))),
        ];
    }

    /**
     * Starts what the system can repair by itself: collection of late accounts, crawl of sites not crawled recently.
     * `$manual` (a click) also restarts a crawl that failed within the last day.
     *
     * @return array{collections: int, crawls: int, failed: int}
     */
    public function repair(User $actor, bool $manual = false): array
    {
        $done = ['collections' => 0, 'crawls' => 0, 'failed' => 0];
        $automations = app(ResourceAutomationService::class);
        foreach ($this->issues() as $issue) {
            if ($issue['who'] !== self::SYSTEM) {
                continue;
            }
            try {
                if ($issue['kind'] === 'stale' && ($issue['automation_id'] ?? null) !== null) {
                    $automations->runNow((int) $issue['automation_id'], $actor);
                    $done['collections']++;
                } elseif ($issue['kind'] === 'crawl' && $issue['asset_id'] !== null && ($manual || ! ($issue['recent_failure'] ?? false))) {
                    $site = DigitalAsset::query()->find($issue['asset_id']);
                    if ($site !== null) {
                        app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestedBy: $actor,
                            context: ['trigger' => 'connection_health.repair', 'force_refresh' => true]);
                        $done['crawls']++;
                    }
                }
            } catch (Throwable $error) {
                report($error);
                $done['failed']++;
            }
        }

        return $done;
    }

    /** @return array<string, mixed>|null */
    private function crawlGap(DigitalAsset $site): ?array
    {
        $base = ['asset_id' => (int) $site->id, 'asset' => (string) ($site->primary_url ?: $site->domain ?: $site->name), 'kind' => 'crawl', 'who' => self::SYSTEM];
        if (blank($site->primary_url) && blank($site->domain)) {
            return ['kind' => 'site_address', 'who' => self::OPERATOR, 'title' => 'Site adresi yok', 'detail' => 'Site varlığında adres girilmemiş; taranamıyor.',
                'url' => route('operator.asset.edit', ['assetId' => $site->id])] + $base;
        }
        $runs = CollectionRun::query()->where('digital_asset_id', $site->id)->latest('id')->limit(25)->get(['id', 'status', 'request_context', 'created_at'])
            ->filter(fn (CollectionRun $run): bool => in_array('WEBSITE_DIRECT', (array) data_get($run->request_context, 'provider_sources', []), true));
        $status = fn (CollectionRun $run): string => $run->status instanceof \BackedEnum ? (string) $run->status->value : (string) $run->status;
        $last = $runs->first();
        if ($last !== null && in_array($status($last), ['queued', 'running', 'retrying'], true)) {
            return null;
        }
        $success = $runs->first(fn (CollectionRun $run): bool => in_array($status($run), ['completed', 'partial'], true));
        if ($success !== null && $success->created_at !== null && $success->created_at->gt(now()->subDays(self::CRAWL_MAX_AGE_DAYS))) {
            return null;
        }
        $failedRecently = $last !== null && $status($last) === 'failed' && $last->created_at !== null && $last->created_at->gt(now()->subHours(self::CRAWL_RETRY_HOURS));

        return $base + [
            'title' => $success === null ? 'Site hiç taranmamış' : 'Site taraması eski',
            'detail' => match (true) {
                $failedRecently => 'Son tarama başarısız oldu; sistem yarın yeniden dener, hemen denemek için "Şimdi onar".',
                $success === null => 'Sayfalar ve teknik sorunlar görünmüyor; sistem taramayı başlatır.',
                default => 'Son başarılı tarama '.$success->created_at->format('d.m.Y').'; sistem yeniden tarar.',
            },
            'recent_failure' => $failedRecently,
            'url' => route('operator.website', ['assetId' => $site->id]),
        ];
    }

    /**
     * @param  Collection<int, int>  $automations  external resource id => automation id
     * @param  array<int, string>  $lastSpend  external resource id => last Google Ads spend day
     * @return array<string, mixed>|null
     */
    private function statusGap(DigitalAsset $asset, DataStatus $status, Collection $automations, array $lastSpend): ?array
    {
        if (! $status->isBound()) {
            return null;
        }
        $base = ['asset_id' => (int) $asset->id, 'asset' => (string) ($status->resourceName ?: $asset->name),
            'automation_id' => $status->externalResourceId !== null ? $automations->get($status->externalResourceId) : null];
        $source = $status->sourceLabel();
        if ($status->state === DataStatus::ACCESS_PROBLEM) {
            return $base + ['kind' => 'access', 'who' => self::OPERATOR, 'title' => $source.': erişim sorunu', 'detail' => $status->detail(),
                'url' => $status->actionUrl ?? route('operator.asset.sources', ['assetId' => $asset->id])];
        }
        if ($status->state === DataStatus::STALE && ! $status->collecting) {
            return $base + ['kind' => 'stale', 'who' => self::SYSTEM, 'title' => $source.': '.$status->label(), 'detail' => $status->detail(),
                'url' => route('operator.asset.sources', ['assetId' => $asset->id])];
        }
        $hint = $status->capability === 'google_ads' && $status->externalResourceId !== null
            ? DormantAccountHint::text($lastSpend[(int) $status->externalResourceId] ?? null) : null;
        if ($hint !== null) {
            return $base + ['kind' => 'dormant', 'who' => self::OPERATOR, 'title' => 'Google Ads: harcama yok', 'detail' => ucfirst($hint),
                'url' => route('operator.brand', ['brand' => $asset->brand_id, 'tab' => 'varliklar'])];
        }

        return null;
    }
}
