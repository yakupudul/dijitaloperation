<?php

namespace MoxDop\MetaAds\Providers;

use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Findings\MetaAdsPerformanceBoundEvidenceEvaluator;

/**
 * Meta Ads module — Digital Asset domain, collectors, and evidence rules.
 */
class MetaAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound('moxdop.meta_ads.loaded')) {
            $this->app->instance('moxdop.meta_ads.loaded', true);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'meta-ads');

        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(MetaAdsBoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(MetaAdsPerformanceBoundEvidenceEvaluator::class));
    }
}
