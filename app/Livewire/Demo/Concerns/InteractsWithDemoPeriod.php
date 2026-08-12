<?php

namespace App\Livewire\Demo\Concerns;

use App\Support\Demo\DemoState;

trait InteractsWithDemoPeriod
{
    public string $period = 'last_28';

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    public bool $showCustomPicker = false;

    public function mountPeriod(): void
    {
        $state = DemoState::all();
        $this->period = (string) ($state['period_preset'] ?? 'last_28');
        $this->periodStart = $state['period_start'] ?? null;
        $this->periodEnd = $state['period_end'] ?? null;
    }

    public function setPeriod(string $preset): void
    {
        $this->period = $preset;
        $this->showCustomPicker = $preset === 'custom';
        if ($preset !== 'custom') {
            DemoState::setPeriod($preset);
        }
    }

    public function applyCustomPeriod(): void
    {
        $this->period = 'custom';
        $this->showCustomPicker = true;
        DemoState::setPeriod('custom', $this->periodStart, $this->periodEnd);
    }

    public function openCustomPicker(): void
    {
        $this->period = 'custom';
        $this->showCustomPicker = true;
    }
}
