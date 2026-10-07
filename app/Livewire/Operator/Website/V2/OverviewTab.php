<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Integrations\Bing\BingWebmasterSync;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Özet (Search Console / GA4 overview style): clicks, impressions, CTR, average position, sessions, key events against
 * the previous period with the daily lines; top queries and pages; pages that won or lost the most clicks; the state of
 * the main service pages (iyi · düşüşte · sorunlu) and the top 5 open suggestions. Reads stored rows only.
 */
final class OverviewTab extends Component
{
    /** Rows in the top lists (queries, pages, winners / losers). */
    public const int TOP = 8;

    public const int MOVERS = 4;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'donem', history: true)]
    public int $period = 28;

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function render(SitePagesReader $pages, SiteAnalysisReader $analysis): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        $period = SitePagesReader::period($this->period);
        $rows = collect($pages->rows($site, $period));
        $moved = $rows->map(fn (array $row): array => $row + ['change' => $row['clicks'] - $row['prev_clicks']])
            ->filter(fn (array $row): bool => abs($row['change']) >= 3);
        $open = $brand !== null ? Suggestion::query()->where('brand_id', $brand->id)->where('channel', 'search')->where('status', Suggestion::OPEN) : null;

        return view('livewire.operator.website.v2.overview-tab', [
            'periodDays' => $period,
            'trend' => $pages->trend($site, $period),
            'totals' => $analysis->totals($site, $period),
            'topQueries' => array_slice($analysis->queries($site, $period), 0, self::TOP),
            'bing' => BingWebmasterSync::summary((int) $site->id),
            'topPages' => $rows->filter(fn (array $row): bool => $row['clicks'] > 0)->sortByDesc('clicks')->take(self::TOP)->values()->all(),
            'winners' => $moved->where('change', '>', 0)->sortByDesc('change')->take(self::MOVERS)->values()->all(),
            'losers' => $moved->where('change', '<', 0)->sortBy('change')->take(self::MOVERS)->values()->all(),
            'services' => $pages->serviceState($site, $period),
            'openCount' => $open?->count() ?? 0,
            'top' => $open !== null ? (clone $open)->with('page:id,url,path')->orderBy('priority')->orderByDesc('id')->limit(5)->get() : collect(),
            'banners' => collect(app(DataStatusReader::class)->forAsset($site))->filter(fn ($status): bool => in_array($status->state, ['not_bound', 'first_load'], true))->groupBy('state'),
        ]);
    }
}
