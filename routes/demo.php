<?php

use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Demo\Assets\AnalyticsPage;
use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\Infrastructure\DomainPage;
use App\Livewire\Demo\Infrastructure\HostingPage;
use App\Livewire\Demo\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
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
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Operations\TaskShow;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])
    ->prefix('app')
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('demo.dashboard');

        Route::livewire('/customers', CustomersIndex::class)->name('demo.customers');
        Route::livewire('/customers/{customerId}', CustomerDetail::class)->name('demo.customer');

        Route::livewire('/brands', BrandsIndex::class)->name('demo.brands');
        Route::livewire('/brands/{brand}', BrandShow::class)->name('demo.brand');

        Route::livewire('/assets', AssetsIndex::class)->name('demo.assets');

        Route::livewire('/integrations', IntegrationsIndex::class)->name('demo.integrations');
        Route::livewire('/integrations/meta', MetaIntegrationPage::class)->name('demo.integrations.meta');

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

        Route::livewire('/findings', FindingsIndex::class)->name('demo.findings');
        Route::livewire('/recommendations', RecommendationsIndex::class)->name('demo.recommendations');
        Route::livewire('/tasks', TasksIndex::class)->name('demo.tasks');
        Route::livewire('/tasks/{taskId}', TaskShow::class)->name('demo.task');
        Route::livewire('/activity', ActivityIndex::class)->name('demo.activity');

        Route::livewire('/settings', SettingsPage::class)->name('demo.settings');
    });
