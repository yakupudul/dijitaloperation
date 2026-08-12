<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\DemoCatalog;
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

    public function expand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function render(): View
    {
        $all = DemoCatalog::findings();
        $findings = DemoCatalog::filterFindings(
            $this->severity === 'all' ? null : $this->severity,
            $this->assetType === 'all' ? null : $this->assetType,
        );

        $summary = [
            'critical' => collect($all)->where('severity', 'critical')->count(),
            'high' => collect($all)->where('severity', 'high')->count(),
            'medium' => collect($all)->where('severity', 'medium')->count(),
        ];

        return view('livewire.demo.operations.findings-index', [
            'findings' => $findings,
            'summary' => $summary,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
