<?php

namespace MoxDop\GoogleAds\Providers;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralDatasetExecutor;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralDatasetExecutorAdapter;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalDatasetExecutor;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;

class GoogleAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.google-ads.loaded', true);

        // Central aliases are isolated from bound request-family IDs. The adapter
        // keeps compatibility concerns local to resource-first execution while the
        // canonical model/status layer continues using RequirementLevel enums.
        $this->app->singleton(GoogleAdsProfessionalDatasetExecutor::class);
        $this->app->singleton(GoogleAdsCentralDatasetExecutor::class);
        $this->app->singleton(GoogleAdsCentralDatasetExecutorAdapter::class);
        $this->app->tag([
            GoogleAdsProfessionalDatasetExecutor::class,
            GoogleAdsCentralDatasetExecutorAdapter::class,
        ], 'collection.dataset_executors');
    }

    public function boot(): void
    {
        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GoogleAdsBoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(GoogleAdsPerformanceBoundEvidenceEvaluator::class));
    }
}
