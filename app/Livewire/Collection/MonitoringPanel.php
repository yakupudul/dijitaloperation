<?php

namespace App\Livewire\Collection;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\User;
use App\Services\Collection\CancellationService;
use App\Services\Collection\Monitoring\CollectionRunMonitorQuery;
use App\Services\Collection\ResumeDatasetRunService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Integrations-embedded collection monitoring.
 * Database is canonical; wire:poll is the safe fallback transport.
 */
class MonitoringPanel extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public ?string $selectedUuid = null;

    public string $historyStatus = '';

    public bool $showTechnical = false;

    public ?string $statusError = null;

    /** @var array<string, mixed>|null */
    public ?array $selectedDetail = null;

    /** @var list<array<string, mixed>> */
    public array $activeRuns = [];

    public function mount(): void
    {
        $this->refreshActive();
    }

    public function refreshActive(): void
    {
        try {
            $this->statusError = null;
            $user = Auth::user();
            $this->activeRuns = app(CollectionRunMonitorQuery::class)->activeSummaries(
                $user instanceof User ? $user : null
            );

            if ($this->selectedUuid !== null) {
                $this->loadSelected(soft: true);
            }
        } catch (\Throwable $e) {
            $this->statusError = __('operator.collection.unable_to_refresh');
            // Preserve last-known activeRuns / selectedDetail.
        }
    }

    public function selectRun(string $uuid): void
    {
        $this->selectedUuid = $uuid;
        $this->loadSelected(soft: false);
    }

    public function clearSelection(): void
    {
        $this->selectedUuid = null;
        $this->selectedDetail = null;
        $this->showTechnical = false;
    }

    public function reloadStatus(): void
    {
        $this->refreshActive();
        Notification::make()
            ->title(__('operator.collection.refresh_status_done'))
            ->success()
            ->send();
    }

    public function cancelSelected(): void
    {
        if ($this->selectedUuid === null) {
            return;
        }

        $run = CollectionRun::query()->where('uuid', $this->selectedUuid)->firstOrFail();
        $this->authorize('cancel', $run);

        app(CancellationService::class)->requestCancellation($run);

        Notification::make()
            ->title(__('operator.collection.cancel_requested'))
            ->body(__('operator.collection.cancel_body'))
            ->warning()
            ->send();

        $this->refreshActive();
    }

    public function retryDataset(string $datasetUuid): void
    {
        $dataset = CollectionDatasetRun::query()->where('uuid', $datasetUuid)->firstOrFail();
        $run = $dataset->collectionRun;
        $this->authorize('retry', $run);

        app(ResumeDatasetRunService::class)->resume($dataset);

        Notification::make()
            ->title(__('operator.collection.retry_started'))
            ->body(__('operator.collection.retry_body'))
            ->success()
            ->send();

        $this->refreshActive();
    }

    public function toggleTechnical(): void
    {
        $this->showTechnical = ! $this->showTechnical;
    }

    public function updatingHistoryStatus(): void
    {
        $this->resetPage();
    }

    public function getPollingIntervalProperty(): ?string
    {
        if ($this->activeRuns !== []) {
            return '5s';
        }

        if ($this->selectedDetail !== null && ! ($this->selectedDetail['is_terminal'] ?? true)) {
            return '5s';
        }

        return null;
    }

    public function render(): View
    {
        $user = Auth::user();
        $filters = [];
        if ($this->historyStatus !== '') {
            $filters['status'] = $this->historyStatus;
        }

        $history = app(CollectionRunMonitorQuery::class)->history(
            $user instanceof User ? $user : null,
            $filters,
            10,
        );

        return view('livewire.collection.monitoring-panel', [
            'history' => $history,
        ]);
    }

    private function loadSelected(bool $soft): void
    {
        if ($this->selectedUuid === null) {
            return;
        }

        try {
            $run = CollectionRun::query()->where('uuid', $this->selectedUuid)->firstOrFail();
            $this->authorize('view', $run);

            $user = Auth::user();
            $this->selectedDetail = app(CollectionRunMonitorQuery::class)->detailByUuid(
                $this->selectedUuid,
                $user instanceof User ? $user : null,
            );
            $this->statusError = null;
        } catch (\Throwable $e) {
            if (! $soft) {
                $this->selectedDetail = null;
            }
            $this->statusError = __('operator.collection.unable_to_refresh');
        }
    }
}
