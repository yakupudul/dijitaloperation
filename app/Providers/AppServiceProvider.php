<?php

namespace App\Providers;

use App\Events\Collection\CollectionRunCancelled;
use App\Events\Collection\CollectionRunCompleted;
use App\Events\Collection\CollectionRunStarted;
use App\Events\Collection\DatasetRunFailed;
use App\Events\Collection\DatasetRunProgressed;
use App\Events\EvidenceCanonicalized;
use App\Listeners\Collection\BroadcastCollectionRunChanged;
use App\Listeners\QueueFindingEvaluationAfterEvidenceCanonicalized;
use App\Models\Collection\CollectionRun;
use App\Policies\CollectionRunPolicy;
use App\Services\Collection\Contracts\NormalizedDatasetWriter;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\DefaultRetryPolicy;
use App\Services\Collection\Providers\Ga4\Ga4DatasetExecutor;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleDatasetExecutor;
use App\Services\DataPool\Contracts\WarehouseWriter;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\FilesystemRawPayloadWriter;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\DataPool\Integrity\DataIntegrityRegistryLoader;
use App\Services\DataPool\Integrity\DataPoolIntegrityAuditor;
use App\Services\DataPool\Integrity\RealDataMigrationReadinessService;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\PartitionManager;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\StorageContractValidator;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Findings\FindingRuleRegistry;
use App\Services\Formulas\FormulaRegistryLoader;
use App\Services\Formulas\Ga4FormulaCalculator;
use App\Services\Formulas\GoogleAdsFormulaCalculator;
use App\Services\Formulas\GscFormulaCalculator;
use App\Services\Formulas\MetaAdsFormulaCalculator;
use App\Services\Ga4\Ga4PoolReadRepository;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Ga4\Ga4UiDatasetGate;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSpecialistReadService;
use App\Services\GoogleAds\GoogleAdsUiDatasetGate;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistReadService;
use App\Services\Gsc\GscUiDatasetGate;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\MetaAds\MetaAdsPoolReadRepository;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\MetaAdsUiDatasetGate;
use App\Services\Opportunities\OpportunityRuleRegistry;
use App\Services\ServiceScope\CommercialServiceContextProvider;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Roles;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(FindingRuleRegistry::class);
        $this->app->singleton(OpportunityRuleRegistry::class);
        $this->app->singleton(AiRouteRegistry::class);
        $this->app->singleton(AgentProfileRegistry::class);
        $this->app->singleton(SkillRegistry::class);

        $this->app->singleton(DataContractRegistryLoader::class);
        $this->app->singleton(RetryPolicy::class, DefaultRetryPolicy::class);

        $this->app->singleton(DataPoolStorageRegistry::class);
        $this->app->singleton(StorageContractValidator::class);
        $this->app->singleton(PartitionManager::class);
        $this->app->singleton(MaterializationService::class);
        $this->app->singleton(DataIntegrityRegistryLoader::class);
        $this->app->singleton(RealDataMigrationReadinessService::class);
        $this->app->singleton(DataPoolIntegrityAuditor::class);
        $this->app->singleton(DataFreshnessPolicyLoader::class);
        $this->app->singleton(IncrementalCoveragePlanner::class);
        $this->app->singleton(DueCollectionQueryService::class);
        $this->app->singleton(StartIncrementalCollectionService::class);

        $this->app->singleton(FormulaRegistryLoader::class);
        $this->app->singleton(Ga4FormulaCalculator::class);
        $this->app->singleton(Ga4SpecialistBindingResolver::class);
        $this->app->singleton(Ga4PoolReadRepository::class);
        $this->app->singleton(Ga4UiDatasetGate::class);
        $this->app->singleton(Ga4SpecialistReadService::class);

        $this->app->singleton(GscFormulaCalculator::class);
        $this->app->singleton(GscSpecialistBindingResolver::class);
        $this->app->singleton(GscPoolReadRepository::class);
        $this->app->singleton(GscUiDatasetGate::class);
        $this->app->singleton(GscSpecialistReadService::class);

        $this->app->singleton(GoogleAdsFormulaCalculator::class);
        $this->app->singleton(GoogleAdsSpecialistBindingResolver::class);
        $this->app->singleton(GoogleAdsPoolReadRepository::class);
        $this->app->singleton(GoogleAdsUiDatasetGate::class);
        $this->app->singleton(GoogleAdsSpecialistReadService::class);

        $this->app->singleton(MetaAdsFormulaCalculator::class);
        $this->app->singleton(MetaAdsSpecialistBindingResolver::class);
        $this->app->singleton(MetaAdsPoolReadRepository::class);
        $this->app->singleton(MetaAdsUiDatasetGate::class);
        $this->app->singleton(MetaAdsSpecialistReadService::class);

        $this->app->singleton(CustomerServiceScopeService::class);
        $this->app->singleton(CustomerServiceScopeReadService::class);
        $this->app->singleton(CommercialServiceContextProvider::class);

        $this->app->singleton(RawPayloadWriter::class, FilesystemRawPayloadWriter::class);
        $this->app->singleton(PostgresWarehouseWriter::class);
        $this->app->singleton(WarehouseWriter::class, PostgresWarehouseWriter::class);
        $this->app->singleton(NormalizedDatasetWriter::class, PostgresWarehouseWriter::class);

        $this->app->singleton(SearchConsoleDatasetExecutor::class);
        $this->app->singleton(Ga4DatasetExecutor::class);
        $this->app->singleton(GoogleAdsDatasetExecutor::class);
        $this->app->singleton(MetaAdsDatasetExecutor::class);
        $this->app->tag([
            SearchConsoleDatasetExecutor::class,
            Ga4DatasetExecutor::class,
            GoogleAdsDatasetExecutor::class,
            MetaAdsDatasetExecutor::class,
        ], 'collection.dataset_executors');

        $this->app->singleton(DatasetExecutorResolver::class, function ($app): DatasetExecutorResolver {
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

        Gate::policy(CollectionRun::class, CollectionRunPolicy::class);

        $broadcast = BroadcastCollectionRunChanged::class;
        Event::listen(CollectionRunStarted::class, [$broadcast, 'handleStarted']);
        Event::listen(CollectionRunCompleted::class, [$broadcast, 'handleCompleted']);
        Event::listen(CollectionRunCancelled::class, [$broadcast, 'handleCancelled']);
        Event::listen(DatasetRunFailed::class, [$broadcast, 'handleDatasetFailed']);
        Event::listen(DatasetRunProgressed::class, [$broadcast, 'handleDatasetProgressed']);
        Event::listen(EvidenceCanonicalized::class, QueueFindingEvaluationAfterEvidenceCanonicalized::class);
    }
}
