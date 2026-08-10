<?php

namespace App\Providers;

use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Roles;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BoundCollectorRegistry::class);
        $this->app->singleton(BoundEvidenceRuleRegistry::class);
        $this->app->singleton(AiRouteRegistry::class);
        $this->app->singleton(AgentProfileRegistry::class);
        $this->app->singleton(SkillRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            return method_exists($user, 'hasRole') && $user->hasRole(Roles::ADMIN)
                ? true
                : null;
        });
    }
}
