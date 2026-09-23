<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithAssetForm;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoState;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Operator edit form for a Digital Asset. Brand and type are fixed after creation: data, bindings and
 * advisor history hang off the asset, so moving or retyping it is a technical (Filament) operation.
 */
#[Layout('operator.layouts.app')]
#[Title('Dijital varlığı düzenle')]
class AssetEdit extends Component
{
    use InteractsWithAssetForm;

    public string $assetId = '';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);
        $asset = DigitalAsset::query()->find($assetId);
        abort_if($asset === null, 404);

        $this->assetId = (string) $asset->id;
        $this->fillAssetForm($asset);
        $this->brandLocked = true;
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $asset = DigitalAsset::query()->findOrFail($this->assetId);
            $this->brand_id = (string) $asset->brand_id;
            $this->type = (string) $asset->type;
            $this->validate($this->assetRules());
            $payload = $this->assetPayload();
            if ($payload === []) {
                return null;
            }
            $asset->fill($payload)->save();
        } finally {
            $this->saving = false;
        }

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
            'sourcesUrl' => AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) !== []
                ? route('operator.asset.sources', ['assetId' => $asset->id])
                : null,
        ]);
    }
}
