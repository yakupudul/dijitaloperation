<?php

namespace App\Providers;

use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use Illuminate\Support\ServiceProvider;

final class GoogleBusinessProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleBusinessProfileBoundCollector::class);
    }

    public function boot(
        BoundCollectorRegistry $registry,
        GoogleBusinessProfileBoundCollector $collector,
    ): void {
        $registry->register($collector);
    }
}
