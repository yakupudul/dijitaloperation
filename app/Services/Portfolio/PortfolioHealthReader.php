<?php

namespace App\Services\Portfolio;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\ResourceAutomation;
use App\Services\CommandCenter\CommandCenter;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Portföy sağlığı: every brand × channel in one grid. A cell says whether the channel is connected, whether its data
 * is current and whether an alert is open on it; a missing connection is a coverage gap. The row adds the brand's
 * open work (from the command center) and the month's ad budget pace.
 */
final class PortfolioHealthReader
{
    public const array CHANNELS = [
        'website' => 'Web sitesi', 'search_console' => 'Search Console', 'ga4' => 'GA4', 'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili',
    ];

    /** Days without new data after which a connected account counts as stale. */
    private const int STALE_DAYS = 4;

    public function __construct(private readonly CommandCenter $center) {}

    /**
     * @return array{rows: list<array<string, mixed>>, gaps: array<string, list<array{brand_id: int, brand: string}>>, unbound: int, totals: array<string, int>}
     */
    public function read(): array
    {
        $brands = Brand::query()->with(['customer', 'digitalAssets' => fn ($q) => $q->where('status', 'active')])
            ->whereHas('customer', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get();
        $assetIds = $brands->flatMap(fn (Brand $b) => $b->digitalAssets->pluck('id'))->all();
        $bindings = CoreAssetBinding::query()->with(['externalResource'])->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)->get()->groupBy('digital_asset_id');
        $automations = ResourceAutomation::query()->whereIn('external_resource_id', $bindings->flatten()->pluck('external_resource_id')->filter()->unique())->get()->keyBy('external_resource_id');
        $alerts = AssetAlert::query()->active()->whereIn('digital_asset_id', $assetIds)->get()->groupBy('digital_asset_id');
        $paired = CoreConnection::query()->whereIn('digital_asset_id', $assetIds)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)->where('enabled', true)->pluck('digital_asset_id')->flip();
        $uptime = Schema::hasTable('uptime_states') ? DB::table('uptime_states')->whereIn('digital_asset_id', $assetIds)->pluck('state', 'digital_asset_id') : collect();
        $work = $this->workByBrand();

        $rows = [];
        $gaps = ['no_website' => [], 'no_search_console' => [], 'no_ga4' => [], 'no_wordpress' => [], 'nothing_connected' => []];
        foreach ($brands as $brand) {
            $cells = [];
            foreach (array_keys(self::CHANNELS) as $channel) {
                $cells[$channel] = $channel === 'website'
                    ? $this->websiteCell($brand, $alerts, $paired, $uptime)
                    : $this->accountCell($brand, $channel, $bindings, $automations, $alerts);
            }
            $states = array_column($cells, 'state');
            $status = in_array('bad', $states, true) ? 'bad' : (in_array('warn', $states, true) ? 'warn' : 'ok');
            $rows[] = [
                'brand_id' => $brand->id, 'brand' => $brand->name, 'customer' => $brand->customer?->name, 'customer_id' => $brand->customer_id,
                'cells' => $cells, 'status' => $status,
                'urgent' => $work[$brand->id]['urgent'] ?? 0, 'open' => $work[$brand->id]['open'] ?? 0,
            ];
            $entry = ['brand_id' => $brand->id, 'brand' => $brand->name];
            if ($cells['website']['state'] === 'missing') {
                $gaps['no_website'][] = $entry;
            } else {
                if ($cells['search_console']['state'] === 'missing') {
                    $gaps['no_search_console'][] = $entry;
                }
                if ($cells['ga4']['state'] === 'missing') {
                    $gaps['no_ga4'][] = $entry;
                }
                if (($cells['website']['wordpress'] ?? true) === false) {
                    $gaps['no_wordpress'][] = $entry;
                }
            }
            if (collect($cells)->except('website')->every(fn (array $c): bool => $c['state'] === 'missing')) {
                $gaps['nothing_connected'][] = $entry;
            }
        }
        usort($rows, fn (array $a, array $b): int => [self::rank($a['status']), -$a['urgent'], $a['brand']] <=> [self::rank($b['status']), -$b['urgent'], $b['brand']]);

        return [
            'rows' => $rows,
            'gaps' => $gaps,
            'unbound' => ResourceAutomation::query()->where('collection_status', 'attention')->where('collection_error', 'unbound')->count(),
            'totals' => ['brands' => count($rows), 'bad' => count(array_filter($rows, fn ($r) => $r['status'] === 'bad')), 'warn' => count(array_filter($rows, fn ($r) => $r['status'] === 'warn'))],
        ];
    }

    private static function rank(string $status): int
    {
        return ['bad' => 0, 'warn' => 1, 'ok' => 2][$status] ?? 3;
    }

    /** @return array<string, mixed> */
    private function websiteCell(Brand $brand, Collection $alerts, Collection $paired, Collection $uptime): array
    {
        $sites = $brand->digitalAssets->where('type', 'website');
        if ($sites->isEmpty()) {
            return ['state' => 'missing', 'label' => 'Yok', 'url' => null];
        }
        $site = $sites->first();
        $notes = [];
        $state = 'ok';
        if (($uptime[$site->id] ?? null) === 'down') {
            [$state, $notes[]] = ['bad', 'Site erişilemiyor'];
        }
        [$alertState, $alertNote] = $this->alertState($alerts->get($site->id, collect()));
        if ($alertState !== null) {
            $state = $this->worse($state, $alertState);
            $notes[] = $alertNote;
        }
        $wordpress = $paired->has($site->id);
        $isWordPress = $wordpress || str_contains(strtolower((string) $site->cms), 'wordpress');
        if (! $wordpress && $isWordPress) {
            $state = $this->worse($state, 'warn');
            $notes[] = 'WordPress eklentisi bağlı değil';
        }

        return ['state' => $state, 'label' => $notes[0] ?? ($wordpress ? 'Sağlıklı · WP bağlı' : 'Sağlıklı'), 'notes' => $notes, 'wordpress' => $isWordPress ? $wordpress : null,
            'url' => route('operator.website', ['assetId' => $site->id])];
    }

    /** @return array<string, mixed> */
    private function accountCell(Brand $brand, string $capability, Collection $bindings, Collection $automations, Collection $alerts): array
    {
        $found = null;
        foreach ($brand->digitalAssets as $asset) {
            foreach ($bindings->get($asset->id, collect()) as $binding) {
                if ($binding->capability === $capability) {
                    $found = [$asset, $binding];
                    break 2;
                }
            }
        }
        if ($found === null) {
            return ['state' => 'missing', 'label' => 'Bağlı değil', 'url' => route('operator.integrations')];
        }
        [$asset, $binding] = $found;
        $automation = $automations->get($binding->external_resource_id);
        $notes = [];
        $state = 'ok';
        if ($automation !== null && $automation->collection_status === 'attention') {
            [$state, $notes[]] = ['bad', match ((string) $automation->collection_error) {
                'reconnect' => 'Yeniden bağlanmalı', 'request_requires_fix' => 'İstek düzeltilmeli', default => 'Veri çekilemiyor',
            }];
        }
        $through = $automation?->data_through !== null ? CarbonImmutable::parse((string) $automation->data_through) : null;
        if ($through !== null && $through->lt(now()->subDays(self::STALE_DAYS + 1)->startOfDay())) {
            $state = $this->worse($state, 'warn');
            $notes[] = 'Veri '.$through->format('d.m').' tarihine kadar';
        }
        [$alertState, $alertNote] = $this->alertState($alerts->get($asset->id, collect()));
        if ($alertState !== null) {
            $state = $this->worse($state, $alertState);
            $notes[] = $alertNote;
        }

        return ['state' => $state, 'label' => $notes[0] ?? ('Güncel'.($through !== null ? ' · '.$through->format('d.m') : '')), 'notes' => $notes,
            'url' => route('operator.asset.sources', ['assetId' => $asset->id])];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function alertState(Collection $alerts): array
    {
        $worst = $alerts->sortBy(fn (AssetAlert $a): int => ['critical' => 0, 'high' => 1, 'medium' => 2][$a->severity] ?? 3)->first();
        if ($worst === null) {
            return [null, null];
        }

        return [in_array($worst->severity, ['critical', 'high'], true) ? 'bad' : 'warn', (string) $worst->title];
    }

    private function worse(string $a, string $b): string
    {
        return self::rank($a) <= self::rank($b) ? $a : $b;
    }

    /** @return array<int, array{open: int, urgent: int}> */
    private function workByBrand(): array
    {
        try {
            return $this->center->items()->whereNotNull('brand_id')->groupBy('brand_id')
                ->map(fn (Collection $items): array => ['open' => $items->count(), 'urgent' => $items->whereIn('severity', ['critical', 'high'])->count()])->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }
}
