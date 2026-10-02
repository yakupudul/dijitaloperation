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
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\AssetBindingCompatibility;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dijital varlık ekle')]
class AssetCreate extends Component
{
    use ConfirmsOwnershipTransfer;
    use InteractsWithAssetForm;

    /**
     * A website with the typed domain already exists: no duplicate is created, the operator may move it here instead.
     *
     * @var array{id: int, message: string, movable: bool}|null
     */
    public ?array $existingSite = null;

    #[Url]
    public string $brandId = '';

    public function mount(): void
    {
        if ($this->brandId !== '') {
            abort_unless(ctype_digit($this->brandId), 404);
            abort_if(Brand::query()->find($this->brandId) === null, 404);
            $this->brand_id = $this->brandId;
            $this->customer_id = (string) Brand::query()->whereKey($this->brandId)->value('customer_id');
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
            if ($this->type === 'website' && $this->offerExistingSite()) {
                return null;
            }
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
     * Website domain already in the portfolio: brandless or same customer → offer the move; another customer →
     * yetki devri panel (Admin + explicit confirmation). Returns true when the create is stopped.
     */
    private function offerExistingSite(): bool
    {
        $url = trim($this->primary_url) !== '' ? $this->primary_url : $this->domain;
        $site = trim($url) !== '' ? app(OwnershipGuard::class)->existingWebsite($url) : null;
        if ($site === null) {
            return false;
        }
        $target = Brand::query()->findOrFail((int) $this->brand_id);
        if ((int) $site->brand_id === (int) $target->id) {
            $this->addError('domain', 'Bu alan adı bu markada zaten kayıtlı: '.$site->name.'.');

            return true;
        }
        $conflict = app(OwnershipGuard::class)->forAssetMove($site, $target);
        if ($conflict !== null) {
            $this->presentOwnershipConflict($conflict, ['site_id' => (int) $site->id, 'brand_id' => (int) $target->id]);
            $this->existingSite = ['id' => (int) $site->id, 'message' => $conflict->plainMessage(), 'movable' => false];

            return true;
        }
        $this->existingSite = [
            'id' => (int) $site->id,
            'message' => $site->brand_id === null
                ? sprintf('%s markaya bağlı olmadan ekli (Entegrasyonlar › Web sitesi).', $site->name)
                : sprintf('%s aynı müşterinin %s markasında kayıtlı.', $site->name, (string) $site->brand?->name),
            'movable' => true,
        ];

        return true;
    }

    public function dismissExistingSite(): void
    {
        $this->existingSite = null;
        $this->cancelOwnershipTransfer();
    }

    /** Moves the existing website to the selected brand instead of creating a duplicate. */
    public function moveExistingSite(OwnershipTransferService $transfers): mixed
    {
        $siteId = (int) ($this->existingSite['id'] ?? 0);
        $site = DigitalAsset::query()->where('type', 'website')->find($siteId);
        $target = ctype_digit($this->brand_id) ? Brand::query()->find((int) $this->brand_id) : null;
        if ($site === null || $target === null) {
            $this->dismissExistingSite();

            return null;
        }
        $confirmed = false;
        if ($this->ownershipConflict !== null) {
            if ($this->ownershipTransferActor() === null) {
                return null;
            }
            $confirmed = (int) ($this->pendingTransfer['site_id'] ?? 0) === $siteId && (int) ($this->pendingTransfer['brand_id'] ?? 0) === (int) $target->id;
        }
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        try {
            $site = $transfers->moveAsset($site, $target, $actor, $confirmed, $this->transferNote);
        } catch (ValidationException $exception) {
            $this->addError('domain', (string) collect($exception->errors())->flatten()->first());

            return null;
        }
        $this->dismissExistingSite();
        DemoState::flash($site->name.' bu markaya taşındı; ikinci bir web sitesi oluşturulmadı.');

        return $this->redirect(route('operator.asset.sources', ['assetId' => $site->id]), navigate: true);
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
            'canTransfer' => $this->canTransferOwnership(),
        ]);
    }
}
