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
use App\Livewire\Demo\Instagram\OverviewPage as InstagramOverviewPage;
use App\Livewire\Demo\Integrations\AiProviderIntegrationPage;
use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\DataForSeoIntegrationPage;
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
use App\Livewire\Demo\Sales\ProspectCreate;
use App\Livewire\Demo\Sales\ProspectShow;
use App\Livewire\Demo\Sales\ProspectsIndex;
use App\Livewire\Demo\Settings\AiAgentsPage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Livewire\Demo\Settings\AiSkillsPage;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Support\Work\WorkUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])
    ->prefix('app')
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

        Route::livewire('/assets', AssetsIndex::class)->name('operator.assets');
        Route::livewire('/assets/create', AssetCreate::class)->name('operator.asset.create');

        Route::livewire('/setup', PortfolioSetupWizard::class)->name('operator.setup');

        Route::livewire('/integrations', IntegrationsIndex::class)->name('operator.integrations');
        Route::livewire('/integrations/google', GoogleIntegrationPage::class)->name('operator.integrations.google');
        Route::livewire('/integrations/meta', MetaIntegrationPage::class)->name('operator.integrations.meta');
        Route::livewire('/integrations/dataforseo', DataForSeoIntegrationPage::class)->name('operator.integrations.dataforseo');
        Route::livewire('/integrations/site-connectors', SiteConnectorsIndex::class)->name('operator.integrations.site-connectors');
        Route::livewire('/integrations/site-connectors/{connector}', SiteConnectorShow::class)->name('operator.integrations.site-connector');
        Route::get('/integrations/site-connectors/{connector}/download', SiteConnectorDownloadController::class)
            ->name('operator.integrations.site-connector.download');
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
        Route::livewire('/assets/analytics/{assetId?}', AnalyticsPage::class)->name('operator.analytics');
        Route::livewire('/assets/search-console/{assetId?}', SearchConsolePage::class)->name('operator.search-console');
        Route::get('/assets/domain/{assetId?}', fn () => new RedirectResponse(route('operator.assets'), 302))->name('operator.domain');
        Route::get('/assets/hosting/{assetId?}', fn () => new RedirectResponse(route('operator.assets'), 302))->name('operator.hosting');
        Route::livewire('/assets/instagram/{assetId?}', InstagramOverviewPage::class)->name('operator.instagram');

        Route::livewire('/opportunities', OpportunitiesIndex::class)->name('operator.opportunities');
        Route::livewire('/findings', FindingsIndex::class)->name('operator.findings');
        Route::livewire('/recommendations', RecommendationsIndex::class)->name('operator.recommendations');
        Route::livewire('/tasks', TasksIndex::class)->name('operator.tasks');
        Route::livewire('/tasks/{taskId}', TaskShow::class)->name('operator.task');
        Route::livewire('/work/{type}/{workId}', WorkShow::class)
            ->whereIn('type', WorkUrl::types())
            ->name('operator.work.show');
        Route::get('/work/{workId}', function (string $workId) {
            $type = request()->query('type');
            if (! is_string($type) || ! WorkUrl::isType($type)) {
                abort(404);
            }

            return redirect()->route('operator.work.show', WorkUrl::parameters($type, $workId));
        })->name('operator.work.show.legacy');
        Route::livewire('/activity', ActivityIndex::class)->name('operator.activity');

        Route::livewire('/prospects', ProspectsIndex::class)->name('operator.prospects');
        Route::livewire('/prospects/create', ProspectCreate::class)->name('operator.prospect.create');
        Route::livewire('/prospects/{prospectId}', ProspectShow::class)->name('operator.prospect');

        Route::livewire('/settings', SettingsPage::class)->name('operator.settings');
        Route::livewire('/settings/playbooks/{playbookId}', PlaybookShow::class)->name('operator.settings.playbook');
        Route::livewire('/settings/ai/control-plane', AiControlPlanePage::class)->name('operator.settings.ai.control-plane');
        Route::livewire('/settings/ai/agents', AiAgentsPage::class)->name('operator.settings.ai.agents');
        Route::livewire('/settings/ai/skills', AiSkillsPage::class)->name('operator.settings.ai.skills');
    });
