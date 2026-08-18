<?php

namespace App\Livewire\Operator;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncOperationTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Public Discovery')]
class PublicDiscoveryIndex extends Component
{
    #[Url]
    public string $q = '';

    #[Url]
    public string $state = 'all';

    public string $message = '';

    public string $messageTone = 'info';

    public function runDiscovery(int $assetId, AsyncOperationService $async): void
    {
        $asset = DigitalAsset::query()
            ->whereKey($assetId)
            ->where('type', 'website')
            ->firstOrFail();

        $result = $async->queuePublicDiscovery($asset, auth()->user());
        $this->message = (string) ($result['message'] ?? 'Public discovery queued.');
        $this->messageTone = ($result['ok'] ?? false) ? 'success' : 'info';
    }

    public function render(WebsiteOperatorWorkspace $websiteWorkspace): View
    {
        $assets = DigitalAsset::query()
            ->with('brand.customer')
            ->where('type', 'website')
            ->when(trim($this->q) !== '', function ($query): void {
                $term = '%'.trim($this->q).'%';
                $query->where(function ($nested) use ($term): void {
                    $nested->where('name', 'like', $term)
                        ->orWhere('domain', 'like', $term)
                        ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $term));
                });
            })
            ->orderBy('name')
            ->get();

        $assetIds = $assets->pluck('id')->map(fn ($id) => (int) $id)->all();

        $operationRuns = $this->latestRunsByModule($assetIds, AsyncOperationTypes::MODULE_PUBLIC_DISCOVERY);
        $discoveryRuns = $this->latestRunsByModule($assetIds, $websiteWorkspace->discoveryResultModuleId());

        $pendingCounts = DiscoveryCandidate::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->where('status', DiscoveryCandidate::STATUS_PENDING)
            ->selectRaw('digital_asset_id, count(*) as aggregate')
            ->groupBy('digital_asset_id')
            ->pluck('aggregate', 'digital_asset_id');

        $acceptedCounts = DiscoveryCandidate::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->where('status', DiscoveryCandidate::STATUS_ACCEPTED)
            ->selectRaw('digital_asset_id, count(*) as aggregate')
            ->groupBy('digital_asset_id')
            ->pluck('aggregate', 'digital_asset_id');

        $rows = $assets->map(function (DigitalAsset $asset) use ($operationRuns, $discoveryRuns, $pendingCounts, $acceptedCounts): array {
            /** @var Run|null $operationRun */
            $operationRun = $operationRuns->get($asset->id);
            /** @var Run|null $discoveryRun */
            $discoveryRun = $discoveryRuns->get($asset->id);

            $activeOperation = $operationRun !== null && in_array((string) $operationRun->status, ['queued', 'running'], true);
            $status = $activeOperation
                ? (string) $operationRun->status
                : (string) (data_get($discoveryRun?->metadata, 'discovery_status') ?: $discoveryRun?->status ?: $operationRun?->status ?: 'not_run');
            $ready = filled($asset->primary_url) || filled($asset->domain);
            $displayRun = $activeOperation ? $operationRun : ($discoveryRun ?? $operationRun);

            return [
                'asset' => $asset,
                'run' => $displayRun,
                'status' => $status,
                'ready' => $ready,
                'pending' => (int) ($pendingCounts[$asset->id] ?? 0),
                'accepted' => (int) ($acceptedCounts[$asset->id] ?? 0),
                'pages' => (int) (data_get($discoveryRun?->metadata, 'pages_inspected') ?? 0),
                'last_run_human' => $displayRun?->finished_at?->diffForHumans() ?? $displayRun?->started_at?->diffForHumans(),
            ];
        });

        $neverRunCount = $rows->where('status', 'not_run')->count();

        if ($this->state === 'needs_review') {
            $rows = $rows->where('pending', '>', 0);
        } elseif ($this->state === 'never_run') {
            $rows = $rows->where('status', 'not_run');
        } elseif ($this->state === 'issue') {
            $rows = $rows->filter(fn (array $row): bool => in_array($row['status'], ['failed', 'partial'], true) || ! $row['ready']);
        }

        return view('livewire.operator.public-discovery-index', [
            'rows' => $rows->values()->all(),
            'counts' => [
                'websites' => $assets->count(),
                'never_run' => $neverRunCount,
                'needs_review' => (int) $pendingCounts->sum(),
                'accepted' => (int) $acceptedCounts->sum(),
            ],
        ]);
    }

    /** @return Collection<int, Run> */
    private function latestRunsByModule(array $assetIds, string $moduleId)
    {
        return Run::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->where('module_id', $moduleId)
            ->orderByDesc('id')
            ->get()
            ->unique('digital_asset_id')
            ->keyBy('digital_asset_id');
    }
}
