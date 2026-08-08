<?php

namespace App\Filament\App\Widgets;

use App\Models\Brand;
use App\Support\BrandOperationalSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class BrandWorkspaceSummaryWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.app.widgets.brand-workspace-summary';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    /**
     * @return array{items: list<array{label: string, value: string, hint: string}>}
     */
    protected function getViewData(): array
    {
        /** @var Brand|null $brand */
        $brand = $this->record instanceof Brand ? $this->record : null;

        if ($brand === null) {
            return ['items' => []];
        }

        $summary = BrandOperationalSummary::for($brand);

        return [
            'items' => [
                [
                    'label' => 'Digital assets',
                    'value' => (string) $summary['digital_assets'],
                    'hint' => 'Assets under this brand',
                ],
                [
                    'label' => 'Healthy connections',
                    'value' => (string) $summary['healthy_connected_assets'],
                    'hint' => 'Assets with an enabled connection and no last_error',
                ],
                [
                    'label' => 'Open findings',
                    'value' => (string) $summary['open_findings'],
                    'hint' => 'Across brand digital assets',
                ],
                [
                    'label' => 'Open recommendations',
                    'value' => (string) $summary['open_recommendations'],
                    'hint' => 'Across brand digital assets',
                ],
                [
                    'label' => 'Open tasks',
                    'value' => (string) $summary['open_tasks'],
                    'hint' => 'Open, in progress, or blocked',
                ],
            ],
        ];
    }
}
