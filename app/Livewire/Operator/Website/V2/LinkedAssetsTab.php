<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Throwable;

/**
 * Bağlı Varlıklar: the website's Search Console / GA4 bindings and the brand's other assets (Business Profile, Google
 * Ads, Meta) — binding status, last data date, link to each asset screen.
 */
final class LinkedAssetsTab extends Component
{
    use WebsiteTab;

    private const array ORDER = ['search_console' => 0, 'ga4' => 1, 'google_business_profile' => 2, 'google_ads' => 3, 'meta_ads' => 4];

    private const array ROUTES = [
        'search_console' => 'operator.search-console', 'ga4' => 'operator.analytics', 'google_business_profile' => 'operator.gbp',
        'google_ads' => 'operator.google-ads.overview', 'meta_ads' => 'operator.meta.overview',
    ];

    public function render(DataStatusReader $reader): View
    {
        $site = $this->site();
        $brand = $site->brand;
        $assets = collect([$site]);
        if ($brand !== null) {
            $assets = $assets->merge(DigitalAsset::query()->where('brand_id', $brand->id)->whereKeyNot($site->id)
                ->whereIn('type', ['google_business_profile', 'gbp', 'google_ads', 'meta_ads', 'ga4', 'search_console'])->orderBy('type')->orderBy('name')->get());
        }
        try {
            $statuses = $reader->forAssets($assets);
        } catch (Throwable $error) {
            report($error);
            $statuses = [];
        }
        $rows = [];
        foreach ($assets as $asset) {
            foreach ($statuses[(int) $asset->id] ?? [] as $status) {
                /** @var DataStatus $status */
                $route = self::ROUTES[$status->capability] ?? null;
                $rows[] = [
                    'capability' => $status->capability,
                    'source' => $status->sourceLabel(),
                    'name' => $status->resourceName ?? (string) $asset->name,
                    'state' => $status->label(),
                    'tone' => $status->tone(),
                    'last' => $status->lastDataDate?->format('d.m.Y'),
                    'url' => $route !== null && Route::has($route) ? route($route, ['assetId' => $asset->id]) : null,
                ];
            }
        }
        usort($rows, fn (array $a, array $b): int => (self::ORDER[$a['capability']] ?? 9) <=> (self::ORDER[$b['capability']] ?? 9));

        return view('livewire.operator.website.v2.linked-assets-tab', ['rows' => $rows, 'hasBrand' => $brand !== null]);
    }
}
