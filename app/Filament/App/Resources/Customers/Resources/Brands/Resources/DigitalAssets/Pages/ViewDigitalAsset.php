<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\AssetBindingsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsActivityRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsIntelligenceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsPerformanceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\GoogleAdsSearchTermsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsConnectionsRelationManager;
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
use App\Jobs\DiagnoseWebsiteJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use App\Services\CrossAssetWebsiteGbpWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\CrossAssetWebsiteInstagramWebsiteUrlConsistencyService;
use App\Services\CrossAssetWebsiteMetaAdsDestinationConsistencyService;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Services\WebsiteDiagnosisService;
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
use MoxDop\GoogleAds\Ai\GoogleAdsAiGuidanceService;
use MoxDop\GoogleAds\Workspace\GoogleAdsWorkspaceData;
use MoxDop\MetaAds\Support\MetaAdsWorkspaceData;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use MoxDop\Website\Discovery\PublicDiscoveryService;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceRefreshService;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;

class ViewDigitalAsset extends ViewRecord
{
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
                ->modalDescription('Collects Search Console and GA4 for active connections, then updates Findings and Recommendations. Stay on this workspace after refresh.')
                ->modalSubmitActionLabel('Refresh data')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->action(function (CollectLiveBoundDataService $collector): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $result = $collector->collect($asset);

                    $sources = collect($result['runs'])
                        ->map(fn (Run $run): string => match (data_get($run->metadata, 'capability')) {
                            'search_console' => 'Search Console',
                            'ga4' => 'GA4',
                            'google_ads' => 'Google Ads',
                            'google_business_profile' => 'Business Profile',
                            default => 'Source',
                        })
                        ->unique()
                        ->values();

                    $evidenceCount = collect($result['runs'])
                        ->sum(fn (Run $run): int => $run->evidence()->count());

                    $findings = $result['findings'] ?? [];
                    $body = trim(implode("\n", array_filter([
                        $sources->isNotEmpty() ? $sources->implode(' + ') : null,
                        $evidenceCount > 0 ? $evidenceCount.' Evidence sets' : null,
                        sprintf(
                            '%d Findings opened · %d updated · %d resolved',
                            (int) ($findings['opened'] ?? 0),
                            (int) ($findings['updated'] ?? 0) + (int) ($findings['reopened'] ?? 0),
                            (int) ($findings['resolved'] ?? 0),
                        ),
                        $result['skipped'] !== [] ? $result['message'] : null,
                    ])));

                    Notification::make()
                        ->title($result['ok'] ? 'Data refreshed' : 'Data refresh incomplete')
                        ->body($body !== '' ? $body : $result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();

                    // Stay on the Website workspace — do not redirect to a Run.
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

                    return 'MoxDOP will refresh external keyword intelligence from DataForSEO. Fresh results are reused automatically; new provider requests may consume DataForSEO credits.';
                })
                ->modalSubmitActionLabel('Refresh')
                ->action(function (SeoIntelligenceRefreshService $service): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $result = $service->refresh($asset);

                    if (($result['blocked_reason'] ?? null) !== null) {
                        Notification::make()
                            ->title('SEO intelligence not ready')
                            ->body($result['message'])
                            ->warning()
                            ->send();

                        return;
                    }

                    if (($result['both_fresh'] ?? false) === true && (int) ($result['provider_calls'] ?? 0) === 0) {
                        Notification::make()
                            ->title('SEO intelligence already fresh')
                            ->body('0 provider requests · $0 additional provider cost')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($result['ok'] ? 'SEO intelligence updated' : 'SEO intelligence refresh incomplete')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                }),
            Action::make('discoverPublicContext')
                ->label('Discover public context')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Discover public context?')
                ->modalDescription('Inspects publicly available information for this Website. Creates a Discovery Run and Evidence, then proposes Brand Context candidates for human review. Does not overwrite Brand Context and does not require GSC/GA4.')
                ->modalSubmitActionLabel('Discover public context')
                ->action(function (PublicDiscoveryService $service): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $result = $service->discover($asset);
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Public discovery not ready')
                            ->body($exception->getMessage())
                            ->warning()
                            ->send();

                        return;
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Public discovery failed')
                            ->body(class_basename($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(match ($result['status']) {
                            'succeeded' => 'Public discovery completed',
                            'partial' => 'Public discovery partial',
                            default => 'Public discovery failed',
                        })
                        ->body(implode(' · ', array_filter([
                            $result['message'],
                            $result['pages_inspected'].' pages',
                            $result['fact_candidates'].' fact candidates',
                            $result['inference_candidates'].' inferences',
                            $result['competitor_candidates'].' competitor candidates',
                        ])))
                        ->{$result['status'] === 'failed' ? 'warning' : 'success'}()
                        ->send();
                }),
            Action::make('generateAiGuidance')
                ->label('Generate AI guidance')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->isWebsiteWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Generate AI guidance?')
                ->modalDescription('Uses current Findings, Evidence and Brand context. AI is advisory — it will not create Findings or Tasks, and will not overwrite deterministic Recommendations.')
                ->modalSubmitActionLabel('Analyze issues with AI')
                ->action(function (WebsiteAiRecommendationService $service): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $result = $service->analyze($asset);
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('AI guidance not ready')
                            ->body($exception->getMessage())
                            ->warning()
                            ->send();

                        return;
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('AI guidance failed')
                            ->body(class_basename($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($result['reused'] ? 'AI analysis is already current' : (
                            $result['run']->status === 'completed' ? 'AI guidance generated' : 'AI guidance failed'
                        ))
                        ->body($result['message'])
                        ->{$result['run']->status === 'completed' || $result['reused'] ? 'success' : 'warning'}()
                        ->send();
                }),
            Action::make('generateGoogleAdsAiGuidance')
                ->label('Generate AI guidance')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->isGoogleAdsWorkspace())
                ->requiresConfirmation()
                ->modalHeading('Generate Google Ads AI guidance?')
                ->modalDescription('Uses current Findings, Evidence and Brand context via Google Ads Analyst. Advisory only — no Ads mutations, Findings, or Tasks.')
                ->modalSubmitActionLabel('Analyze with AI')
                ->action(function (GoogleAdsAiGuidanceService $service): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();

                    try {
                        $result = $service->analyze($asset);
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('AI guidance not ready')
                            ->body($exception->getMessage())
                            ->warning()
                            ->send();

                        return;
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('AI guidance failed')
                            ->body(class_basename($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($result['reused'] ? 'AI analysis is already current' : (
                            $result['run']->status === 'completed' ? 'AI guidance generated' : 'AI guidance failed'
                        ))
                        ->body($result['message'])
                        ->{$result['run']->status === 'completed' || $result['reused'] ? 'success' : 'warning'}()
                        ->send();
                }),
            Action::make('collectLiveData')
                ->label('Collect live data')
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color('primary')
                ->visible(fn (): bool => $this->canCollectLiveBoundData())
                ->requiresConfirmation()
                ->modalHeading('Collect live provider data')
                ->modalDescription('Runs collectors for this asset’s active provider connections. Read-only.')
                ->modalSubmitActionLabel('Collect live data')
                ->action(function (CollectLiveBoundDataService $collector): void {
                    /** @var DigitalAsset $asset */
                    $asset = $this->getRecord();
                    $result = $collector->collect($asset);

                    Notification::make()
                        ->title($result['ok'] ? 'Live data collection finished' : 'Live data collection incomplete')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                }),
            ActionGroup::make([
                Action::make('runWebsiteDiagnosis')
                    ->label('Run technical diagnosis')
                    ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canRunWebsiteDiagnosis())
                    ->requiresConfirmation()
                    ->modalHeading('Run Website technical diagnosis')
                    ->modalDescription('Starts a deterministic Website Diagnosis run using catalog checks. External systems are read-only.')
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
                            ->body('Technical check finished with status '.$run->status.'.')
                            ->success()
                            ->send();
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
                ->dropdownPlacement('bottom-end'),
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
                    ->view('meta-ads::filament.digital-assets.workspaces.meta-ads.overview')
                    ->viewData(fn (): array => [
                        'summary' => MetaAdsWorkspaceData::forAsset($this->getRecord()),
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
            $workspace = MetaAdsWorkspaceData::forAsset($asset);
            $parts = array_values(array_filter([
                $workspace['connection_label'] ?? null,
                $workspace['account_label'] !== 'Not bound' ? $workspace['account_label'] : null,
            ], fn (?string $part): bool => filled($part)));

            return $parts === [] ? 'Meta Ads' : implode(' · ', $parts);
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
                MetaAdsConnectionsRelationManager::class,
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
}
