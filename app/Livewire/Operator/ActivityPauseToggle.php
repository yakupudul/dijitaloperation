<?php

namespace App\Livewire\Operator;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Services\Collection\Activity\ActivityTierService;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Google Ads / Meta Ads asset header: the operator marks the ad account "Duraklatıldı (müşteri kararı)".
 * While paused the account is collected like a dormant one (one cheap weekly check); the flag clears itself as soon
 * as spend reappears. Placed with <x-operator.activity-pause :asset="$asset" />.
 */
class ActivityPauseToggle extends Component
{
    private const array CAPABILITIES = ['google_ads', 'meta_ads'];

    #[Locked]
    public string $assetId;

    public string $message = '';

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->asset();
    }

    public function pause(ActivityTierService $tiers): void
    {
        $this->authorizeOperator();
        $resourceId = $this->resourceId();
        if ($resourceId === null) {
            return;
        }
        $tiers->pause($resourceId, auth()->user());
        $this->message = 'Hesap duraklatıldı. Veriler haftada bir kez kontrol edilecek; harcama yeniden başlarsa duraklatma kendiliğinden kalkar.';
    }

    public function resume(ActivityTierService $tiers): void
    {
        $this->authorizeOperator();
        $resourceId = $this->resourceId();
        if ($resourceId === null) {
            return;
        }
        $tiers->resume($resourceId, auth()->user());
        $this->message = 'Duraklatma kaldırıldı.';
    }

    public function render(ActivityTierService $tiers): View
    {
        $resourceId = $this->resourceId();
        $row = $resourceId !== null ? $tiers->row($resourceId) : null;

        return view('livewire.operator.activity-pause-toggle', [
            'available' => $resourceId !== null && $tiers->ready(),
            'activity' => $row instanceof ResourceActivity ? $row : null,
        ]);
    }

    private function authorizeOperator(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->is_active && $user->can(Permissions::ACCESS_APP), 403);
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->whereIn('type', self::CAPABILITIES)->firstOrFail();
    }

    private function resourceId(): ?int
    {
        $asset = $this->asset();
        $id = CoreAssetBinding::query()->where('digital_asset_id', $asset->id)->where('capability', (string) $asset->type)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->whereNotNull('external_resource_id')
            ->orderBy('id')->value('external_resource_id');

        return $id !== null ? (int) $id : null;
    }
}
