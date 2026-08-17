<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Customers')]
class CustomersIndex extends Component
{
    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $type = '';

    #[Url(history: true)]
    public string $industry = '';

    #[Url(history: true)]
    public string $hq_country = '';

    #[Url(history: true)]
    public string $responsible = '';

    #[Url(history: true)]
    public string $service = '';

    #[Url(history: true)]
    public string $attention = '';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $dir = 'asc';

    public bool $showOptionalColumns = false;

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->type = '';
        $this->industry = '';
        $this->hq_country = '';
        $this->responsible = '';
        $this->service = '';
        $this->attention = '';
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        $this->dir = 'asc';
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->status !== ''
            || $this->type !== ''
            || $this->industry !== ''
            || $this->hq_country !== ''
            || $this->responsible !== ''
            || $this->service !== ''
            || $this->attention !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function filteredCustomers(): array
    {
        $rows = Customer::query()
            ->with(['brands.digitalAssets', 'responsibleUsers'])
            ->get()
            ->map(fn (Customer $customer): array => OperatorPortfolioPresenter::customer($customer));

        if ($this->search !== '') {
            $q = mb_strtolower($this->search);
            $rows = $rows->filter(function (array $customer) use ($q): bool {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $customer['name'] ?? '',
                    $customer['legal_name'] ?? '',
                    $customer['primary_email'] ?? '',
                ])));

                return str_contains($hay, $q);
            });
        }

        if ($this->status !== '') {
            $rows = $rows->filter(fn (array $c): bool => ($c['status'] ?? '') === $this->status);
        }
        if ($this->type !== '') {
            $rows = $rows->filter(fn (array $c): bool => ($c['type'] ?? '') === $this->type);
        }
        if ($this->industry !== '') {
            $rows = $rows->filter(fn (array $c): bool => ($c['industry'] ?? '') === $this->industry);
        }
        if ($this->hq_country !== '') {
            $rows = $rows->filter(fn (array $c): bool => ($c['hq_country'] ?? '') === $this->hq_country);
        }
        if ($this->responsible !== '') {
            $rows = $rows->filter(fn (array $c): bool => in_array($this->responsible, $c['responsible_user_ids'] ?? [], true));
        }
        if ($this->service !== '') {
            $rows = $rows->filter(fn (array $c): bool => in_array($this->service, $c['services'] ?? [], true));
        }
        if ($this->attention === 'needs_attention') {
            $rows = $rows->filter(fn (array $c): bool => (bool) ($c['needs_attention'] ?? false));
        } elseif ($this->attention === 'clear') {
            $rows = $rows->filter(fn (array $c): bool => ! ($c['needs_attention'] ?? false));
        }

        $sort = $this->sort;
        $dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $rows = $rows->sortBy(function (array $c) use ($sort) {
            return match ($sort) {
                'industry' => $c['industry_label'] ?? '',
                'brands' => (int) ($c['brands_count'] ?? 0),
                'findings' => (int) ($c['open_findings'] ?? 0),
                'tasks' => (int) ($c['open_tasks'] ?? 0),
                'service_started' => $c['service_started_at'] ?? '',
                'updated' => $c['updated_at'] ?? $c['name'] ?? '',
                default => mb_strtolower((string) ($c['name'] ?? '')),
            };
        }, SORT_REGULAR, $dir === 'desc');

        return $rows->values()->all();
    }

    public function render(): View
    {
        $customers = $this->filteredCustomers();
        $allCount = Customer::query()->count();

        $typeOptions = collect(CustomerType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])->all();
        $statusOptions = collect(CustomerStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])->all();

        return view('livewire.demo.portfolio.customers-index', [
            'customers' => $customers,
            'allCount' => $allCount,
            'hasFilters' => $this->hasActiveFilters(),
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'serviceOptions' => AgencyServiceOptions::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
