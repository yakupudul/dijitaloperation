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
        $query = CollectionRun::query()->select(['id', 'digital_asset_id', 'status', 'metadata', 'request_context', 'last_activity_at', 'requested_by_user_id'])->whereIn('status', ['queued', 'running', 'retrying', 'cancellation_requested']);
        $count = auth()->check() ? (clone $query)->count() : 0;
        $runs = $count > 0 ? $query->with([
            'digitalAsset:id,name',
            'resourceRuns:id,collection_run_id,provider_or_source,external_resource_id',
            'resourceRuns.externalResource:id,display_name',
            'datasetRuns:id,collection_run_id,status,last_activity_at,retry_at,started_at,created_at',
        ])->orderBy('id')->limit(8)->get() : collect();
        $tr = app()->getLocale() === 'tr';
        $items = $runs->map(function (CollectionRun $run) use ($tr): array {
            $datasets = $run->datasetRuns;
            $unfinished = $datasets->filter(fn ($dataset) => ! $dataset->status->isTerminal());
            $cutoff = now()->subSeconds(max(1800, (int) config('moxdop-collection.stale_running_seconds', 1800)));
            $running = $unfinished->where('status', CollectionRunStatus::Running);
            $observed = $running->isNotEmpty() ? $running : $unfinished;
            $stalled = $observed->isNotEmpty() && $observed->every(function ($dataset) use ($cutoff): bool {
                if ($dataset->status === CollectionRunStatus::Retrying && $dataset->retry_at?->isFuture()) {
                    return false;
                }

                return ($dataset->last_activity_at ?? $dataset->started_at ?? $dataset->created_at)?->lt($cutoff) ?? true;
            });
            $state = match (true) {
                $run->status === CollectionRunStatus::CancellationRequested => $tr ? 'Durduruluyor' : 'Stopping',
                $datasets->isEmpty() => $tr ? 'Hazırlanıyor' : 'Preparing',
                $unfinished->isEmpty() => $tr ? 'Sonuçlandırılıyor' : 'Finalizing',
                $stalled => $tr ? 'İlerleme durmuş · kontrol gerekiyor' : 'Stalled · needs attention',
                $unfinished->contains('status', CollectionRunStatus::Running) => $tr ? 'Veri alınıyor' : 'Collecting',
                $unfinished->contains('status', CollectionRunStatus::Retrying) => $tr ? 'Yeniden deneme bekleniyor' : 'Waiting to retry',
                default => $tr ? 'Sırada' : 'Queued',
            };
            $names = $run->resourceRuns->map(fn ($resource) => $resource->externalResource?->display_name)->filter()->unique()->implode(', ');
            $providers = $run->resourceRuns->pluck('provider_or_source')->unique()->implode(', ');
            $last = $datasets->pluck('last_activity_at')->filter()->sort()->last() ?? $run->last_activity_at;

            return [
                'id' => $run->id, 'name' => $run->digitalAsset?->name ?: ($names ?: $providers),
                'provider' => $providers, 'state' => $state, 'stalled' => $stalled,
                'completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
                'total' => $datasets->count(), 'last' => $last?->diffForHumans(),
                'automatic' => data_get($run->metadata, 'automatic_collection', false)
                    || data_get($run->request_context, 'context.collection_intent') === 'wordpress_event_reconciliation'
                    || $run->requested_by_user_id === null,
            ];
        });

        return view('livewire.demo.partials.active-data-sync-indicator', ['activeCount' => $count, 'items' => $items, 'tr' => $tr]);
    }
}
