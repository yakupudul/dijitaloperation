<?php

namespace App\Providers;

use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use Illuminate\Support\ServiceProvider;

final class SearchConsoleCentralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchConsoleCentralDatasetExecutor::class);
        $this->app->tag([
            SearchConsoleCentralDatasetExecutor::class,
        ], 'collection.dataset_executors');
    }
}