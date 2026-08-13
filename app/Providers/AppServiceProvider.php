<?php

namespace App\Providers;

use App\Services\Collection\Contracts\NormalizedDatasetWriter;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\DefaultRetryPolicy;
use App\Services\Collection\Writers\NullNormalizedDatasetWriter;
use App\Services\Collection\Writers\NullRawPayloadWriter;
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

        $this->app->singleton(DataContractRegistryLoader::class);
        $this->app->singleton(RetryPolicy::class, DefaultRetryPolicy::class);
        $this->app->singleton(RawPayloadWriter::class, NullRawPayloadWriter::class);
        $this->app->singleton(NormalizedDatasetWriter::class, NullNormalizedDatasetWriter::class);
        $this->app->singleton(DatasetExecutorResolver::class, function ($app): DatasetExecutorResolver {
            // Provider-specific executors register later (Prompt 13+). Prompt 9 ships the resolver only.
            return new DatasetExecutorResolver($app->tagged('collection.dataset_executors'));
        });
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
