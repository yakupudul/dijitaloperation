<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Kept for future Settings/System surfaces. Not shown on the operational Dashboard.
 */
class SystemStatusWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.app.widgets.system-status-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{title: string, status: string, user: string, environment: string, laravel: string}
     */
    protected function getViewData(): array
    {
        $user = Auth::user();

        return [
            'title' => 'MoxDOP',
            'status' => 'System status',
            'user' => $user?->name ?? 'Unknown',
            'environment' => (string) config('app.env'),
            'laravel' => app()->version(),
        ];
    }
}
