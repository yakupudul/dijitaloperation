<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Jobs\AnalyzeWebsiteGbpAddressConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpPhoneConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpWebsiteUrlConsistencyJob;
use App\Jobs\DiagnoseWebsiteJob;
use App\Models\DigitalAsset;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use App\Services\CrossAssetWebsiteGbpWebsiteUrlConsistencyService;
use App\Services\WebsiteDiagnosisService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

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
                    } catch (\Throwable $exception) {
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
            Action::make('runWebsiteGbpWebsiteUrlConsistency')
                ->label('Run Website↔GBP URL check')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible(fn (): bool => $this->canRunWebsiteGbpWebsiteUrlConsistency())
                ->requiresConfirmation()
                ->modalHeading('Run Website ↔ GBP website URL consistency')
                ->modalDescription('Compares existing Website HTTP Evidence with Brand Google Business Profile location Evidence. No external writes.')
                ->modalSubmitActionLabel('Run check')
                ->action(function (): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $run = (new AnalyzeWebsiteGbpWebsiteUrlConsistencyJob($asset))->handle(
                            app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Website↔GBP URL check failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $skip = is_string($run->metadata['skip_reason'] ?? null)
                        ? $run->metadata['skip_reason']
                        : null;

                    Notification::make()
                        ->title($skip === null ? 'Website↔GBP URL check completed' : 'Website↔GBP URL check skipped')
                        ->body(
                            $skip === null
                                ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                        )
                        ->success()
                        ->send();

                    $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                }),
            Action::make('runWebsiteGbpPhoneConsistency')
                ->label('Run Website↔GBP phone check')
                ->icon(Heroicon::OutlinedPhone)
                ->color('gray')
                ->visible(fn (): bool => $this->canRunWebsiteGbpPhoneConsistency())
                ->requiresConfirmation()
                ->modalHeading('Run Website ↔ GBP phone consistency')
                ->modalDescription('Compares existing Website page_html telephone Evidence with Brand Google Business Profile location Evidence. No external writes.')
                ->modalSubmitActionLabel('Run check')
                ->action(function (): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $run = (new AnalyzeWebsiteGbpPhoneConsistencyJob($asset))->handle(
                            app(CrossAssetWebsiteGbpPhoneConsistencyService::class),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Website↔GBP phone check failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $skip = is_string($run->metadata['skip_reason'] ?? null)
                        ? $run->metadata['skip_reason']
                        : null;

                    Notification::make()
                        ->title($skip === null ? 'Website↔GBP phone check completed' : 'Website↔GBP phone check skipped')
                        ->body(
                            $skip === null
                                ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                        )
                        ->success()
                        ->send();

                    $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                }),
            Action::make('runWebsiteGbpAddressConsistency')
                ->label('Run Website↔GBP address check')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->visible(fn (): bool => $this->canRunWebsiteGbpAddressConsistency())
                ->requiresConfirmation()
                ->modalHeading('Run Website ↔ GBP address consistency')
                ->modalDescription('Compares existing Website page_html postal-address Evidence with Brand Google Business Profile storefront Evidence. No external writes.')
                ->modalSubmitActionLabel('Run check')
                ->action(function (): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $run = (new AnalyzeWebsiteGbpAddressConsistencyJob($asset))->handle(
                            app(CrossAssetWebsiteGbpAddressConsistencyService::class),
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Website↔GBP address check failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $skip = is_string($run->metadata['skip_reason'] ?? null)
                        ? $run->metadata['skip_reason']
                        : null;

                    Notification::make()
                        ->title($skip === null ? 'Website↔GBP address check completed' : 'Website↔GBP address check skipped')
                        ->body(
                            $skip === null
                                ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                        )
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

    private function canRunWebsiteGbpWebsiteUrlConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteGbpWebsiteUrlConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }

    private function canRunWebsiteGbpPhoneConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteGbpPhoneConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }

    private function canRunWebsiteGbpAddressConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteGbpAddressConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }
}
