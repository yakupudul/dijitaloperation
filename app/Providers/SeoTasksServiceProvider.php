<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the brand setup AI route ("Otomatik kur"), the Faz 2 ownership routes (brand candidates, services) and the
 * Faz 3 Sorgular routes (filter rules, clusters).
 */
final class SeoTasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_SETUP,
            'name' => 'Brand Setup Assistant',
            'module' => 'brand_setup',
            'description' => '"Otomatik kur": proposes a brand\'s services from its own website pages, Search Console queries and crawl candidates, matched to the service catalog. Review-only until the operator approves.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_CANDIDATES,
            'name' => 'Brand Candidates',
            'module' => 'ownership',
            'description' => 'Keşfedilen varlıklar: one call per batch groups the discovered accounts exact signals could not place and proposes each brand candidate\'s sector (Business Profile category > site > ads). Proposal only.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_SERVICES,
            'name' => 'Brand Services From Pages',
            'module' => 'ownership',
            'description' => 'Hizmet keşfi: one call proposes the brand\'s services from its own service pages (title / URL / H1), matched to the sector\'s service catalog. Proposal only; the operator approves.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_FILTER_RULES,
            'name' => 'Query Filter Rules',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile kural üret" / "Filtreye ekle › AI ile düzenle": one call proposes negative filter terms (a query containing one is deleted) and matching keywords per service from the selected queries. Proposal only; saving starts a rescan the operator approves.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_PLAN_SECTORS,
            'name' => 'Query Plan Sectors',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile planla" adım 1: one batched call assigns sectors to brands without one (Business Profile category > site title > ads) and proposes asset overrides only when an asset clearly differs. Nothing is saved before approval.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_PLAN_SERVICES,
            'name' => 'Query Plan Services',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile planla" adım 2: one batched call proposes missing services and matching keyword fixes (add / remove / move) per sector from brand services, site page names and query samples. Checklist; the operator approves.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_PLAN_FILTERS,
            'name' => 'Query Plan Filters',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile planla" adım 3: one batched call proposes negative filter terms per sector (each must occur in the sector\'s query samples). Checklist; the operator approves.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_SCAN_FILTERS,
            'name' => 'Query Filter Scan',
            'module' => 'queries',
            'description' => 'Filtre sepeti "Sorgularda tara": the sector\'s query words (keywords, generic words and own brand names left out, place names found without AI) go 400 per call with one example query each; brand / company, person, place and off-topic words come back. Checklist; the operator approves.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_ASSIGN_SERVICES,
            'name' => 'Query Service Assignment',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile hizmet öner": unassigned library queries go 200 per call (every batch, per sector with its services and matching keywords); each gets an existing service of its sector or none, plus optional new matching keywords. Checklist; the operator approves.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_TRIAGE,
            'name' => 'Query Triage (autopilot)',
            'module' => 'queries',
            'description' => 'Sorgu otomatik pilotu: unassigned library queries (200 per call, per sector) get a service, a filter term (person name, brand name, irrelevant, forbidden phrase) or none, plus new matching keywords — applied without approval; each query is asked once.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_CLUSTER,
            'name' => 'Query Clusters',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile kümele": a service\'s topics go in parts (skeleton from the most searched, then 300 per call) into clusters (same user need on the same page type) with intent, main query, page type and subtopics. Locked clusters keep their definition.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::QUERIES_CLUSTER_REVIEW,
            'name' => 'Query Cluster Review',
            'module' => 'queries',
            'description' => 'Sorgular "AI ile kümele" last step: one call reviews a service\'s clusters, merges those one page would cover (never from a locked cluster) and clarifies unlocked names / needs.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);
    }
}
