<?php

namespace App\Filament\App\Widgets;

use App\Services\Async\AsyncWorkerHealth;
use Filament\Widgets\Widget;

class AsyncWorkerHealthWidget extends Widget
{
    protected string $view = 'filament.app.widgets.async-worker-health';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'health' => app(AsyncWorkerHealth::class)->snapshot(),
        ];
    }
}
