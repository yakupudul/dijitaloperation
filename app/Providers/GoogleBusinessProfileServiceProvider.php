<?php

namespace App\Providers;

use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Integrations\Google\GoogleBusinessProfileRetentionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class GoogleBusinessProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleBusinessProfileBoundCollector::class);
        $this->app->singleton(GoogleBusinessProfileRetentionService::class);
    }

    public function boot(
        BoundCollectorRegistry $registry,
        GoogleBusinessProfileBoundCollector $collector,
        GoogleBusinessProfileRetentionService $retention,
    ): void {
        $registry->register($collector);

        $retentionKey = 'gbp:content-retention:'.now()->toDateString();
        if (Cache::add($retentionKey, true, now()->addDay())) {
            $retention->purgeExpired();
        }
    }
}
