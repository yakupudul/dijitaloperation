<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithAssetForm;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dijital varlık ekle')]
class AssetCreate extends Component
{
    use InteractsWithAssetForm;

    #[Url]
    public string $brandId = '';

    public function mount(): void
    {
        if ($this->brandId !== '') {
            abort_unless(ctype_digit($this->brandId), 404);
            abort_if(Brand::query()->find($this->brandId) === null, 404);
            $this->brand_id = $this->brandId;
            $this->brandLocked = true;
        }
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->assetRules());
            $payload = $this->assetPayload();
            if ($payload === []) {
                return null;
            }

            $asset = DigitalAsset::query()->create($payload + [
                'brand_id' => (int) $this->brand_id,
                'type' => $this->type,
                'module_id' => OperatorPortfolioPresenter::derivedModuleId($this->type),
            ]);
        } finally {
            $this->saving = false;
        }

        $capabilities = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type);
        if ($capabilities !== []) {
            DemoState::flash(__('operator.forms.asset_defined_next', ['name' => $asset->name]));

            return $this->redirect(route('operator.asset.sources', ['assetId' => $asset->id]), navigate: true);
        }

        DemoState::flash(__('operator.forms.asset_defined', ['name' => $asset->name]));

        return $this->redirect(OperatorPortfolioPresenter::specialistUrl($asset), navigate: true);
    }

    /**
     * Types that can be created. Instagram has no data source yet; GA4 and Search Console are sources of the
     * Website asset (bound on its Data Sources page), not separate assets. Existing ones stay editable.
     *
     * @return array<string, string>
     */
    protected function typeOptions(): array
    {
        return array_diff_key(DigitalAssetTypes::options(), ['instagram' => true, 'ga4' => true, 'gsc' => true]);
    }

    public function render(): View
    {
        $backUrl = $this->brandLocked
            ? route('operator.brand', ['brand' => $this->brand_id, 'tab' => 'assets'])
            : route('operator.assets');

        return view('livewire.demo.portfolio.asset-form', $this->assetFormViewData() + [
            'mode' => 'create',
            'pageTitle' => __('operator.forms.add_digital_asset'),
            'pageSubtitle' => __('operator.forms.add_digital_asset_subtitle'),
            'backUrl' => $backUrl,
            'typeLocked' => false,
            'sourcesUrl' => null,
        ]);
    }
}
