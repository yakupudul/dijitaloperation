<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Services\GoogleAds\GoogleAdsLandingPageControlService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LandingPageControlPanel extends Component
{
    public string $assetId;
    public string $period = 'last_28';
    public ?string $periodStart = null;
    public ?string $periodEnd = null;
    public ?string $selectedLandingId = null;

    public function mount(string $assetId, string $period = 'last_28', ?string $periodStart = null, ?string $periodEnd = null): void
    {
        $this->assetId = $assetId;
        $this->period = $period;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    public function openLanding(string $rowId): void
    {
        $row = app(GoogleAdsLandingPageControlService::class)->findRow(
            $this->assetId,
            $this->periodStart,
            $this->periodEnd,
            $rowId,
        );

        if ($row !== null) {
            $this->selectedLandingId = $rowId;
        }
    }

    public function closeLanding(): void
    {
        $this->selectedLandingId = null;
    }

    public function render(): View
    {
        $control = app(GoogleAdsLandingPageControlService::class)->workspace(
            $this->assetId,
            $this->periodStart,
            $this->periodEnd,
        );

        $selectedLanding = $this->selectedLandingId !== null
            ? collect($control['rows'] ?? [])->firstWhere('id', $this->selectedLandingId)
            : null;

        return view('livewire.operator.google-ads.landing-page-control-panel', [
            'control' => $control,
            'selectedLanding' => $selectedLanding,
        ]);
    }
}
