<?php

namespace App\Filament\App\Resources\Runs\Pages;

use App\Filament\App\Resources\Runs\RunResource;
use App\Filament\App\Widgets\AsyncWorkerHealthWidget;
use Filament\Resources\Pages\ListRecords;

class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AsyncWorkerHealthWidget::class,
        ];
    }
}
