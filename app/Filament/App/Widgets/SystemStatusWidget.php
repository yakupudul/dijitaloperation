<?php

namespace App\Filament\App\Widgets;

use App\Services\Observability\OperationalHealthSnapshot;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Real bounded operational dimensions (Prompt 66).
 * Kept undiscovered on the main Dashboard — available for Settings/System surfaces.
 * Never shows a numeric health score or hard-coded "All Systems Operational".
 */
class SystemStatusWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.app.widgets.system-status-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $snapshot = app(OperationalHealthSnapshot::class)->snapshot();

        return [
            'title' => 'MoxDOP Operations',
            'user' => $user?->name ?? 'Unknown',
            'environment' => (string) config('app.env'),
            'laravel' => app()->version(),
            'dimensions' => $snapshot['dimensions'],
            'open_alert_count' => $snapshot['open_alert_count'],
            'overall_score' => null,
        ];
    }
}
