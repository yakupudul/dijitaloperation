<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Services\Collection\CancellationService;
use App\Services\Collection\Monitoring\CollectionDatasetLabelResolver;
use App\Services\Collection\ResumeDatasetRunService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class MetaAdsCollectionMonitor extends Component
{
    public ?string $actionMessage = null;

    public function stopRun(int $runId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->metaIntegration();
        $run = CollectionRun::query()->findOrFail($runId);

        if (! $this->runBelongsToIntegration($run, $integration)) {
            abort(404);
        }

        if ($run->status->isTerminal()) {
            $this->actionMessage = 'Bu Meta Ads veri toplama işlemi zaten tamamlanmış.';
            return;
        }

        $cancellation->requestCancellation($run);
        $this->actionMessage = "Run #{$run->id} için durdurma istendi. Çalışan istek güvenli noktada duracak.";
    }

    public function stopResource(int $resourceRunId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->metaIntegration();
        $resource = CollectionResourceRun::query()
            ->with(['collectionRun', 'externalResource'])
            ->findOrFail($resourceRunId);

        if ($resource->provider_or_source !== 'META_ADS'
            || (int) $resource->externalResource?->integration_id !== (int) $integration->id
            || ! $resource->collectionRun instanceof CollectionRun) {
            abort(404);
        }

        if ($resource->status->isTerminal()) {
            $this->actionMessage = 'Bu reklam hesabı için toplama zaten tamamlanmış.';
            return;
        }

        $name = $resource->externalResource?->display_name ?: 'Meta Ad Account';
        $cancellation->requestResourceCancellation($resource);
        $this->actionMessage = "{$name} için durdurma istendi. Diğer reklam hesapları devam edecek.";
    }

    public function retryDataset(int $datasetRunId, ResumeDatasetRunService $resume): void
    {
        $this->authorizeOperator();
        $integration = $this->metaIntegration();
        $dataset = CollectionDatasetRun::query()
            ->with(['resourceRun.externalResource', 'collectionRun'])
            ->findOrFail($datasetRunId);

        if ($dataset->provider_or_source !== 'META_ADS'
            || (int) $dataset->resourceRun?->externalResource?->integration_id !== (int) $integration->id
            || $dataset->status !== CollectionRunStatus::Failed) {
            abort(404);
        }

        $resume->resume($dataset);
        $this->actionMessage = $this->datasetLabel($dataset).' yeniden denemeye alındı.';
    }

    public function render(): View
    {
        $integration = $this->metaIntegration(false);
        if (! $integration instanceof CoreIntegration) {
            return view('livewire.demo.integrations.meta-ads-collection-monitor', [
                'runs' => [],
                'issues' => [],
            ]);
        }

        $activeStatuses = [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
            CollectionRunStatus::CancellationRequested->value,
        ];

        $runs = CollectionRun::query()
            ->whereIn('status', $activeStatuses)
            ->whereHas('resourceRuns', fn ($q) => $q
                ->where('provider_or_source', 'META_ADS')
                ->whereHas('externalResource', fn ($r) => $r->where('integration_id', (int) $integration->id)))
            ->with(['resourceRuns' => fn ($q) => $q
                ->where('provider_or_source', 'META_ADS')
                ->whereHas('externalResource', fn ($r) => $r->where('integration_id', (int) $integration->id))
                ->with(['externalResource', 'datasetRuns']), 'datasetRuns'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (CollectionRun $run): array => $this->mapRun($run))
            ->values()
            ->all();

        $issues = CollectionResourceRun::query()
            ->where('provider_or_source', 'META_ADS')
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', (int) $integration->id))
            ->whereHas('datasetRuns', fn ($q) => $q->where('status', CollectionRunStatus::Failed->value))
            ->with(['externalResource', 'datasetRuns'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->unique('external_resource_id')
            ->take(10)
            ->map(fn (CollectionResourceRun $resource): array => $this->mapIssue($resource))
            ->filter(fn (array $issue): bool => $issue['failed_count'] > 0)
            ->values()
            ->all();

        return view('livewire.demo.integrations.meta-ads-collection-monitor', [
            'runs' => $runs,
            'issues' => $issues,
        ]);
    }

    private function authorizeOperator(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }
    }

    private function metaIntegration(bool $abortWhenMissing = true): ?CoreIntegration
    {
        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::META)
            ->orderBy('id')
            ->first();

        if (! $integration instanceof CoreIntegration && $abortWhenMissing) {
            abort(404);
        }

        return $integration;
    }

    private function runBelongsToIntegration(CollectionRun $run, CoreIntegration $integration): bool
    {
        return $run->resourceRuns()
            ->where('provider_or_source', 'META_ADS')
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', (int) $integration->id))
            ->exists();
    }

    /** @return array<string,mixed> */
    private function mapRun(CollectionRun $run): array
    {
        $resources = $run->resourceRuns->values();
        $datasets = $resources->flatMap->datasetRuns;
        $total = $datasets->count();
        $finished = $datasets->filter(fn (CollectionDatasetRun $d): bool => $d->status->isTerminal())->count();
        $progress = $total > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $d): float => $this->datasetProgress($d)) / $total * 100, 1)
            : 0.0;

        return [
            'id' => (int) $run->id,
            'label' => (string) (data_get($run->metadata, 'collection_intent_label') ?: data_get($run->request_context, 'context.collection_intent_label') ?: 'Meta Ads Veri Toplama'),
            'status' => $run->status->value,
            'status_label' => $this->statusLabel($run->status),
            'progress_percent' => $progress,
            'accounts_total' => $resources->count(),
            'accounts_finished' => $resources->filter(fn (CollectionResourceRun $r): bool => $r->status->isTerminal())->count(),
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
            'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
            'datasets_retrying' => $datasets->where('status', CollectionRunStatus::Retrying)->count(),
            'rows_received' => (int) $datasets->sum('rows_received'),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'last_activity' => $run->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $run->status->isTerminal() && $run->status !== CollectionRunStatus::CancellationRequested,
            'resources' => $resources->map(fn (CollectionResourceRun $r): array => $this->mapResource($r))->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function mapResource(CollectionResourceRun $resource): array
    {
        $datasets = $resource->datasetRuns;
        $total = $datasets->count();
        $finished = $datasets->filter(fn (CollectionDatasetRun $d): bool => $d->status->isTerminal())->count();
        $progress = $total > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $d): float => $this->datasetProgress($d)) / $total * 100, 1)
            : 0.0;
        $current = $datasets->first(fn (CollectionDatasetRun $d): bool => in_array($d->status, [CollectionRunStatus::Running, CollectionRunStatus::Retrying, CollectionRunStatus::CancellationRequested], true))
            ?? $datasets->first(fn (CollectionDatasetRun $d): bool => $d->status === CollectionRunStatus::Queued);
        $meta = is_array($resource->externalResource?->metadata) ? $resource->externalResource->metadata : [];
        $range = $current instanceof CollectionDatasetRun ? data_get($current->metadata, 'date_range') : null;
        if (! is_array($range) && $current instanceof CollectionDatasetRun && is_array($current->checkpoint)) {
            $range = data_get($current->checkpoint, 'last_slice');
        }

        return [
            'id' => (int) $resource->id,
            'name' => (string) ($resource->externalResource?->display_name ?: 'Meta Ad Account'),
            'external_id' => (string) ($resource->externalResource?->external_id ?: '—'),
            'business' => $meta['business_name'] ?? $resource->externalResource?->parent_external_id,
            'currency' => $meta['currency'] ?? null,
            'timezone' => $meta['timezone_name'] ?? null,
            'status' => $resource->status->value,
            'status_label' => $this->statusLabel($resource->status),
            'terminal' => $resource->status->isTerminal(),
            'progress_percent' => $progress,
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'current_dataset' => $current instanceof CollectionDatasetRun ? $this->datasetLabel($current) : null,
            'current_range' => is_array($range) && isset($range['start'], $range['end']) ? $range['start'].' → '.$range['end'] : null,
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $resource->status->isTerminal() && $resource->status !== CollectionRunStatus::CancellationRequested,
            'errors' => $datasets
                ->filter(fn (CollectionDatasetRun $d): bool => $d->status === CollectionRunStatus::Failed)
                ->map(fn (CollectionDatasetRun $d): array => $this->mapDatasetError($d))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function mapIssue(CollectionResourceRun $resource): array
    {
        $meta = is_array($resource->externalResource?->metadata) ? $resource->externalResource->metadata : [];
        $errors = $resource->datasetRuns
            ->filter(fn (CollectionDatasetRun $d): bool => $d->status === CollectionRunStatus::Failed)
            ->map(fn (CollectionDatasetRun $d): array => $this->mapDatasetError($d))
            ->values()
            ->all();

        return [
            'name' => (string) ($resource->externalResource?->display_name ?: 'Meta Ad Account'),
            'business' => $meta['business_name'] ?? $resource->externalResource?->parent_external_id,
            'failed_count' => count($errors),
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    private function mapDatasetError(CollectionDatasetRun $dataset): array
    {
        $message = trim((string) ($dataset->error_message ?? ''));
        return [
            'id' => (int) $dataset->id,
            'label' => $this->datasetLabel($dataset),
            'category' => $dataset->error_category?->value,
            'code' => filled($dataset->error_code) ? (string) $dataset->error_code : null,
            'message' => $message !== '' ? $message : 'Meta bu veri grubu için ayrıntılı bir hata mesajı döndürmedi.',
            'attempts' => (int) $dataset->attempt_count,
        ];
    }

    private function datasetLabel(CollectionDatasetRun $dataset): string
    {
        return app(CollectionDatasetLabelResolver::class)->label((string) $dataset->dataset_contract_id);
    }

    private function datasetProgress(CollectionDatasetRun $dataset): float
    {
        if ($dataset->status->isTerminal()) {
            return 1.0;
        }
        $percentage = $dataset->percentage();
        return $percentage === null ? 0.0 : min(1.0, max(0.0, $percentage / 100));
    }

    private function statusLabel(CollectionRunStatus $status): string
    {
        return match ($status) {
            CollectionRunStatus::Queued => 'Sırada',
            CollectionRunStatus::Running => 'Çekiliyor',
            CollectionRunStatus::Retrying => 'Tekrar deneniyor',
            CollectionRunStatus::CancellationRequested => 'Durduruluyor',
            CollectionRunStatus::Completed => 'Tamamlandı',
            CollectionRunStatus::Partial => 'Kısmen tamamlandı',
            CollectionRunStatus::Failed => 'Hata',
            CollectionRunStatus::Cancelled => 'Durduruldu',
            CollectionRunStatus::Skipped => 'Atlandı',
            CollectionRunStatus::NotEligible => 'Uygun değil',
        };
    }
}
