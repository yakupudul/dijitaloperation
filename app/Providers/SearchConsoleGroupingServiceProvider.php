<?php

namespace App\Providers;

use App\Services\Integrations\Google\SearchConsolePropertyGroupingService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as IlluminateView;

final class SearchConsoleGroupingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchConsolePropertyGroupingService::class);
    }

    public function boot(): void
    {
        View::composer('livewire.demo.integrations.connector-page', function (IlluminateView $view): void {
            $payload = $view->getData();
            $data = $payload['data'] ?? null;

            if (! is_array($data) || ($data['id'] ?? null) !== 'gsc') {
                return;
            }

            $rawResources = $payload['resources'] ?? [];
            if (! is_array($rawResources)) {
                return;
            }

            $grouped = app(SearchConsolePropertyGroupingService::class)->group($rawResources);
            $rawCount = count($rawResources);
            $groupCount = count($grouped);

            $data['provider_resources_count'] = $rawCount;
            $data['site_groups_count'] = $groupCount;
            $data['resources_count'] = $groupCount === $rawCount
                ? $groupCount
                : $groupCount.' site · '.$rawCount.' Google';
            $data['resources'] = $grouped;

            $view->with('data', $data);
            $view->with('resources', $grouped);
        });
    }
}
