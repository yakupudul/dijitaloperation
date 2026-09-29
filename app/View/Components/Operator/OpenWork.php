<?php

namespace App\View\Components\Operator;

use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatusReader;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Açık işler" card of an asset overview: how many alerts are open for this asset (suggestions join in Faz 4–7).
 */
class OpenWork extends Component
{
    /** @var array{alerts: int} */
    public array $counts;

    public string $url;

    public function __construct(public DigitalAsset $asset)
    {
        $this->counts = [
            'alerts' => AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->whereNotIn('kind', DataStatusReader::FRESHNESS_ALERT_KINDS)->count(),
        ];
        $this->url = route('operator.alerts', ['asset' => $asset->id]);
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
