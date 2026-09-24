<?php

namespace App\Providers;

use App\Services\Ai\Insights\AiInsightService;
use App\Services\Ai\Insights\Definitions\AdvisorExplainInsight;
use App\Services\Ai\Insights\Definitions\AlertCauseInsight;
use App\Services\Ai\Insights\Definitions\CustomerBriefInsight;
use App\Services\Ai\Insights\Definitions\LandingFitInsight;
use App\Services\Ai\Insights\Definitions\LeadScoreInsight;
use App\Services\Ai\Insights\Definitions\ReviewThemesInsight;
use App\Services\Ai\Insights\Definitions\SearchTermTriageInsight;
use App\Services\Ai\Insights\Definitions\TechnicalTasksInsight;
use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/** On-click AI insights: definitions and their AI routes (AI Control Plane). */
final class AiInsightServiceProvider extends ServiceProvider
{
    private const array DEFINITIONS = [
        AdvisorExplainInsight::class => ['Advisor Finding Explanation', 'advisor', 'analysis', 'Explains one advisor finding: why it matters for this business and the steps to act on it.'],
        SearchTermTriageInsight::class => ['Search Term Triage', 'advisor', 'classification', 'Sorts the last 30 days of Google Ads search terms into irrelevant (negative candidates), to review and fine, using the brand services and areas.'],
        ReviewThemesInsight::class => ['Review Themes', 'intel', 'analysis', 'Groups the brand\'s and competitors\' review texts into praised and criticised themes. Reviewer names are not sent.'],
        AlertCauseInsight::class => ['Alert Root Cause', 'alerts', 'analysis', 'Compares the daily numbers of all channels around an alert to name its most likely cause.'],
        LandingFitInsight::class => ['Ad ↔ Landing Page Fit', 'advisor', 'analysis', 'Checks whether ads, keywords and crawled landing pages promise the same thing.'],
        CustomerBriefInsight::class => ['Customer Call Brief', 'portfolio', 'analysis', 'Short status brief of one customer (health, budget, open work, alerts) before a call.'],
        LeadScoreInsight::class => ['Agency Lead Score', 'sales', 'classification', 'Scores one incoming agency request and drafts the first reply. Contact details are not sent.'],
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
