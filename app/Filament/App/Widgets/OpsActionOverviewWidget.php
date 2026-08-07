<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Models\CoreConnection;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Action-oriented agency ops cards derived from live domain records (no fake KPIs).
 */
class OpsActionOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'What needs attention';

    protected ?string $description = 'Counts from open Findings, Recommendations, Connections, and Tasks. Empty means nothing queued — not a fake zero KPI.';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $criticalFindings = Finding::query()
            ->where('status', 'open')
            ->whereIn('severity', ['critical', 'high'])
            ->count();

        $openRecommendations = Recommendation::query()
            ->where('status', 'open')
            ->count();

        $failedConnections = CoreConnection::query()
            ->whereNotNull('last_error')
            ->where('last_error', '!=', '')
            ->count();

        $openTasks = Task::query()
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->count();

        $overdueTasks = Task::query()
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $openCrossChannelFindings = Finding::query()
            ->where('status', 'open')
            ->where('category', 'cross-channel')
            ->count();

        $openWebsiteTechnicalFindings = Finding::query()
            ->where('status', 'open')
            ->where('source_module', 'website')
            ->whereIn('severity', ['critical', 'high'])
            ->count();

        $recentlyResolvedImportantFindings = Finding::query()
            ->where('status', 'resolved')
            ->whereIn('severity', ['critical', 'high'])
            ->where('last_seen_at', '>=', now()->subDays(7))
            ->count();

        return [
            Stat::make('Critical open Findings', (string) $criticalFindings)
                ->description($criticalFindings === 0
                    ? 'No critical/high open Findings'
                    : 'Open Findings with critical or high severity')
                ->color($criticalFindings > 0 ? 'danger' : 'gray')
                ->url(FindingResource::getUrl('index')),
            Stat::make('Open Recommendations', (string) $openRecommendations)
                ->description($openRecommendations === 0
                    ? 'No open Recommendations waiting for action'
                    : 'Recommendations still in open status')
                ->color($openRecommendations > 0 ? 'warning' : 'gray')
                ->url(RecommendationResource::getUrl('index')),
            Stat::make('Connections with errors', (string) $failedConnections)
                ->description($failedConnections === 0
                    ? 'No Connection last_error values recorded'
                    : 'Enabled or disabled Connections reporting last_error')
                ->color($failedConnections > 0 ? 'danger' : 'gray'),
            Stat::make('Open Tasks', (string) $openTasks)
                ->description($overdueTasks > 0
                    ? $overdueTasks.' overdue (due_date before today)'
                    : ($openTasks === 0
                        ? 'No open / in-progress / blocked Tasks'
                        : 'Open, in-progress, or blocked Tasks'))
                ->color($overdueTasks > 0 ? 'danger' : ($openTasks > 0 ? 'warning' : 'gray')),
            Stat::make('Open cross-channel Findings', (string) $openCrossChannelFindings)
                ->description($openCrossChannelFindings === 0
                    ? 'No open cross-channel Findings'
                    : 'Open Findings with category cross-channel')
                ->color($openCrossChannelFindings > 0 ? 'warning' : 'gray')
                ->url(FindingResource::getUrl('index')),
            Stat::make('Website technical Findings', (string) $openWebsiteTechnicalFindings)
                ->description($openWebsiteTechnicalFindings === 0
                    ? 'No critical/high open Website Findings'
                    : 'Open critical/high Findings from website module')
                ->color($openWebsiteTechnicalFindings > 0 ? 'danger' : 'gray')
                ->url(FindingResource::getUrl('index')),
            Stat::make('Recently resolved important', (string) $recentlyResolvedImportantFindings)
                ->description($recentlyResolvedImportantFindings === 0
                    ? 'No critical/high Findings resolved in the last 7 days'
                    : 'Critical/high Findings resolved within the last 7 days')
                ->color($recentlyResolvedImportantFindings > 0 ? 'success' : 'gray')
                ->url(FindingResource::getUrl('index')),
        ];
    }
}
