<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class SystemStatusWidget extends Widget
{
    protected string $view = 'filament.app.widgets.system-status-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{title: string, status: string, user: string, environment: string, laravel: string}
     */
    protected function getViewData(): array
    {
        $user = Auth::user();

        return [
            'title' => 'MoxDOP Agency Operations OS',
            'status' => 'Internal workspace is online',
            'user' => $user?->name ?? 'Unknown',
            'environment' => (string) config('app.env'),
            'laravel' => app()->version(),
        ];
    }
}
