<?php

namespace App\Providers;

use App\Services\Analyst\AnalystRegistry;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/** Brand workspace analysts: one AI route and one prompt operation per live channel (analyst.<channel>), analysis steps. */
final class AnalystServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnalystRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(AnalystRegistry::class);
        $routes = $this->app->make(AiRouteRegistry::class);
        $prompts = $this->app->make(PromptRegistry::class);
        foreach (AnalystRegistry::CHANNELS as $channel => [, , $name, $description]) {
            if (! $registry->has($channel)) {
                continue;
            }
            $routes->register([
                'key' => AnalystRegistry::routeKey($channel), 'name' => $name, 'module' => 'analyst', 'description' => $description,
                'default_steps' => AiDefaultSteps::analysis(),
            ]);
            $prompts->register(AnalystRegistry::routeKey($channel), (array) config('moxdop-prompts.channel_analyst'));
        }
    }
}
