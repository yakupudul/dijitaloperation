<?php

namespace App\View\Components\Operator;

use App\Models\AdvisorItem;
use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Services\DataStatus\DataStatusReader;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Açık işler" card of an asset overview: how many advisor recommendations, SEO tasks and alerts are open for this
 * asset, linking to the Komuta merkezi filtered to the asset (`?asset={id}`). Replaces the legacy Finding /
 * Recommendation lists on asset overviews.
 */
class OpenWork extends Component
{
    /** @var array{advisor: int, seo: int, alerts: int} */
    public array $counts;

    public string $url;

    public function __construct(public DigitalAsset $asset)
    {
        $this->counts = [
            'advisor' => AdvisorItem::query()->open()->where('digital_asset_id', $asset->id)->count(),
            'seo' => SeoTask::query()->open()->where('digital_asset_id', $asset->id)->count(),
            'alerts' => AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->whereNotIn('kind', DataStatusReader::FRESHNESS_ALERT_KINDS)->count(),
        ];
        $this->url = route('operator.command-center', ['asset' => $asset->id]);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function render(): View
    {
        return view('components.operator.open-work');
    }
}
