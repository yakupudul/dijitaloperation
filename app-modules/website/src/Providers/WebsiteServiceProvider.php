<?php

namespace MoxDop\Website\Providers;

use Illuminate\Support\ServiceProvider;

class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('moxdop.website.loaded', true);
    }

    public function boot(): void
    {
        // Intentionally empty: module scaffold only. No product UI or business logic.
    }
}
