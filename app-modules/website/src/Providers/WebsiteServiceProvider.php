<?php

namespace MoxDop\Website\Providers;

use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\Website\Collection\Ga4BoundCollector;
use MoxDop\Website\Collection\SearchConsoleBoundCollector;

class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.website.loaded', true);
    }

    public function boot(): void
    {
        $registry = $this->app->make(BoundCollectorRegistry::class);
        $registry->register($this->app->make(SearchConsoleBoundCollector::class));
        $registry->register($this->app->make(Ga4BoundCollector::class));
    }
}
