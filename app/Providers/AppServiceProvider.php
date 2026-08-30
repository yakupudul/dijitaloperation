<?php

namespace App\Providers;

use App\Contracts\Ai\AgentContextGateway as AgentContextGatewayContract;
use App\Contracts\IntelligenceMemory\BrandMemoryContextProvider;
use App\Contracts\IntelligenceMemory\IntelligenceMemoryAccessPolicy as IntelligenceMemoryAccessPolicyContract;
use App\Contracts\IntelligenceMemory\IntelligenceMemoryGateway as IntelligenceMemoryGatewayContract;
use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Contracts\IntelligenceMemory\SectorMemoryContextProvider;
use App\Contracts\IntelligenceMemory\SkillKnowledgeContextProvider;
use App\Events\Collection\CollectionRunCancelled;
use App\Events\Collection\CollectionRunCompleted;
use App\Events\Collection\CollectionRunStarted;
use App\Events\Collection\DatasetRunFailed;
use App\Events\Collection\DatasetRunProgressed;
use App\Events\EvidenceCanonicalized;
use App\Listeners\Collection\BroadcastCollectionRunChanged;
use App\Listeners\Collection\QueueWebsiteAnalysisAfterCollection;
use App\Listeners\QueueFindingEvaluationAfterEvidenceCanonicalized;
use App\Models\Collection\CollectionRun;
use App\Policies\CollectionRunPolicy;
use App\Services\Ai\AgentContextGateway;
use App\Services\Assistant\Adapters\GoogleAdsAssistantReadAdapter;
use App\Services\Assistant\AssistantAnswerGroundingValidator;
use App\Services\Assistant\AssistantBoundaryGuard;
use App\Services\Assistant\AssistantCapabilityExecutor;
use App\Services\Assistant\AssistantDateRangeResolver;
use App\Services\Assistant\AssistantIntentInterpreter;
use App\Services\Assistant\AssistantIntentValidator;
use App\Services\Assistant\AssistantQueryPlanner;
use App\Services\Assistant\AssistantScopeResolver;
use App\Services\Assistant\MoxdopAssistantService;
use App\Services\BrandExperiences\BrandExperienceEvidenceQualityEvaluator;
use App\Services\BrandExperiences\BrandExperienceReadService;
use App\Services\BrandExperiences\BrandExperienceSectorContributionBuilder;
use App\Services\BrandExperiences\BrandExperienceService;
use App\Services\BusinessOutcomes\BusinessOutcomeAggregateService;
use App\Services\BusinessOutcomes\BusinessOutcomeCsvImportService;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\Collection\Contracts\NormalizedDatasetWriter;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\DefaultRetryPolicy;
use App\Services\Collection\Providers\DataForSeo\DataForSeoDatasetExecutor;
use App\Services\Collection\Providers\Ga4\Ga4DatasetExecutor;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleDatasetExecutor;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
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
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationAdvisoryJudge;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationAssertionEngine;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationBaselineService;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationBoundaryGuard;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationContractFactory;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationHumanReviewService;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationMockedOutputFactory;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationRegressionComparer;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationRetrievalMetricsCalculator;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationRunner;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationSyntheticFixtureBuilder;
use App\Services\IntelligenceMemory\CanonicalSkillKnowledgeContextProvider;
use App\Services\IntelligenceMemory\ExperienceBrandMemoryContextProvider;
use App\Services\IntelligenceMemory\IntelligenceMemoryAccessPolicy;
use App\Services\IntelligenceMemory\IntelligenceMemoryArchitectureAuditor;
use App\Services\IntelligenceMemory\IntelligenceMemoryAuthority;
use App\Services\IntelligenceMemory\IntelligenceMemoryGateway;
use App\Services\IntelligenceMemory\OperatorConfirmedSectorIdentityResolver;
use App\Services\IntelligenceMemory\ReleasedSectorMemoryContextProvider;
use App\Services\IntelligenceRetrieval\BrandExperienceRetriever;
use App\Services\IntelligenceRetrieval\IntelligenceContextReferenceValidator;
use App\Services\IntelligenceRetrieval\IntelligenceRetrievalService;
use App\Services\IntelligenceRetrieval\RelevantGoalRetriever;
use App\Services\IntelligenceRetrieval\SectorPatternRetriever;
use App\Services\MetaAds\MetaAdsPoolReadRepository;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\MetaAdsUiDatasetGate;
use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorMailConfigService;
use App\Services\Opportunities\OpportunityRuleRegistry;
use App\Services\SectorLearning\ProductionSectorLearningPrivacyGate;
use App\Services\SectorLearning\SectorLearningAggregatorService;
use App\Services\SectorLearning\SectorLearningArtifactService;
use App\Services\SectorLearning\SectorLearningAuditService;
use App\Services\SectorLearning\SectorLearningContributionBounder;
use App\Services\SectorLearning\SectorLearningContributionProjector;
use App\Services\SectorLearning\SectorLearningContributionRepository;
use App\Services\SectorLearning\SectorMemoryReadService;
use App\Services\ServiceScope\CommercialServiceContextProvider;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Assistant\AssistantCapabilityRegistry;
use App\Support\Assistant\AssistantEvaluationHooks;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\BusinessOutcomes\BusinessOutcomeKindRegistry;
use App\Support\IntelligenceMemory\AgentMemoryPermissionCatalog;
use App\Support\IntelligenceMemory\SkillMemoryContractResolver;
use App\Support\IntelligenceMemory\SkillMemoryCustomerDataGuard;
use App\Support\Roles;
use App\Support\Skills\SkillRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AgencySettingService::class);
        $this->app->singleton(OperatorMailConfigService::class);

        $this->app->singleton(BoundCollectorRegistry::class);
        $this->app->singleton(BoundEvidenceRuleRegistry::class);
        $this->app->singleton(FindingRuleRegistry::class);
        $this->app->singleton(OpportunityRuleRegistry::class);
        $this->app->singleton(AiRouteRegistry::class);
        $this->app->singleton(AgentProfileRegistry::class);
        $this->app->singleton(SkillRegistry::class);
        $this->app->singleton(AgentContextGatewayContract::class, AgentContextGateway::class);

        $this->app->singleton(SkillMemoryCustomerDataGuard::class);
        $this->app->singleton(AgentMemoryPermissionCatalog::class);
        $this->app->singleton(SkillMemoryContractResolver::class);
        $this->app->singleton(IntelligenceMemoryAuthority::class);
        $this->app->singleton(IntelligenceMemoryArchitectureAuditor::class);
        $this->app->singleton(SectorIdentityResolver::class, OperatorConfirmedSectorIdentityResolver::class);
        $this->app->singleton(SectorLearningPrivacyGate::class, ProductionSectorLearningPrivacyGate::class);
        $this->app->singleton(SectorLearningContributionProjector::class);
        $this->app->singleton(SectorLearningContributionBounder::class);
        $this->app->singleton(SectorLearningContributionRepository::class);
        $this->app->singleton(SectorLearningAggregatorService::class);
        $this->app->singleton(SectorLearningArtifactService::class);
        $this->app->singleton(SectorMemoryReadService::class);
        $this->app->singleton(SectorLearningAuditService::class);
        $this->app->singleton(BrandExperienceRetriever::class);
        $this->app->singleton(SectorPatternRetriever::class);
        $this->app->singleton(RelevantGoalRetriever::class);
        $this->app->singleton(IntelligenceContextReferenceValidator::class);
        $this->app->singleton(IntelligenceRetrievalService::class);
        $this->app->singleton(IntelligenceEvaluationSyntheticFixtureBuilder::class);
        $this->app->singleton(IntelligenceEvaluationContractFactory::class);
        $this->app->singleton(IntelligenceEvaluationRetrievalMetricsCalculator::class);
        $this->app->singleton(IntelligenceEvaluationAssertionEngine::class);
        $this->app->singleton(IntelligenceEvaluationMockedOutputFactory::class);
        $this->app->singleton(IntelligenceEvaluationBoundaryGuard::class);
        $this->app->singleton(IntelligenceEvaluationAdvisoryJudge::class);
        $this->app->singleton(IntelligenceEvaluationHumanReviewService::class);
        $this->app->singleton(IntelligenceEvaluationBaselineService::class);
        $this->app->singleton(IntelligenceEvaluationRegressionComparer::class);
        $this->app->singleton(IntelligenceEvaluationRunner::class);
        $this->app->singleton(AssistantCapabilityRegistry::class);
        $this->app->singleton(AssistantMetricRegistry::class);
        $this->app->singleton(AssistantSourceAuthority::class);
        $this->app->singleton(AssistantEvaluationHooks::class);
        $this->app->singleton(AssistantDateRangeResolver::class);
        $this->app->singleton(AssistantScopeResolver::class);
        $this->app->singleton(AssistantIntentInterpreter::class);
        $this->app->singleton(AssistantIntentValidator::class);
        $this->app->singleton(AssistantQueryPlanner::class);
        $this->app->singleton(AssistantAnswerGroundingValidator::class);
        $this->app->singleton(AssistantBoundaryGuard::class);
        $this->app->singleton(GoogleAdsAssistantReadAdapter::class);
        $this->app->singleton(AssistantCapabilityExecutor::class);
        $this->app->singleton(MoxdopAssistantService::class);
        $this->app->singleton(BusinessOutcomeKindRegistry::class);
        $this->app->singleton(BusinessOutcomeDefinitionService::class);
        $this->app->singleton(BusinessOutcomeObservationService::class);
        $this->app->singleton(BusinessOutcomeAggregateService::class);
        $this->app->singleton(BusinessOutcomeReadService::class);
        $this->app->singleton(BusinessOutcomeCsvImportService::class);
        $this->app->singleton(ClientValueStoryReadService::class);
        $this->app->singleton(BrandExperienceEvidenceQualityEvaluator::class);
        $this->app->singleton(BrandExperienceService::class);
        $this->app->singleton(BrandExperienceReadService::class);
        $this->app->singleton(BrandExperienceSectorContributionBuilder::class);
        $this->app->singleton(BrandMemoryContextProvider::class, ExperienceBrandMemoryContextProvider::class);
        $this->app->singleton(SectorMemoryContextProvider::class, ReleasedSectorMemoryContextProvider::class);
        $this->app->singleton(SkillKnowledgeContextProvider::class, CanonicalSkillKnowledgeContextProvider::class);
        $this->app->singleton(IntelligenceMemoryAccessPolicyContract::class, IntelligenceMemoryAccessPolicy::class);
        $this->app->singleton(IntelligenceMemoryGatewayContract::class, IntelligenceMemoryGateway::class);

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
        $this->app->singleton(WebsiteDatasetExecutor::class);
        $this->app->singleton(DataForSeoDatasetExecutor::class);
        $this->app->tag([
            SearchConsoleDatasetExecutor::class,
            Ga4DatasetExecutor::class,
            GoogleAdsDatasetExecutor::class,
            MetaAdsDatasetExecutor::class,
            WebsiteDatasetExecutor::class,
            DataForSeoDatasetExecutor::class,
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

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Prompt65: N+1 detection in local only. Performance tests enable explicitly.
        // Do not abort the PHPUnit suite — production stays unchanged.
        if ($this->app->environment('local')) {
            Model::preventLazyLoading();
        }

        $broadcast = BroadcastCollectionRunChanged::class;
        Event::listen(CollectionRunStarted::class, [$broadcast, 'handleStarted']);
        Event::listen(CollectionRunCompleted::class, [$broadcast, 'handleCompleted']);
        Event::listen(CollectionRunCompleted::class, QueueWebsiteAnalysisAfterCollection::class);
        Event::listen(CollectionRunCancelled::class, [$broadcast, 'handleCancelled']);
        Event::listen(DatasetRunFailed::class, [$broadcast, 'handleDatasetFailed']);
        Event::listen(DatasetRunProgressed::class, [$broadcast, 'handleDatasetProgressed']);
        Event::listen(EvidenceCanonicalized::class, QueueFindingEvaluationAfterEvidenceCanonicalized::class);

        View::composer([
            'operator.layouts.app',
            'operator.layouts.sidebar',
            'operator.auth.login',
            'operator.auth.forgot-password',
            'operator.auth.reset-password',
        ], function ($view): void {
            $view->with('operatorBranding', app(AgencySettingService::class)->branding());
        });

        $this->app->booted(function (): void {
            app(OperatorMailConfigService::class)->applyToRuntime();
        });
    }
}
