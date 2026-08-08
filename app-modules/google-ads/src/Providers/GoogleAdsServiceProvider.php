<?php

namespace MoxDop\GoogleAds\Providers;

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
    }

    public function boot(): void
    {
        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GoogleAdsBoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(GoogleAdsPerformanceBoundEvidenceEvaluator::class));
    }
}
