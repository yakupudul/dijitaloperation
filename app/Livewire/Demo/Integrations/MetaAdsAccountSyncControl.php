<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreIntegration;
use App\Services\Collection\Meta\MetaSingleBindingCollectionOrchestrator;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class MetaAdsAccountSyncControl extends Component
{
    public ?string $feedback = null;

    public string $feedbackTone = 'info';

    public function updateAccount(string $bindingId, MetaSingleBindingCollectionOrchestrator $collector): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if (! $integration instanceof CoreIntegration) {
            $this->feedback = 'Meta entegrasyonu bulunamadı.';
            $this->feedbackTone = 'warning';

            return;
        }

        $binding = CoreAssetBinding::query()
            ->with(['digitalAsset', 'externalResource'])
            ->whereKey($bindingId)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('capability', MetaConnectorRegistry::META_ADS)
            ->whereHas('externalResource', fn ($query) => $query
                ->where('integration_id', (int) $integration->id)
                ->where('provider', ProviderRegistry::META)
                ->where('resource_type', MetaResourceType::META_AD_ACCOUNT))
            ->first();

        if (! $binding instanceof CoreAssetBinding) {
            $this->feedback = 'Bağlı Meta reklam hesabı bulunamadı.';
            $this->feedbackTone = 'warning';

            return;
        }

        $result = $collector->start($integration, $binding, $user);
        $this->feedback = $result['message'];
        $this->feedbackTone = in_array($result['outcome'], ['started', 'active_equivalent', 'data_current'], true)
            ? 'success'
            : 'warning';

        if ($result['collection_run'] !== null) {
            $this->dispatch('collection-run-selected', uuid: $result['collection_run']->uuid);
        }
    }

    public function render(): View
    {
        $integration = $this->metaIntegration();
        $accounts = [];

        if ($integration instanceof CoreIntegration) {
            $bindings = CoreAssetBinding::query()
                ->with(['digitalAsset.brand', 'externalResource'])
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->where('capability', MetaConnectorRegistry::META_ADS)
                ->whereHas('externalResource', fn ($query) => $query
                    ->where('integration_id', (int) $integration->id)
                    ->where('provider', ProviderRegistry::META)
                    ->where('resource_type', MetaResourceType::META_AD_ACCOUNT))
                ->orderBy('id')
                ->get();

            $accounts = $bindings->map(function (CoreAssetBinding $binding): array {
                $lastRun = CollectionResourceRun::query()
                    ->with('collectionRun')
                    ->where('core_asset_binding_id', (int) $binding->id)
                    ->where('provider_or_source', 'META_ADS')
                    ->orderByDesc('id')
                    ->first();

                $meta = is_array($binding->externalResource?->metadata)
                    ? $binding->externalResource->metadata
                    : [];

                return [
                    'binding_id' => (int) $binding->id,
                    'name' => (string) ($binding->externalResource?->display_name ?: 'Meta Ad Account'),
                    'external_id' => (string) ($binding->externalResource?->external_id ?: '—'),
                    'currency' => $meta['currency'] ?? null,
                    'asset' => (string) ($binding->digitalAsset?->name ?: 'Meta Ads'),
                    'brand' => (string) ($binding->digitalAsset?->brand?->name ?: ''),
                    'status' => $lastRun?->status?->value,
                    'status_label' => $this->statusLabel($lastRun?->status),
                    'last_activity' => $lastRun?->last_activity_at?->diffForHumans(),
                    'has_collection' => $lastRun !== null,
                    'active' => $lastRun !== null && ! $lastRun->status->isTerminal(),
                ];
            })->all();
        }

        return view('livewire.demo.integrations.meta-ads-account-sync-control', [
            'accounts' => $accounts,
        ]);
    }

    private function metaIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->where('provider', ProviderRegistry::META)
            ->orderBy('id')
            ->first();
    }

    private function statusLabel(?CollectionRunStatus $status): string
    {
        return match ($status) {
            CollectionRunStatus::Queued => 'Sırada',
            CollectionRunStatus::Running => 'Güncelleniyor',
            CollectionRunStatus::Retrying => 'Yeniden deneniyor',
            CollectionRunStatus::Completed => 'Güncel',
            CollectionRunStatus::Partial => 'Kısmen güncellendi',
            CollectionRunStatus::Failed => 'Güncelleme başarısız',
            CollectionRunStatus::CancellationRequested => 'Durduruluyor',
            CollectionRunStatus::Cancelled => 'Durduruldu',
            CollectionRunStatus::Skipped => 'Atlandı',
            CollectionRunStatus::NotEligible => 'Uygun değil',
            default => 'Henüz veri alınmadı',
        };
    }
}
