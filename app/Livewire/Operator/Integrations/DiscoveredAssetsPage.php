<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Customer;
use App\Services\Ownership\OwnershipIntegrity;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Services\Portfolio\PortfolioGroupCreator;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Entegrasyonlar › Keşfedilen varlıklar: every discovered account and website (bound or not), brand grouping
 * proposals with one-click approve (customer → brand → assets), and ownership problems with a safe fix. Sector lives
 * on the brand (Faz 2).
 */
#[Layout('operator.layouts.app')]
#[Title('Keşfedilen varlıklar')]
final class DiscoveredAssetsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $bound = '';

    /** @var array<string, string> proposal form key => customer id ('' = new customer with the brand name) */
    public array $customerFor = [];

    public string $message = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'bound'], true)) {
            $this->resetPage();
        }
    }

    public function approve(string $groupKey, PortfolioDiscoveryGrouper $grouper, PortfolioGroupCreator $creator): void
    {
        $this->authorizeAdmin();
        $group = collect($grouper->groups())->firstWhere('key', $groupKey);
        if ($group === null) {
            $this->message = 'Öneri artık yok.';

            return;
        }
        $formKey = $this->formKey($groupKey);
        $customerId = $this->customerFor[$formKey] ?? '';
        try {
            $outcome = $creator->create([
                'brand_id' => $group['existing_brand_id'],
                'customer_id' => $customerId !== '' ? (int) $customerId : $group['existing_customer_id'],
                'customer_name' => $group['suggested_brand'],
                'brand_name' => $group['suggested_brand'],
                'website_url' => $group['host'] !== null ? 'https://'.$group['host'].'/' : '',
            ], array_values(array_map(fn (array $r): int => $r['id'], array_filter($group['resources'], fn (array $r): bool => $r['selected']))), auth()->user());
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->message = 'Oluşturulamadı.';

            return;
        }
        $failed = collect($outcome['results'])->where('ok', false)->count();
        $this->message = $outcome['brand']->name.' hazır'.($failed > 0 ? ' · '.$failed.' hata' : '').'.';
    }

    public function fixIntegrity(OwnershipIntegrity $integrity): void
    {
        $this->authorizeAdmin();
        $this->message = $integrity->fix(auth()->user()).' bağlantı kapatıldı.';
    }

    public function render(PortfolioDiscoveryGrouper $grouper, OwnershipIntegrity $integrity): View
    {
        $all = $grouper->subjects();
        $needle = SeoText::fold($this->search);
        $filtered = $all->filter(fn (array $s): bool => ($this->kind === '' || $s['kind'] === $this->kind)
            && ($this->bound === '' || ($this->bound === 'yes') === $s['bound'])
            && ($needle === '' || str_contains(SeoText::fold($s['name'].' '.$s['host'].' '.$s['brand']), $needle)))
            ->sortBy([fn (array $a, array $b): int => $a['bound'] <=> $b['bound'], fn (array $a, array $b): int => strcmp($a['name'], $b['name'])])->values();
        $page = max(1, $this->getPage());
        $rows = new LengthAwarePaginator($filtered->forPage($page, 50)->values(), $filtered->count(), 50, $page, ['path' => request()->url()]);
        $groups = collect($grouper->groups())->filter(fn (array $g): bool => collect($g['resources'])->where('selected', true)->isNotEmpty())->take(20)
            ->map(fn (array $g): array => $g + ['form_key' => $this->formKey($g['key'])])->values();

        return view('livewire.operator.integrations.discovered-assets-page', [
            'rows' => $rows,
            'total' => $all->count(),
            'unbound' => $all->where('bound', false)->count(),
            'groups' => $groups,
            'problems' => $integrity->problems(),
            'customers' => Customer::query()->orderBy('name')->pluck('name', 'id')->all(),
            'kinds' => ['website' => 'Web sitesi', 'search_console' => 'Search Console', 'ga4' => 'GA4', 'google_business_profile' => 'İşletme Profili', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta'],
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function formKey(string $groupKey): string
    {
        return 'g'.substr(md5($groupKey), 0, 12);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
