<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Customer;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Services\Portfolio\PortfolioGroupCreator;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * /customers/discover — "Keşfet ve Grupla": discovered Google / Meta accounts that are not yet in the
 * portfolio, grouped into proposed brands. The owner ticks accounts, names the customer and brand, and
 * creates everything in one click (bindings go through the same services as "Otomatik kur"). Admin only.
 */
#[Layout('operator.layouts.app')]
#[Title('Keşfet ve Grupla')]
final class DiscoverAndGroupPage extends Component
{
    /**
     * Form state per group, indexed by a hash of the group key (dots/colons break wire:model paths).
     *
     * @var array<string, array{customer_id: string, customer_name: string, brand_name: string, website_url: string, resources: array<int|string, bool>}>
     */
    public array $forms = [];

    /** @var array<string, list<array{key: string, label: string, ok: bool, message: string}>> */
    public array $results = [];

    /** @var array<string, array{name: string, url: string}> */
    public array $created = [];

    public function mount(PortfolioDiscoveryGrouper $grouper): void
    {
        $this->authorizeAdmin();
        $this->syncForms($grouper->groups());
    }

    public function create(string $formKey, PortfolioGroupCreator $creator, PortfolioDiscoveryGrouper $grouper): void
    {
        $this->authorizeAdmin();
        $group = collect($grouper->groups())->first(fn (array $g): bool => $this->formKey($g['key']) === $formKey);
        $form = $this->forms[$formKey] ?? null;
        if ($group === null || $form === null) {
            return;
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
            ], $resourceIds, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('forms.'.$formKey.'.'.$field, $messages[0]);
            }

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('forms.'.$formKey.'.brand_name', 'Oluşturulamadı: '.$exception->getMessage());

            return;
        }

        $this->results[$formKey] = $outcome['results'];
        $this->created[$formKey] = [
            'name' => (string) $outcome['brand']->name,
            'url' => route('operator.brand', ['brand' => $outcome['brand']->id]),
        ];
    }

    public function render(PortfolioDiscoveryGrouper $grouper): View
    {
        $groups = $grouper->groups();
        $this->syncForms($groups);

        return view('livewire.operator.portfolio.discover-and-group', [
            'groups' => collect($groups)->map(fn (array $g): array => $g + ['form_key' => $this->formKey($g['key'])])->all(),
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
                'customer_name' => (string) $group['suggested_brand'],
                'brand_name' => (string) $group['suggested_brand'],
                'website_url' => $group['host'] !== null ? 'https://'.$group['host'].'/' : '',
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
