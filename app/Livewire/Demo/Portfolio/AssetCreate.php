<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CmsOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\LanguageOptions;
use App\Support\Options\WebsiteTypeOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Add digital asset')]
class AssetCreate extends Component
{
    #[Url]
    public string $brandId = '';

    public string $brand_id = '';

    public bool $brandLocked = false;

    public string $name = '';

    public string $type = 'website';

    public string $status = 'active';

    public string $domain = '';

    public string $primary_url = '';

    public string $cms = '';

    public string $site_type = '';

    /** @var list<string> */
    public array $languages = [];

    /** @var list<string> */
    public array $target_countries = [];

    public string $seo_market_country = '';

    public string $seo_market_language = '';

    public string $hosting_context = '';

    public bool $saving = false;

    public function mount(): void
    {
        if ($this->brandId !== '') {
            abort_unless(ctype_digit($this->brandId), 404);
            abort_if(Brand::query()->find($this->brandId) === null, 404);
            $this->brand_id = $this->brandId;
            $this->brandLocked = true;
        }
    }

    public function updatedType(): void
    {
        if ($this->type !== 'website') {
            $this->domain = '';
            $this->primary_url = '';
            $this->cms = '';
            $this->site_type = '';
            $this->languages = [];
            $this->target_countries = [];
            $this->seo_market_country = '';
            $this->seo_market_language = '';
            $this->hosting_context = '';
        }
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        $brandIds = Brand::query()->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $typeOptions = array_keys($this->typeOptions());

        $rules = [
            'brand_id' => ['required', Rule::in($brandIds)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::in($typeOptions)],
            'status' => ['required', Rule::in(array_column(DigitalAssetStatus::cases(), 'value'))],
        ];

        if ($this->type === 'website') {
            $rules = array_merge($rules, [
                'domain' => ['nullable', 'string', 'max:180'],
                'primary_url' => ['nullable', 'url', 'max:255'],
                'cms' => ['nullable', Rule::in(array_keys(CmsOptions::options()))],
                'site_type' => ['nullable', Rule::in(array_keys(WebsiteTypeOptions::options()))],
                'languages' => ['array'],
                'languages.*' => [Rule::in(array_keys(LanguageOptions::options()))],
                'target_countries' => ['array'],
                'target_countries.*' => [Rule::in(array_keys(CountryOptions::options()))],
                'seo_market_country' => ['nullable', Rule::in(array_keys(CountryOptions::options()))],
                'seo_market_language' => ['nullable', Rule::in(array_keys(LanguageOptions::options()))],
                'hosting_context' => ['nullable', 'string', 'max:2000'],
            ]);
        }

        $this->validate($rules);

        $asset = DigitalAsset::query()->create([
            'brand_id' => (int) $this->brand_id,
            'name' => trim($this->name),
            'type' => $this->type,
            'status' => $this->status,
            'module_id' => OperatorPortfolioPresenter::derivedModuleId($this->type),
            'domain' => $this->type === 'website' && $this->domain !== '' ? trim($this->domain) : null,
            'primary_url' => $this->type === 'website' && $this->primary_url !== '' ? trim($this->primary_url) : null,
            'cms' => $this->type === 'website' && $this->cms !== '' ? $this->cms : null,
            'site_type' => $this->type === 'website' && $this->site_type !== '' ? $this->site_type : null,
            'languages' => $this->type === 'website' ? array_values($this->languages) : null,
            'target_countries' => $this->type === 'website' ? array_values($this->target_countries) : null,
            'hosting_context' => $this->type === 'website' && $this->hosting_context !== '' ? trim($this->hosting_context) : null,
        ]);

        DemoState::flash(__('operator.forms.asset_defined', ['name' => $asset->name]));
        $this->saving = false;

        return $this->redirect(route('operator.assets'), navigate: true);
    }

    /**
     * @return array<string, string>
     */
    protected function typeOptions(): array
    {
        return DigitalAssetTypes::options();
    }

    public function render(): View
    {
        $brands = Brand::query()->with('customer')->orderBy('name')->get();
        $brandOptions = $brands->mapWithKeys(function (Brand $brand): array {
            $label = $brand->name;
            if ($brand->customer?->name) {
                $label .= ' — '.$brand->customer->name;
            }

            return [(string) $brand->id => $label];
        })->all();

        $statusOptions = collect(DigitalAssetStatus::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->name])
            ->all();

        $backUrl = $this->brandLocked
            ? route('operator.brand', ['brand' => $this->brand_id, 'tab' => 'assets'])
            : route('operator.assets');

        return view('livewire.demo.portfolio.asset-form', [
            'mode' => 'create',
            'pageTitle' => __('operator.forms.add_digital_asset'),
            'pageSubtitle' => __('operator.forms.add_digital_asset_subtitle'),
            'backUrl' => $backUrl,
            'brandOptions' => $brandOptions,
            'brandLocked' => $this->brandLocked,
            'brandName' => $brandOptions[$this->brand_id] ?? null,
            'typeOptions' => $this->typeOptions(),
            'statusOptions' => $statusOptions,
            'cmsOptions' => CmsOptions::options(),
            'websiteTypeOptions' => WebsiteTypeOptions::options(),
            'languageOptions' => LanguageOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'isWebsite' => $this->type === 'website',
        ]);
    }
}
