<?php

namespace App\Livewire\Demo\Portfolio\Concerns;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CityOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Validation\Rule;

trait InteractsWithCustomerForm
{
    public string $name = '';

    public string $legal_name = '';

    public string $type = 'company';

    public string $status = 'active';

    public string $industry = '';

    public string $industry_other = '';

    public string $hq_country = '';

    public string $hq_city = '';

    public string $hq_city_other = '';

    /** @var list<string> */
    public array $services = [];

    public string $service_started_at = '';

    public string $primary_email = '';

    public string $primary_phone = '';

    /** @var list<string> */
    public array $responsible_user_ids = [];

    public bool $saving = false;

    /**
     * @return array<string, mixed>
     */
    protected function customerRules(): array
    {
        $eligible = array_map(static fn (int $id): string => (string) $id, OperatorUserDirectory::eligibleIds());

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'type' => ['required', Rule::in(array_column(CustomerType::cases(), 'value'))],
            'status' => ['required', Rule::in(array_column(CustomerStatus::cases(), 'value'))],
            'industry' => ['nullable', Rule::in(array_keys(IndustryOptions::options()))],
            'industry_other' => ['nullable', 'string', 'max:120'],
            'hq_country' => ['nullable', Rule::in(array_keys(CountryOptions::options()))],
            'hq_city' => ['nullable', 'string', 'max:120'],
            'hq_city_other' => ['nullable', 'string', 'max:120'],
            'services' => ['array'],
            'services.*' => [Rule::in(array_keys(AgencyServiceOptions::options()))],
            'service_started_at' => ['nullable', 'date'],
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:60'],
            'responsible_user_ids' => ['array'],
            'responsible_user_ids.*' => [Rule::in($eligible)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function customerValidationAttributes(): array
    {
        return [
            'name' => 'customer name',
            'legal_name' => 'legal name',
            'hq_country' => __('operator.forms.hq_country'),
            'hq_city' => __('operator.forms.hq_city'),
            'hq_city_other' => __('operator.forms.city_other'),
            'primary_email' => 'primary email',
            'primary_phone' => 'primary phone',
            'service_started_at' => 'service started',
            'responsible_user_ids' => 'responsible team',
        ];
    }

    public function updatedHqCountry(): void
    {
        if ($this->hq_city === '' && $this->hq_city_other === '') {
            return;
        }

        if ($this->hq_city === CityOptions::OTHER || ! CityOptions::isCatalogCity($this->hq_country, $this->hq_city)) {
            $this->hq_city = '';
            $this->hq_city_other = '';
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    protected function fillCustomerForm(array $customer): void
    {
        $this->name = (string) ($customer['name'] ?? '');
        $this->legal_name = (string) ($customer['legal_name'] ?? '');
        $this->type = (string) ($customer['type'] ?? 'company');
        $this->status = (string) ($customer['status'] ?? 'active');
        $this->industry = (string) ($customer['industry'] ?? '');
        $this->industry_other = (string) ($customer['industry_other'] ?? '');
        $this->hq_country = (string) ($customer['hq_country'] ?? '');
        $storedCity = (string) ($customer['hq_city'] ?? '');
        if (CityOptions::isCatalogCity($this->hq_country, $storedCity)) {
            $this->hq_city = $storedCity;
            $this->hq_city_other = '';
        } elseif ($storedCity !== '') {
            $this->hq_city = CityOptions::OTHER;
            $this->hq_city_other = $storedCity;
        } else {
            $this->hq_city = '';
            $this->hq_city_other = '';
        }
        $this->services = array_values($customer['services'] ?? []);
        $this->service_started_at = (string) ($customer['service_started_at'] ?? '');
        $this->primary_email = (string) ($customer['primary_email'] ?? '');
        $this->primary_phone = (string) ($customer['primary_phone'] ?? '');
        $this->responsible_user_ids = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $customer['responsible_user_ids'] ?? [],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerPayload(?string $id = null): array
    {
        return [
            'id' => $id,
            'name' => trim($this->name),
            'legal_name' => trim($this->legal_name) !== '' ? trim($this->legal_name) : null,
            'type' => $this->type,
            'status' => $this->status,
            'industry' => $this->industry !== '' ? $this->industry : null,
            'hq_country' => $this->hq_country !== '' ? $this->hq_country : null,
            'hq_city' => $this->resolvedHqCity(),
            'services' => array_values($this->services),
            'service_started_at' => $this->service_started_at !== '' ? $this->service_started_at : null,
            'primary_email' => $this->primary_email !== '' ? trim($this->primary_email) : null,
            'primary_phone' => $this->primary_phone !== '' ? trim($this->primary_phone) : null,
            'responsible_user_ids' => array_values($this->responsible_user_ids),
        ];
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
    protected function customerFormViewData(): array
    {
        $typeOptions = [];
        foreach (CustomerType::cases() as $case) {
            $typeOptions[$case->value] = __('operator.customer.types.'.$case->value);
        }

        $statusOptions = [];
        foreach (CustomerStatus::cases() as $case) {
            $statusOptions[$case->value] = __('operator.states.'.$case->value);
        }

        return [
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'cityOptions' => CityOptions::optionsForCountry($this->hq_country !== '' ? $this->hq_country : null),
            'serviceOptions' => AgencyServiceOptions::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'showIndustryOther' => $this->industry === IndustryOptions::OTHER,
            'showCityOther' => $this->hq_city === CityOptions::OTHER,
            'cityOtherValue' => CityOptions::OTHER,
        ];
    }

    protected function resolvedHqCity(): ?string
    {
        if ($this->hq_city === CityOptions::OTHER) {
            $other = trim($this->hq_city_other);

            return $other !== '' ? $other : null;
        }

        $city = trim($this->hq_city);

        return $city !== '' ? $city : null;
    }
}
