<?php

namespace App\Livewire\Operator\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Enums\Observability\OperationalHealthStatus;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Async\AsyncWorkerHealth;
use App\Services\Observability\WorkerHeartbeatService;
use App\Support\Async\AsyncOperationTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Public Discovery')]
class PublicDiscoveryPage extends Component
{
    public int $assetId;

    public string $statusMessage = '';

    public string $statusTone = 'info';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);

        $asset = $this->asset((int) $assetId);
        abort_unless($asset->type === 'website', 404);

        $this->assetId = $asset->id;
    }

    public function runDiscovery(
        AsyncOperationService $operations,
        WorkerHeartbeatService $workers,
        AsyncWorkerHealth $queueHealth,
    ): void {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $worker = $workers->snapshot();
        $queue = $queueHealth->snapshot();
        $workerStatus = $worker['status'] ?? OperationalHealthStatus::Unknown;

        if ($workerStatus === OperationalHealthStatus::Unhealthy || (bool) ($queue['worker_appears_idle'] ?? false)) {
            $reason = $workerStatus === OperationalHealthStatus::Unhealthy
                ? (string) ($worker['message'] ?? __('operator_runtime.discovery.runtime_degraded'))
                : (string) ($queue['message'] ?? __('operator_runtime.discovery.runtime_degraded'));

            $this->statusTone = 'error';
            $this->statusMessage = __('operator_runtime.discovery.queue_problem', ['message' => $reason]);

            return;
        }

        try {
            $result = $operations->queuePublicDiscovery($this->asset(), $actor);
            $run = $result['run'] ?? $result['existing_run'] ?? null;

            $this->statusTone = ($result['ok'] ?? false) ? 'success' : 'error';
            $this->statusMessage = $run instanceof Run
                ? __('operator_runtime.discovery.queued', ['id' => $run->id])
                : (string) ($result['message'] ?? __('operator_runtime.discovery.queue_problem', ['message' => 'Unknown queue result']));
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = __('operator_runtime.discovery.queue_problem', ['message' => $e->getMessage()]);
        }
    }

    public function acceptCandidate(int $candidateId, WebsiteOperatorWorkspace $workspace): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $workspace->acceptCandidate($this->candidate($candidateId), $actor);
            $this->statusTone = 'success';
            $this->statusMessage = __('operator_runtime.discovery.candidate_accepted');
        } catch (ValidationException $e) {
            $this->statusTone = 'error';
            $this->statusMessage = collect($e->errors())->flatten()->first()
                ?? __('operator_runtime.discovery.candidate_accept_failed');
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = __('operator_runtime.discovery.candidate_accept_failed_detail', ['message' => $e->getMessage()]);
        }
    }

    public function ignoreCandidate(int $candidateId, WebsiteOperatorWorkspace $workspace): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $workspace->ignoreCandidate($this->candidate($candidateId), $actor);
            $this->statusTone = 'success';
            $this->statusMessage = __('operator_runtime.discovery.candidate_ignored');
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = __('operator_runtime.discovery.candidate_ignore_failed_detail', ['message' => $e->getMessage()]);
        }
    }

    public function render(
        WebsiteOperatorWorkspace $workspace,
        WorkerHeartbeatService $workers,
        AsyncWorkerHealth $queueHealth,
    ): View {
        $asset = $this->asset();
        $worker = $workers->snapshot();
        $queue = $queueHealth->snapshot();
        $operationRun = $this->latestOperationRun();
        $workerStatus = $worker['status'] ?? OperationalHealthStatus::Unknown;

        $runtimeTone = match ($workerStatus) {
            OperationalHealthStatus::Healthy => 'success',
            OperationalHealthStatus::Degraded, OperationalHealthStatus::Unhealthy => 'error',
            default => 'info',
        };

        if ((bool) ($queue['worker_appears_idle'] ?? false)) {
            $runtimeTone = 'error';
        }

        return view('livewire.operator.website.public-discovery', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'discovery' => $workspace->discovery($asset),
            'runtime' => [
                'tone' => $runtimeTone,
                'worker_status' => $workerStatus instanceof OperationalHealthStatus ? $workerStatus->value : (string) $workerStatus,
                'worker_message' => (string) ($worker['message'] ?? ''),
                'queue_message' => (string) ($queue['message'] ?? ''),
                'pending_jobs' => (int) ($queue['pending_jobs'] ?? 0),
                'oldest_queued_job_age_seconds' => $queue['oldest_queued_job_age_seconds'] ?? null,
                'run' => $operationRun,
                'failure' => $operationRun instanceof Run
                    ? data_get($operationRun->metadata, 'failure_summary')
                    : null,
                'phase' => $operationRun instanceof Run
                    ? data_get($operationRun->metadata, 'phase_label')
                    : null,
            ],
        ]);
    }

    private function latestOperationRun(): ?Run
    {
        return Run::query()
            ->where('digital_asset_id', $this->assetId)
            ->where('module_id', AsyncOperationTypes::MODULE_PUBLIC_DISCOVERY)
            ->latest('id')
            ->first();
    }

    private function asset(?int $id = null): DigitalAsset
    {
        return DigitalAsset::query()
            ->with('brand')
            ->whereKey($id ?? $this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }

    private function candidate(int $candidateId): DiscoveryCandidate
    {
        return DiscoveryCandidate::query()
            ->whereKey($candidateId)
            ->where('digital_asset_id', $this->assetId)
            ->firstOrFail();
    }
}
