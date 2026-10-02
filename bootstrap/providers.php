<?php

use App\Providers\AdvisorServiceProvider;
use App\Providers\AiInsightServiceProvider;
use App\Providers\AnalystServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IntelligenceCoreServiceProvider;
use App\Providers\MetaAdsCollectionServiceProvider;
use App\Providers\RecurringAutomationServiceProvider;
use App\Providers\SearchConsoleCentralServiceProvider;
use App\Providers\SearchConsoleGroupingServiceProvider;
use App\Providers\SeoTasksServiceProvider;
use App\Providers\SiteScreenServiceProvider;
use App\Providers\SiteServiceProvider;
use App\Providers\WebsiteIntelligenceServiceProvider;

return [
    AppServiceProvider::class,
    IntelligenceCoreServiceProvider::class,
    MetaAdsCollectionServiceProvider::class,
    SearchConsoleCentralServiceProvider::class,
    SearchConsoleGroupingServiceProvider::class,
    WebsiteIntelligenceServiceProvider::class,
    AppPanelProvider::class,
    HorizonServiceProvider::class,
    RecurringAutomationServiceProvider::class,
    SeoTasksServiceProvider::class,
    SiteServiceProvider::class,
    AdvisorServiceProvider::class,
    AiInsightServiceProvider::class,
    AnalystServiceProvider::class,
    SiteScreenServiceProvider::class,
];
