<?php

namespace MoxDop\GoogleAds\Providers;

use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;

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
    }
}
