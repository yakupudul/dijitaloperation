<?php

namespace App\Livewire\Demo\Partials;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionRun;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ActiveDataSyncIndicator extends Component
{
    public function render(): View
    {
        $runs = collect();
        if (auth()->check()) {
            $runs = CollectionRun::query()
                ->where('requested_by_user_id', auth()->id())
                ->whereIn('status', [
                    CollectionRunStatus::Queued->value,
                    CollectionRunStatus::Running->value,
                    CollectionRunStatus::Retrying->value,
                    CollectionRunStatus::CancellationRequested->value,
                ])
                ->with(['datasetRuns', 'resourceRuns'])
                ->orderByDesc('id')
                ->limit(8)
                ->get();
        }

        $datasets = $runs->flatMap(fn (CollectionRun $run) => $run->datasetRuns)->values();
        $total = $datasets->count();
        $work = 0.0;
        foreach ($datasets as $dataset) {
            if ($dataset->status->isTerminal()) {
                $work += 1.0;
            } elseif ((int) ($dataset->progress_total ?? 0) > 0) {
                $work += min(1.0, (int) ($dataset->progress_current ?? 0) / (int) $dataset->progress_total);
            }
        }
        $progress = $total > 0 ? max(1, min(99, (int) round(($work / $total) * 100))) : null;

        $providers = $runs->flatMap(fn (CollectionRun $run) => $run->resourceRuns->pluck('provider_or_source'))
            ->map(fn ($provider): string => match (strtoupper((string) $provider)) {
                'GA4' => 'Analytics',
                'SEARCH_CONSOLE' => 'Search Console',
                'GOOGLE_ADS' => 'Google Ads',
                'META_ADS' => 'Meta Ads',
                default => ucwords(strtolower(str_replace('_', ' ', (string) $provider))),
            })
            ->unique()->values()->all();

        return view('livewire.demo.partials.active-data-sync-indicator', [
            'activeCount' => $runs->count(),
            'progress' => $progress,
            'providers' => $providers,
        ]);
    }
}
