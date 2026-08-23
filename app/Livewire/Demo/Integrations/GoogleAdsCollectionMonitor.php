<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Services\Collection\CancellationService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class GoogleAdsCollectionMonitor extends Component
{
    private const array CENTRAL_INTENTS = [
        'google_ads_central_initial',
        'google_ads_central_update',
        'google_ads_central_repair',
        'google_ads_central_resume',
        'google_ads_central_smart',
    ];

    public ?string $actionMessage = null;

    public function stopRun(int $runId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $run = CollectionRun::query()->with('resourceRuns')->findOrFail($runId);

        if (! $this->runBelongsToIntegration($run, $integration)) {
            abort(404);
        }
        if ($run->status->isTerminal()) {
            $this->actionMessage = 'Bu veri toplama zaten tamamlanmış.';

            return;
        }

        $cancellation->requestCancellation($run);
        $this->actionMessage = "Run #{$run->id} için durdurma istendi. Çalışan API isteği güvenli noktada duracak.";
    }

    public function stopResource(int $resourceRunId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $resourceRun = CollectionResourceRun::query()
            ->with(['collectionRun', 'externalResource', 'datasetRuns'])
            ->findOrFail($resourceRunId);
        $run = $resourceRun->collectionRun;

        if (! $run instanceof CollectionRun
            || ! $this->runBelongsToIntegration($run, $integration)
            || (int) $resourceRun->externalResource?->integration_id !== (int) $integration->id
            || $resourceRun->provider_or_source !== 'GOOGLE_ADS') {
            abort(404);
        }
        if ($resourceRun->status->isTerminal()) {
            $this->actionMessage = 'Bu Google Ads hesabı için toplama zaten tamamlanmış.';

            return;
        }

        $name = $resourceRun->externalResource?->display_name ?: 'Google Ads hesabı';
        $cancellation->requestResourceCancellation($resourceRun);
        $this->actionMessage = "{$name} için durdurma istendi. Diğer seçili hesaplar devam edecek.";
    }

    public function repairResource(int $externalResourceId, GoogleAdsCentralCollectionService $collector): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();

        try {
            $run = $collector->startSmartUpdate($integration, [$externalResourceId], auth()->user());
            $label = (string) data_get($run->metadata, 'collection_intent_label', 'Google Ads veri onarımı');
            $this->actionMessage = "{$label} başlatıldı. Run #{$run->id}.";
        } catch (Throwable $e) {
            $this->actionMessage = 'Eksik Google Ads verileri yeniden başlatılamadı: '.$e->getMessage();
        }
    }

    public function render(): View
    {
        $integration = $this->googleIntegration(false);
        if (! $integration instanceof CoreIntegration) {
            return view('livewire.demo.integrations.google-ads-collection-monitor', [
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
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereIn('metadata->collection_intent', self::CENTRAL_INTENTS)
            ->where('request_context->context->google_integration_id', (int) $integration->id)
            ->whereIn('status', $activeStatuses)
            ->with(['resourceRuns.externalResource', 'resourceRuns.datasetRuns', 'datasetRuns'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (CollectionRun $run): array => $this->mapRun($run))
            ->values()
            ->all();

        $issues = CollectionResourceRun::query()
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->whereNull('digital_asset_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereHas('externalResource', fn ($query) => $query->where('integration_id', (int) $integration->id))
            ->whereHas('collectionRun', fn ($query) => $query
                ->where('metadata->collection_scope', 'provider_resource_first')
                ->whereIn('metadata->collection_intent', self::CENTRAL_INTENTS)
                ->where('request_context->context->google_integration_id', (int) $integration->id))
            ->with(['externalResource', 'datasetRuns'])
            ->orderByDesc('id')
            ->limit(150)
            ->get()
            ->unique('external_resource_id')
            ->filter(fn (CollectionResourceRun $resource): bool => in_array($resource->status, [
                CollectionRunStatus::Partial,
                CollectionRunStatus::Failed,
                CollectionRunStatus::Cancelled,
            ], true) || $resource->datasetRuns->contains(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Failed))
            ->take(10)
            ->map(fn (CollectionResourceRun $resource): array => $this->mapIssue($resource))
            ->values()
            ->all();

        return view('livewire.demo.integrations.google-ads-collection-monitor', [
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

    private function googleIntegration(bool $abortWhenMissing = true): ?CoreIntegration
    {
        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->orderBy('id')
            ->first();

        if (! $integration instanceof CoreIntegration && $abortWhenMissing) {
            abort(404);
        }

        return $integration;
    }

    private function runBelongsToIntegration(CollectionRun $run, CoreIntegration $integration): bool
    {
        return data_get($run->metadata, 'collection_scope') === 'provider_resource_first'
            && in_array(data_get($run->metadata, 'collection_intent'), self::CENTRAL_INTENTS, true)
            && (int) data_get($run->request_context, 'context.google_integration_id') === (int) $integration->id;
    }

    /** @return array<string,mixed> */
    private function mapRun(CollectionRun $run): array
    {
        $datasets = $run->datasetRuns;
        $total = $datasets->count();
        $finished = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count();
        $progress = $total > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $total * 100, 1)
            : 0.0;

        $resources = $run->resourceRuns
            ->map(fn (CollectionResourceRun $resource): array => $this->mapResource($resource))
            ->values()
            ->all();

        return [
            'id' => (int) $run->id,
            'label' => (string) (data_get($run->metadata, 'collection_intent_label') ?: 'Google Ads merkezi veri toplama'),
            'status' => $run->status->value,
            'status_label' => $this->statusLabel($run->status),
            'progress_percent' => $progress,
            'accounts_total' => count($resources),
            'accounts_finished' => collect($resources)->where('terminal', true)->count(),
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
            'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
            'datasets_cancelled' => $datasets->where('status', CollectionRunStatus::Cancelled)->count(),
            'rows_received' => (int) $datasets->sum('rows_received'),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'last_activity' => $run->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $run->status->isTerminal() && $run->status !== CollectionRunStatus::CancellationRequested,
            'resources' => $resources,
        ];
    }

    /** @return array<string,mixed> */
    private function mapResource(CollectionResourceRun $resource): array
    {
        $datasets = $resource->datasetRuns;
        $total = $datasets->count();
        $finished = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count();
        $progress = $total > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $total * 100, 1)
            : 0.0;
        $current = $datasets->first(fn (CollectionDatasetRun $dataset): bool => in_array($dataset->status, [
            CollectionRunStatus::Running,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::CancellationRequested,
        ], true)) ?? $datasets->first(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Queued);
        $meta = is_array($resource->externalResource?->metadata) ? $resource->externalResource->metadata : [];
        $checkpoint = $current instanceof CollectionDatasetRun && is_array($current->checkpoint) ? $current->checkpoint : [];
        $range = is_array($checkpoint['last_slice'] ?? null)
            ? $checkpoint['last_slice']
            : (is_array(data_get($current?->metadata, 'date_range')) ? data_get($current?->metadata, 'date_range') : null);
        $customerId = preg_replace('/\D+/', '', (string) ($meta['customer_id'] ?? $resource->externalResource?->external_id)) ?? '';
        $formatted = strlen($customerId) === 10
            ? substr($customerId, 0, 3).'-'.substr($customerId, 3, 3).'-'.substr($customerId, 6)
            : $customerId;

        return [
            'id' => (int) $resource->id,
            'external_resource_id' => (int) $resource->external_resource_id,
            'name' => (string) ($resource->externalResource?->display_name ?: 'Google Ads hesabı'),
            'customer_id' => $formatted,
            'status' => $resource->status->value,
            'status_label' => $this->statusLabel($resource->status),
            'terminal' => $resource->status->isTerminal(),
            'progress_percent' => $progress,
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
            'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'current_dataset' => $current instanceof CollectionDatasetRun ? $this->familyLabel((string) $current->request_family_id) : null,
            'current_range' => is_array($range) && isset($range['start'], $range['end']) ? $range['start'].' → '.$range['end'] : null,
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $resource->status->isTerminal() && $resource->status !== CollectionRunStatus::CancellationRequested,
            'errors' => $datasets
                ->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Failed)
                ->map(fn (CollectionDatasetRun $dataset): array => $this->mapDatasetError($dataset))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function mapIssue(CollectionResourceRun $resource): array
    {
        $mapped = $this->mapResource($resource);
        $mapped['failed_count'] = count($mapped['errors']);

        return $mapped;
    }

    /** @return array<string,mixed> */
    private function mapDatasetError(CollectionDatasetRun $dataset): array
    {
        $message = trim((string) ($dataset->error_message ?? ''));

        return [
            'label' => $this->familyLabel((string) $dataset->request_family_id),
            'category' => $dataset->error_category?->value,
            'code' => filled($dataset->error_code) ? (string) $dataset->error_code : null,
            'message' => $message !== '' ? $message : 'Bu veri grubu tamamlanamadı. Google ayrıntılı bir hata mesajı döndürmedi.',
            'attempts' => (int) $dataset->attempt_count,
        ];
    }

    private function datasetProgress(CollectionDatasetRun $dataset): float
    {
        if ($dataset->status->isTerminal()) {
            return 1.0;
        }
        $percentage = $dataset->percentage();

        return $percentage === null ? 0.0 : min(1.0, max(0.0, $percentage / 100));
    }

    private function familyLabel(string $family): string
    {
        try {
            return GoogleAdsCentralRequestFamilyCatalog::label($family);
        } catch (Throwable) {
            return $family;
        }
    }

    private function statusLabel(CollectionRunStatus $status): string
    {
        return match ($status) {
            CollectionRunStatus::Queued => 'Sırada',
            CollectionRunStatus::Running => 'Çekiliyor',
            CollectionRunStatus::Retrying => 'Yeniden deneniyor',
            CollectionRunStatus::Partial => 'Kısmi',
            CollectionRunStatus::Completed => 'Tamamlandı',
            CollectionRunStatus::Failed => 'Hata',
            CollectionRunStatus::CancellationRequested => 'Durduruluyor',
            CollectionRunStatus::Cancelled => 'Durduruldu',
            CollectionRunStatus::Skipped => 'Atlandı',
            CollectionRunStatus::NotEligible => 'Uygun değil',
        };
    }
}
