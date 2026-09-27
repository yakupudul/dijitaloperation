<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Concerns\ConfirmsOwnershipTransfer;
use App\Livewire\Demo\Portfolio\Concerns\InteractsWithAssetForm;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Ownership\OwnershipTransferService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Operator edit form for a Digital Asset. The type is fixed after creation. The owner is picked with Customer → Brand:
 * another brand of the same customer (or a brandless website getting its first brand) moves directly; another
 * customer's brand is a yetki devri that an Admin confirms inline and that is recorded (ownership_transfers).
 */
#[Layout('operator.layouts.app')]
#[Title('Dijital varlığı düzenle')]
class AssetEdit extends Component
{
    use ConfirmsOwnershipTransfer;
    use InteractsWithAssetForm;

    public string $assetId = '';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);
        $asset = DigitalAsset::query()->with('brand')->find($assetId);
        abort_if($asset === null, 404);

        $this->assetId = (string) $asset->id;
        $this->fillAssetForm($asset);
    }

    public function save(): mixed
    {
        return $this->persist(false);
    }

    /** "Devret": the Admin ticked "Yetki devrini onaylıyorum" for moving the asset to another customer's brand. */
    public function confirmAssetMove(): mixed
    {
        if ($this->ownershipTransferActor() === null) {
            return null;
        }

        return $this->persist(true);
    }

    private function persist(bool $transferConfirmed): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $asset = DigitalAsset::query()->findOrFail($this->assetId);
            $this->type = (string) $asset->type;
            $this->validate($this->assetRules());
            $target = Brand::query()->findOrFail((int) $this->brand_id);

            if ((int) $asset->brand_id !== (int) $target->id) {
                $conflict = app(OwnershipGuard::class)->forAssetMove($asset, $target);
                if ($conflict !== null && ! $transferConfirmed) {
                    $this->presentOwnershipConflict($conflict, ['brand_id' => (int) $target->id]);

                    return null;
                }
                if ($transferConfirmed && (int) ($this->pendingTransfer['brand_id'] ?? 0) !== (int) $target->id) {
                    // The brand changed after the panel was shown: ask again for the new target.
                    $this->cancelOwnershipTransfer();

                    return null;
                }
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                try {
                    $asset = app(OwnershipTransferService::class)->moveAsset($asset, $target, $actor, $transferConfirmed, $this->transferNote);
                } catch (ValidationException $exception) {
                    $this->addError('brand_id', (string) collect($exception->errors())->flatten()->first());

                    return null;
                }
            }

            $payload = $this->assetPayload();
            if ($payload === []) {
                return null;
            }
            $asset->fill($payload)->save();
        } finally {
            $this->saving = false;
        }

        $this->cancelOwnershipTransfer();
        DemoState::flash(__('operator.forms.asset_updated'));

        return $this->redirect(OperatorPortfolioPresenter::specialistUrl($asset), navigate: true);
    }

    public function render(): View
    {
        $asset = DigitalAsset::query()->findOrFail($this->assetId);

        return view('livewire.demo.portfolio.asset-form', $this->assetFormViewData() + [
            'mode' => 'edit',
            'pageTitle' => __('operator.forms.edit_digital_asset'),
            'pageSubtitle' => __('operator.forms.edit_digital_asset_subtitle'),
            'backUrl' => OperatorPortfolioPresenter::specialistUrl($asset),
            'typeLocked' => true,
            'canTransfer' => $this->canTransferOwnership(),
            'sourcesUrl' => AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) !== []
                ? route('operator.asset.sources', ['assetId' => $asset->id])
                : null,
        ]);
    }
}
