<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Services\Collection\CancellationService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Ga4CollectionMonitor extends Component
{
    public ?string $actionMessage = null;

    public function stopRun(int $runId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $run = $this->scopedRun($runId, $integration);

        if ($run->status->isTerminal()) {
            $this->actionMessage = 'Bu veri toplama zaten tamamlanmış.';

            return;
        }

        $cancellation->requestCancellation($run);
        $this->actionMessage = "Run #{$run->id} için durdurma istendi. Çalışan istek güvenli noktada duracak.";
    }

    public function stopResource(int $resourceRunId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();

        $resourceRun = CollectionResourceRun::query()
            ->with(['collectionRun', 'externalResource'])
            ->findOrFail($resourceRunId);

        $run = $resourceRun->collectionRun;
        if (! $run instanceof CollectionRun
            || ! $this->runBelongsToIntegration($run, $integration)
            || (int) $resourceRun->externalResource?->integration_id !== (int) $integration->id
            || $resourceRun->provider_or_source !== 'GA4') {
            abort(404);
        }

        if ($resourceRun->status->isTerminal()) {
            $this->actionMessage = 'Bu GA4 property için toplama zaten bitmiş.';

            return;
        }

        $name = $resourceRun->externalResource?->display_name ?: 'GA4 property';
        $cancellation->requestResourceCancellation($resourceRun);
        $this->actionMessage = "{$name} için durdurma istendi. Diğer seçili property'ler devam edecek.";
    }

    public function render(): View
    {
        $integration = $this->googleIntegration(false);
        if (! $integration instanceof CoreIntegration) {
            return view('livewire.demo.integrations.ga4-collection-monitor', [
                'runs' => [],
                'hasActive' => false,
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
            ->whereIn('metadata->collection_intent', ['ga4_central_initial', 'ga4_central_restatement'])
            ->where('request_context->context->google_integration_id', (int) $integration->id)
            ->whereIn('status', $activeStatuses)
            ->with([
                'resourceRuns.externalResource',
                'resourceRuns.datasetRuns',
                'datasetRuns',
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (CollectionRun $run): array => $this->mapRun($run))
            ->values()
            ->all();

        return view('livewire.demo.integrations.ga4-collection-monitor', [
            'runs' => $runs,
            'hasActive' => $runs !== [],
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

    private function scopedRun(int $runId, CoreIntegration $integration): CollectionRun
    {
        $run = CollectionRun::query()->findOrFail($runId);
        if (! $this->runBelongsToIntegration($run, $integration)) {
            abort(404);
        }

        return $run;
    }

    private function runBelongsToIntegration(CollectionRun $run, CoreIntegration $integration): bool
    {
        return data_get($run->metadata, 'collection_scope') === 'provider_resource_first'
            && in_array(data_get($run->metadata, 'collection_intent'), ['ga4_central_initial', 'ga4_central_restatement'], true)
            && (int) data_get($run->request_context, 'context.google_integration_id') === (int) $integration->id;
    }

    /** @return array<string, mixed> */
    private function mapRun(CollectionRun $run): array
    {
        $datasets = $run->datasetRuns;
        $datasetTotal = $datasets->count();
        $finishedDatasets = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count();
        $completedDatasets = $datasets->where('status', CollectionRunStatus::Completed)->count();
        $failedDatasets = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $cancelledDatasets = $datasets->where('status', CollectionRunStatus::Cancelled)->count();
        $progress = $datasetTotal > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $datasetTotal * 100, 1)
            : 0.0;

        $resources = $run->resourceRuns
            ->map(fn (CollectionResourceRun $resource): array => $this->mapResource($resource))
            ->values()
            ->all();

        return [
            'id' => (int) $run->id,
            'label' => (string) (data_get($run->metadata, 'collection_intent_label') ?: 'GA4 Merkezi Veri Toplama'),
            'status' => $run->status->value,
            'status_label' => $this->statusLabel($run->status),
            'progress_percent' => $progress,
            'properties_total' => count($resources),
            'properties_finished' => collect($resources)->where('terminal', true)->count(),
            'datasets_total' => $datasetTotal,
            'datasets_finished' => $finishedDatasets,
            'datasets_completed' => $completedDatasets,
            'datasets_failed' => $failedDatasets,
            'datasets_cancelled' => $cancelledDatasets,
            'rows_received' => (int) $datasets->sum('rows_received'),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'last_activity' => $run->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $run->status->isTerminal() && $run->status !== CollectionRunStatus::CancellationRequested,
            'resources' => $resources,
        ];
    }

    /** @return array<string, mixed> */
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

        $resourceMeta = is_array($resource->externalResource?->metadata) ? $resource->externalResource->metadata : [];
        $checkpoint = $current instanceof CollectionDatasetRun && is_array($current->checkpoint) ? $current->checkpoint : [];
        $range = is_array($checkpoint['last_slice'] ?? null)
            ? $checkpoint['last_slice']
            : ($current instanceof CollectionDatasetRun && is_array(data_get($current->metadata, 'date_range'))
                ? data_get($current->metadata, 'date_range')
                : null);

        return [
            'id' => (int) $resource->id,
            'name' => (string) ($resource->externalResource?->display_name ?: 'GA4 Property'),
            'account_name' => $resourceMeta['account_display_name'] ?? $resourceMeta['account'] ?? 'Google Analytics',
            'property_id' => $resourceMeta['property_id'] ?? preg_replace('/^properties\//', '', (string) $resource->externalResource?->external_id),
            'status' => $resource->status->value,
            'status_label' => $this->statusLabel($resource->status),
            'terminal' => $resource->status->isTerminal(),
            'progress_percent' => $progress,
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
            'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
            'datasets_cancelled' => $datasets->where('status', CollectionRunStatus::Cancelled)->count(),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'current_dataset' => $current instanceof CollectionDatasetRun ? $this->familyLabel((string) $current->request_family_id) : null,
            'current_stage' => $current?->stage,
            'current_range' => is_array($range) && isset($range['start'], $range['end']) ? $range['start'].' → '.$range['end'] : null,
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $resource->status->isTerminal() && $resource->status !== CollectionRunStatus::CancellationRequested,
        ];
    }

    private function datasetProgress(CollectionDatasetRun $dataset): float
    {
        if ($dataset->status->isTerminal()) {
            return 1.0;
        }

        $percentage = $dataset->percentage();
        if ($percentage === null) {
            return 0.0;
        }

        return min(1.0, max(0.0, $percentage / 100));
    }

    private function statusLabel(CollectionRunStatus $status): string
    {
        return match ($status) {
            CollectionRunStatus::Queued => 'Sırada',
            CollectionRunStatus::Running => 'Çekiliyor',
            CollectionRunStatus::Retrying => 'Tekrar deneniyor',
            CollectionRunStatus::CancellationRequested => 'Durduruluyor',
            CollectionRunStatus::Completed => 'Tamamlandı',
            CollectionRunStatus::Partial => 'Kısmi tamamlandı',
            CollectionRunStatus::Failed => 'Hata',
            CollectionRunStatus::Cancelled => 'Durduruldu',
            CollectionRunStatus::Skipped => 'Atlandı',
            CollectionRunStatus::NotEligible => 'Uygun değil',
        };
    }

    private function familyLabel(string $family): string
    {
        return match ($family) {
            'GA4_RF_PROPERTY_METADATA' => 'Property ve yapı bilgileri',
            'GA4_RF_PROPERTY_DAILY' => 'Günlük ana performans',
            'GA4_RF_CHANNEL_DAILY' => 'Kanal performansı',
            'GA4_RF_SOURCE_MEDIUM_DAILY' => 'Source / Medium',
            'GA4_RF_CAMPAIGN_DAILY' => 'Kampanyalar',
            'GA4_RF_FIRST_USER_DAILY' => 'İlk kullanıcı kazanımı',
            'GA4_RF_LANDING_PAGE_DAILY' => 'Landing page',
            'GA4_RF_PAGE_CONTENT_DAILY' => 'Sayfa ve içerik',
            'GA4_RF_EVENT_DAILY' => 'Event verileri',
            'GA4_RF_KEY_EVENT_DAILY' => 'Key Event verileri',
            'GA4_RF_DEVICE_DAILY' => 'Cihaz',
            'GA4_RF_TECHNOLOGY_DAILY' => 'Tarayıcı / işletim sistemi',
            'GA4_RF_COUNTRY_DAILY' => 'Ülke',
            'GA4_RF_REGION_DAILY' => 'Bölge',
            'GA4_RF_CITY_DAILY' => 'Şehir',
            'GA4_RF_HOUR_DAILY' => 'Gün / saat',
            'GA4_RF_ECOMMERCE_ITEM_DAILY' => 'E-ticaret ürünleri',
            default => $family,
        };
    }
}
