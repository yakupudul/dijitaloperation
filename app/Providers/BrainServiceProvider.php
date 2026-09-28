<?php

namespace App\Providers;

use App\Services\Brain\Proposals\Kinds\ClusterTargetsKind;
use App\Services\Brain\Proposals\Kinds\MatchingKeywordKind;
use App\Services\Brain\Proposals\Kinds\MetaAdServicesKind;
use App\Services\Brain\Proposals\Kinds\PageFeaturesKind;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Service Brain: the proposal kinds of the review queue and the AI routes they use. Account → sector, query → service
 * and service clustering left the review queue: the query pipeline (App\Services\Queries) does them automatically and
 * the operator corrects them on Sorgular / Keşfedilen varlıklar (manual wins).
 */
final class BrainServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ProposalKind>> */
    private const array KINDS = [
        MatchingKeywordKind::class,
        ClusterTargetsKind::class,
        MetaAdServicesKind::class,
        PageFeaturesKind::class,
    ];

    /** route key => [name, steps kind, description] */
    private const array ROUTES = [
        AiRouteKeys::BRAIN_QUERY_CLASSIFIER => ['Queries: Query → Service', 'classification', 'Daily, in batches: files core queries the service names and matching expressions could not place under one service of their sector, or marks them irrelevant. Operational brands only; operator changes win.'],
        AiRouteKeys::QUERIES_ASSET_SECTOR => ['Queries: Asset Sector', 'classification', 'Daily, in batches: assigns a sector to every discovered account and website that has none (or whose signals changed). Operator changes win.'],
        AiRouteKeys::QUERIES_CLUSTERING => ['Queries: Topic Clusters', 'analysis', 'Weekly or on demand: groups one service\'s core queries into page-sized topic clusters with a page-type guess. Operational brands only.'],
        AiRouteKeys::BRAIN_PAGE_FEATURES => ['Brain: Page Features', 'classification', 'On operator click: reads a service page and fills a fixed checklist (answer-first intro, question headings, sub-questions covered, clinician reviewer, sources).'],
        AiRouteKeys::BRAIN_CREATIVE_CLASSIFIER => ['Brain: Meta Creative → Service', 'classification', 'On operator click: says which service and which message angle each Meta ad is about, from its texts and names.'],
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
                'key' => $key, 'name' => $name, 'module' => 'brain', 'description' => $description,
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
