<?php

namespace MoxDop\GoogleBusinessProfile\Providers;

use App\Contracts\GbpOperatorWorkspace as GbpOperatorWorkspaceContract;
use App\Services\Integrations\BoundCollectorRegistry;
use Illuminate\Support\ServiceProvider;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;
use MoxDop\GoogleBusinessProfile\Workspace\OperatorGbpWorkspace;

class GoogleBusinessProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.google-business-profile.loaded', true);
        $this->app->singleton(GbpOperatorWorkspaceContract::class, OperatorGbpWorkspace::class);
    }

    public function boot(): void
    {
        $this->app->make(BoundCollectorRegistry::class)
            ->register($this->app->make(GbpLocationBoundCollector::class));
    }
}
