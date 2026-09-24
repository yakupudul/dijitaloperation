<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Customer;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Services\Portfolio\PortfolioGroupCreator;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * /customers/discover — "Toplu ekle": discovered Google / Meta accounts that are not yet in the portfolio,
 * grouped into proposed brands. The owner types the customer for the groups they work with (groups left
 * blank are skipped), optionally the cities served, and creates all filled groups in one click. Bindings go
 * through the same services as "Otomatik kur"; after the site crawl "Otomatik kur" proposes the brand's
 * services from the shared service pool by itself. Admin only.
 */
#[Layout('operator.layouts.app')]
#[Title('Toplu ekle')]
final class DiscoverAndGroupPage extends Component
{
    /**
     * Form state per group, indexed by a hash of the group key (dots/colons break wire:model paths).
     *
     * @var array<string, array{customer_id: string, customer_name: string, brand_name: string, website_url: string, cities: string, resources: array<int|string, bool>}>
     */
    public array $forms = [];

    /** @var array<string, list<array{key: string, label: string, ok: bool, message: string}>> */
    public array $results = [];

    /** @var array<string, array{name: string, url: string}> */
    public array $created = [];

    public string $bulkMessage = '';

    /** Filters only what is shown; "Doldurulanları oluştur" still covers every filled group. */
    public string $search = '';

    public string $filter = 'all';

    public int $limit = 20;

    public function updatedSearch(): void
    {
        $this->limit = 20;
    }

    public function updatedFilter(): void
    {
        $this->limit = 20;
    }

    public function showMore(): void
    {
        $this->limit += 20;
    }

    public function mount(PortfolioDiscoveryGrouper $grouper): void
    {
        $this->authorizeAdmin();
        $this->syncForms($grouper->groups());
    }

    public function create(string $formKey, PortfolioGroupCreator $creator, PortfolioDiscoveryGrouper $grouper): void
    {
        $this->authorizeAdmin();
        $group = collect($grouper->groups())->first(fn (array $g): bool => $this->formKey($g['key']) === $formKey);
        if ($group !== null) {
            $this->createGroup($group, $formKey, $creator);
        }
    }

    /** Creates every group whose customer was filled in (or which belongs to an existing brand); the rest are skipped. */
    public function createAll(PortfolioGroupCreator $creator, PortfolioDiscoveryGrouper $grouper): void
    {
        $this->authorizeAdmin();
        $done = 0;
        $failed = 0;
        foreach ($grouper->groups() as $group) {
            $formKey = $this->formKey($group['key']);
            $form = $this->forms[$formKey] ?? null;
            $filled = $form !== null && ($group['existing_brand_id'] !== null || $form['customer_id'] !== '' || trim($form['customer_name']) !== '');
            if (! $filled || collect($form['resources'])->filter()->isEmpty()) {
                continue;
            }
            $this->createGroup($group, $formKey, $creator) ? $done++ : $failed++;
        }
        $this->bulkMessage = $done === 0 && $failed === 0
            ? 'Oluşturulacak grup yok: çalıştığın grupların müşteri adını yaz.'
            : $done.' grup oluşturuldu'.($failed > 0 ? ', '.$failed.' grupta hata var (aşağıda)' : '').'.';
    }

    /** @param  array<string, mixed>  $group */
    private function createGroup(array $group, string $formKey, PortfolioGroupCreator $creator): bool
    {
        $form = $this->forms[$formKey] ?? null;
        if ($form === null) {
            return false;
        }
        $allowed = array_column($group['resources'], 'id');
        $resourceIds = collect($form['resources'])->filter()->keys()->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $allowed, true))->values()->all();

        try {
            $outcome = $creator->create([
                'brand_id' => $group['existing_brand_id'],
                'customer_id' => $form['customer_id'] !== '' ? (int) $form['customer_id'] : null,
                'customer_name' => $form['customer_name'],
                'brand_name' => $form['brand_name'],
                'website_url' => $form['website_url'],
                'cities' => $form['cities'] ?? '',
            ], $resourceIds, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('forms.'.$formKey.'.'.$field, $messages[0]);
            }

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('forms.'.$formKey.'.brand_name', 'Oluşturulamadı: '.$exception->getMessage());

            return false;
        }

        $this->results[$formKey] = $outcome['results'];
        $this->created[$formKey] = [
            'name' => (string) $outcome['brand']->name,
            'url' => route('operator.brand', ['brand' => $outcome['brand']->id]),
        ];

        return true;
    }

    public function render(PortfolioDiscoveryGrouper $grouper): View
    {
        $groups = $grouper->groups();
        $this->syncForms($groups);

        $needle = SeoText::fold($this->search);
        $matching = collect($groups)
            ->filter(fn (array $g): bool => match ($this->filter) {
                'web' => $g['host'] !== null,
                'noweb' => $g['host'] === null,
                'existing' => $g['existing_brand_id'] !== null,
                default => true,
            })
            ->filter(fn (array $g): bool => $needle === '' || str_contains(SeoText::fold($g['suggested_brand'].' '.$g['host'].' '
                .collect($g['resources'])->map(fn (array $r): string => $r['label'].' '.$r['external_id'])->implode(' ')), $needle))
            ->values();
        $visible = $matching->take($this->limit)->map(fn (array $g): array => $g + ['form_key' => $this->formKey($g['key'])])->all();

        return view('livewire.operator.portfolio.discover-and-group', [
            'groups' => $groups,
            'visible' => $visible,
            'total' => count($groups),
            'matching' => $matching->count(),
            'shown' => count($visible),
            'customers' => Customer::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /** @param  list<array<string, mixed>>  $groups */
    private function syncForms(array $groups): void
    {
        foreach ($groups as $group) {
            $key = $this->formKey($group['key']);
            if (isset($this->forms[$key])) {
                continue;
            }
            $this->forms[$key] = [
                'customer_id' => $group['existing_customer_id'] !== null ? (string) $group['existing_customer_id'] : '',
                // Left blank on purpose: only groups the owner works with get a customer, the rest are skipped.
                'customer_name' => '',
                'brand_name' => (string) $group['suggested_brand'],
                'website_url' => $group['host'] !== null ? 'https://'.$group['host'].'/' : '',
                'cities' => '',
                'resources' => collect($group['resources'])->mapWithKeys(fn (array $r): array => [$r['id'] => (bool) $r['selected']])->all(),
            ];
        }
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
