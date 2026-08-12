<?php

namespace App\Livewire\Operator\Meta\Concerns;

use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\ComparisonPeriod;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;
use MoxDop\MetaAds\Workspace\MetaWorkspacePeriodCoordinator;

/**
 * Shared Meta workspace period state for the TailAdmin operator pages.
 *
 * Period selection is persisted per Digital Asset and never triggers a synchronous
 * Meta call — the workspace reads the local historical store; uncovered ranges are
 * filled by a background gap-enrich queued through the coordinator.
 */
trait InteractsWithMetaWorkspacePeriod
{
    public string $period = ComparisonPeriod::PRESET_LAST_28;

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    public bool $compare = true;

    protected function bootPeriodFromSession(DigitalAsset $asset): void
    {
        $filters = MetaWorkspaceFilters::get((int) $asset->id);
        $this->period = $filters['period_preset'];
        $this->periodStart = $filters['period_start'];
        $this->periodEnd = $filters['period_end'];
        $this->compare = $filters['compare'];
    }

    public function setPeriod(string $preset): void
    {
        $this->period = ComparisonPeriod::isPreset($preset) ? $preset : ComparisonPeriod::PRESET_LAST_28;
        $this->persistPeriod();
    }

    public function applyCustomPeriod(): void
    {
        $this->period = ComparisonPeriod::PRESET_CUSTOM;
        $this->persistPeriod();
    }

    public function toggleCompare(): void
    {
        $this->compare = ! $this->compare;
        $this->persistPeriod();
    }

    protected function persistPeriod(): void
    {
        $asset = $this->metaAsset();
        $user = auth()->user();

        MetaWorkspacePeriodCoordinator::apply($asset, [
            'period_preset' => $this->period,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'compare' => $this->compare,
        ], $user instanceof User ? $user : null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentFilters(): array
    {
        $asset = $this->metaAsset();

        return MetaWorkspaceFilters::get((int) $asset->id);
    }

    abstract protected function metaAsset(): DigitalAsset;
}
