<?php

namespace MoxDop\Website\Providers;

use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\Website\Collection\Ga4BoundCollector;
use MoxDop\Website\Collection\SearchConsoleBoundCollector;
use MoxDop\Website\Findings\WebsitePerformanceBoundEvidenceEvaluator;

class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.website.loaded', true);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'website');

        $registry = $this->app->make(BoundCollectorRegistry::class);
        $registry->register($this->app->make(SearchConsoleBoundCollector::class));
        $registry->register($this->app->make(Ga4BoundCollector::class));

        $this->app->make(BoundEvidenceRuleRegistry::class)
            ->register($this->app->make(WebsitePerformanceBoundEvidenceEvaluator::class));
    }
}
