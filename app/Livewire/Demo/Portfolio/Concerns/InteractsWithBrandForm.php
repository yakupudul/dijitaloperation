<?php

namespace App\Livewire\Demo\Portfolio\Concerns;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\ServiceCatalogItem;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Options\LanguageOptions;
use Illuminate\Validation\Rule;

trait InteractsWithBrandForm
{
    public string $customer_id = '';

    public string $name = '';

    public string $sector = '';

    public string $primary_country = '';

    /** @var list<string> */
    public array $target_markets = [];

    /** @var list<string> */
    public array $languages = [];

    public string $description = '';

    public string $audience = '';

    public string $offerings = '';

    public string $competitors = '';

    /** @var list<string> */
    public array $responsible_user_ids = [];

    public string $logo_url = '';

    /** @var list<string> */
    public array $selected_service_catalog_ids = [];

    /** @var list<string> */
    public array $priority_service_catalog_ids = [];

    /** @var list<array{country_code: string, city_name: string, district_name: string}> */
    public array $service_areas = [['country_code' => 'TR', 'city_name' => '', 'district_name' => '']];

    public string $new_service_name = '';

    public bool $new_service_is_priority = false;

    public bool $customerLocked = false;

    public bool $saving = false;

    /**
     * @return array<string, mixed>
     */
    protected function brandRules(): array
    {
        $customerIds = Customer::query()->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $eligible = array_map(static fn (int $id): string => (string) $id, OperatorUserDirectory::eligibleIds());

        return [
            'customer_id' => ['required', Rule::in($customerIds)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'sector' => ['nullable', Rule::in(array_keys(IndustryOptions::options()))],
            'primary_country' => ['nullable', Rule::in(array_keys(CountryOptions::options()))],
            'target_markets' => ['array'],
            'target_markets.*' => [Rule::in(array_keys(CountryOptions::options()))],
            'languages' => ['array'],
            'languages.*' => [Rule::in(array_keys(LanguageOptions::options()))],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:2000'],
            'offerings' => ['nullable', 'string', 'max:2000'],
            'competitors' => ['nullable', 'string', 'max:2000'],
            'responsible_user_ids' => ['array'],
            'responsible_user_ids.*' => [Rule::in($eligible)],
            'logo_url' => ['nullable', 'url', 'max:255'],
            'selected_service_catalog_ids' => ['array'],
            'selected_service_catalog_ids.*' => ['integer', 'exists:service_catalog_items,id'],
            'priority_service_catalog_ids' => ['array'],
            'priority_service_catalog_ids.*' => ['integer', 'exists:service_catalog_items,id'],
            'new_service_name' => ['nullable', 'string', 'max:255'],
            'new_service_is_priority' => ['boolean'],
            'service_areas' => ['array', 'min:1', 'max:100'],
            'service_areas.*.country_code' => ['required', Rule::in(array_keys(CountryOptions::options()))],
            'service_areas.*.city_name' => ['nullable', 'string', 'max:160'],
            'service_areas.*.district_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    protected function fillBrandForm(array $brand): void
    {
        $this->customer_id = (string) ($brand['customer_id'] ?? '');
        $this->name = (string) ($brand['name'] ?? '');
        $this->sector = (string) ($brand['sector'] ?? $brand['industry'] ?? '');
        $this->primary_country = (string) ($brand['primary_country'] ?? '');
        $this->target_markets = array_values($brand['target_markets'] ?? []);
        $this->languages = array_values($brand['languages'] ?? []);
        $this->description = (string) ($brand['description'] ?? '');
        $this->audience = (string) ($brand['audience'] ?? '');
        $this->offerings = (string) ($brand['offerings'] ?? '');
        $this->competitors = (string) ($brand['competitors'] ?? '');
        $this->responsible_user_ids = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $brand['responsible_user_ids'] ?? [],
        ));
        $this->logo_url = (string) ($brand['logo_url'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function brandEloquentPayload(): array
    {
        return [
            'customer_id' => (int) $this->customer_id,
            'name' => trim($this->name),
            'sector' => $this->sector !== '' ? $this->sector : null,
            'primary_country' => $this->primary_country !== '' ? $this->primary_country : null,
            'target_markets' => array_values($this->target_markets),
            'languages' => array_values($this->languages),
            'description' => $this->description !== '' ? trim($this->description) : null,
            'audience' => $this->audience !== '' ? trim($this->audience) : null,
            'offerings' => $this->offerings !== '' ? trim($this->offerings) : null,
            'competitors' => $this->competitors !== '' ? trim($this->competitors) : null,
            'logo_url' => $this->logo_url !== '' ? trim($this->logo_url) : null,
        ];
    }

    public function addServiceArea(): void
    {
        $this->service_areas[] = ['country_code' => $this->primary_country ?: 'TR', 'city_name' => '', 'district_name' => ''];
    }

    public function removeServiceArea(int $index): void
    {
        if (! isset($this->service_areas[$index])) {
            return;
        }

        unset($this->service_areas[$index]);
        $this->service_areas = array_values($this->service_areas);

        if ($this->service_areas === []) {
            $this->addServiceArea();
        }
    }

    protected function fillCommercialContext(Brand $brand): void
    {
        $brand->loadMissing(['offerings', 'serviceAreas']);
        $this->selected_service_catalog_ids = $brand->offerings
            ->filter(fn ($offering): bool => $offering->status->value === 'active' && $offering->service_catalog_item_id !== null)
            ->pluck('service_catalog_item_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
        $this->priority_service_catalog_ids = $brand->offerings
            ->filter(fn ($offering): bool => $offering->status->value === 'active' && $offering->priority_rank !== null && $offering->service_catalog_item_id !== null)
            ->sortBy('priority_rank')
            ->pluck('service_catalog_item_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        $areas = $brand->serviceAreas
            ->where('status', 'active')
            ->sortBy('priority_rank')
            ->map(fn ($area): array => [
                'country_code' => (string) $area->country_code,
                'city_name' => (string) ($area->city_name ?? ''),
                'district_name' => (string) ($area->district_name ?? ''),
            ])
            ->values()
            ->all();

        if ($areas !== []) {
            $this->service_areas = $areas;
        }
    }

    /**
     * @return list<int>
     */
    protected function sanitizedResponsibleUserIds(): array
    {
        return OperatorUserDirectory::sanitizeIds($this->responsible_user_ids);
    }

    /**
     * @return array<string, mixed>
     */
    protected function brandFormViewData(): array
    {
        $customers = Customer::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();

        return [
            'customerOptions' => $customers,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'languageOptions' => LanguageOptions::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'customerLocked' => $this->customerLocked,
            'customerName' => $customers[$this->customer_id] ?? null,
            'serviceOptions' => ServiceCatalogItem::query()
                ->with('primaryName')
                ->where('status', 'active')
                ->orderBy('sector')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (ServiceCatalogItem $service): array => [
                    (string) $service->id => [
                        'label' => $service->primaryName?->raw_label ?? 'İsimsiz hizmet',
                        'sector' => $service->sector,
                    ],
                ])
                ->all(),
        ];
    }
}
