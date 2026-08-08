<?php

namespace MoxDop\GoogleBusinessProfile\Providers;

use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;

class GoogleBusinessProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.google-business-profile.loaded', true);
    }

    public function boot(): void
    {
        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GbpLocationBoundCollector::class));
    }
}
