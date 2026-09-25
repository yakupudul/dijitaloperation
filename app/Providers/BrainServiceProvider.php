<?php

namespace App\Providers;

use App\Services\Brain\Proposals\Kinds\AccountMappingKind;
use App\Services\Brain\Proposals\Kinds\ClusterTargetsKind;
use App\Services\Brain\Proposals\Kinds\MatchingKeywordKind;
use App\Services\Brain\Proposals\Kinds\MetaAdServicesKind;
use App\Services\Brain\Proposals\Kinds\PageFeaturesKind;
use App\Services\Brain\Proposals\Kinds\QueryServiceKind;
use App\Services\Brain\Proposals\Kinds\ServiceClustersKind;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/** Service Brain: the proposal kinds of the review queue and the AI routes they use. */
final class BrainServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ProposalKind>> */
    private const array KINDS = [
        AccountMappingKind::class,
        QueryServiceKind::class,
        MatchingKeywordKind::class,
        ServiceClustersKind::class,
        ClusterTargetsKind::class,
        MetaAdServicesKind::class,
        PageFeaturesKind::class,
    ];

    /** route key => [name, steps kind, description] */
    private const array ROUTES = [
        AiRouteKeys::BRAIN_ACCOUNT_MAPPING => ['Brain: Account → Sector & Services', 'classification', 'Reads the most frequent queries of each Google account and proposes its sector and services. Proposals are reviewed before they are saved.'],
        AiRouteKeys::BRAIN_QUERY_CLASSIFIER => ['Brain: Query → Service', 'classification', 'Assigns library queries the rules and the similarity model could not place to one service and one search intent. Proposals are reviewed before they are saved.'],
        AiRouteKeys::BRAIN_CLUSTER_LABELS => ['Brain: Cluster Names & Page Types', 'classification', 'Names the page-sized query clusters of a service and says whether each is a main service page, supporting content or an FAQ block.'],
        AiRouteKeys::BRAIN_PAGE_FEATURES => ['Brain: Page Features', 'classification', 'Reads a service page and fills a fixed checklist (answer-first intro, question headings, sub-questions covered, clinician reviewer, sources).'],
        AiRouteKeys::BRAIN_CREATIVE_CLASSIFIER => ['Brain: Meta Creative → Service', 'classification', 'Says which service and which message angle each Meta ad is about, from its texts and names.'],
    ];

    public function register(): void
    {
        $this->app->singleton(ProposalService::class);
    }

    public function boot(): void
    {
        $routes = $this->app->make(AiRouteRegistry::class);
        foreach (self::ROUTES as $key => [$name, $steps, $description]) {
            $routes->register([
                'key' => $key, 'name' => $name, 'module' => 'brain', 'description' => 'On operator click: '.$description,
                'default_steps' => $steps === 'classification' ? AiDefaultSteps::classification() : AiDefaultSteps::analysis(),
            ]);
        }
        $routes->register([
            'key' => AiRouteKeys::BRAIN_EMBEDDINGS, 'name' => 'Brain: Embeddings', 'module' => 'brain',
            'description' => 'Turns queries, service names and page titles into vectors for similarity and clustering (cheap; cached per text).',
            'default_steps' => [
                ['provider' => AiProviderCatalog::OPENAI, 'model' => (string) config('moxdop.ai.defaults.embedding_model', 'text-embedding-3-small')],
                ['provider' => AiProviderCatalog::GEMINI, 'model' => (string) config('moxdop.ai.defaults.gemini_embedding_model', 'gemini-embedding-001')],
            ],
        ]);

        $this->app->afterResolving(ProposalService::class, function (ProposalService $service): void {
            foreach (self::KINDS as $class) {
                $service->register($this->app->make($class));
            }
        });
    }
}
