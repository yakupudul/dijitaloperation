<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Concerns\InteractsWithMetaExpertWorkspace;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\AssetBindingsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsActivityRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsIntelligenceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsPerformanceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsSearchTermsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsActivityRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsCampaignsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsCreativesRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsInsightsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteActivityRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteDiscoveryRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteHealthRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsitePerformanceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteSettingsRelationManager;
use App\Filament\App\Resources\Runs\RunResource;
use App\Jobs\AnalyzeInstagramMetaAdsDestinationConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpAddressConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpPhoneConsistencyJob;
use App\Jobs\AnalyzeWebsiteGbpWebsiteUrlConsistencyJob;
use App\Jobs\AnalyzeWebsiteGoogleAdsLandingConsistencyJob;
use App\Jobs\AnalyzeWebsiteInstagramWebsiteUrlConsistencyJob;
use App\Jobs\AnalyzeWebsiteMetaAdsDestinationConsistencyJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use App\Services\CrossAssetWebsiteGbpWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\CrossAssetWebsiteInstagramWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteMetaAdsDestinationConsistencyService;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Support\Integrations\AssetBindingCompatibility;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use MoxDop\GoogleAds\Workspace\GoogleAdsWorkspaceData;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceRefreshService;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;

class ViewDigitalAsset extends ViewRecord
{
    use InteractsWithMetaExpertWorkspace;

    protected static string $resource = DigitalAssetResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'mox-workspace-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshData')
                ->label('Refresh data')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Refresh website data')
                ->modalDescription('Queues Search Console and GA4 collection for active connections, then Findings. You can leave this page — progress is in Activity.')
                ->modalSubmitActionLabel('Refresh data')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $this->notifyAsyncQueueResult(
                        $async->queueBoundCollect($asset, $this->asyncOperator()),
                        'Website data refresh queued.',
                    );
                }),
            Action::make('refreshSeoIntelligence')
                ->label('Refresh SEO intelligence')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Refresh SEO intelligence?')
                ->modalDescription(function (): string {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $preview = app(SeoIntelligenceRefreshService::class)->preview($asset);

                    if (($preview['blocked_reason'] ?? null) !== null) {
                        return (string) $preview['message'];
                    }

                    if (($preview['both_fresh'] ?? false) === true) {
                        return 'SEO intelligence is already up to date. Fresh DataForSEO results will be reused. No provider request will be made.';
                    }

                    return 'Queues a DataForSEO refresh in the background. Fresh results are reused automatically; new provider requests may consume DataForSEO credits. Progress is in Activity.';
                })
                ->modalSubmitActionLabel('Refresh')
                ->action(function (SeoIntelligenceRefreshService $service, AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $preview = $service->preview($asset);

                    if (($preview['blocked_reason'] ?? null) !== null) {
                        Notification::make()
                            ->title('SEO intelligence not ready')
                            ->body($preview['message'])
                            ->warning()
                            ->send();

                        return;
                    }

                    if (($preview['both_fresh'] ?? false) === true) {
                        Notification::make()
                            ->title('SEO intelligence already fresh')
                            ->body('0 provider requests · $0 additional provider cost')
                            ->success()
                            ->send();

                        return;
                    }

                    $this->notifyAsyncQueueResult(
                        $async->queueSeoIntelligenceRefresh($asset, $this->asyncOperator()),
                        'SEO intelligence refresh queued.',
                    );
                }),
            Action::make('discoverPublicContext')
                ->label('Discover public context')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Discover public context?')
                ->modalDescription('Queues public website inspection. Creates Discovery Evidence and Brand Context candidates for human review. Does not overwrite Brand Context. Progress is in Activity.')
                ->modalSubmitActionLabel('Discover public context')
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $this->notifyAsyncQueueResult(
                        $async->queuePublicDiscovery($asset, $this->asyncOperator()),
                        'Public discovery queued.',
                    );
                }),
            Action::make('generateAiGuidance')
                ->label('Generate AI guidance')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Generate AI guidance?')
                ->modalDescription('Queues advisory AI analysis from Findings, Evidence and Brand context. Does not create Findings or Tasks. Progress is in Activity.')
                ->modalSubmitActionLabel('Analyze issues with AI')
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $this->notifyAsyncQueueResult(
                        $async->queueWebsiteAiGuidance($asset, $this->asyncOperator()),
                        'Website AI guidance queued.',
                    );
                }),
            Action::make('generateGoogleAdsAiGuidance')
                ->label('Generate AI guidance')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->isGoogleAdsWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Generate Google Ads AI guidance?')
                ->modalDescription('Queues advisory Google Ads Analyst guidance. No Ads mutations, Findings, or Tasks. Progress is in Activity.')
                ->modalSubmitActionLabel('Analyze with AI')
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $this->notifyAsyncQueueResult(
                        $async->queueGoogleAdsAiGuidance($asset, $this->asyncOperator()),
                        'Google Ads AI guidance queued.',
                    );
                }),
            Action::make('generateMetaAdsAiGuidance')
                ->label('Generate AI guidance')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->isMetaAdsWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Generate Meta Ads AI guidance?')
                ->modalDescription('Queues advisory Meta Ads Analyst guidance. No Meta mutations, Findings, or Tasks. Progress is in Activity.')
                ->modalSubmitActionLabel('Analyze with AI')
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $this->notifyAsyncQueueResult(
                        $async->queueMetaAdsAiGuidance($asset, $this->asyncOperator()),
                        'Meta Ads AI guidance queued.',
                    );
                }),
            Action::make('refreshMetaData')
                ->label('Refresh data')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->visible(fn (): bool => $this->isMetaAdsWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Refresh Meta historical data')
                ->modalDescription('Incrementally refreshes the bound Meta Ad Account (recent correction window through today) into the read-only historical store. Older history is preserved. Progress is in Activity.')
                ->modalSubmitActionLabel('Refresh data')
                ->action(fn (): mixed => $this->refreshMetaWorkspaceData()),
            Action::make('collectLiveData')
                ->label('Collect live data')
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color(fn (): string => $this->isMetaAdsWorkspace() ? 'gray' : 'primary')
                ->visible(fn (): bool => $this->canCollectLiveBoundData())
                ->requiresConfirmation()
                ->modalHeading('Collect live provider data')
                ->modalDescription('Queues read-only collectors for this asset’s active provider connections. You can leave this page — progress is in Activity.')
                ->modalSubmitActionLabel('Collect live data')
                ->action(function (AsyncOperationService $async): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $queuedTitle = match ($asset->type) {
                        'meta_ads' => 'Meta Ads collection queued.',
                        'google_ads' => 'Google Ads collection queued.',
                        default => 'Live data collection queued.',
                    };
                    $extra = [];
                    if ($asset->type === 'meta_ads') {
                        $filters = MetaWorkspaceFilters::get((int) $asset->id);
                        $extra = [
                            'period_preset' => $filters['period_preset'],
                            'period_start' => $filters['period_start'],
                            'period_end' => $filters['period_end'],
                            'compare' => $filters['compare'],
                            'human_title' => 'Collect live data',
                        ];
                    }
                    $this->notifyAsyncQueueResult(
                        $async->queueBoundCollect($asset, $this->asyncOperator(), $extra),
                        $queuedTitle,
                    );
                }),
            ActionGroup::make([
                Action::make('runWebsiteDiagnosis')
                    ->label('Run technical diagnosis')
                    ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canRunWebsiteDiagnosis())
                    ->requiresConfirmation()
                    ->modalHeading('Run Website technical diagnosis')
                    ->modalDescription('Queues deterministic Website Diagnosis catalog checks. External systems are read-only. Progress is in Activity.')
                    ->modalSubmitActionLabel('Run diagnosis')
                    ->action(function (AsyncOperationService $async): void {
                        /** @var DigitalAsset $asset */
                        $asset = $this->getRecord();
                        $this->notifyAsyncQueueResult(
                            $async->queueWebsiteDiagnosis($asset, $this->asyncOperator()),
                            'Website diagnosis queued.',
                        );
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
                ->label('More')
                ->icon(Heroicon::OutlinedEllipsisHorizontal)
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end')
                ->visible(fn (): bool => $this->hasAnyMoreAction()),
            EditAction::make()
                ->label('Edit asset')
                ->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        if ($this->isWebsiteWorkspace()) {
            return $schema->components([
                ViewEntry::make('website_overview')
                    ->hiddenLabel()
                    ->view('website::workspace.overview')
                    ->viewData(fn (): array => [
                        'data' => app(WebsiteWorkspaceData::class)->for($this->getRecord()),
                    ])
                    ->columnSpanFull(),
            ]);
        }

        if ($this->isGoogleAdsWorkspace()) {
            return $schema->components([
                ViewEntry::make('google_ads_overview')
                    ->hiddenLabel()
                    ->view('google-ads::workspace.overview')
                    ->viewData(fn (): array => [
                        'data' => app(GoogleAdsWorkspaceData::class)->for($this->getRecord()),
                    ])
                    ->columnSpanFull(),
            ]);
        }

        if ($this->isMetaAdsWorkspace()) {
            return $schema->components([
                ViewEntry::make('meta_ads_overview')
                    ->hiddenLabel()
                    ->view('meta-ads::workspace.overview')
                    ->viewData(fn (): array => [
                        'data' => app(MetaAdsWorkspaceData::class)->for($this->getRecord()),
                    ])
                    ->columnSpanFull(),
            ]);
        }

        return DigitalAssetResource::infolist($schema);
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

        if ($this->isWebsiteWorkspace()) {
            $workspace = app(WebsiteWorkspaceData::class)->for($asset);
            $host = filled($asset->domain)
                ? $asset->domain
                : (filled($asset->primary_url) ? (parse_url((string) $asset->primary_url, PHP_URL_HOST) ?: $asset->primary_url) : null);

            $parts = array_values(array_filter([
                $host ? $host.' · '.$type.' · '.$status : $type.' · '.$status,
                ! empty($workspace['last_updated_human']) ? 'Last updated '.$workspace['last_updated_human'] : null,
                $workspace['connection_health'] ?? null,
            ], fn (?string $part): bool => filled($part)));

            return $parts === [] ? null : implode(' · ', $parts);
        }

        if ($this->isGoogleAdsWorkspace()) {
            $workspace = app(GoogleAdsWorkspaceData::class)->for($asset);
            $parts = array_values(array_filter([
                $type.' · '.$status,
                ! empty($workspace['last_updated_human']) ? 'Last updated '.$workspace['last_updated_human'] : null,
                $workspace['connection_health'] ?? null,
            ], fn (?string $part): bool => filled($part)));

            return $parts === [] ? null : implode(' · ', $parts);
        }

        if ($this->isMetaAdsWorkspace()) {
            $workspace = app(MetaAdsWorkspaceData::class)->for($asset);
            $identity = $workspace['account_identity'] ?? [];
            $accountName = filled($identity['name'] ?? null) ? $identity['name'] : null;
            $businessName = filled($identity['business_name'] ?? null) ? $identity['business_name'] : null;

            $parts = array_values(array_filter([
                $accountName ? 'Meta Ads · '.$accountName : 'Meta Ads · '.$status,
                $businessName ? 'Meta Business: '.$businessName : null,
                ! empty($workspace['last_updated_human']) ? 'Last updated '.$workspace['last_updated_human'] : null,
                $workspace['connection_health'] ?? null,
            ], fn (?string $part): bool => filled($part)));

            return $parts === [] ? null : implode(' · ', $parts);
        }

        $identifier = filled($asset->primary_url)
            ? $asset->primary_url
            : (filled($asset->domain) ? $asset->domain : null);

        $parts = array_values(array_filter([
            $identifier,
            $type.' · '.$status,
        ], fn (?string $part): bool => filled($part)));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function asyncOperator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}  $result
     */
    private function notifyAsyncQueueResult(array $result, string $queuedTitle): void
    {
        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title($queuedTitle)
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        if (($result['existing_run'] ?? null) instanceof Run) {
            Notification::make()
                ->title('Already in progress')
                ->body($result['message'])
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Could not queue operation')
            ->body($result['message'] ?? 'Unknown error')
            ->danger()
            ->send();
    }

    private function isWebsiteWorkspace(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === 'website';
    }

    private function isGoogleAdsWorkspace(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === 'google_ads';
    }

    private function isMetaAdsWorkspace(): bool
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();

        return $asset->type === 'meta_ads';
    }

    /**
     * Show Collect live data only when this asset type has at least one
     * compatible capability with a registered bound collector. Website uses
     * its dedicated Refresh data workflow instead.
     */
    private function canCollectLiveBoundData(): bool
    {
        if ($this->isWebsiteWorkspace()) {
            return false;
        }

        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();
        $registry = app(BoundCollectorRegistry::class);

        foreach (AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) as $capability) {
            if ($registry->forCapability($capability) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getRecord();
        /** @var Brand|null $brand */
        $brand = $this->getParentRecord();

        $crumbs = [];

        if ($brand instanceof Brand) {
            $crumbs[BrandResource::getUrl('view', [
                'record' => $brand,
                'customer' => $brand->customer_id,
            ])] = $brand->name;
        }

        $crumbs[] = $asset->name;

        return $crumbs;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    /**
     * @return array<int|string, class-string>
     */
    public function getRelationManagers(): array
    {
        if ($this->isWebsiteWorkspace()) {
            return [
                WebsitePerformanceRelationManager::class,
                WebsiteHealthRelationManager::class,
                WebsiteDiscoveryRelationManager::class,
                WebsiteConnectionsRelationManager::class,
                WebsiteActivityRelationManager::class,
                WebsiteSettingsRelationManager::class,
            ];
        }

        if ($this->isGoogleAdsWorkspace()) {
            return [
                GoogleAdsPerformanceRelationManager::class,
                GoogleAdsSearchTermsRelationManager::class,
                GoogleAdsIntelligenceRelationManager::class,
                GoogleAdsConnectionsRelationManager::class,
                GoogleAdsActivityRelationManager::class,
            ];
        }

        if ($this->isMetaAdsWorkspace()) {
            return [
                MetaAdsCampaignsRelationManager::class,
                MetaAdsCreativesRelationManager::class,
                MetaAdsInsightsRelationManager::class,
                MetaAdsConnectionsRelationManager::class,
                MetaAdsActivityRelationManager::class,
            ];
        }

        return [
            'assetBindings' => AssetBindingsRelationManager::class,
            'connections' => ConnectionsRelationManager::class,
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

    /**
     * The "More" ActionGroup only contains Website- and Instagram-scoped
     * cross-asset checks. Hide the whole group when none apply — e.g. for
     * Meta Ads and Google Ads asset workspaces — instead of showing an empty
     * dropdown.
     */
    private function hasAnyMoreAction(): bool
    {
        return $this->canRunWebsiteDiagnosis()
            || $this->canRunWebsiteGbpWebsiteUrlConsistency()
            || $this->canRunWebsiteGbpPhoneConsistency()
            || $this->canRunWebsiteGbpAddressConsistency()
            || $this->canRunWebsiteGoogleAdsLandingConsistency()
            || $this->canRunWebsiteInstagramWebsiteUrlConsistency()
            || $this->canRunWebsiteMetaAdsDestinationConsistency()
            || $this->canRunInstagramMetaAdsDestinationConsistency();
    }
}
