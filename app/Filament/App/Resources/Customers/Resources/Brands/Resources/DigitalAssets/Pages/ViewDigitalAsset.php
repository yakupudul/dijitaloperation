<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Jobs\DiagnoseWebsiteJob;
use App\Models\DigitalAsset;
use App\Services\WebsiteDiagnosisService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewDigitalAsset extends ViewRecord
{
    protected static string $resource = DigitalAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runWebsiteDiagnosis')
                ->label('Run diagnosis')
                ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                ->color('primary')
                ->visible(fn (): bool => $this->canRunWebsiteDiagnosis())
                ->requiresConfirmation()
                ->modalHeading('Run Website Diagnosis')
                ->modalDescription('Starts a deterministic Website Diagnosis run for this asset using the catalog checks. External systems are read-only.')
                ->modalSubmitActionLabel('Run diagnosis')
                ->action(function (): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $run = (new DiagnoseWebsiteJob($asset))->handle(
                            app(WebsiteDiagnosisService::class),
                        );
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Website Diagnosis failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Website Diagnosis completed')
                        ->body('Run #'.$run->id.' finished with status '.$run->status.'.')
                        ->success()
                        ->send();

                    $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                }),
            EditAction::make(),
        ];
    }

    private function canRunWebsiteDiagnosis(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        if ($asset->type !== 'website') {
            return false;
        }

        $primaryUrl = is_string($asset->primary_url) ? trim($asset->primary_url) : '';

        return $primaryUrl !== '';
    }
}
