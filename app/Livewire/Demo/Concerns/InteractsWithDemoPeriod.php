<?php

namespace App\Livewire\Demo\Concerns;

use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use Livewire\Attributes\Url;

trait InteractsWithDemoPeriod
{
    #[Url(as: 'period', history: true)]
    public string $period = 'last_28';

    #[Url(as: 'from', history: true)]
    public ?string $periodStart = null;

    #[Url(as: 'to', history: true)]
    public ?string $periodEnd = null;

    public bool $showCustomPicker = false;

    #[Url(as: 'compare', history: true)]
    public bool $compare = true;

    public ?string $draftPeriodStart = null;

    public ?string $draftPeriodEnd = null;

    public ?string $customPeriodError = null;

    public function mountPeriod(): void
    {
        $queryPeriod = request()->query('period');
        if (! filled($queryPeriod)) {
            $state = DemoState::all();
            $this->period = (string) ($state['period_preset'] ?? $this->period ?: 'last_28');
            $this->periodStart = $state['period_start'] ?? $this->periodStart;
            $this->periodEnd = $state['period_end'] ?? $this->periodEnd;
            $this->compare = array_key_exists('compare', $state) ? (bool) $state['compare'] : $this->compare;
        }

        if ($this->period === 'custom' && filled($this->periodStart) && filled($this->periodEnd)) {
            $this->showCustomPicker = false;
        }

        $this->syncPeriodState();
    }

    public function setPeriod(string $preset): void
    {
        if ($preset === 'custom') {
            $this->openCustomPicker();

            return;
        }

        $this->period = $preset;
        $this->showCustomPicker = false;
        $this->customPeriodError = null;
        $bounds = DemoPeriod::bounds($preset);
        $this->periodStart = $bounds['start']->toDateString();
        $this->periodEnd = $bounds['end']->toDateString();
        $this->draftPeriodStart = $this->periodStart;
        $this->draftPeriodEnd = $this->periodEnd;
        $this->syncPeriodState();
        $this->resetPeriodDependentState();
    }

    public function openCustomPicker(): void
    {
        $bounds = DemoPeriod::bounds(
            $this->period === 'custom' ? 'custom' : $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
        $this->draftPeriodStart = $this->periodStart ?: $bounds['start']->toDateString();
        $this->draftPeriodEnd = $this->periodEnd ?: $bounds['end']->toDateString();
        $this->customPeriodError = null;
        $this->showCustomPicker = true;
        $this->period = 'custom';
    }

    public function cancelCustomPeriod(): void
    {
        $this->showCustomPicker = false;
        $this->customPeriodError = null;
        $this->draftPeriodStart = $this->periodStart;
        $this->draftPeriodEnd = $this->periodEnd;

        if ($this->period === 'custom' && (! filled($this->periodStart) || ! filled($this->periodEnd))) {
            $this->period = 'last_28';
            $bounds = DemoPeriod::bounds('last_28');
            $this->periodStart = $bounds['start']->toDateString();
            $this->periodEnd = $bounds['end']->toDateString();
            $this->syncPeriodState();
        }
    }

    public function applyCustomPeriod(): void
    {
        $error = DemoPeriod::validateCustom($this->draftPeriodStart, $this->draftPeriodEnd);
        if ($error !== null) {
            $this->customPeriodError = $error;
            $this->showCustomPicker = true;

            return;
        }

        $this->period = 'custom';
        $this->periodStart = $this->draftPeriodStart;
        $this->periodEnd = $this->draftPeriodEnd;
        $this->customPeriodError = null;
        $this->showCustomPicker = false;
        $this->syncPeriodState();
        $this->resetPeriodDependentState();
    }

    public function toggleCompare(): void
    {
        $this->compare = ! $this->compare;
        DemoState::put(['compare' => $this->compare]);
    }

    public function appliedPeriodLabel(): string
    {
        $bounds = DemoPeriod::bounds($this->period, $this->periodStart, $this->periodEnd);

        return $bounds['label'];
    }

    public function comparePeriodLabel(): ?string
    {
        if (! $this->compare) {
            return null;
        }

        $prev = DemoPeriod::previousBounds($this->period, $this->periodStart, $this->periodEnd);

        return $prev['label'];
    }

    protected function syncPeriodState(): void
    {
        if ($this->period !== 'custom') {
            $bounds = DemoPeriod::bounds($this->period);
            $this->periodStart = $bounds['start']->toDateString();
            $this->periodEnd = $bounds['end']->toDateString();
        }

        DemoState::setPeriod($this->period, $this->periodStart, $this->periodEnd);
        DemoState::put(['compare' => $this->compare]);
    }

    /**
     * Hook for pages that paginate period-dependent tables.
     */
    protected function resetPeriodDependentState(): void
    {
        if (property_exists($this, 'page')) {
            $this->page = 1;
        }
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
