<?php

namespace App\Livewire\Operator\Assistant;

use App\Models\AssetRenewal;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Assistant\RenewalService;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Yenilemeler: domain / hosting / SSL / other renewals of all brands with expiry, provider, cost, what the
 * customer pays and the collection state. Domain and SSL dates fill themselves; a manually entered date wins.
 */
#[Layout('operator.layouts.app')]
#[Title('Yenilemeler')]
final class RenewalsPage extends Component
{
    #[Url]
    public string $brand = '';

    #[Url]
    public string $window = '90';

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
    }

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = ['brand_id' => $this->brand, 'digital_asset_id' => '', 'kind' => 'hosting', 'label' => '', 'provider' => '', 'expires_on' => '',
            'auto_renew' => false, 'cost_amount' => '', 'charge_amount' => '', 'currency' => 'TRY', 'collection_status' => 'not_billed', 'notes' => ''];
    }

    public function edit(int $id): void
    {
        $row = AssetRenewal::query()->findOrFail($id);
        $this->editingId = $row->id;
        $this->form = [
            'brand_id' => (string) $row->brand_id, 'digital_asset_id' => (string) ($row->digital_asset_id ?? ''), 'kind' => $row->kind, 'label' => $row->label,
            'provider' => (string) $row->provider, 'expires_on' => $row->expires_on?->toDateString() ?? '', 'auto_renew' => $row->auto_renew,
            'cost_amount' => $row->cost_amount !== null ? (string) $row->cost_amount : '', 'charge_amount' => $row->charge_amount !== null ? (string) $row->charge_amount : '',
            'currency' => $row->currency, 'collection_status' => $row->collection_status, 'notes' => (string) $row->notes,
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate([
            'form.brand_id' => ['required', 'exists:brands,id'], 'form.kind' => ['required', 'in:'.implode(',', array_keys(AssetRenewal::KINDS))],
            'form.label' => ['required', 'string', 'max:255'], 'form.provider' => ['nullable', 'string', 'max:160'], 'form.expires_on' => ['nullable', 'date'],
            'form.cost_amount' => ['nullable', 'numeric', 'min:0'], 'form.charge_amount' => ['nullable', 'numeric', 'min:0'], 'form.currency' => ['required', 'in:TRY,USD,EUR'],
            'form.collection_status' => ['required', 'in:'.implode(',', array_keys(AssetRenewal::COLLECTION))], 'form.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $row = $this->editingId ? AssetRenewal::query()->findOrFail($this->editingId) : new AssetRenewal;
        $date = $this->form['expires_on'] !== '' ? $this->form['expires_on'] : null;
        $row->fill([
            'brand_id' => (int) $this->form['brand_id'],
            'digital_asset_id' => $this->form['digital_asset_id'] !== '' ? (int) $this->form['digital_asset_id'] : null,
            'kind' => $this->form['kind'], 'label' => trim($this->form['label']), 'provider' => trim((string) $this->form['provider']) ?: null,
            'expires_on' => $date, 'auto_renew' => (bool) $this->form['auto_renew'],
            'cost_amount' => $this->form['cost_amount'] !== '' ? (float) $this->form['cost_amount'] : null,
            'charge_amount' => $this->form['charge_amount'] !== '' ? (float) $this->form['charge_amount'] : null,
            'currency' => $this->form['currency'], 'collection_status' => $this->form['collection_status'], 'notes' => trim((string) $this->form['notes']) ?: null,
        ]);
        // A date typed by the operator is never replaced by RDAP / TLS.
        if (! $row->exists || $row->isDirty('expires_on')) {
            $row->expires_source = 'manual';
        }
        $row->save();
        $this->editingId = null;
        $this->message = 'Kaydedildi.';
    }

    public function setCollection(int $id, string $status): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        abort_unless(array_key_exists($status, AssetRenewal::COLLECTION), 422);
        AssetRenewal::query()->findOrFail($id)->forceFill(['collection_status' => $status])->save();
    }

    public function renewed(int $id): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $row = AssetRenewal::query()->findOrFail($id);
        $row->forceFill(['expires_on' => ($row->expires_on ?? now())->copy()->addYear(), 'expires_source' => 'manual', 'collection_status' => $row->charge_amount !== null ? 'not_billed' : $row->collection_status])->save();
        $this->message = $row->label.' bir yıl uzatıldı (yeni bitiş '.$row->expires_on?->format('d.m.Y').').';
    }

    public function refresh(RenewalService $renewals): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $stats = $renewals->daily();
        $this->message = sprintf('%d alan adı ve %d SSL tarihi güncellendi.', $stats['domains_refreshed'], $stats['ssl_refreshed']);
    }

    public function render(): View
    {
        $rows = AssetRenewal::query()->with('brand:id,name')
            ->when($this->brand !== '', fn ($q) => $q->where('brand_id', (int) $this->brand))
            ->when($this->window !== 'all', fn ($q) => $q->where(fn ($inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '<=', now()->addDays((int) $this->window))))
            ->orderByRaw('case when expires_on is null then 1 else 0 end')->orderBy('expires_on')->get();
        $upcomingCharge = $rows->filter(fn (AssetRenewal $r): bool => $r->charge_amount !== null && in_array($r->collection_status, ['not_billed', 'billed'], true)
            && $r->expires_on !== null && $r->expires_on->lte(now()->addDays(30)))->groupBy('currency')->map(fn ($g) => $g->sum('charge_amount'));

        return view('livewire.operator.assistant.renewals', [
            'rows' => $rows,
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id'),
            'assets' => $this->editingId !== null && ($this->form['brand_id'] ?? '') !== ''
                ? DigitalAsset::query()->where('brand_id', (int) $this->form['brand_id'])->orderBy('name')->pluck('name', 'id') : collect(),
            'upcomingCharge' => $upcomingCharge,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
