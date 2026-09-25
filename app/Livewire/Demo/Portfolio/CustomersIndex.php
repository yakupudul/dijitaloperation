<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Portfolio\PortfolioDeletionService;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** @var list<int> selected customer ids for bulk actions */
    public array $selected = [];

    public function toggleAll(array $visibleIds): void
    {
        $visibleIds = array_map('intval', $visibleIds);
        $this->selected = array_values(array_intersect($this->selected, $visibleIds)) === $visibleIds && $visibleIds !== []
            ? []
            : $visibleIds;
    }

    /** Admin-only removal of the selected customers (and their brands/assets): data is kept, collection stops. */
    public function deleteSelected(PortfolioDeletionService $deletion): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if ($ids === []) {
            return;
        }
        $result = $deletion->deleteCustomers($ids, auth()->user());
        $this->selected = [];
        DemoState::flash($result['deleted'].' müşteri silindi. Toplanan veriler korundu, veri çekimi durdu; hesap tekrar bir markaya bağlanırsa çekim devam eder.'.($result['skipped'] > 0 ? ' '.$result['skipped'].' kayıt silinemedi.' : ''), $result['skipped'] > 0 ? 'warning' : 'success');
    }

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

    /**
     * Active ↔ passive switch on the list. Passive stops every automatic flow for the customer's assets
     * (collection, plans, alerts, WordPress); data is kept and flows resume when switched back.
     */
    public function toggleActive(string $customerId): void
    {
        abort_unless(ctype_digit($customerId), 404);
        $customer = Customer::query()->findOrFail((int) $customerId);
        $activate = $customer->status !== CustomerStatus::Active;
        $customer->forceFill(['status' => $activate ? CustomerStatus::Active : CustomerStatus::Inactive])->save();
        if ($activate) {
            app(ResourceAutomationService::class)->resumeForCustomer((int) $customer->id);
        }

        DemoState::flash(__($activate ? 'customer_status.activated' : 'customer_status.paused', ['name' => $customer->name]));
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

        $typeOptions = collect(CustomerType::cases())->mapWithKeys(fn ($c) => [$c->value => __('operator.customer.types.'.$c->value)])->all();
        $statusOptions = collect(CustomerStatus::cases())->mapWithKeys(fn ($c) => [$c->value => __('operator.states.'.$c->value)])->all();

        return view('livewire.demo.portfolio.customers-index', [
            'customers' => $customers,
            'allCount' => $allCount,
            'visibleIds' => array_values(array_map(fn (array $c): int => (int) $c['id'], $customers)),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'hasFilters' => $this->hasActiveFilters(),
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'serviceOptions' => AgencyServiceOptions::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'flash' => DemoState::pullFlash(),
            // Faz 10b: customer health score (daily), reasons as the badge tooltip.
            'health' => Schema::hasTable('customer_health') ? DB::table('customer_health')->get(['customer_id', 'score', 'band', 'reasons'])
                ->mapWithKeys(fn (object $r): array => [(int) $r->customer_id => ['score' => (int) $r->score, 'band' => (string) $r->band,
                    'reasons' => collect((array) json_decode((string) $r->reasons, true))->map(fn (array $x): string => '−'.$x['points'].' '.$x['text'])->implode("\n") ?: 'Sorun görünmüyor.']])->all() : [],
        ]);
    }
}
