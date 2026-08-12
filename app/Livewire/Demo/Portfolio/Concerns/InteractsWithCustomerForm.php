<?php

namespace App\Livewire\Demo\Portfolio\Concerns;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Support\Demo\DemoCatalog;
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'type' => ['required', Rule::in(array_column(CustomerType::cases(), 'value'))],
            'status' => ['required', Rule::in(array_column(CustomerStatus::cases(), 'value'))],
            'industry' => ['nullable', Rule::in(array_keys(IndustryOptions::options()))],
            'industry_other' => ['nullable', 'string', 'max:120'],
            'hq_country' => ['nullable', Rule::in(array_keys(CountryOptions::options()))],
            'hq_city' => ['nullable', 'string', 'max:120'],
            'services' => ['array'],
            'services.*' => [Rule::in(array_keys(AgencyServiceOptions::options()))],
            'service_started_at' => ['nullable', 'date'],
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:60'],
            'responsible_user_ids' => ['array'],
            'responsible_user_ids.*' => [Rule::in(array_column(DemoCatalog::teamMembers(), 'id'))],
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
            'hq_country' => 'HQ country',
            'hq_city' => 'HQ city',
            'primary_email' => 'primary email',
            'primary_phone' => 'primary phone',
            'service_started_at' => 'service started',
            'responsible_user_ids' => 'responsible team',
        ];
    }

    public function updatedHqCountry(): void
    {
        $allowed = CityOptions::forCountry($this->hq_country);
        if ($this->hq_city !== '' && $allowed !== [] && ! in_array($this->hq_city, $allowed, true)) {
            // Keep custom cities; only clear when previous city was from another country's suggestions and no longer matches.
            if (! in_array($this->hq_city, $allowed, true)) {
                // Custom city values are allowed — do not clear.
            }
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
        $this->hq_city = (string) ($customer['hq_city'] ?? '');
        $this->services = array_values($customer['services'] ?? []);
        $this->service_started_at = (string) ($customer['service_started_at'] ?? '');
        $this->primary_email = (string) ($customer['primary_email'] ?? '');
        $this->primary_phone = (string) ($customer['primary_phone'] ?? '');
        $this->responsible_user_ids = array_values($customer['responsible_user_ids'] ?? []);
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
            'industry_other' => $this->industry === IndustryOptions::OTHER && $this->industry_other !== ''
                ? trim($this->industry_other)
                : null,
            'hq_country' => $this->hq_country !== '' ? $this->hq_country : null,
            'hq_city' => $this->hq_city !== '' ? trim($this->hq_city) : null,
            'services' => array_values($this->services),
            'service_started_at' => $this->service_started_at !== '' ? $this->service_started_at : null,
            'primary_email' => $this->primary_email !== '' ? trim($this->primary_email) : null,
            'primary_phone' => $this->primary_phone !== '' ? trim($this->primary_phone) : null,
            'responsible_user_ids' => array_values($this->responsible_user_ids),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerFormViewData(): array
    {
        $typeOptions = [];
        foreach (CustomerType::cases() as $case) {
            $typeOptions[$case->value] = $case->name;
        }

        $statusOptions = [];
        foreach (CustomerStatus::cases() as $case) {
            $statusOptions[$case->value] = $case->name;
        }

        $teamOptions = [];
        foreach (DemoCatalog::teamMembers() as $member) {
            $teamOptions[$member['id']] = $member['name'];
        }

        return [
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'cityOptions' => CityOptions::optionsForCountry($this->hq_country !== '' ? $this->hq_country : null),
            'serviceOptions' => AgencyServiceOptions::options(),
            'teamOptions' => $teamOptions,
            'showIndustryOther' => $this->industry === IndustryOptions::OTHER,
        ];
    }
}
