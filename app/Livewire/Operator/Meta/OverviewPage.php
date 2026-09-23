<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Meta\OverviewPage as LegacyOverviewPage;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior layered over the existing Meta Ads visual workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        if ($assetId === null || $assetId === '') {
            // Never guess which customer's account to open: send the operator to the Meta asset list.
            $this->redirectRoute('operator.assets', ['type' => 'meta_ads'], navigate: true);

            return;
        }

        parent::mount($assetId, $tab);
    }

    /** @return array<string, list<array<string, mixed>>> */
    protected function recordedOperations(): array
    {
        $assetId = (int) $this->assetId;

        $findings = Finding::query()
            ->where('digital_asset_id', $assetId)
            ->where('status', Finding::STATUS_OPEN)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'severity'])
            ->map(fn (Finding $finding): array => ['id' => $finding->id, 'title' => (string) $finding->title, 'severity' => $this->severityLabel((string) $finding->severity)])
            ->all();

        $recommendations = AdvisorItem::query()->open()
            ->where('digital_asset_id', $assetId)
            ->orderByDesc('priority_score')
            ->limit(20)
            ->get()
            ->map(fn (AdvisorItem $item): array => ['id' => $item->id, 'title' => (string) $item->title, 'severity' => $item->severityLabel()])
            ->merge(Recommendation::query()
                ->where('digital_asset_id', $assetId)
                ->where('status', Recommendation::STATUS_OPEN)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'title'])
                ->map(fn (Recommendation $recommendation): array => ['id' => $recommendation->id, 'title' => (string) $recommendation->title]))
            ->values()
            ->all();

        $tasks = Task::query()
            ->where('digital_asset_id', $assetId)
            ->whereNotIn('status', ['done', 'completed', 'cancelled', 'closed'])
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'status'])
            ->map(fn (Task $task): array => ['id' => $task->id, 'title' => (string) $task->title])
            ->all();

        $outcomes = AdvisorItem::query()
            ->where('digital_asset_id', $assetId)
            ->whereNotNull('measured_at')
            ->latest('measured_at')
            ->limit(20)
            ->get(['id', 'title', 'outcome'])
            ->map(fn (AdvisorItem $item): array => ['id' => $item->id, 'title' => (string) $item->title, 'outcome' => $item->outcome])
            ->all();

        return ['findings' => $findings, 'recommendations' => $recommendations, 'tasks' => $tasks, 'outcomes' => $outcomes];
    }

    private function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Kritik',
            'high' => 'Yüksek',
            'medium' => 'Orta',
            'low' => 'Düşük',
            default => $severity,
        };
    }

    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'meta_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'operations';
    }
}
