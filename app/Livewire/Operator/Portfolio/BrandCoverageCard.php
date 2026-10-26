<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Work\ClusterCoverage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Özet › Küme kapsaması (yakup, 2026-10-26): per website and service how many clusters have a sufficient page,
 * a thin one or none, how many already have an article sent or planned, and which service the weekly cycle writes now
 * and next. A service opens its clusters with their page and content line. Read only.
 */
final class BrandCoverageCard extends Component
{
    #[Locked]
    public int $brandId;

    /** "siteId:serviceId" of the open service, empty = none. */
    public string $open = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    /** Loaded after the page (lazy): the counts read every cluster row of the brand's sites. */
    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function toggle(int $siteId, int $serviceId): void
    {
        $key = $siteId.':'.$serviceId;
        $this->open = $this->open === $key ? '' : $key;
    }

    public function render(ClusterCoverage $coverage): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $clusters = [];
        if ($this->open !== '') {
            [$siteId, $serviceId] = array_map('intval', explode(':', $this->open) + [1 => 0]);
            $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->find($siteId);
            $clusters = $site !== null ? $coverage->clusters($brand, $site, $serviceId) : [];
        }

        return view('livewire.operator.portfolio.brand-coverage-card', [
            'brand' => $brand,
            'sites' => $coverage->forBrand($brand),
            'clusters' => $clusters,
        ]);
    }
}
