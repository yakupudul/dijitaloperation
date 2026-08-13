<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Findings')]
class FindingsIndex extends Component
{
    public string $severity = 'all';

    public string $assetType = 'all';

    public string $status = 'all';

    public ?string $expandedId = null;

    public function mount(): void
    {
        $severity = DemoState::getFilter('finding_severity');
        $assetType = DemoState::getFilter('finding_asset_type');

        if (is_string($severity) && $severity !== '') {
            $this->severity = $severity;
        }

        if (is_string($assetType) && $assetType !== '') {
            $this->assetType = $assetType;
        }
    }

    public function setSeverity(string $severity): void
    {
        $this->severity = $severity;
        DemoState::setFilter('finding_severity', $severity === 'all' ? null : $severity);
    }

    public function setAssetType(string $assetType): void
    {
        $this->assetType = $assetType;
        DemoState::setFilter('finding_asset_type', $assetType === 'all' ? null : $assetType);
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function expand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function acknowledge(string $id): void
    {
        DemoState::setFindingStatus($id, 'acknowledged');
    }

    public function resolve(string $id): void
    {
        DemoState::setFindingStatus($id, 'resolved');
    }

    public function reopen(string $id): void
    {
        DemoState::setFindingStatus($id, 'open');
    }

    public function render(): View
    {
        $all = DemoState::findingsWithStatus();
        $findings = collect($all);

        if ($this->severity !== 'all') {
            $findings = $findings->where('severity', $this->severity);
        }

        if ($this->assetType !== 'all') {
            $findings = $findings->where('asset_type', $this->assetType);
        }

        if ($this->status !== 'all') {
            $findings = $findings->where('status', $this->status);
        }

        $summary = [
            'critical_high' => collect($all)->whereIn('severity', ['critical', 'high'])->where('status', 'open')->count(),
            'new' => collect($all)->where('status', 'open')->count(),
            'regressions' => collect($all)->whereIn('type', ['performance', 'local', 'measurement'])->whereIn('severity', ['critical', 'high'])->where('status', 'open')->count(),
            'resolved' => collect($all)->where('status', 'resolved')->count(),
            'acknowledged' => collect($all)->where('status', 'acknowledged')->count(),
        ];

        return view('livewire.demo.operations.findings-index', [
            'findings' => $findings->values()->all(),
            'summary' => $summary,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
