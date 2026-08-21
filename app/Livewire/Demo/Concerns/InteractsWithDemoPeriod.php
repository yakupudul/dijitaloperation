<?php

namespace App\Livewire\Demo\Concerns;

use App\Services\Operator\AgencySettingService;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Operator\OperatorPeriod;
use App\Support\Reality\DemoCatalogAssetGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    #[Url(as: 'compare_mode', history: true)]
    public string $compareMode = 'previous';

    public ?string $draftPeriodStart = null;

    public ?string $draftPeriodEnd = null;

    public ?string $customPeriodError = null;

    public function mountPeriod(): void
    {
        $queryPeriod = request()->query('period');
        if (! filled($queryPeriod)) {
            $state = DemoState::all();
            $defaultPreset = app(AgencySettingService::class)->defaultAnalyticalDateRange();
            $this->period = (string) ($state['period_preset'] ?? $this->period ?: $defaultPreset);
            $this->periodStart = $state['period_start'] ?? $this->periodStart;
            $this->periodEnd = $state['period_end'] ?? $this->periodEnd;
            $this->compare = array_key_exists('compare', $state) ? (bool) $state['compare'] : $this->compare;
            $storedMode = $state['compare_mode'] ?? $this->compareMode;
            $this->compareMode = in_array($storedMode, ['previous', 'yoy'], true) ? $storedMode : 'previous';
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
        $bounds = $this->periodBounds($preset);
        $this->periodStart = $bounds['start']->toDateString();
        $this->periodEnd = $bounds['end']->toDateString();
        $this->draftPeriodStart = $this->periodStart;
        $this->draftPeriodEnd = $this->periodEnd;
        $this->syncPeriodState();
        $this->resetPeriodDependentState();
    }

    public function openCustomPicker(): void
    {
        $bounds = $this->periodBounds(
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
            $bounds = $this->periodBounds('last_28');
            $this->periodStart = $bounds['start']->toDateString();
            $this->periodEnd = $bounds['end']->toDateString();
            $this->syncPeriodState();
        }
    }

    public function applyCustomPeriod(): void
    {
        $error = $this->usesDemoPeriodAnchor()
            ? DemoPeriod::validateCustom($this->draftPeriodStart, $this->draftPeriodEnd, $this->periodContextAssetId())
            : OperatorPeriod::validateCustom($this->draftPeriodStart, $this->draftPeriodEnd);
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
        DemoState::put(['compare' => $this->compare, 'compare_mode' => $this->compareMode]);
    }

    public function setCompareMode(string $mode): void
    {
        $this->compareMode = in_array($mode, ['previous', 'yoy'], true) ? $mode : 'previous';
        $this->compare = true;
        DemoState::put(['compare' => true, 'compare_mode' => $this->compareMode]);
    }

    public function appliedPeriodLabel(): string
    {
        $bounds = $this->periodBounds($this->period, $this->periodStart, $this->periodEnd);

        return $bounds['label'];
    }

    public function comparePeriodLabel(): ?string
    {
        if (! $this->compare) {
            return null;
        }

        $prev = $this->usesDemoPeriodAnchor()
            ? ($this->compareMode === 'yoy'
                ? DemoPeriod::yearOverYearBounds($this->period, $this->periodStart, $this->periodEnd, $this->periodContextAssetId())
                : DemoPeriod::previousBounds($this->period, $this->periodStart, $this->periodEnd, $this->periodContextAssetId()))
            : ($this->compareMode === 'yoy'
                ? OperatorPeriod::yearOverYearBounds($this->period, $this->periodStart, $this->periodEnd)
                : OperatorPeriod::previousBounds($this->period, $this->periodStart, $this->periodEnd));

        return $prev['label'];
    }

    public function periodAnchorDate(): string
    {
        return DemoPeriod::anchor($this->periodContextAssetId())->toDateString();
    }

    public function periodPickerMaxDate(): string
    {
        return $this->usesDemoPeriodAnchor()
            ? DemoPeriod::ANCHOR_DATE
            : OperatorPeriod::pickerMaxDate();
    }

    public function periodPickerMinDate(): string
    {
        return $this->usesDemoPeriodAnchor()
            ? Carbon::parse(DemoPeriod::ANCHOR_DATE)->subDays(89)->toDateString()
            : OperatorPeriod::pickerMinDate();
    }

    protected function syncPeriodState(): void
    {
        if ($this->period !== 'custom') {
            $bounds = $this->periodBounds($this->period);
            $this->periodStart = $bounds['start']->toDateString();
            $this->periodEnd = $bounds['end']->toDateString();
        }

        DemoState::setPeriod($this->period, $this->periodStart, $this->periodEnd);
        DemoState::put(['compare' => $this->compare, 'compare_mode' => $this->compareMode]);
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

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    protected function periodBounds(string $preset, ?string $start = null, ?string $end = null): array
    {
        return $this->usesDemoPeriodAnchor()
            ? DemoPeriod::bounds($preset, $start, $end, $this->periodContextAssetId())
            : OperatorPeriod::bounds($preset, $start, $end);
    }

    protected function periodContextAssetId(): ?string
    {
        if (! property_exists($this, 'assetId')) {
            return null;
        }

        $assetId = trim((string) $this->assetId);

        return $assetId !== '' ? $assetId : null;
    }

    protected function usesDemoPeriodAnchor(): bool
    {
        $assetId = $this->periodContextAssetId() ?? '';

        return DemoCatalogAssetGuard::isDemoCatalogAssetId($assetId);
    }
}
