<?php

namespace App\Livewire\Demo\Partials;

use App\Services\Collection\Presentation\DataSyncScopeService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DataSyncControl extends Component
{
    public int $assetId;

    /** @var list<string> */
    public array $capabilities = [];

    /** @var list<string> */
    public array $providers = [];

    public string $buttonLabel = '';

    public string $title = '';

    public bool $showProviders = false;

    public bool $showButton = true;

    public bool $compact = false;

    public string $feedback = '';

    public string $feedbackTone = 'info';

    /** @param list<string> $capabilities @param list<string> $providers */
    public function mount(
        int|string $assetId,
        array $capabilities,
        array $providers,
        string $buttonLabel = '',
        string $title = '',
        bool $showProviders = false,
        bool $showButton = true,
        bool $compact = false,
    ): void {
        $this->assetId = (int) $assetId;
        $this->capabilities = array_values($capabilities);
        $this->providers = array_values($providers);
        $this->buttonLabel = $buttonLabel;
        $this->title = $title;
        $this->showProviders = $showProviders;
        $this->showButton = $showProviders ? false : $showButton;
        $this->compact = $compact;
    }

    public function updateData(DataSyncScopeService $sync): void
    {
        $result = $sync->start(
            $this->assetId,
            $this->capabilities,
            $this->providers,
            auth()->user(),
        );

        $outcome = (string) ($result['outcome'] ?? 'failed');
        $this->feedbackTone = in_array($outcome, ['started', 'active_equivalent', 'data_current'], true)
            ? 'success'
            : 'warning';

        $this->feedback = match ($outcome) {
            'started' => app()->getLocale() === 'tr' ? 'Güncelleme başlatıldı.' : 'Update started.',
            'active_equivalent' => app()->getLocale() === 'tr' ? 'Bu güncelleme zaten çalışıyor.' : 'This update is already running.',
            'data_current' => app()->getLocale() === 'tr' ? 'Veriler zaten güncel.' : 'Data is already current.',
            'action_required' => app()->getLocale() === 'tr' ? 'Bağlantı kontrolü gerekiyor.' : 'Connection check required.',
            default => (string) ($result['message'] ?? (app()->getLocale() === 'tr' ? 'Güncelleme başlatılamadı.' : 'Update could not be started.')),
        };
    }

    public function render(DataSyncScopeService $sync): View
    {
        return view('livewire.demo.partials.data-sync-control', [
            'syncStatus' => $sync->status($this->assetId, $this->capabilities, $this->providers),
        ]);
    }
}
