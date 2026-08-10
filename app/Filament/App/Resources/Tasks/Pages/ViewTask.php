<?php

namespace App\Filament\App\Resources\Tasks\Pages;

use App\Filament\App\Resources\Tasks\TaskResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TaskResource::makeStartWorkAction(),
            TaskResource::makeBlockAction(),
            TaskResource::makeResumeAction(),
            TaskResource::makeCompleteAction(),
            TaskResource::makeCancelAction(),
            TaskResource::makeReevaluateOutcomeAction(),
        ];
    }
}
