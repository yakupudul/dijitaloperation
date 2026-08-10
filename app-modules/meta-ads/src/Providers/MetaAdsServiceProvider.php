<?php

namespace MoxDop\MetaAds\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Meta Ads module — V1 connection / Digital Asset domain surface only.
 *
 * Does not own Meta Graph credentials, HTTP client, or resource discovery
 * (those live in Core Integration). Does not register AI guidance, Skills,
 * collectors, or Insights yet.
 */
class MetaAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'meta-ads');

        if (! $this->app->bound('moxdop.meta_ads.loaded')) {
            $this->app->instance('moxdop.meta_ads.loaded', true);
        }
    }
}
