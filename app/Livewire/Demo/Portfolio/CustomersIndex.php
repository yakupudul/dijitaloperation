<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\User;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Operator\PortfolioSignalsReader;
use App\Services\Portfolio\PortfolioDeletionService;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Müşteriler: one row per customer with its brands, the real open work (actionable suggestions) and the data problems of
 * its brands' sources, computed once per render (PortfolioSignalsReader); filters run on that result and the rows are
 * paginated.
 */
#[Layout('operator.layouts.app')]
#[Title('Müşteriler')]
class CustomersIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

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

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'industry', 'hq_country', 'responsible', 'service', 'attention'], true)) {
            $this->resetPage();
        }
    }

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
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        $this->dir = in_array($column, ['work', 'attention'], true) ? 'desc' : 'asc';
    }

    /**
     * Active ↔ passive switch on the list. Passive stops every automatic flow for the customer's assets
     * (collection, plans, alerts, WordPress); data is kept and flows resume when switched back. Only an Admin or one
     * of the customer's responsible users (hesap sorumlusu) may switch it.
     */
    public function toggleActive(string $customerId): void
    {
        abort_unless(ctype_digit($customerId), 404);
        $customer = Customer::query()->findOrFail((int) $customerId);
        abort_unless(self::canToggle($customer, auth()->user()), 403);
        $activate = $customer->status !== CustomerStatus::Active;
        // Passive: every automatic flow, AI and paid call stops (service scope); active again: paused collection resumes.
        $customer->forceFill(['status' => $activate ? CustomerStatus::Active : CustomerStatus::Inactive])->save();

        DemoState::flash(__($activate ? 'customer_status.activated' : 'customer_status.paused', ['name' => $customer->name]));
    }

    /** Admin, or a responsible user of the customer. */
    public static function canToggle(Customer $customer, mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $customer->responsibleUsers()->whereKey($user->id)->exists();
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
        $models = Customer::query()
            ->with(['brands.digitalAssets', 'brands.sectorCategory', 'responsibleUsers'])
            ->get();
        $signals = app(PortfolioSignalsReader::class)->forCustomers($models)['customers'];
        $rows = $models->map(fn (Customer $customer): array => OperatorPortfolioPresenter::customer($customer, $signals[(int) $customer->id] ?? []));

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
            $rows = $rows->filter(fn (array $c): bool => in_array($this->industry, $c['sector_codes'] ?? [], true));
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
        $rows = $rows->sortBy(function (array $c) use ($sort) {
            return match ($sort) {
                'industry' => mb_strtolower((string) ($c['sector_label'] ?? '')),
                'brands' => (int) ($c['brands_count'] ?? 0),
                'work' => (int) ($c['open_work'] ?? 0),
                'attention' => ((int) ($c['needs_attention'] ?? false)) * 1000 + (int) ($c['data_issues'] ?? 0),
                'service_started' => $c['service_started_at'] ?? '',
                'updated' => $c['updated_at'] ?? $c['name'] ?? '',
                default => mb_strtolower((string) ($c['name'] ?? '')),
            };
        }, SORT_REGULAR, $this->dir === 'desc');

        return $rows->values()->all();
    }

    public function render(): View
    {
        $all = $this->filteredCustomers();
        $page = max(1, $this->getPage());
        $customers = new LengthAwarePaginator(array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($all), self::PER_PAGE, $page);
        $allCount = Customer::query()->count();
        $actor = auth()->user();
        $isAdmin = (bool) $actor?->hasRole(Roles::ADMIN);

        $typeOptions = collect(CustomerType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value === 'individual' ? 'Bireysel' : 'Şirket'])->all();
        $statusOptions = collect(CustomerStatus::cases())->mapWithKeys(fn ($c) => [$c->value => __('operator.states.'.$c->value)])->all();

        return view('livewire.demo.portfolio.customers-index', [
            'customers' => $customers,
            'allCount' => $allCount,
            'attentionCount' => collect($all)->where('needs_attention', true)->count(),
            'visibleIds' => array_values(array_map(fn (array $c): int => (int) $c['id'], $customers->items())),
            'isAdmin' => $isAdmin,
            'actorId' => $actor !== null ? (string) $actor->getAuthIdentifier() : '',
            'hasFilters' => $this->hasActiveFilters(),
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'industryOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'serviceOptions' => AgencyServiceOptions::options(),
            'teamOptions' => OperatorUserDirectory::options(),
            'flash' => DemoState::pullFlash(),
            'health' => $this->health(),
        ]);
    }

    /**
     * Customer health score (when the daily score table exists) with its reasons, biggest point loss first.
     *
     * @return array<int, array{score: int, band: string, reasons: list<string>}>
     */
    private function health(): array
    {
        if (! Schema::hasTable('customer_health')) {
            return [];
        }

        return DB::table('customer_health')->get(['customer_id', 'score', 'band', 'reasons'])
            ->mapWithKeys(fn (object $r): array => [(int) $r->customer_id => [
                'score' => (int) $r->score,
                'band' => (string) $r->band,
                'reasons' => collect((array) json_decode((string) $r->reasons, true))
                    ->filter(fn (mixed $x): bool => is_array($x) && isset($x['text']))
                    ->sortByDesc(fn (array $x): int => (int) ($x['points'] ?? 0))
                    ->map(fn (array $x): string => '−'.(int) ($x['points'] ?? 0).' '.$x['text'])
                    ->values()->all(),
            ]])->all();
    }
}
