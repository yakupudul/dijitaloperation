<?php

namespace MoxDop\SampleModule\Providers;

use Illuminate\Support\ServiceProvider;

class SampleModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.sample-module.loaded', true);
    }

    public function boot(): void
    {
        // Intentionally empty: smoke-test module only. No product UI or business logic.
    }
}
