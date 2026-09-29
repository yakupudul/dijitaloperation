<?php

namespace App\Providers;

use App\Services\Ai\Insights\AiInsightService;
use App\Services\Insights\Definitions\AlertCauseInsight;
use App\Services\Insights\Definitions\MetaGeoInsight;
use App\Services\Insights\Definitions\TechnicalTasksInsight;
use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/** On-click AI insights: definitions and their AI routes (AI Control Plane). */
final class AiInsightServiceProvider extends ServiceProvider
{
    private const array DEFINITIONS = [
        AlertCauseInsight::class => ['Alert Root Cause', 'alerts', 'analysis', 'Compares the daily numbers of all channels around an alert to name its most likely cause.'],
        MetaGeoInsight::class => ['Meta Service × Area × Audience', 'advisor', 'analysis', 'Reads Meta campaign, ad set and ad names with country and city results and ad set targeting to say which service, area and audience brought conversions.'],
        TechnicalTasksInsight::class => ['Website Developer Task List', 'website', 'analysis', 'Turns the website technical observations into a prioritised developer task list.'],
    ];

    public function register(): void
    {
        $this->app->singleton(AiInsightService::class);
    }

    public function boot(): void
    {
        $service = $this->app->make(AiInsightService::class);
        $routes = $this->app->make(AiRouteRegistry::class);
        foreach (self::DEFINITIONS as $class => [$name, $module, $steps, $description]) {
            $definition = new $class;
            $service->register($definition);
            $routes->register([
                'key' => $definition->routeKey(),
                'name' => $name,
                'module' => $module,
                'description' => 'On operator click: '.$description,
                'default_steps' => $steps === 'classification' ? AiDefaultSteps::classification() : AiDefaultSteps::analysis(),
            ]);
        }
    }
}
