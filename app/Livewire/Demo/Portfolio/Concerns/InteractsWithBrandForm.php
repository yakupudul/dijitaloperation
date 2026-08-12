<?php

namespace App\Livewire\Demo\Portfolio\Concerns;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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

    public bool $customerLocked = false;

    public bool $saving = false;

    /**
     * @return array<string, mixed>
     */
    protected function brandRules(): array
    {
        $customerIds = collect(DemoState::all()['customers'] ?? [])->pluck('id')->all();

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
            'responsible_user_ids.*' => [Rule::in(array_column(DemoCatalog::teamMembers(), 'id'))],
            'logo_url' => ['nullable', 'url', 'max:255'],
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
        $this->responsible_user_ids = array_values($brand['responsible_user_ids'] ?? []);
        $this->logo_url = (string) ($brand['logo_url'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function brandPayload(?string $id = null): array
    {
        return [
            'id' => $id,
            'customer_id' => $this->customer_id,
            'name' => trim($this->name),
            'sector' => $this->sector !== '' ? $this->sector : null,
            'industry' => $this->sector !== '' ? $this->sector : null,
            'primary_country' => $this->primary_country !== '' ? $this->primary_country : null,
            'target_markets' => array_values($this->target_markets),
            'languages' => array_values($this->languages),
            'description' => $this->description !== '' ? trim($this->description) : null,
            'audience' => $this->audience !== '' ? trim($this->audience) : null,
            'offerings' => $this->offerings !== '' ? trim($this->offerings) : null,
            'competitors' => $this->competitors !== '' ? trim($this->competitors) : null,
            'responsible_user_ids' => array_values($this->responsible_user_ids),
            'logo_url' => $this->logo_url !== '' ? trim($this->logo_url) : null,
            'health' => 'healthy',
            'health_label' => 'Healthy',
            'assets_count' => 0,
            'open_findings' => 0,
            'open_tasks' => 0,
            'summary' => [
                'media_spend' => 0,
                'platform_leads' => 0,
                'website_leads' => 0,
                'calls_messages' => 0,
                'currency' => 'TRY',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function brandFormViewData(): array
    {
        $customers = collect(DemoState::all()['customers'] ?? [])
            ->mapWithKeys(fn (array $c): array => [($c['id'] ?? '') => ($c['name'] ?? '')])
            ->all();

        $teamOptions = collect(DemoCatalog::teamMembers())
            ->mapWithKeys(fn (array $m): array => [$m['id'] => $m['name']])
            ->all();

        return [
            'customerOptions' => $customers,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'languageOptions' => LanguageOptions::options(),
            'teamOptions' => $teamOptions,
            'customerLocked' => $this->customerLocked,
            'customerName' => $customers[$this->customer_id] ?? null,
        ];
    }
}
