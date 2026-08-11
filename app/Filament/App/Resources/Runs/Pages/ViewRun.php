<?php

namespace App\Filament\App\Resources\Runs\Pages;

use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryOperation')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Retry this operation?')
                ->modalDescription('Starts a new attempt. The original Activity record stays as historical truth.')
                ->visible(fn (): bool => app(AsyncOperationService::class)->canRetry($this->getRecord()))
                ->action(function (AsyncOperationService $async): void {
                    /** @var Run $run */
                    $run = $this->getRecord();
                    $user = auth()->user();
                    $result = $async->retry($run, $user instanceof User ? $user : null);

                    if (($result['queued'] ?? false) === true && ($result['run'] ?? null) instanceof Run) {
                        Notification::make()
                            ->title('Retry queued')
                            ->body($result['message'])
                            ->success()
                            ->send();

                        $this->redirect(RunResource::getUrl('view', ['record' => $result['run']]));

                        return;
                    }

                    Notification::make()
                        ->title('Retry not started')
                        ->body($result['message'] ?? 'Unable to retry.')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
