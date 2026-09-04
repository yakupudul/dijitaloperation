<?php

use App\Http\Controllers\Demo\OperatorFileDownloadController;
use App\Http\Controllers\Integrations\WordPressConnectorDownloadController;
use App\Http\Controllers\Operator\LegacyWorkRedirectController;
use App\Http\Controllers\Operator\RetiredAssetTypeRedirectController;
use App\Http\Controllers\Prospects\ProspectReportArtifactDownloadController;
use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\Instagram\OverviewPage as InstagramOverviewPage;
use App\Livewire\Demo\Integrations\AiProviderIntegrationPage;
use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\DataForSeoIntegrationPage;
use App\Livewire\Demo\Integrations\GoogleAdsConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Livewire\Operator\Integrations\SiteConnectorsIndex;
use App\Livewire\Demo\Meta\AdDetailPage;
use App\Livewire\Demo\Meta\AdSetDetailPage;
use App\Livewire\Demo\Meta\AdSetsPage;
use App\Livewire\Demo\Meta\AdsPage;
use App\Livewire\Demo\Meta\BreakdownsPage;
use App\Livewire\Demo\Meta\CampaignDetailPage;
use App\Livewire\Demo\Meta\CampaignsPage;
use App\Livewire\Demo\Meta\CreativesPage;
use App\Livewire\Demo\Meta\InsightsPage;
use App\Livewire\Demo\Operations\ActivityIndex;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Operations\OpportunitiesIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Operations\TaskShow;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\BrandEdit;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomerEdit;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Livewire\Demo\Portfolio\PortfolioSetupWizard;
use App\Livewire\Demo\ProfilePage;
use App\Livewire\Demo\Sales\IntentRadarIndex;
use App\Livewire\Demo\Sales\IntentSignalShow;
use App\Livewire\Demo\Sales\ProspectConvert;
use App\Livewire\Demo\Sales\ProspectCreate;
use App\Livewire\Demo\Sales\ProspectShow;
use App\Livewire\Demo\Sales\ProspectsIndex;
use App\Livewire\Demo\Sales\SearchProfileForm;
use App\Livewire\Demo\Sales\SearchProfileShow;
use App\Livewire\Demo\Sales\SearchProfilesIndex;
use App\Livewire\Demo\Settings\AiAgentsPage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Livewire\Demo\Settings\AiSkillsPage;
use App\Livewire\Demo\Settings\BackgroundOperationsPage;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Livewire\Operator\Assets\AnalyticsPage;
use App\Livewire\Operator\Assets\SearchConsolePage;
use App\Livewire\Operator\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Operator\Library\SearchQueryLibraryPage;
use App\Livewire\Operator\Library\ServiceCatalogPage;
use App\Livewire\Operator\Library\BrandQueryPortfolioPage;
use App\Livewire\Operator\Library\SearchDemandClustersPage;
use App\Livewire\Operator\Library\SearchDemandCompetitorLibraryPage;
use App\Livewire\Operator\Library\SearchDemandCompetitorPagesPage;
use App\Livewire\Operator\Library\SearchDemandVisibilityMapPage;
use App\Livewire\Operator\Library\SearchDemandEnrichmentPage;
use App\Livewire\Operator\Library\SearchDemandPageOwnershipPage;
use App\Livewire\Operator\Meta\OverviewPage as MetaOverviewPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Support\Work\WorkUrl;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('operator.dashboard');

        Route::livewire('/customers', CustomersIndex::class)->name('operator.customers');
        Route::livewire('/customers/create', CustomerCreate::class)->name('operator.customer.create');
        Route::livewire('/customers/{customerId}/edit', CustomerEdit::class)->name('operator.customer.edit');
        Route::livewire('/customers/{customerId}', CustomerDetail::class)->name('operator.customer');

        Route::livewire('/brands', BrandsIndex::class)->name('operator.brands');
        Route::livewire('/brands/create', BrandCreate::class)->name('operator.brand.create');
        Route::livewire('/brands/{brandId}/edit', BrandEdit::class)->name('operator.brand.edit');
        Route::livewire('/brands/{brand}', BrandShow::class)->name('operator.brand');

        Route::livewire('/library/services', ServiceCatalogPage::class)->name('operator.library.services');
        Route::livewire('/library/search-queries', SearchQueryLibraryPage::class)->name('operator.library.search-queries');
        Route::livewire('/library/brand-query-portfolios', BrandQueryPortfolioPage::class)->name('operator.library.brand-query-portfolios');
        Route::livewire('/library/search-demand-clusters', SearchDemandClustersPage::class)->name('operator.library.search-demand-clusters');
        Route::livewire('/library/search-demand-visibility', SearchDemandVisibilityMapPage::class)->name('operator.library.search-demand-visibility');
        Route::livewire('/library/search-demand-enrichment', SearchDemandEnrichmentPage::class)->name('operator.library.search-demand-enrichment');
        Route::livewire('/library/search-demand-ownership', SearchDemandPageOwnershipPage::class)->name('operator.library.search-demand-ownership');
        Route::livewire('/library/search-demand-competitors', SearchDemandCompetitorLibraryPage::class)->name('operator.library.search-demand-competitors');
        Route::livewire('/library/search-demand-competitor-pages', SearchDemandCompetitorPagesPage::class)->name('operator.library.search-demand-competitor-pages');

        Route::livewire('/assets', AssetsIndex::class)->name('operator.assets');
        Route::livewire('/assets/create', AssetCreate::class)->name('operator.asset.create');

        Route::livewire('/setup', PortfolioSetupWizard::class)->name('operator.setup');

        Route::livewire('/integrations', IntegrationsIndex::class)->name('operator.integrations');
        Route::livewire('/integrations/google', GoogleIntegrationPage::class)->name('operator.integrations.google');
        Route::livewire('/integrations/meta', MetaIntegrationPage::class)->name('operator.integrations.meta');
        Route::livewire('/integrations/dataforseo', DataForSeoIntegrationPage::class)->name('operator.integrations.dataforseo');
        Route::livewire('/integrations/site-connectors', SiteConnectorsIndex::class)->name('operator.integrations.site-connectors');
        Route::livewire('/integrations/site-connectors/{connector}', SiteConnectorShow::class)->name('operator.integrations.site-connector');
        Route::get('/integrations/site-connectors/{connector}/download', WordPressConnectorDownloadController::class)
            ->name('operator.integrations.site-connector.download');
        Route::livewire('/integrations/connectors/google-ads', GoogleAdsConnectorPage::class)->name('operator.integrations.google-ads.connector');
        Route::livewire('/integrations/connectors/{connector}', ConnectorPage::class)->name('operator.integrations.connector');
        Route::livewire('/integrations/{provider}', AiProviderIntegrationPage::class)->name('operator.integrations.ai');

        Route::livewire('/files', FilesIndex::class)->name('operator.files');
        Route::get('/files/{file}/download', OperatorFileDownloadController::class)->name('operator.files.download');
        Route::livewire('/profile', ProfilePage::class)->name('operator.profile');

        Route::livewire('/assets/meta/{assetId?}', MetaOverviewPage::class)->name('operator.meta.overview');
        Route::livewire('/assets/meta/{assetId}/campaigns', CampaignsPage::class)->name('operator.meta.campaigns');
        Route::livewire('/assets/meta/{assetId}/campaigns/{campaignId}', CampaignDetailPage::class)->name('operator.meta.campaign');
        Route::livewire('/assets/meta/{assetId}/adsets', AdSetsPage::class)->name('operator.meta.adsets');
        Route::livewire('/assets/meta/{assetId}/adsets/{adSetId}', AdSetDetailPage::class)->name('operator.meta.adset');
        Route::livewire('/assets/meta/{assetId}/ads', AdsPage::class)->name('operator.meta.ads');
        Route::livewire('/assets/meta/{assetId}/ads/{adId}', AdDetailPage::class)->name('operator.meta.ad');
        Route::livewire('/assets/meta/{assetId}/creatives', CreativesPage::class)->name('operator.meta.creatives');
        Route::livewire('/assets/meta/{assetId}/breakdowns', BreakdownsPage::class)->name('operator.meta.breakdowns');
        Route::livewire('/assets/meta/{assetId}/insights', InsightsPage::class)->name('operator.meta.insights');

        Route::livewire('/assets/google-ads/{assetId?}', GoogleAdsOverviewPage::class)->name('operator.google-ads.overview');
        Route::livewire('/assets/website/{assetId?}', WebsiteOverviewPage::class)->name('operator.website');
        Route::livewire('/assets/gbp/{assetId?}', GbpOverviewPage::class)->name('operator.gbp');
        Route::livewire('/assets/analytics/{assetId?}', AnalyticsPage::class)
            ->whereNumber('assetId')
            ->name('operator.analytics');
        Route::livewire('/assets/search-console/{assetId?}', SearchConsolePage::class)
            ->whereNumber('assetId')
            ->name('operator.search-console');
        Route::get('/assets/domain/{assetId?}', RetiredAssetTypeRedirectController::class)->name('operator.domain');
        Route::get('/assets/hosting/{assetId?}', RetiredAssetTypeRedirectController::class)->name('operator.hosting');
        Route::livewire('/assets/instagram/{assetId?}', InstagramOverviewPage::class)->name('operator.instagram');

        Route::livewire('/opportunities', OpportunitiesIndex::class)->name('operator.opportunities');
        Route::livewire('/findings', FindingsIndex::class)->name('operator.findings');
        Route::livewire('/recommendations', RecommendationsIndex::class)->name('operator.recommendations');
        Route::livewire('/tasks', TasksIndex::class)->name('operator.tasks');
        Route::livewire('/tasks/{taskId}', TaskShow::class)->name('operator.task');
        Route::livewire('/work/{type}/{workId}', WorkShow::class)
            ->whereIn('type', WorkUrl::types())
            ->name('operator.work.show');
        Route::get('/work/{workId}', LegacyWorkRedirectController::class)
            ->name('operator.work.show.legacy');
        Route::livewire('/activity', ActivityIndex::class)->name('operator.activity');

        Route::livewire('/prospects', ProspectsIndex::class)->name('operator.prospects');
        Route::livewire('/prospects/create', ProspectCreate::class)->name('operator.prospect.create');
        Route::livewire('/prospects/search-profiles', SearchProfilesIndex::class)->name('operator.search-profiles');
        Route::livewire('/prospects/search-profiles/create', SearchProfileForm::class)->name('operator.search-profile.create');
        Route::livewire('/prospects/search-profiles/{profileId}/edit', SearchProfileForm::class)->name('operator.search-profile.edit');
        Route::livewire('/prospects/search-profiles/{profileId}', SearchProfileShow::class)->name('operator.search-profile');
        Route::livewire('/prospects/intent-radar', IntentRadarIndex::class)->name('operator.intent-radar');
        Route::livewire('/prospects/intent-signals/{signalId}', IntentSignalShow::class)->name('operator.intent-signal');
        Route::livewire('/prospects/{prospectId}/convert', ProspectConvert::class)->name('operator.prospect.convert');
        Route::get('/prospects/{prospectId}/reports/{artifactId}/download', [ProspectReportArtifactDownloadController::class, 'download'])
            ->whereNumber('prospectId')
            ->whereNumber('artifactId')
            ->name('operator.prospect.report.pdf');
        Route::livewire('/prospects/{prospectId}', ProspectShow::class)->name('operator.prospect');

        Route::livewire('/settings', SettingsPage::class)->name('operator.settings');
        Route::livewire('/settings/background-operations', BackgroundOperationsPage::class)->name('operator.settings.background-operations');
        Route::livewire('/settings/playbooks/{playbookId}', PlaybookShow::class)->name('operator.settings.playbook');
        Route::livewire('/settings/ai/control-plane', AiControlPlanePage::class)->name('operator.settings.ai.control-plane');
        Route::livewire('/settings/ai/agents', AiAgentsPage::class)->name('operator.settings.ai.agents');
        Route::livewire('/settings/ai/skills', AiSkillsPage::class)->name('operator.settings.ai.skills');
    });
