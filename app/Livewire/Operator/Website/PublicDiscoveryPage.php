<?php

namespace App\Livewire\Operator\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Enums\Observability\OperationalHealthStatus;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Async\AsyncWorkerHealth;
use App\Services\Observability\WorkerHeartbeatService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Public Discovery')]
class PublicDiscoveryPage extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId;

    #[Locked]
    public ?int $selectedCandidateId = null;

    #[Locked]
    public string $currentValue = '';

    public string $statusMessage = '';

    public string $statusTone = 'info';

    public string $filter = 'pending';

    public string $editedValue = '';

    public ?int $offeringId = null;

    public ?int $serviceAreaId = null;

    public string $countryCode = '';

    public string $cityName = '';

    public string $districtName = '';

    public function updatedCountryCode(): void { $this->cityName = ''; $this->districtName = ''; }
    public function updatedCityName(): void { $this->districtName = ''; }

    public bool $confirmServiceArea = false;

    public bool $replaceExisting = false;

    public function updatedFilter(): void
    {
        $this->resetPage('candidatesPage');
    }

    public function reviewCandidate(int $candidateId): void
    {
        $this->resetValidation();
        $candidate = $this->candidate($candidateId);
        $this->selectedCandidateId = $candidate->id;
        $this->editedValue = $candidate->accepted_value ?? $candidate->proposed_value;
        $this->reset('offeringId', 'serviceAreaId', 'countryCode', 'cityName', 'districtName', 'confirmServiceArea', 'replaceExisting');
        $this->currentValue = in_array($candidate->target_field, ['business_summary', 'positioning'], true)
            ? (string) ($this->asset()->brand->intelligenceContext?->{$candidate->target_field} ?? '') : '';
    }

    public function closeReview(): void
    {
        $this->selectedCandidateId = null;
        $this->resetValidation();
    }

    public function applyReview(WebsiteOperatorWorkspace $workspace): void
    {
        abort_if($this->selectedCandidateId === null, 404);
        $candidate = $workspace->acceptCandidate($this->candidate($this->selectedCandidateId), auth()->user(), $this->editedValue, [
            'offering_id' => $this->offeringId, 'service_area_id' => $this->serviceAreaId,
            'country_code' => $this->countryCode, 'city_name' => $this->cityName, 'district_name' => $this->districtName,
            'confirm_service_area' => $this->confirmServiceArea, 'replace_existing' => $this->replaceExisting,
            'expected_current' => $this->currentValue, 'apply_reviewed' => true,
        ]);
        $this->statusMessage = __('public_discovery.receipt.'.data_get($candidate->support_json, 'application.state', 'reviewed'));
        $this->statusTone = data_get($candidate->support_json, 'application.state') === 'conflict' ? 'info' : 'success';
        $this->closeReview();
    }

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
        $workerHealth = $this->translatedHealth(
            'worker_health',
            (string) ($worker['message_key'] ?? 'no_heartbeats'),
            is_array($worker['message_replace'] ?? null) ? $worker['message_replace'] : [],
        );
        $queueHealthCopy = $this->translatedHealth(
            'queue_health',
            (string) ($queue['message_key'] ?? 'no_jobs'),
            is_array($queue['message_replace'] ?? null) ? $queue['message_replace'] : [],
        );

        if ($workerStatus === OperationalHealthStatus::Unhealthy || (bool) ($queue['worker_appears_idle'] ?? false)) {
            $reason = $workerStatus === OperationalHealthStatus::Unhealthy
                ? $workerHealth
                : $queueHealthCopy;

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
            $this->statusMessage = __('operator_runtime.discovery.queue_problem', ['message' => __('public_discovery.operation_failed')]);
        }
    }

    public function acceptCandidate(int $candidateId): void
    {
        $this->reviewCandidate($candidateId);
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
            $this->statusMessage = __('operator_runtime.discovery.candidate_ignore_failed_detail', ['message' => __('public_discovery.operation_failed')]);
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
        $workerStatusValue = $workerStatus instanceof OperationalHealthStatus ? $workerStatus->value : (string) $workerStatus;
        $workerHealthKey = (string) ($worker['message_key'] ?? 'no_heartbeats');
        $queueHealthKey = (string) ($queue['message_key'] ?? 'no_jobs');
        $workerReplace = is_array($worker['message_replace'] ?? null) ? $worker['message_replace'] : [];
        $queueReplace = is_array($queue['message_replace'] ?? null) ? $queue['message_replace'] : [];

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
            'candidates' => DiscoveryCandidate::query()->where('digital_asset_id', $asset->id)->where('brand_id', $asset->brand_id)
                ->when(in_array($this->filter, ['pending', 'accepted', 'ignored'], true), fn ($query) => $query->where('status', $this->filter))
                ->when($this->filter === 'unapplied', fn ($query) => $query->where('status', 'accepted')->whereNull('support_json->application'))
                ->orderByDesc('id')->paginate(15, pageName: 'candidatesPage'),
            'candidateCounts' => DiscoveryCandidate::query()->where('digital_asset_id', $asset->id)->where('brand_id', $asset->brand_id)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'selectedCandidate' => $this->selectedCandidateId === null ? null : $this->candidate($this->selectedCandidateId),
            'offerings' => BrandOffering::query()->where('brand_id', $asset->brand_id)->where('status', 'active')->with('primaryName')->get(),
            'serviceAreas' => BrandServiceArea::query()->where('brand_id', $asset->brand_id)->where('status', 'active')->get(),
            'runtime' => [
                'tone' => $runtimeTone,
                'worker_status' => $workerStatusValue,
                'worker_status_key' => $workerStatusValue,
                'worker_health_key' => $workerHealthKey,
                'queue_health_key' => $queueHealthKey,
                'worker_health_replace' => $workerReplace,
                'queue_health_replace' => $queueReplace,
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

    /**
     * @param  array<string, mixed>  $replace
     */
    private function translatedHealth(string $group, string $key, array $replace): string
    {
        $translationKey = 'operator_runtime.discovery.'.$group.'.'.$key;
        $translated = __($translationKey, $replace);

        return $translated === $translationKey
            ? __('operator_runtime.discovery.runtime_degraded')
            : $translated;
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
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);

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
            ->where('digital_asset_id', $this->asset()->id)
            ->where('brand_id', $this->asset()->brand_id)
            ->firstOrFail();
    }
}
