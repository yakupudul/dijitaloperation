<?php

namespace App\Livewire\Demo\Portfolio\Concerns;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\DataForSeo\DataForSeoLabsMarketDirectory;
use App\Support\DigitalAssetTypes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Options\CmsOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\LanguageOptions;
use App\Support\Options\WebsiteTypeOptions;
use Illuminate\Validation\Rule;

/**
 * Shared state, rules and view data for the operator Digital Asset create / edit forms.
 * The SEO market is stored as DataForSEO location/language codes (same as the technical panel).
 */
trait InteractsWithAssetForm
{
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

    public function updatedSeoMarketCountry(): void
    {
        $this->seo_market_language = '';
    }

    protected function fillAssetForm(DigitalAsset $asset): void
    {
        $this->brand_id = (string) $asset->brand_id;
        $this->name = (string) $asset->name;
        $this->type = (string) $asset->type;
        $this->status = $asset->status instanceof DigitalAssetStatus ? $asset->status->value : (string) $asset->status;
        $this->domain = (string) $asset->domain;
        $this->primary_url = (string) $asset->primary_url;
        $this->cms = (string) $asset->cms;
        $this->site_type = (string) $asset->site_type;
        $this->languages = is_array($asset->languages) ? array_values($asset->languages) : [];
        $this->target_countries = is_array($asset->target_countries) ? array_values($asset->target_countries) : [];
        $this->seo_market_country = $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : '';
        $this->seo_market_language = (string) $asset->seo_market_language_code;
        $this->hosting_context = (string) $asset->hosting_context;
    }

    /** @return array<string, mixed> */
    protected function assetRules(): array
    {
        $brandIds = Brand::query()->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $rules = [
            'brand_id' => ['required', Rule::in($brandIds)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::in(array_keys($this->typeOptions()))],
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
                'seo_market_country' => ['nullable', 'string', 'max:20'],
                'seo_market_language' => ['nullable', 'string', 'max:20'],
                'hosting_context' => ['nullable', 'string', 'max:2000'],
            ]);
        }

        return $rules;
    }

    /**
     * Website-only columns. The SEO market is saved only when both codes resolve in the DataForSEO market
     * directory; a blank selection leaves the stored market untouched.
     *
     * @return array<string, mixed>
     */
    protected function assetPayload(): array
    {
        $website = $this->type === 'website';
        $payload = [
            'name' => trim($this->name),
            'status' => $this->status,
            'domain' => $website && $this->domain !== '' ? trim($this->domain) : null,
            'primary_url' => $website && $this->primary_url !== '' ? trim($this->primary_url) : null,
            'cms' => $website && $this->cms !== '' ? $this->cms : null,
            'site_type' => $website && $this->site_type !== '' ? $this->site_type : null,
            'languages' => $website ? array_values($this->languages) : null,
            'target_countries' => $website ? array_values($this->target_countries) : null,
            'hosting_context' => $website && $this->hosting_context !== '' ? trim($this->hosting_context) : null,
        ];

        if ($website && ctype_digit($this->seo_market_country) && $this->seo_market_language !== '') {
            $integration = $this->activeDataForSeo();
            $directory = app(DataForSeoLabsMarketDirectory::class);
            $location = $directory->locationName($integration, (int) $this->seo_market_country);
            $language = $directory->languageName($integration, (int) $this->seo_market_country, $this->seo_market_language);
            if ($location === null || $language === null) {
                $this->addError('seo_market_country', __('operator.forms.asset_form.seo_market_invalid'));

                return [];
            }
            $payload += [
                'seo_market_location_code' => (int) $this->seo_market_country,
                'seo_market_location_name' => $location,
                'seo_market_language_code' => $this->seo_market_language,
                'seo_market_language_name' => $language,
            ];
        }

        return $payload;
    }

    /** @return array<string, string> */
    protected function typeOptions(): array
    {
        return DigitalAssetTypes::options();
    }

    /** @return array<string, mixed> */
    protected function assetFormViewData(): array
    {
        $brandOptions = Brand::query()->with('customer')->orderBy('name')->get()->mapWithKeys(function (Brand $brand): array {
            $label = $brand->name;
            if ($brand->customer?->name) {
                $label .= ' — '.$brand->customer->name;
            }

            return [(string) $brand->id => $label];
        })->all();

        $isWebsite = $this->type === 'website';
        $marketCountries = [];
        $marketLanguages = [];
        if ($isWebsite) {
            $integration = $this->activeDataForSeo();
            $directory = app(DataForSeoLabsMarketDirectory::class);
            $marketCountries = array_map('strval', $directory->locationOptions($integration));
            $marketCountries = array_combine(array_map('strval', array_keys($marketCountries)), array_values($marketCountries));
            if (ctype_digit($this->seo_market_country)) {
                $marketLanguages = $directory->languageOptionsForLocation($integration, (int) $this->seo_market_country);
            }
        }

        return [
            'brandOptions' => $brandOptions,
            'brandLocked' => $this->brandLocked,
            'brandName' => $brandOptions[$this->brand_id] ?? null,
            'typeOptions' => $this->typeOptions(),
            'statusOptions' => collect(DigitalAssetStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->name])->all(),
            'cmsOptions' => CmsOptions::options(),
            'websiteTypeOptions' => WebsiteTypeOptions::options(),
            'languageOptions' => LanguageOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'marketCountryOptions' => $marketCountries,
            'marketLanguageOptions' => $marketLanguages,
            'isWebsite' => $isWebsite,
        ];
    }

    private function activeDataForSeo(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->where('status', CoreIntegration::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }
}
