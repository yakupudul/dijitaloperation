<?php

namespace App\Livewire\Demo\Operations;

use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Services\Findings\FindingLifecycleService;
use App\Services\Findings\FindingReadService;
use App\Support\Demo\DemoState;
use App\Support\Findings\Dto\FindingReadDto;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Production Findings triage inbox — backed by App\Models\Finding via FindingReadService.
 * No Demo fixtures: empty result set means no Finding rows exist yet for the current filters.
 */
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
        $finding = $this->resolveFinding($id);
        if ($finding === null) {
            DemoState::flash(__('operator.flash.finding_not_found'), 'info');

            return;
        }

        $finding->status = FindingLifecycleService::STATUS_ACKNOWLEDGED;
        $finding->resolved_at = null;
        $finding->save();
        DemoState::flash(__('operator.flash.finding_acknowledged'));
    }

    public function resolve(string $id): void
    {
        $finding = $this->resolveFinding($id);
        if ($finding === null) {
            DemoState::flash(__('operator.flash.finding_not_found'), 'info');

            return;
        }

        $finding->status = FindingLifecycleService::STATUS_RESOLVED;
        $finding->resolved_at = now();
        $finding->save();
        DemoState::flash(__('operator.flash.finding_resolved'));
    }

    public function reopen(string $id): void
    {
        $finding = $this->resolveFinding($id);
        if ($finding === null) {
            DemoState::flash(__('operator.flash.finding_not_found'), 'info');

            return;
        }

        $finding->status = FindingLifecycleService::STATUS_OPEN;
        $finding->resolved_at = null;
        $finding->save();
        DemoState::flash(__('operator.flash.finding_reopened'));
    }

    public function render(): View
    {
        $dtos = app(FindingReadService::class)->query([], 500);
        $all = array_map(fn (FindingReadDto $dto): array => $this->present($dto), $dtos);
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
            'regressions' => collect($all)->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
            'resolved' => collect($all)->where('status', 'resolved')->count(),
            'acknowledged' => collect($all)->where('status', 'acknowledged')->count(),
        ];

        return view('livewire.demo.operations.findings-index', [
            'findings' => $findings->values()->all(),
            'summary' => $summary,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    private function resolveFinding(string $id): ?Finding
    {
        if (! ctype_digit($id)) {
            return null;
        }

        return Finding::query()->find((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FindingReadDto $dto): array
    {
        $asset = DigitalAsset::query()->with('brand')->find($dto->digitalAssetId);
        $assetType = is_string($asset?->type) ? $asset->type : '';
        $brandName = $asset?->brand?->name ?? ($dto->brandId !== null ? 'Brand #'.$dto->brandId : '—');
        $assetName = $asset?->name ?? ('Asset #'.$dto->digitalAssetId);

        $recommendationIds = Recommendation::query()
            ->where('finding_id', $dto->id)
            ->pluck('id');
        $recommendationCount = $recommendationIds->count();
        $taskCount = $recommendationIds->isEmpty()
            ? 0
            : Task::query()->whereIn('recommendation_id', $recommendationIds)->count();

        $lastObserved = $this->formatMoment($dto->lastDetectedAt) ?? $this->formatMoment($dto->firstDetectedAt) ?? '—';
        $detected = $this->formatMoment($dto->firstDetectedAt) ?? $lastObserved;

        return [
            'id' => (string) $dto->id,
            'severity' => $dto->severity,
            'status' => $dto->status,
            'category' => $dto->category,
            'type' => $dto->category,
            'asset_type' => $assetType,
            'brand' => $brandName,
            'asset' => $assetName,
            'title' => $dto->title,
            'summary' => $dto->summary,
            'observation' => $dto->summary ?? $dto->title,
            'plain' => $dto->summary ?? $dto->title,
            'why' => null,
            'evidence' => $dto->supportingEvidenceIds === []
                ? 'No linked Evidence rows on the latest evaluation.'
                : 'Evidence #'.implode(', #', $dto->supportingEvidenceIds),
            'source_label' => $dto->ruleId !== null ? 'Rule '.$dto->ruleId : 'Finding',
            'last_observed' => $lastObserved,
            'detected' => $detected,
            'recommendations_count' => $recommendationCount,
            'tasks_count' => $taskCount,
        ];
    }

    private function formatMoment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->diffForHumans();
        }

        try {
            return Carbon::parse((string) $value)->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    }
}
