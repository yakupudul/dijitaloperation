<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Filament\App\Resources\Tasks\TaskResource;
use App\Models\CoreConnection;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Action-oriented agency ops cards derived from live domain records (no fake KPIs).
 */
class OpsActionOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'What needs attention';

    protected ?string $description = null;

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
            ->whereIn('status', TaskStatus::active())
            ->count();

        $overdueTasks = Task::query()
            ->whereIn('status', TaskStatus::active())
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $awaitingFollowUp = Task::query()
            ->where('status', TaskStatus::COMPLETED)
            ->where('outcome_status', TaskOutcomeStatus::AWAITING_FOLLOW_UP)
            ->count();

        $regressionObserved = Task::query()
            ->where('status', TaskStatus::COMPLETED)
            ->where('outcome_status', TaskOutcomeStatus::REGRESSION_OBSERVED)
            ->count();

        $improvementObserved = Task::query()
            ->where('status', TaskStatus::COMPLETED)
            ->where('outcome_status', TaskOutcomeStatus::IMPROVEMENT_OBSERVED)
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

        $attentionTotal = $criticalFindings
            + $openRecommendations
            + $failedConnections
            + $openTasks
            + $regressionObserved
            + $awaitingFollowUp
            + $openCrossChannelFindings
            + $openWebsiteTechnicalFindings;

        if ($attentionTotal === 0) {
            return [
                Stat::make('All clear', '0')
                    ->description(
                        $recentlyResolvedImportantFindings > 0
                            ? $recentlyResolvedImportantFindings.' important findings resolved in the last 7 days'
                            : 'No issues currently require attention'
                    )
                    ->color('success')
                    ->url(FindingResource::getUrl('index')),
            ];
        }

        $stats = [];

        if ($criticalFindings > 0) {
            $stats[] = Stat::make('Critical open Findings', (string) $criticalFindings)
                ->description('Open findings with critical or high severity')
                ->color('danger')
                ->url(FindingResource::getUrl('index'));
        }

        if ($openWebsiteTechnicalFindings > 0) {
            $stats[] = Stat::make('Website technical Findings', (string) $openWebsiteTechnicalFindings)
                ->description('Open critical/high findings from website checks')
                ->color('danger')
                ->url(FindingResource::getUrl('index'));
        }

        if ($openCrossChannelFindings > 0) {
            $stats[] = Stat::make('Open cross-channel Findings', (string) $openCrossChannelFindings)
                ->description('Cross-channel findings still open')
                ->color('warning')
                ->url(FindingResource::getUrl('index'));
        }

        if ($openRecommendations > 0) {
            $stats[] = Stat::make('Open Recommendations', (string) $openRecommendations)
                ->description('Recommendations waiting for action')
                ->color('warning')
                ->url(RecommendationResource::getUrl('index'));
        }

        if ($failedConnections > 0) {
            $stats[] = Stat::make('Connections with errors', (string) $failedConnections)
                ->description('Connections reporting a recent issue')
                ->color('danger');
        }

        if ($openTasks > 0) {
            $stats[] = Stat::make('Open Tasks', (string) $openTasks)
                ->description($overdueTasks > 0
                    ? $overdueTasks.' overdue'
                    : 'Open, in progress, or blocked')
                ->color($overdueTasks > 0 ? 'danger' : 'warning')
                ->url(TaskResource::getUrl('index'));
        }

        if ($regressionObserved > 0) {
            $stats[] = Stat::make('Regression observed', (string) $regressionObserved)
                ->description('Linked Finding reappeared after earlier improvement')
                ->color('danger')
                ->url(TaskResource::getUrl('index'));
        }

        if ($awaitingFollowUp > 0) {
            $stats[] = Stat::make('Awaiting follow-up', (string) $awaitingFollowUp)
                ->description('Completed Tasks waiting for comparable Finding evaluation')
                ->color('warning')
                ->url(TaskResource::getUrl('index'));
        }

        if ($improvementObserved > 0) {
            $stats[] = Stat::make('Improvement observed', (string) $improvementObserved)
                ->description('Linked Finding resolved in a later successful evaluation')
                ->color('success')
                ->url(TaskResource::getUrl('index'));
        }

        if ($recentlyResolvedImportantFindings > 0) {
            $stats[] = Stat::make('Recently resolved important', (string) $recentlyResolvedImportantFindings)
                ->description('Critical/high findings resolved in the last 7 days')
                ->color('success')
                ->url(FindingResource::getUrl('index'));
        }

        return $stats;
    }
}
