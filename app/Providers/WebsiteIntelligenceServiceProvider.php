<?php

namespace App\Providers;

use App\Services\Collection\Providers\Website\WordPressRestDatasetExecutor;
use Illuminate\Support\ServiceProvider;

final class WebsiteIntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WordPressRestDatasetExecutor::class);
        $this->app->tag([WordPressRestDatasetExecutor::class], 'collection.dataset_executors');
    }
}
