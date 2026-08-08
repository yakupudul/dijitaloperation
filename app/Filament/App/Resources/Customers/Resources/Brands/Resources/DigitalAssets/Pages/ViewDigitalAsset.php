<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Jobs\AnalyzeInstagramMetaAdsDestinationConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpAddressConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpPhoneConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpWebsiteUrlConsistencyJob;
use App\Jobs\AnalyzeWebsiteGoogleAdsLandingConsistencyJob;
use App\Jobs\AnalyzeWebsiteInstagramWebsiteUrlConsistencyJob;
use App\Jobs\AnalyzeWebsiteMetaAdsDestinationConsistencyJob;
use App\Jobs\DiagnoseWebsiteJob;
use App\Models\DigitalAsset;
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use App\Services\CrossAssetWebsiteGbpWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\CrossAssetWebsiteInstagramWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteMetaAdsDestinationConsistencyService;
use App\Services\WebsiteDiagnosisService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewDigitalAsset extends ViewRecord
{
    protected static string $resource = DigitalAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
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
                ActionGroup::make([
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
                ])->label('Website ↔ Google Business')->icon(Heroicon::OutlinedBuildingStorefront),
                ActionGroup::make([
                    Action::make('runWebsiteGoogleAdsLandingConsistency')
                        ->label('Run Website↔Google Ads landing check')
                        ->icon(Heroicon::OutlinedCursorArrowRays)
                        ->color('gray')
                        ->visible(fn (): bool => $this->canRunWebsiteGoogleAdsLandingConsistency())
                        ->requiresConfirmation()
                        ->modalHeading('Run Website ↔ Google Ads landing URL consistency')
                        ->modalDescription('Compares existing Website HTTP Evidence with Brand Google Ads landing final URL Evidence. No external writes.')
                        ->modalSubmitActionLabel('Run check')
                        ->action(function (): void {
                            /** @var DigitalAsset $asset */
                            $asset = $this->getRecord();

                            try {
                                $run = (new AnalyzeWebsiteGoogleAdsLandingConsistencyJob($asset))->handle(
                                    app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class),
                                );
                            } catch (\Throwable $exception) {
                                Notification::make()
                                    ->title('Website↔Google Ads landing check failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $skip = is_string($run->metadata['skip_reason'] ?? null)
                                ? $run->metadata['skip_reason']
                                : null;

                            Notification::make()
                                ->title($skip === null ? 'Website↔Google Ads landing check completed' : 'Website↔Google Ads landing check skipped')
                                ->body(
                                    $skip === null
                                        ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                        : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                                )
                                ->success()
                                ->send();

                            $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                        }),
                ])->label('Website ↔ Google Ads')->icon(Heroicon::OutlinedCursorArrowRays),
                ActionGroup::make([
                    Action::make('runWebsiteInstagramWebsiteUrlConsistency')
                        ->label('Run Website↔Instagram website check')
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->color('gray')
                        ->visible(fn (): bool => $this->canRunWebsiteInstagramWebsiteUrlConsistency())
                        ->requiresConfirmation()
                        ->modalHeading('Run Website ↔ Instagram website URL consistency')
                        ->modalDescription('Compares existing Website HTTP Evidence with Brand Instagram account profile website Evidence. No external writes.')
                        ->modalSubmitActionLabel('Run check')
                        ->action(function (): void {
                            /** @var DigitalAsset $asset */
                            $asset = $this->getRecord();

                            try {
                                $run = (new AnalyzeWebsiteInstagramWebsiteUrlConsistencyJob($asset))->handle(
                                    app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class),
                                );
                            } catch (\Throwable $exception) {
                                Notification::make()
                                    ->title('Website↔Instagram website check failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $skip = is_string($run->metadata['skip_reason'] ?? null)
                                ? $run->metadata['skip_reason']
                                : null;

                            Notification::make()
                                ->title($skip === null ? 'Website↔Instagram website check completed' : 'Website↔Instagram website check skipped')
                                ->body(
                                    $skip === null
                                        ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                        : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                                )
                                ->success()
                                ->send();

                            $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                        }),
                    Action::make('runWebsiteMetaAdsDestinationConsistency')
                        ->label('Run Website↔Meta Ads destination check')
                        ->icon(Heroicon::OutlinedMegaphone)
                        ->color('gray')
                        ->visible(fn (): bool => $this->canRunWebsiteMetaAdsDestinationConsistency())
                        ->requiresConfirmation()
                        ->modalHeading('Run Website ↔ Meta Ads destination URL consistency')
                        ->modalDescription('Compares existing Website HTTP Evidence with Brand Meta Ads ad destination URL Evidence. No external writes.')
                        ->modalSubmitActionLabel('Run check')
                        ->action(function (): void {
                            /** @var DigitalAsset $asset */
                            $asset = $this->getRecord();

                            try {
                                $run = (new AnalyzeWebsiteMetaAdsDestinationConsistencyJob($asset))->handle(
                                    app(CrossAssetWebsiteMetaAdsDestinationConsistencyService::class),
                                );
                            } catch (\Throwable $exception) {
                                Notification::make()
                                    ->title('Website↔Meta Ads destination check failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $skip = is_string($run->metadata['skip_reason'] ?? null)
                                ? $run->metadata['skip_reason']
                                : null;

                            Notification::make()
                                ->title($skip === null ? 'Website↔Meta Ads destination check completed' : 'Website↔Meta Ads destination check skipped')
                                ->body(
                                    $skip === null
                                        ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                        : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                                )
                                ->success()
                                ->send();

                            $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                        }),
                ])->label('Website ↔ Meta / Instagram')->icon(Heroicon::OutlinedGlobeAlt),
                Action::make('runInstagramMetaAdsDestinationConsistency')
                    ->label('Run Instagram↔Meta Ads destination check')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canRunInstagramMetaAdsDestinationConsistency())
                    ->requiresConfirmation()
                    ->modalHeading('Run Instagram ↔ Meta Ads destination URL consistency')
                    ->modalDescription('Compares existing Instagram account profile website Evidence with Brand Meta Ads ad destination URL Evidence. No external writes.')
                    ->modalSubmitActionLabel('Run check')
                    ->action(function (): void {
                        /** @var DigitalAsset $asset */
                        $asset = $this->getRecord();

                        try {
                            $run = (new AnalyzeInstagramMetaAdsDestinationConsistencyJob($asset))->handle(
                                app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class),
                            );
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Instagram↔Meta Ads destination check failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $skip = is_string($run->metadata['skip_reason'] ?? null)
                            ? $run->metadata['skip_reason']
                            : null;

                        Notification::make()
                            ->title($skip === null ? 'Instagram↔Meta Ads destination check completed' : 'Instagram↔Meta Ads destination check skipped')
                            ->body(
                                $skip === null
                                    ? 'Run #'.$run->id.' finished with status '.$run->status.'.'
                                    : 'Run #'.$run->id.' finished without comparison ('.$skip.').'
                            )
                            ->success()
                            ->send();

                        $this->redirect(RunResource::getUrl('view', ['record' => $run]));
                    }),
            ])
                ->label('Run analysis')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('primary')
                ->button()
                ->dropdownPlacement('bottom-end'),
            EditAction::make()
                ->label('Edit asset'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        $type = str($asset->type)->replace('_', ' ')->title()->toString();
        $status = $asset->status instanceof \BackedEnum
            ? str($asset->status->value)->replace('_', ' ')->title()->toString()
            : (string) $asset->status;

        $identifier = filled($asset->primary_url)
            ? $asset->primary_url
            : (filled($asset->domain) ? $asset->domain : null);

        $parts = array_values(array_filter([
            $identifier,
            $type.' · '.$status,
        ], fn (?string $part): bool => filled($part)));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function getBreadcrumb(): string
    {
        return 'Workspace';
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
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

    private function canRunWebsiteGoogleAdsLandingConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteGoogleAdsLandingConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }

    private function canRunWebsiteInstagramWebsiteUrlConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }

    private function canRunWebsiteMetaAdsDestinationConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetWebsiteMetaAdsDestinationConsistencyService::ASSET_TYPE_WEBSITE
            && $asset->brand_id !== null;
    }

    private function canRunInstagramMetaAdsDestinationConsistency(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === CrossAssetInstagramMetaAdsDestinationConsistencyService::ASSET_TYPE_INSTAGRAM
            && $asset->brand_id !== null;
    }
}
