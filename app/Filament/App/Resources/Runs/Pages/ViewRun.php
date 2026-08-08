<?php

namespace App\Filament\App\Resources\Runs\Pages;

use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Run;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Run $run */
        $run = $this->getRecord();

        return RunResource::activityTitle($run);
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }
}
