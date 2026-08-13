<?php

use App\Http\Controllers\Demo\OperatorFileDownloadController;
use App\Http\Controllers\Demo\SiteConnectorDownloadController;
use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Demo\Assets\AnalyticsPage;
use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Demo\Infrastructure\DomainPage;
use App\Livewire\Demo\Infrastructure\HostingPage;
use App\Livewire\Demo\Instagram\OverviewPage as InstagramOverviewPage;
use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Demo\Integrations\SiteConnectorShow;
use App\Livewire\Demo\Integrations\SiteConnectorsIndex;
use App\Livewire\Demo\Meta\AdDetailPage;
use App\Livewire\Demo\Meta\AdSetDetailPage;
use App\Livewire\Demo\Meta\AdSetsPage;
use App\Livewire\Demo\Meta\AdsPage;
use App\Livewire\Demo\Meta\BreakdownsPage;
use App\Livewire\Demo\Meta\CampaignDetailPage;
use App\Livewire\Demo\Meta\CampaignsPage;
use App\Livewire\Demo\Meta\CreativesPage;
use App\Livewire\Demo\Meta\InsightsPage;
use App\Livewire\Demo\Meta\OverviewPage as MetaOverviewPage;
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
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomerEdit;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Livewire\Demo\Portfolio\PortfolioSetupWizard;
use App\Livewire\Demo\ProfilePage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Livewire\Demo\Settings\AiAgentsPage;
use App\Livewire\Demo\Settings\AiSkillsPage;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])
    ->prefix('app')
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('demo.dashboard');

        Route::livewire('/customers', CustomersIndex::class)->name('demo.customers');
        Route::livewire('/customers/create', CustomerCreate::class)->name('demo.customer.create');
        Route::livewire('/customers/{customerId}/edit', CustomerEdit::class)->name('demo.customer.edit');
        Route::livewire('/customers/{customerId}', CustomerDetail::class)->name('demo.customer');

        Route::livewire('/brands', BrandsIndex::class)->name('demo.brands');
        Route::livewire('/brands/create', BrandCreate::class)->name('demo.brand.create');
        Route::livewire('/brands/{brandId}/edit', BrandEdit::class)->name('demo.brand.edit');
        Route::livewire('/brands/{brand}', BrandShow::class)->name('demo.brand');

        Route::livewire('/assets', AssetsIndex::class)->name('demo.assets');
        Route::livewire('/assets/create', AssetCreate::class)->name('demo.asset.create');

        Route::livewire('/setup', PortfolioSetupWizard::class)->name('demo.setup');

        Route::livewire('/integrations', IntegrationsIndex::class)->name('demo.integrations');
        Route::livewire('/integrations/google', GoogleIntegrationPage::class)->name('demo.integrations.google');
        Route::livewire('/integrations/meta', MetaIntegrationPage::class)->name('demo.integrations.meta');
        Route::livewire('/integrations/site-connectors', SiteConnectorsIndex::class)->name('demo.integrations.site-connectors');
        Route::livewire('/integrations/site-connectors/{connector}', SiteConnectorShow::class)->name('demo.integrations.site-connector');
        Route::get('/integrations/site-connectors/{connector}/download', SiteConnectorDownloadController::class)
            ->name('demo.integrations.site-connector.download');
        Route::livewire('/integrations/connectors/{connector}', ConnectorPage::class)->name('demo.integrations.connector');

        Route::livewire('/files', FilesIndex::class)->name('demo.files');
        Route::get('/files/{file}/download', OperatorFileDownloadController::class)->name('demo.files.download');
        Route::livewire('/profile', ProfilePage::class)->name('demo.profile');

        Route::livewire('/assets/meta/{assetId?}', MetaOverviewPage::class)->name('demo.meta.overview');
        Route::livewire('/assets/meta/{assetId}/campaigns', CampaignsPage::class)->name('demo.meta.campaigns');
        Route::livewire('/assets/meta/{assetId}/campaigns/{campaignId}', CampaignDetailPage::class)->name('demo.meta.campaign');
        Route::livewire('/assets/meta/{assetId}/adsets', AdSetsPage::class)->name('demo.meta.adsets');
        Route::livewire('/assets/meta/{assetId}/adsets/{adSetId}', AdSetDetailPage::class)->name('demo.meta.adset');
        Route::livewire('/assets/meta/{assetId}/ads', AdsPage::class)->name('demo.meta.ads');
        Route::livewire('/assets/meta/{assetId}/ads/{adId}', AdDetailPage::class)->name('demo.meta.ad');
        Route::livewire('/assets/meta/{assetId}/creatives', CreativesPage::class)->name('demo.meta.creatives');
        Route::livewire('/assets/meta/{assetId}/breakdowns', BreakdownsPage::class)->name('demo.meta.breakdowns');
        Route::livewire('/assets/meta/{assetId}/insights', InsightsPage::class)->name('demo.meta.insights');

        Route::livewire('/assets/google-ads/{assetId?}', GoogleAdsOverviewPage::class)->name('demo.google-ads.overview');
        Route::livewire('/assets/website/{assetId?}', WebsiteOverviewPage::class)->name('demo.website');
        Route::livewire('/assets/gbp/{assetId?}', GbpOverviewPage::class)->name('demo.gbp');
        Route::livewire('/assets/analytics/{assetId?}', AnalyticsPage::class)->name('demo.analytics');
        Route::livewire('/assets/search-console/{assetId?}', SearchConsolePage::class)->name('demo.search-console');
        Route::livewire('/assets/domain/{assetId?}', DomainPage::class)->name('demo.domain');
        Route::livewire('/assets/hosting/{assetId?}', HostingPage::class)->name('demo.hosting');
        Route::livewire('/assets/instagram/{assetId?}', InstagramOverviewPage::class)->name('demo.instagram');

        Route::livewire('/opportunities', OpportunitiesIndex::class)->name('demo.opportunities');
        Route::livewire('/findings', FindingsIndex::class)->name('demo.findings');
        Route::livewire('/recommendations', RecommendationsIndex::class)->name('demo.recommendations');
        Route::livewire('/tasks', TasksIndex::class)->name('demo.tasks');
        Route::livewire('/tasks/{taskId}', TaskShow::class)->name('demo.task');
        Route::livewire('/work/{workId}', WorkShow::class)->name('demo.work.show');
        Route::livewire('/activity', ActivityIndex::class)->name('demo.activity');

        Route::livewire('/settings', SettingsPage::class)->name('demo.settings');
        Route::livewire('/settings/playbooks/{playbookId}', PlaybookShow::class)->name('demo.settings.playbook');
        Route::livewire('/settings/ai/control-plane', AiControlPlanePage::class)->name('demo.settings.ai.control-plane');
        Route::livewire('/settings/ai/agents', AiAgentsPage::class)->name('demo.settings.ai.agents');
        Route::livewire('/settings/ai/skills', AiSkillsPage::class)->name('demo.settings.ai.skills');
    });
