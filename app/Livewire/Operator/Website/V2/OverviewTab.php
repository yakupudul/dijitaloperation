<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Genel Bakış: 6 numbers (pages by category, cluster coverage, open suggestions, 28-day organic clicks ±%, last content
 * date, last data date) + the top 5 open suggestions. Reads stored rows only.
 */
final class OverviewTab extends Component
{
    #[Locked]
    public int $assetId = 0;

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function render(SiteMetrics $metrics): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        $categories = Page::query()->where('website_asset_id', $site->id)->groupBy('category')->selectRaw('category, count(*) as n')->pluck('n', 'category')->all();
        $coverage = BrandClusterPage::query()->where('website_asset_id', $site->id)->selectRaw("count(*) as total, sum(CASE WHEN state = 'sufficient' THEN 1 ELSE 0 END) as ok")->first();
        $open = $brand !== null ? Suggestion::query()->where('brand_id', $brand->id)->where('channel', 'search')->where('status', Suggestion::OPEN) : null;
        $clicks = $brand !== null ? $metrics->cachedSiteClicks($brand, $site) : null;
        $lastContent = Page::query()->where('website_asset_id', $site->id)->where(fn ($q) => $q->where('category', 'blog')->orWhere('wp_post_type', 'post'))->max('changed_at');
        $lastData = max(array_filter([
            $clicks['end'] ?? null,
            ($pagesAt = Page::query()->where('website_asset_id', $site->id)->max('updated_at')) !== null ? substr((string) $pagesAt, 0, 10) : null,
        ]) ?: [null]);

        return view('livewire.operator.website.v2.overview-tab', [
            'categories' => $categories,
            'pageTotal' => array_sum($categories),
            'coverageTotal' => (int) ($coverage->total ?? 0),
            'coverageOk' => (int) ($coverage->ok ?? 0),
            'openCount' => $open?->count() ?? 0,
            'top' => $open !== null ? (clone $open)->orderBy('priority')->orderByDesc('id')->limit(5)->get() : collect(),
            'clicks' => $clicks,
            'lastContent' => $lastContent !== null ? substr((string) $lastContent, 0, 10) : null,
            'lastData' => $lastData,
            'banners' => collect(app(DataStatusReader::class)->forAsset($site))->filter(fn ($status): bool => in_array($status->state, ['not_bound', 'first_load'], true))->groupBy('state'),
            'unassignedCount' => DB::table('pages')->where('website_asset_id', $site->id)->whereNull('category')->count(),
        ]);
    }
}
