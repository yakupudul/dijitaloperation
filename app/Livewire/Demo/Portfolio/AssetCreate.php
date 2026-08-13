<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\DigitalAssetStatus;
use App\Support\Demo\DemoCatalog;
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
            $this->brand_id = $this->brandId;
            $this->brandLocked = true;
        } else {
            $this->brand_id = DemoCatalog::BRAND_ID;
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

        $brandIds = collect(DemoState::all()['brands'] ?? [])->pluck('id')->all();
        if ($brandIds === []) {
            $brandIds = [DemoCatalog::BRAND_ID];
        }

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

        $id = 'da-demo-'.substr(md5($this->name.microtime(true)), 0, 8);
        $taxonomy = DemoCatalog::assetTaxonomy($this->type === 'google_business_profile' ? 'gbp' : $this->type);

        $asset = [
            'id' => $id,
            'brand_id' => $this->brand_id,
            'name' => trim($this->name),
            'type' => $this->type,
            'type_label' => $this->typeOptions()[$this->type] ?? $this->type,
            'status' => $this->status,
            'role' => $taxonomy['role'],
            'role_label' => $taxonomy['role_label'],
            'health' => 'healthy',
            'health_label' => 'Healthy',
            'provenance' => 'Manual',
            'open_findings' => 0,
            'last_update' => 'Just now',
            'primary_metric_label' => 'Status',
            'primary_metric' => 'Registered',
            'route' => match ($this->type) {
                'website' => 'demo.website',
                'meta_ads' => 'demo.meta.overview',
                'google_ads' => 'demo.google-ads.overview',
                'google_business_profile' => 'demo.gbp',
                'ga4', 'analytics' => 'demo.analytics',
                'gsc', 'search_console' => 'demo.search-console',
                'instagram' => 'demo.instagram',
                default => 'demo.assets',
            },
            // Website-specific (not shown for other types)
            'domain' => $this->type === 'website' ? ($this->domain !== '' ? trim($this->domain) : null) : null,
            'primary_url' => $this->type === 'website' ? ($this->primary_url !== '' ? trim($this->primary_url) : null) : null,
            'cms' => $this->type === 'website' && $this->cms !== '' ? $this->cms : null,
            'site_type' => $this->type === 'website' && $this->site_type !== '' ? $this->site_type : null,
            'languages' => $this->type === 'website' ? array_values($this->languages) : [],
            'target_countries' => $this->type === 'website' ? array_values($this->target_countries) : [],
            'seo_market_country' => $this->type === 'website' && $this->seo_market_country !== '' ? $this->seo_market_country : null,
            'seo_market_language' => $this->type === 'website' && $this->seo_market_language !== '' ? $this->seo_market_language : null,
            'hosting_context' => $this->type === 'website' && $this->hosting_context !== '' ? trim($this->hosting_context) : null,
            // module_id is derived — never collected as free-text from operators
            'module_id' => $this->derivedModuleId($this->type),
        ];

        DemoState::addDemoAsset($asset);
        $this->saving = false;

        return $this->redirect(route('demo.assets'), navigate: true);
    }

    /**
     * @return array<string, string>
     */
    protected function typeOptions(): array
    {
        $options = DigitalAssetTypes::options();
        // Preserve demo product types already present in the catalog
        $options['ga4'] = 'Google Analytics';
        $options['gsc'] = 'Search Console';
        // Domain / Hosting are Website Infrastructure — not selectable Digital Assets.

        return $options;
    }

    protected function derivedModuleId(string $type): ?string
    {
        return match ($type) {
            'website' => 'website',
            'meta_ads' => 'meta-ads',
            'google_ads' => 'google-ads',
            'google_business_profile' => 'google-business-profile',
            'ga4', 'analytics' => 'analytics',
            'gsc', 'search_console' => 'search-console',
            'instagram' => 'instagram',
            default => null,
        };
    }

    public function render(): View
    {
        $brands = collect(DemoState::all()['brands'] ?? []);
        if ($brands->isEmpty()) {
            $brands = collect([DemoCatalog::brand()]);
        }
        $customers = collect(DemoState::all()['customers'] ?? [])->keyBy('id');

        $brandOptions = $brands->mapWithKeys(function (array $brand) use ($customers): array {
            $customerName = $customers[$brand['customer_id'] ?? '']['name'] ?? null;
            $label = $brand['name'] ?? '';
            if (is_string($customerName) && $customerName !== '') {
                $label .= ' — '.$customerName;
            }

            return [($brand['id'] ?? '') => $label];
        })->all();

        $statusOptions = collect(DigitalAssetStatus::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->name])
            ->all();

        $backUrl = $this->brandLocked
            ? route('demo.brand', ['brand' => $this->brand_id, 'tab' => 'assets'])
            : route('demo.assets');

        return view('livewire.demo.portfolio.asset-form', [
            'mode' => 'create',
            'pageTitle' => 'Add digital asset',
            'pageSubtitle' => 'Register the managed asset. Provider connections happen later in Integrations.',
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
