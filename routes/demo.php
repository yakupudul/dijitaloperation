<?php

use App\Http\Controllers\Demo\OperatorFileDownloadController;
use App\Http\Controllers\Integrations\WordPressConnectorDownloadController;
use App\Http\Controllers\Operator\GbpReviewCardController;
use App\Http\Controllers\Operator\GbpReviewRepliesPdfController;
use App\Http\Controllers\Operator\MetaLegacyPageRedirectController;
use App\Http\Controllers\Operator\QueriesExportController;
use App\Http\Controllers\Operator\RetiredAssetTypeRedirectController;
use App\Http\Controllers\Operator\WordPressLoginController;
use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\Integrations\AiProviderIntegrationPage;
use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\DataForSeoIntegrationPage;
use App\Livewire\Demo\Integrations\GoogleAdsConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetEdit;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\BrandEdit;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomerEdit;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Livewire\Demo\ProfilePage;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Operator\AiJobsPage;
use App\Livewire\Operator\Assets\AnalyticsPage;
use App\Livewire\Operator\Assets\SearchConsolePage;
use App\Livewire\Operator\DataCenterPage;
use App\Livewire\Operator\Gbp\Desk\BranchPagesPage as GbpBranchPagesPage;
use App\Livewire\Operator\Gbp\Desk\DeskPage as GbpDeskPage;
use App\Livewire\Operator\Gbp\Desk\PhotosPage as GbpPhotosPage;
use App\Livewire\Operator\Gbp\Desk\ProfileFieldsPage as GbpProfileFieldsPage;
use App\Livewire\Operator\Gbp\Desk\ReviewsPage as GbpReviewsPage;
use App\Livewire\Operator\Gbp\PostPlanPage as GbpPostPlanPage;
use App\Livewire\Operator\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Operator\Integrations\DiscoveredAssetsPage;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Livewire\Operator\Integrations\WebsiteDuplicatesPage;
use App\Livewire\Operator\Integrations\WordPressSitesPage;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Library\QueryPlanWizard;
use App\Livewire\Operator\Library\ServiceCatalogPage;
use App\Livewire\Operator\Library\WebsiteStandardsPage;
use App\Livewire\Operator\Meta\AssignPage as MetaAssignPage;
use App\Livewire\Operator\Meta\CampaignPage as MetaCampaignPage;
use App\Livewire\Operator\Meta\DeskPage as MetaDeskPage;
use App\Livewire\Operator\Meta\OverviewPage as MetaOverviewPage;
use App\Livewire\Operator\Meta\StrategyPage as MetaStrategyPage;
use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Livewire\Operator\Settings\ImprovementsPage;
use App\Livewire\Operator\Settings\ReleasesPage;
use App\Livewire\Operator\Settings\SectorPacksPage;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Livewire\Operator\Settings\UsersPage;
use App\Livewire\Operator\Website\V2\WebsiteScreen;
use App\Livewire\Operator\Website\WebsitesIndex;
use App\Livewire\Operator\Winners\LibrariesPage;
use App\Livewire\Operator\Winners\ServicePage as WinnerServicePage;
use App\Livewire\Operator\Winners\WinnersPage;
use App\Livewire\Operator\Work\WorkPage;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Support\Facades\Route;

// MoxDOP v2 (Faz 0): retired operator screens. Old bookmarks land on Bugün instead of a 404.
foreach ([
    '/opportunities', '/findings', '/recommendations', '/tasks', '/tasks/{taskId}', '/work/{workId}', '/work/{type}/{workId}',
    '/alerts', '/archive', '/activity', '/compliance', '/customers/discover',
    '/settings/background-operations', '/settings/costs', '/settings/ai-quality', '/settings/push',
    '/settings/ai/control-plane', '/settings/ai/agents', '/settings/ai/skills', '/integrations/site-connectors',
] as $retiredUri) {
    Route::redirect($retiredUri, '/');
}

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

        Route::livewire('/library/website-standards', WebsiteStandardsPage::class)->name('operator.library.website-standards');

        Route::livewire('/library/services', ServiceCatalogPage::class)->name('operator.library.services');
        Route::livewire('/library/queries', QueriesPage::class)->name('operator.library.queries');
        Route::livewire('/library/queries/plan', QueryPlanWizard::class)->name('operator.library.queries.plan');
        Route::get('/library/queries/export', QueriesExportController::class)->name('operator.library.queries.export');
        // Old per-rescan review links (notifications): every proposal now lives in the Sorgular › Silinecekler tab.
        Route::redirect('/library/queries/review/{review}', '/library/queries?tab=deletions')->where('review', '[0-9]{1,18}')->name('operator.library.queries.review');

        Route::livewire('/assets', AssetsIndex::class)->name('operator.assets');
        Route::livewire('/assets/create', AssetCreate::class)->name('operator.asset.create');
        Route::livewire('/assets/{assetId}/edit', AssetEdit::class)->where('assetId', '[0-9]{1,18}')->name('operator.asset.edit');

        Route::livewire('/integrations', IntegrationsIndex::class)->name('operator.integrations');
        Route::livewire('/integrations/google', GoogleIntegrationPage::class)->name('operator.integrations.google');
        Route::livewire('/integrations/meta', MetaIntegrationPage::class)->name('operator.integrations.meta');
        Route::livewire('/integrations/discovered', DiscoveredAssetsPage::class)->name('operator.integrations.discovered');
        Route::livewire('/integrations/dataforseo', DataForSeoIntegrationPage::class)->name('operator.integrations.dataforseo');
        Route::livewire('/integrations/site-connectors/{connector}', SiteConnectorShow::class)->name('operator.integrations.site-connector');
        Route::get('/integrations/site-connectors/{connector}/download', WordPressConnectorDownloadController::class)
            ->name('operator.integrations.site-connector.download');
        Route::livewire('/integrations/connectors/google-ads', GoogleAdsConnectorPage::class)->name('operator.integrations.google-ads.connector');
        Route::livewire('/integrations/connectors/{connector}', ConnectorPage::class)->name('operator.integrations.connector');
        Route::livewire('/integrations/{provider}', AiProviderIntegrationPage::class)->where('provider', implode('|', AiProviderCatalog::supported()))->name('operator.integrations.ai');

        Route::livewire('/files', FilesIndex::class)->name('operator.files');
        Route::get('/files/{file}/download', OperatorFileDownloadController::class)->name('operator.files.download');
        Route::livewire('/profile', ProfilePage::class)->name('operator.profile');

        Route::livewire('/assets/meta/{assetId?}', MetaOverviewPage::class)->name('operator.meta.overview');
        // Old per-entity Meta pages: kept as named redirects into the matching asset-page tab.
        Route::get('/assets/meta/{assetId}/campaigns', MetaLegacyPageRedirectController::class)->defaults('tab', 'campaigns')->name('operator.meta.campaigns');
        Route::livewire('/assets/meta/{assetId}/campaigns/{campaignId}', MetaCampaignPage::class)->name('operator.meta.campaign');
        Route::livewire('/assets/meta/{assetId}/eslestir', MetaAssignPage::class)->name('operator.meta.assign');
        Route::livewire('/meta', MetaDeskPage::class)->name('operator.meta-desk');
        Route::livewire('/meta/strateji', MetaStrategyPage::class)->name('operator.meta-strategy');
        Route::livewire('/kazananlar', WinnersPage::class)->name('operator.winners');
        Route::livewire('/kazananlar/{serviceId}', WinnerServicePage::class)->whereNumber('serviceId')->name('operator.winner-service');
        Route::livewire('/kutuphaneler', LibrariesPage::class)->name('operator.libraries');
        Route::get('/assets/meta/{assetId}/adsets', MetaLegacyPageRedirectController::class)->defaults('tab', 'campaigns')->defaults('level', 'adsets')->name('operator.meta.adsets');
        Route::get('/assets/meta/{assetId}/adsets/{adSetId}', MetaLegacyPageRedirectController::class)->defaults('tab', 'campaigns')->defaults('level', 'adsets')->name('operator.meta.adset');
        Route::get('/assets/meta/{assetId}/ads', MetaLegacyPageRedirectController::class)->defaults('tab', 'campaigns')->defaults('level', 'ads')->name('operator.meta.ads');
        Route::get('/assets/meta/{assetId}/ads/{adId}', MetaLegacyPageRedirectController::class)->defaults('tab', 'campaigns')->defaults('level', 'ads')->name('operator.meta.ad');
        Route::get('/assets/meta/{assetId}/creatives', MetaLegacyPageRedirectController::class)->defaults('tab', 'creatives')->name('operator.meta.creatives');
        Route::get('/assets/meta/{assetId}/breakdowns', MetaLegacyPageRedirectController::class)->defaults('tab', 'audience')->name('operator.meta.breakdowns');
        Route::get('/assets/meta/{assetId}/insights', MetaLegacyPageRedirectController::class)->defaults('tab', 'overview')->name('operator.meta.insights');

        Route::livewire('/assets/google-ads/{assetId?}', GoogleAdsOverviewPage::class)->name('operator.google-ads.overview');
        Route::livewire('/websites', WebsitesIndex::class)->name('operator.websites');
        Route::livewire('/assets/website/{assetId?}', WebsiteScreen::class)->name('operator.website');
        Route::livewire('/assets/gbp/{assetId?}', GbpOverviewPage::class)->name('operator.gbp');
        Route::livewire('/assets/analytics/{assetId?}', AnalyticsPage::class)
            ->where('assetId', '[0-9]{1,18}')
            ->name('operator.analytics');
        Route::livewire('/assets/search-console/{assetId?}', SearchConsolePage::class)
            ->where('assetId', '[0-9]{1,18}')
            ->name('operator.search-console');
        Route::get('/assets/domain/{assetId?}', RetiredAssetTypeRedirectController::class)->name('operator.domain');
        Route::get('/assets/hosting/{assetId?}', RetiredAssetTypeRedirectController::class)->name('operator.hosting');
        Route::get('/assets/instagram/{assetId?}', RetiredAssetTypeRedirectController::class)->name('operator.instagram');

        Route::livewire('/brands/{brand}/setup', BrandSetupPage::class)->name('operator.brand.setup');

        Route::livewire('/ai-jobs', AiJobsPage::class)->name('operator.ai-jobs');
        Route::livewire('/settings', SettingsPage::class)->name('operator.settings');
        Route::livewire('/settings/system-health', SystemHealthPage::class)->name('operator.settings.system-health');
        Route::livewire('/settings/ai-operations', AiOperationsPage::class)->name('operator.settings.ai-operations');
        Route::livewire('/settings/users', UsersPage::class)->name('operator.settings.users');
        Route::livewire('/settings/improvements', ImprovementsPage::class)->name('operator.settings.improvements');
        Route::livewire('/settings/releases', ReleasesPage::class)->name('operator.settings.releases');
        Route::livewire('/work', WorkPage::class)->name('operator.work');
        Route::livewire('/gbp-posts', GbpPostPlanPage::class)->name('operator.gbp-posts');
        Route::livewire('/gbp', GbpDeskPage::class)->name('operator.gbp-desk');
        Route::livewire('/gbp/sube-sayfalari', GbpBranchPagesPage::class)->name('operator.gbp-branch-pages');
        Route::livewire('/gbp/aciklama-ve-saatler', GbpProfileFieldsPage::class)->name('operator.gbp-profile-fields');
        Route::livewire('/gbp/fotograflar', GbpPhotosPage::class)->name('operator.gbp-photos');
        Route::livewire('/gbp/yorumlar', GbpReviewsPage::class)->name('operator.gbp-reviews');
        Route::get('/gbp/yorumlar/pdf', GbpReviewRepliesPdfController::class)->middleware('throttle:20,1')->name('operator.gbp-review-replies-pdf');
        Route::get('/gbp/yorum-karti/{assetId}', GbpReviewCardController::class)->where('assetId', '[0-9]{1,18}')->name('operator.gbp-review-card');
        Route::livewire('/settings/sector-packs', SectorPacksPage::class)->name('operator.settings.sector-packs');
        Route::livewire('/data-center', DataCenterPage::class)->name('operator.data-center');
        Route::livewire('/integrations/wordpress-sites', WordPressSitesPage::class)->name('operator.integrations.wordpress-sites');
        Route::livewire('/integrations/website-duplicates', WebsiteDuplicatesPage::class)->name('operator.integrations.website-duplicates');
        Route::post('/integrations/wordpress-sites/{site}/login', WordPressLoginController::class)->where('site', '[0-9]{1,18}')->middleware('throttle:10,1')->name('operator.integrations.wordpress-login');
    });
