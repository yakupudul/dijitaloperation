<?php

namespace App\Providers;

use App\Services\Collection\Providers\Website\WordPressConnectorDatasetExecutor;
use Illuminate\Support\ServiceProvider;

final class WebsiteIntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WordPressConnectorDatasetExecutor::class);
        $this->app->tag([WordPressConnectorDatasetExecutor::class], 'collection.dataset_executors');
    }
}
